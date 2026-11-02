<?php

namespace App\Http\Controllers;

use App\ApiKey;
use App\ApiWebhook;
use App\Services\Reseller\WebhookSender;
use App\Services\Reseller\WebhookUrlGuard;
use App\Services\Reseller\WebhookUrlRejected;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Storefront side of the reseller API: a customer the admin has given API
 * access (`users.api_enabled`, CMS Users page) lists, creates and revokes their
 * own keys on /account-dashboard/api-keys, and sets the one order-status
 * webhook endpoint their store receives (see OrderWebhooks).
 */
class ApiKeysController extends Controller
{
    /** Enough for a few environments (live, staging, a second store). */
    private const MAX_ACTIVE_KEYS = 5;

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'api_enabled' => (bool) $user->api_enabled,
            'kyc_approved' => $user->verification_status === 'approved',
            'keys' => ApiKey::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->get(['id', 'name', 'prefix', 'allowed_ips', 'last_used_at', 'created_at']),
            'webhook' => optional(ApiWebhook::where('user_id', $user->id)->first())->summary(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->api_enabled) {
            return response()->json([
                'message' => __('api.api_keys.not_enabled'),
                'code' => 'api_not_enabled',
            ], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'allowed_ips' => ['nullable', 'array', 'max:20'],
            'allowed_ips.*' => ['ip'],
        ]);

        if (ApiKey::where('user_id', $user->id)->whereNull('revoked_at')->count() >= self::MAX_ACTIVE_KEYS) {
            return response()->json([
                'message' => __('api.api_keys.limit', ['max' => self::MAX_ACTIVE_KEYS]),
                'code' => 'api_key_limit',
            ], 422);
        }

        [$key, $plain] = ApiKey::issue($user, $data['name'], $data['allowed_ips'] ?? null);

        // The only time the full key is ever returned.
        return response()->json([
            'key' => $plain,
            'api_key' => $key->only(['id', 'name', 'prefix', 'allowed_ips', 'last_used_at', 'created_at']),
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $revoked = ApiKey::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->where('id', (int) $id)
            ->update(['revoked_at' => now()]);

        return $revoked
            ? response()->json(['message' => __('api.api_keys.revoked')])
            : response()->json(['message' => __('api.orders.not_found'), 'code' => 'not_found'], 404);
    }

    /**
     * Create or change the webhook URL. A new webhook gets a signing secret,
     * returned in this response only; changing the URL keeps the secret, so a
     * reseller moving their endpoint does not have to redeploy it.
     */
    public function saveWebhook(Request $request, WebhookUrlGuard $guard)
    {
        $user = $request->user();

        if ($denied = $this->apiDisabled($user)) {
            return $denied;
        }

        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'url']]);
        $url = trim($data['url']);

        try {
            $guard->resolve($url);
        } catch (WebhookUrlRejected $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        $webhook = ApiWebhook::where('user_id', $user->id)->first();
        $secret = null;

        if ($webhook) {
            $webhook->url = $url;
        } else {
            $secret = ApiWebhook::newSecret();
            $webhook = new ApiWebhook(['user_id' => $user->id, 'url' => $url, 'secret' => $secret]);
        }

        $webhook->save();

        return response()->json([
            'message' => __('api.api_keys.webhook_saved'),
            'webhook' => $webhook->summary(),
            'secret' => $secret,
        ], $secret ? 201 : 200);
    }

    /** Replace the signing secret. The old one stops verifying immediately. */
    public function rotateWebhookSecret(Request $request)
    {
        $user = $request->user();

        if ($denied = $this->apiDisabled($user)) {
            return $denied;
        }

        $webhook = ApiWebhook::where('user_id', $user->id)->first();

        if (!$webhook) {
            return response()->json(['message' => __('api.api_keys.webhook_missing'), 'code' => 'not_found'], 404);
        }

        $secret = ApiWebhook::newSecret();
        $webhook->secret = $secret;
        $webhook->save();

        return response()->json(['webhook' => $webhook->summary(), 'secret' => $secret]);
    }

    /**
     * Send a signed `ping` event right now, once, and report what the endpoint
     * answered — so a reseller can check their signature verification without
     * waiting for a real order.
     */
    public function testWebhook(Request $request, WebhookSender $sender)
    {
        $user = $request->user();

        if ($denied = $this->apiDisabled($user)) {
            return $denied;
        }

        $webhook = ApiWebhook::where('user_id', $user->id)->first();

        if (!$webhook) {
            return response()->json(['message' => __('api.api_keys.webhook_missing'), 'code' => 'not_found'], 404);
        }

        $eventId = 'evt_' . Str::uuid()->getHex();
        $body = json_encode([
            'id' => $eventId,
            'type' => 'ping',
            'created_at' => now()->toIso8601String(),
            'data' => (object) [],
        ], JSON_UNESCAPED_SLASHES);

        try {
            $response = $sender->send($webhook, $eventId, 'ping', $body);
            $status = $response->status();
            $error = $response->successful() ? null : 'HTTP ' . $status;
        } catch (WebhookUrlRejected $e) {
            [$status, $error] = [null, $e->getMessage()];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            [$status, $error] = [null, 'Connection failed: ' . $e->getMessage()];
        }

        $webhook->recordDelivery($status, $error);

        return response()->json([
            'delivered' => $error === null,
            'status' => $status,
            'message' => $error === null
                ? __('api.api_keys.webhook_test_sent', ['status' => $status])
                : __('api.api_keys.webhook_test_failed', ['error' => $error]),
            'webhook' => $webhook->summary(),
        ], $error === null ? 200 : 422);
    }

    public function deleteWebhook(Request $request)
    {
        ApiWebhook::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => __('api.api_keys.webhook_removed')]);
    }

    private function apiDisabled($user)
    {
        return $user->api_enabled ? null : response()->json([
            'message' => __('api.api_keys.not_enabled'),
            'code' => 'api_not_enabled',
        ], 403);
    }
}
