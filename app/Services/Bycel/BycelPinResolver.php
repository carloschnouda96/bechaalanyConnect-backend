<?php

namespace App\Services\Bycel;

use App\BycelPurchase;
use App\Services\Suppliers\SupplierApiException;
use Illuminate\Support\Facades\Log;

/**
 * Decides which /last_pin_report row belongs to which purchase intent.
 *
 * THE WINDOW ARGUMENT
 *
 * Purchases are serialised by a mutex, so our intents form a totally ordered
 * sequence, each carrying the watermark (highest MerchantPurchaseId) observed just
 * before its POST. For intent I with watermark W_I, and W_next the watermark of the
 * next intent recorded after it:
 *
 *     our purchase for I, if it happened, lies strictly inside (W_I, W_next]
 *     and it is the only purchase WE made in that window.
 *
 * Anything else in the window came from outside this system — the Bycel merchant
 * app, another integration, a manual sale. Two consequences:
 *
 *  - the window is bounded and recomputable days later, so a crashed purchase can
 *    still be reconciled correctly;
 *  - PROVABLE NON-PURCHASE: once a LATER intent exists (so the window is closed),
 *    if a coverage-proven page shows no candidate in it, nothing was bought. That
 *    is the only condition under which an order may be auto-failed and refunded.
 *
 * COVERAGE
 *
 * `last=N` returns the most recent N transactions. Selecting "rows above the
 * watermark" is only sound if the page actually reaches back PAST the watermark —
 * otherwise intervening merchant-app sales can push our row off the end and leave
 * exactly one unrelated match. So coverage must be proven
 * (min(MerchantPurchaseId) <= watermark) before any claim, escalating the page size
 * until it is.
 *
 * NEVER relax "exactly one candidate" to "the closest one". Every degradation in
 * this class is designed to end in manual review, never in a wrong delivery.
 */
class BycelPinResolver
{
    public function __construct(
        private BycelClient $client,
        private BycelPurchaseLedger $ledger,
    ) {
    }

    /**
     * @param int $attempts in-lock retries while a freshly-bought PIN materialises
     */
    public function resolve(BycelPurchase $purchase, int $attempts = 1): BycelClaimOutcome
    {
        $backoffMs = (int) config('services.bycel.claim_backoff_ms', 1000);
        $outcome = BycelClaimOutcome::pending('not attempted');

        for ($i = 0; $i < max(1, $attempts); $i++) {
            if ($i > 0) {
                usleep($backoffMs * 1000 * $i);
            }

            $outcome = $this->attempt($purchase);

            if (!$outcome->is(BycelClaimOutcome::PENDING)) {
                return $outcome;
            }
        }

        return $outcome;
    }

    private function attempt(BycelPurchase $purchase): BycelClaimOutcome
    {
        $watermark = (int) $purchase->watermark_id;

        try {
            [$rows, $covered] = $this->pageUntilCovered($watermark);
        } catch (SupplierApiException $e) {
            // A read failure tells us nothing about whether the purchase happened.
            return BycelClaimOutcome::pending('report unavailable: ' . $e->getMessage());
        }

        if (!$covered) {
            // Claiming without proven coverage risks matching an unrelated sale.
            Log::warning('Bycel report page never reached back past the watermark', [
                'purchase_id' => $purchase->id,
                'watermark' => $watermark,
            ]);

            return BycelClaimOutcome::pending('report window too short to prove coverage');
        }

        $upper = $this->ledger->nextWatermarkAfter($purchase);
        $claimed = array_flip($this->ledger->claimedIds($watermark));

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $this->intOrNull($row['MerchantPurchaseId'] ?? null);
            if ($id === null || $id <= $watermark) {
                continue;
            }
            if ($upper !== null && $id > $upper) {
                continue;
            }
            if (isset($claimed[$id])) {
                continue; // already belongs to another intent
            }
            if (!$this->matches($row, $purchase)) {
                continue;
            }

            $candidates[] = $row;
        }

        if (count($candidates) === 1) {
            return BycelClaimOutcome::claimed($candidates[0]);
        }

        if (count($candidates) > 1) {
            Log::critical('Bycel PIN claim is ambiguous — refusing to auto-deliver', [
                'purchase_id' => $purchase->id,
                'order_id' => $purchase->order_id,
                'candidate_ids' => array_map(fn ($r) => $r['MerchantPurchaseId'] ?? null, $candidates),
            ]);

            return BycelClaimOutcome::ambiguous($candidates);
        }

        // Zero candidates. Only safe to declare "never purchased" once the window is
        // closed by a later intent — otherwise the PIN may simply not exist yet.
        if ($upper !== null) {
            return BycelClaimOutcome::notPurchased('window closed with no matching row');
        }

        return BycelClaimOutcome::pending('no matching row yet');
    }

    /**
     * Fetch escalating page sizes until the page provably reaches back past the
     * watermark.
     *
     * @return array{0: array, 1: bool} [rows, coverageProven]
     */
    private function pageUntilCovered(int $watermark): array
    {
        $sizes = config('services.bycel.report_page_sizes', [20, 50, 100, 200]);
        $sizes = is_array($sizes) && $sizes !== [] ? $sizes : [20, 50, 100, 200];

        $rows = [];
        foreach ($sizes as $size) {
            $rows = $this->client->lastPinReport((int) $size);

            if ($rows === []) {
                // A fresh account with no transactions at all: trivially covered.
                return [[], true];
            }

            $ids = array_values(array_filter(array_map(
                fn ($r) => is_array($r) ? $this->intOrNull($r['MerchantPurchaseId'] ?? null) : null,
                $rows
            ), fn ($v) => $v !== null));

            if ($ids === []) {
                return [$rows, false];
            }

            // Covered if we can see at or below the watermark, or if the account
            // simply has fewer transactions than we asked for.
            if (min($ids) <= $watermark || count($rows) < (int) $size) {
                return [$rows, true];
            }
        }

        return [$rows, false];
    }

    /**
     * Field-level match against the snapshot taken at purchase time.
     *
     * ProductName is NOT matched by default: product_list says "Alfa Invoice" while
     * last_pin_report says "alfa 4.5$ " (note the trailing space) — two different
     * vocabularies. Enable services.bycel.match_product_name only once live probing
     * proves they agree.
     */
    private function matches(array $row, BycelPurchase $purchase): bool
    {
        $snapshot = is_array($purchase->snapshot) ? $purchase->snapshot : [];

        if ($this->intOrNull($row['Qty'] ?? null) !== 1) {
            return false;
        }

        $pin = trim((string) ($row['PinCode'] ?? ''));
        $recharged = preg_replace('/\D+/', '', (string) ($row['DirectRecharge'] ?? ''));

        if ($purchase->family === 'voucher') {
            // A voucher row carries a PIN and no recharged number.
            if ($pin === '' || $recharged !== '') {
                return false;
            }
        } else {
            // A recharge row is identified by the number it credited.
            $expected = preg_replace('/\D+/', '', (string) $purchase->recipient);
            if ($expected === '' || $recharged === '' || $recharged !== $expected) {
                return false;
            }
        }

        $priceTolerance = (float) config('services.bycel.price_tolerance_lbp', 1.0);
        $faceTolerance = (float) config('services.bycel.face_tolerance_usd', 0.005);

        // last_pin_report's FinalSellingPrice_LBP is the PRE-discount price, which is
        // exactly what product_list quotes — that is what makes it a valid match key.
        if (!$this->within($row['FinalSellingPrice_LBP'] ?? null, $snapshot['FinalSellingPrice_LBP'] ?? null, $priceTolerance)) {
            return false;
        }
        if (!$this->within($row['FacePrice_USD'] ?? null, $snapshot['FacePrice_USD'] ?? null, $faceTolerance)) {
            return false;
        }

        if (config('services.bycel.match_product_name', false)) {
            $a = $this->normalizeName((string) ($row['ProductName'] ?? ''));
            $b = $this->normalizeName((string) ($snapshot['ProductName'] ?? ''));
            if ($a === '' || $b === '' || $a !== $b) {
                return false;
            }
        }

        return true;
    }

    private function within($actual, $expected, float $tolerance): bool
    {
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        return abs((float) $actual - (float) $expected) <= $tolerance;
    }

    private function normalizeName(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    private function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
