<?php

namespace App\Jobs;

use App\ApiWebhook;
use App\Models\User;
use App\Services\Reseller\WebhookSender;
use App\Services\Reseller\WebhookUrlRejected;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers one order-status event to a reseller's webhook (queued by
 * OrderWebhooks). Any non-2xx answer, timeout or connection error is retried
 * with growing backoff — 8 attempts over roughly 8 hours — after which the
 * event is dropped and the reseller still has GET /v1/orders/{id}.
 *
 * Delivery is at-least-once: a reseller who answered 2xx too slowly gets the
 * same event again, with the same `id`, and is told to deduplicate on it.
 *
 * In production the queue is the database queue drained every minute by
 * /api/cron/queue/work, so the first attempt lands within about a minute of the
 * status change and the backoff below is a lower bound.
 *
 * The endpoint is looked up when the job RUNS, by user, not frozen at dispatch:
 * a reseller who fixes a broken URL gets the pending retries at the new one, and
 * one who removes their webhook or loses API access gets nothing more.
 */
class DeliverOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(
        public int $userId,
        public string $eventId,
        public string $eventType,
        public string $body,
    ) {
        // Never before the status change that produced this event has committed
        // (see FulfillSupplierOrderJob for why this is a call, not a property).
        $this->afterCommit();

        // Its own queue, so a reseller's stalling endpoint cannot hold up other customers'
        // fulfillment. Every worker has to drain it: see CronController::queueWork.
        $this->onQueue('webhooks');
    }

    /** Seconds before attempts 2..8: 30s, 2m, 10m, 30m, 1h, 2h, 4h. */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600, 7200, 14400];
    }

    public function handle(WebhookSender $sender): void
    {
        $webhook = ApiWebhook::where('user_id', $this->userId)->first();

        if (!$webhook || !User::where('id', $this->userId)->where('api_enabled', 1)->exists()) {
            return;
        }

        try {
            $response = $sender->send($webhook, $this->eventId, $this->eventType, $this->body);
        } catch (WebhookUrlRejected $e) {
            $webhook->recordDelivery(null, $e->getMessage());

            if ($e->retryable) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }

            // Re-pointed at a private address since it was saved: never retried.
            Log::warning('Webhook URL refused at delivery', ['user_id' => $this->userId, 'error' => $e->getMessage()]);

            return;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // The transport text can describe the reseller's network, or ours.
            $webhook->recordDelivery(null, 'Connection failed.');

            throw $e;
        }

        if ($response->successful()) {
            $webhook->recordDelivery($response->status(), null);

            return;
        }

        $webhook->recordDelivery($response->status(), 'Endpoint answered HTTP ' . $response->status());

        throw new RuntimeException("Webhook endpoint answered HTTP {$response->status()}");
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Webhook delivery abandoned after all retries', [
            'user_id' => $this->userId,
            'event_id' => $this->eventId,
            'event' => $this->eventType,
            'error' => $e->getMessage(),
        ]);
    }
}
