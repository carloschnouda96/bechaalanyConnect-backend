<?php

namespace Tests\Unit;

use App\BycelPurchase;
use App\Services\Bycel\BycelClaimOutcome;
use App\Services\Bycel\BycelClient;
use App\Services\Bycel\BycelPinResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Support\FakeBycelLedger;

/**
 * The logic that decides which /last_pin_report row belongs to which order — i.e.
 * which customer is handed which card. Every degradation here must end in manual
 * review, never in a wrong delivery, so the negative cases matter more than the
 * happy path.
 */
class BycelPinResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bycel.base_url' => 'https://www.bycel.app/OutAPIV1',
            'services.bycel.key' => 'TEST_key',
            'services.bycel.report_page_sizes' => [3, 6],
            'services.bycel.price_tolerance_lbp' => 1.0,
            'services.bycel.face_tolerance_usd' => 0.005,
            'services.bycel.match_product_name' => false,
            'services.bycel.claim_backoff_ms' => 0,
        ]);
    }

    /** A voucher row as last_pin_report returns it. */
    private function row(int $id, array $overrides = []): array
    {
        return array_merge([
            'MerchantPurchaseId' => $id,
            'ProductName' => 'alfa 4.5$ ',
            'Qty' => 1,
            'PinCode' => '4111984074937' . $id,
            'Serial' => '81501852388' . $id,
            'DirectRecharge' => null,
            'FacePrice_USD' => 4.5,
            'FinalSellingPrice_LBP' => 467052.5,
            'PriceAfterDiscount_LBP' => 461212.625,
            'DiscountPercentage' => 1.45,
        ], $overrides);
    }

    private function purchase(array $overrides = []): BycelPurchase
    {
        $p = new BycelPurchase(array_merge([
            'order_id' => 1,
            'order_uuid' => 'uuid-1',
            'family' => 'voucher',
            'product_id' => '3468',
            'watermark_id' => 100,
            'state' => BycelPurchase::STATE_SENT,
            'snapshot' => ['FinalSellingPrice_LBP' => 467052.5, 'FacePrice_USD' => 4.5, 'ProductName' => 'alfa 4.5$'],
        ], $overrides));
        $p->id = 1;

        return $p;
    }

    private function resolver(FakeBycelLedger $ledger): BycelPinResolver
    {
        return new BycelPinResolver(new BycelClient(), $ledger);
    }

    private function fakeReport(array $rows): void
    {
        Http::fake(['*last_pin_report*' => Http::response($rows, 200)]);
    }

    // ------------------------------------------------------------------ coverage

    /**
     * The page must reach back PAST the watermark. Otherwise intervening
     * merchant-app sales can push our row off the end, leaving exactly one
     * unrelated match that would look claimable.
     */
    public function test_refuses_to_claim_without_proven_coverage(): void
    {
        // Every id is above the watermark and the page is always full, so the
        // report never proves it reached back far enough.
        $this->fakeReport([$this->row(201), $this->row(202), $this->row(203), $this->row(204), $this->row(205), $this->row(206)]);

        $outcome = $this->resolver(new FakeBycelLedger())->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::PENDING));
        $this->assertStringContainsString('coverage', $outcome->reason);
    }

    /** A short page (fewer rows than requested) means the account has no more. */
    public function test_short_page_counts_as_covered(): void
    {
        $this->fakeReport([$this->row(101)]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::CLAIMED));
    }

    public function test_empty_report_is_covered_and_pending_while_window_open(): void
    {
        $this->fakeReport([]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    // ------------------------------------------------------------------ claiming

    public function test_exactly_one_candidate_is_claimed(): void
    {
        $this->fakeReport([$this->row(101), $this->row(99)]);

        $outcome = $this->resolver(new FakeBycelLedger())->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::CLAIMED));
        $this->assertSame(101, $outcome->row['MerchantPurchaseId']);
    }

    /** Two matching rows in the window: someone bought in the Bycel app too. */
    public function test_multiple_candidates_are_never_auto_delivered(): void
    {
        $this->fakeReport([$this->row(101), $this->row(102), $this->row(99)]);

        $outcome = $this->resolver(new FakeBycelLedger())->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::AMBIGUOUS));
        $this->assertCount(2, $outcome->candidates);
    }

    /** Rows already attached to another intent are not up for grabs. */
    public function test_already_claimed_rows_are_excluded(): void
    {
        $this->fakeReport([$this->row(101), $this->row(102), $this->row(99)]);

        $ledger = new FakeBycelLedger();
        $ledger->claimed = [102];

        $outcome = $this->resolver($ledger)->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::CLAIMED));
        $this->assertSame(101, $outcome->row['MerchantPurchaseId']);
    }

    /** The next intent's watermark closes the window; later rows are not ours. */
    public function test_rows_above_the_next_watermark_are_excluded(): void
    {
        $this->fakeReport([$this->row(150), $this->row(99)]);

        $ledger = new FakeBycelLedger();
        $ledger->nextWatermark = 120; // our purchase must be in (100, 120]

        $outcome = $this->resolver($ledger)->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::NOT_PURCHASED));
    }

    /**
     * The provable-non-purchase rule: only once a later intent has closed the
     * window may we declare nothing was bought — that is what makes a refund safe.
     */
    public function test_no_candidate_with_closed_window_is_provably_not_purchased(): void
    {
        $this->fakeReport([$this->row(99)]);

        $ledger = new FakeBycelLedger();
        $ledger->nextWatermark = 130;

        $this->assertTrue($this->resolver($ledger)->resolve($this->purchase())->is(BycelClaimOutcome::NOT_PURCHASED));
    }

    public function test_no_candidate_with_open_window_stays_pending(): void
    {
        $this->fakeReport([$this->row(99)]);

        // nextWatermark is null → the window is still open → never refund.
        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    // -------------------------------------------------------------------- matching

    public function test_voucher_rows_require_a_pin_and_no_recharged_number(): void
    {
        $this->fakeReport([
            $this->row(101, ['PinCode' => '']),                        // no PIN
            $this->row(102, ['DirectRecharge' => '70123456']),         // a recharge, not a voucher
            $this->row(99),
        ]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    public function test_recharge_rows_match_on_the_recharged_number(): void
    {
        $this->fakeReport([
            $this->row(101, ['PinCode' => '', 'DirectRecharge' => '70476165']),
            $this->row(102, ['PinCode' => '', 'DirectRecharge' => '03999999']),
            $this->row(99),
        ]);

        $outcome = $this->resolver(new FakeBycelLedger())->resolve(
            $this->purchase(['family' => 'recharge', 'recipient' => '70476165'])
        );

        $this->assertTrue($outcome->is(BycelClaimOutcome::CLAIMED));
        $this->assertSame(101, $outcome->row['MerchantPurchaseId']);
    }

    public function test_price_mismatch_disqualifies_a_row(): void
    {
        $this->fakeReport([$this->row(101, ['FinalSellingPrice_LBP' => 999999.0]), $this->row(99)]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    public function test_face_value_mismatch_disqualifies_a_row(): void
    {
        $this->fakeReport([$this->row(101, ['FacePrice_USD' => 22.73]), $this->row(99)]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    public function test_quantity_other_than_one_disqualifies_a_row(): void
    {
        $this->fakeReport([$this->row(101, ['Qty' => 2]), $this->row(99)]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::PENDING));
    }

    /** Names differ between endpoints ("Alfa Invoice" vs "alfa 4.5$ "), so it is opt-in. */
    public function test_optional_name_matching_tolerates_whitespace_and_case(): void
    {
        config(['services.bycel.match_product_name' => true]);
        $this->fakeReport([$this->row(101, ['ProductName' => '  ALFA   4.5$  ']), $this->row(99)]);

        $this->assertTrue($this->resolver(new FakeBycelLedger())->resolve($this->purchase())->is(BycelClaimOutcome::CLAIMED));
    }

    /** A read failure says nothing about whether the purchase happened. */
    public function test_report_failure_is_pending_not_a_refund(): void
    {
        Http::fake(['*' => Http::response('Unauthorized client', 401)]);

        $ledger = new FakeBycelLedger();
        $ledger->nextWatermark = 130; // window closed — must still NOT be NOT_PURCHASED

        $outcome = $this->resolver($ledger)->resolve($this->purchase());

        $this->assertTrue($outcome->is(BycelClaimOutcome::PENDING));
    }
}
