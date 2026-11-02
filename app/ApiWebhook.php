<?php

namespace App;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A reseller's order-status webhook endpoint (one per user). Not a CMS model —
 * `api_webhooks` is a plain migration table, like `api_keys`.
 *
 * The signing secret is encrypted at rest, not hashed: every delivery has to
 * sign with it. It is returned to the reseller only when it is created or
 * rotated, the same "shown once" rule as an API key.
 */
class ApiWebhook extends Model
{
    public const SECRET_PREFIX = 'whsec_';

    protected $fillable = ['user_id', 'url', 'secret'];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'last_delivery_at' => 'datetime',
        'last_delivery_status' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function newSecret(): string
    {
        return self::SECRET_PREFIX . Str::random(40);
    }

    /** What the account page shows — never the secret. */
    public function summary(): array
    {
        return [
            'url' => $this->url,
            'last_delivery_at' => optional($this->last_delivery_at)->toIso8601String(),
            'last_delivery_status' => $this->last_delivery_status,
            'last_delivery_error' => $this->last_delivery_error,
        ];
    }

    public function recordDelivery(?int $status, ?string $error): void
    {
        $this->forceFill([
            'last_delivery_at' => now(),
            'last_delivery_status' => $status,
            'last_delivery_error' => $error === null ? null : Str::limit($error, 480),
        ])->saveQuietly();
    }
}
