<?php

namespace App\Console\Commands;

use App\ProductPriceVariation;
use App\ProductsVariation;
use App\Services\Cms\VariationPricing;
use Illuminate\Console\Command;

/**
 * One-off: turn existing fixed prices into the profit % that yields them, so they follow
 * supplier cost changes from now on. Prices stay as they are today (to 3 dp).
 *
 * Opt-in — it changes behaviour: a locked public price or a fixed tier price stops being
 * frozen. Variations with no recorded cost are skipped (nothing to derive from).
 */
class DerivePricePercentages extends Command
{
    protected $signature = 'prices:derive-percentages {--dry-run : Report only, write nothing}';

    protected $description = 'Convert fixed public/tier prices into a saved profit % on cost';

    public function handle(VariationPricing $pricing): int
    {
        $dry = (bool) $this->option('dry-run');
        $public = $tier = $skipped = $drift = 0;

        ProductsVariation::withoutGlobalScope('cms_draft_flag')
            ->with(['priceVariations' => fn ($q) => $q->withoutGlobalScope('cms_draft_flag')])
            ->orderBy('id')
            ->chunkById(200, function ($variations) use ($pricing, $dry, &$public, &$tier, &$skipped, &$drift) {
                foreach ($variations as $variation) {
                    $cost = VariationPricing::costOf($variation);
                    $fixedPublic = (bool) $variation->manual_price;
                    $fixedTiers = $variation->priceVariations->filter(fn (ProductPriceVariation $r) => !$r->isPercentMode());

                    if (!$fixedPublic && $fixedTiers->isEmpty()) {
                        continue;
                    }

                    if ($cost === null || $cost <= 0) {
                        $skipped++;
                        continue;
                    }

                    $targets = [];
                    if ($fixedPublic) {
                        $targets[VariationPricing::PUBLIC] = (float) $variation->price;
                    }
                    foreach ($fixedTiers as $row) {
                        $targets[(string) $row->user_types_id] = (float) $row->price;
                    }

                    foreach ($targets as $column => $price) {
                        $pct = VariationPricing::percentFor($price, $cost);

                        if (abs(ProductsVariation::computeSellingPrice($cost, $pct) - $price) >= 0.0005) {
                            $drift++;
                            $this->warn("{$variation->slug} [{$column}]: {$price} becomes " . ProductsVariation::computeSellingPrice($cost, $pct) . " at {$pct}%");
                        }

                        $column === VariationPricing::PUBLIC ? $public++ : $tier++;

                        if (!$dry) {
                            $pricing->applyCell($variation, [
                                'variation_id' => $variation->id,
                                'column' => (string) $column,
                                'mode' => 'price',
                                'value' => $price,
                            ]);
                        }
                    }
                }
            });

        $this->info(($dry ? '[dry run] ' : '') . "public: {$public}, tier: {$tier}, skipped (no cost): {$skipped}, rounding drift: {$drift}");

        return self::SUCCESS;
    }
}
