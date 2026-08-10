<?php

namespace App\Services\Suppliers;

use App\FixedSetting;

/**
 * Resolves the LBP→USD divisor used to normalise supplier prices that arrive in
 * Lebanese Pounds (usharez, umanage) into the USD the storefront sells in.
 *
 * LBP→USD is a market rate, not a per-supplier one, so the platform keeps a
 * single `fixed_settings.lbp_per_usd` that every supplier reads. A supplier may
 * still declare its own column as an override for the case where one supplier
 * quotes at a different internal rate.
 *
 * Resolution order (first positive value wins):
 *   1. the supplier's own fixed_settings override column, when it declares one
 *   2. the shared `fixed_settings.lbp_per_usd`
 *   3. the supplier's config/env value
 *   4. the global `services.lbp_per_usd` (LBP_PER_USD)
 *
 * Returns 0.0 when nothing usable is configured — callers must treat that as
 * "not configured" rather than dividing by it.
 */
class ExchangeRate
{
    /**
     * @param string|null $overrideColumn supplier-specific fixed_settings column,
     *                                    e.g. 'usharez_lbp_per_usd'
     * @param string|null $configKey      supplier config key,
     *                                    e.g. 'services.usharez.lbp_per_usd'
     */
    public static function lbpPerUsd(?string $overrideColumn = null, ?string $configKey = null): float
    {
        $settings = null;
        try {
            $settings = FixedSetting::first();
        } catch (\Throwable $e) {
            // fixed_settings unreachable (e.g. migrations not run) — use config.
        }

        $candidates = [];

        if ($settings !== null) {
            if ($overrideColumn !== null) {
                $candidates[] = $settings->{$overrideColumn} ?? null;
            }
            $candidates[] = $settings->lbp_per_usd ?? null;
        }

        if ($configKey !== null) {
            $candidates[] = config($configKey);
        }
        $candidates[] = config('services.lbp_per_usd');

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return (float) $candidate;
            }
        }

        return 0.0;
    }
}
