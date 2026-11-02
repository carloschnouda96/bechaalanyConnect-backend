<?php

namespace Tests\Feature\Suppliers;

use App\Services\PerfectPanel\PerfectPanelServicePage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Perfect Panel v2 API has no description or delivery time, so 1xpanel's are
 * read from its public /services page. The fixture below is a trimmed copy of
 * the real markup (2026-10-09): the description is a double-HTML-escaped JS
 * template literal split with <br />.
 */
class PerfectPanelServicePageTest extends TestCase
{
    public static function fixture(): string
    {
        return <<<'HTML'
<div class="service-content">
  <div class="service-item" data-category="639367" data-service-id="817" data-price="$15.50">
    <span class="ss-name">Mix 1XPanel 🚩Premium Members Telegram - 365 Days</span>
    <span class="avg c-pointer" data-original-title="The average completion time is calculated based on the completion times of the latest orders.">
      <svg></svg>
      <span>Average time:</span>
      <strong>
            8 minutes
      </strong>
    </span>
    <button onclick="modalOpen(`serviceDetail817`, {
      serviceModalTitle: `Mix 1XPanel 🚩Premium Members Telegram - 365 Days`,
      serviceModalId: '817',
      serviceModalText: `Quality: Premium Members&lt;br /&gt;
Speed: Fast&lt;br /&gt;
Refill: 150 Days Refill&lt;br /&gt;
Link: Check Description&lt;br /&gt;
&lt;br /&gt;
Start Time: Instant&lt;br /&gt;
Drop: Up to 365 days Non Drop&lt;br /&gt;
📍 provider : our platforms&lt;br /&gt;
👉  Screenshot Of Quality :https://storage.perfectcdn.com/a3vltc/rfnb178oh6fwjt03.jpg&lt;br /&gt;
📍 Link: Use the Telegram invite link &amp;amp; never reuse it.&lt;br /&gt;
~recommended~drop~exclusive`,
    })">Details</button>
  </div>
  <div class="service-item" data-category="1" data-service-id="5153">
    <span>Average time:</span>
    <strong>New Service</strong>
    <button onclick="modalOpen(`serviceDetail5153`, {
      serviceModalText: `Quality: USA 🇺🇸&lt;br /&gt;
Speed: 1M/D&lt;br /&gt;
Refill: NoRefill&lt;br /&gt;
Link: Post Link&lt;br /&gt;
Start Time: Instant`,
    })">Details</button>
  </div>
</div>
HTML;
    }

    public function test_it_reads_the_structured_fields_the_average_time_and_the_notes(): void
    {
        $details = (new PerfectPanelServicePage())->parse(self::fixture());

        $this->assertSame(['817', '5153'], array_map('strval', array_keys($details)));

        $this->assertSame('Premium Members', $details['817']['quality']);
        $this->assertSame('Fast', $details['817']['speed']);
        $this->assertSame('150 Days Refill', $details['817']['refill']);
        $this->assertSame('Check Description', $details['817']['link']);
        $this->assertSame('Instant', $details['817']['start_time']);
        $this->assertSame('Up to 365 days Non Drop', $details['817']['drop']);
        $this->assertSame('8 minutes', $details['817']['average_time']);
        // A second "Link:" line is a note, decoded twice (&amp;amp; → &).
        $this->assertSame(['📍 Link: Use the Telegram invite link & never reuse it.'], $details['817']['notes']);

        $this->assertSame('Post Link', $details['5153']['link']);
        $this->assertNull($details['5153']['drop']);
        $this->assertNull($details['5153']['average_time'], '"New Service" means no average yet');
    }

    public function test_supplier_branding_never_survives(): void
    {
        $details = (new PerfectPanelServicePage())->parse(self::fixture());
        $all = json_encode($details, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('provider', $all);
        $this->assertStringNotContainsStringIgnoringCase('perfectcdn', $all);
        $this->assertStringNotContainsStringIgnoringCase('1xpanel', $all);
        $this->assertStringNotContainsString('~recommended', $all);

        $this->assertSame(
            'Premium Members Telegram - 365 Days',
            PerfectPanelServicePage::sanitizeName('Mix 1XPanel 🚩Premium Members Telegram - 365 Days')
        );
        $this->assertSame('Instagram Followers - HQ', PerfectPanelServicePage::sanitizeName('Instagram Followers - HQ'));
    }

    public function test_an_unreachable_page_yields_no_details_instead_of_failing_the_sync(): void
    {
        Http::fake(['panel.test/*' => Http::response('down', 503)]);

        $this->assertSame([], (new PerfectPanelServicePage())->fetch('https://panel.test'));
    }

    public function test_a_page_with_no_services_yields_no_details(): void
    {
        Http::fake(['panel.test/*' => Http::response('<html>Cloudflare challenge</html>', 200)]);

        $this->assertSame([], (new PerfectPanelServicePage())->fetch('https://panel.test'));
    }
}
