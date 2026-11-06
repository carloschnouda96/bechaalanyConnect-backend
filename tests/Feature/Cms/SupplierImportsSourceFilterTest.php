<?php

namespace Tests\Feature\Cms;

use App\SupplierCategory;
use Hellotreedigital\Cms\Models\Admin;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Source dropdown on Supplier imports. `source` is a text column, so the vendor filter
 * popup cannot offer it and SanitizeCmsListFilters (integer ids only) cannot carry it.
 */
class SupplierImportsSourceFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(), 'admin');
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    private function admin(): Admin
    {
        $admin = new Admin();
        $admin->name = 'Imports Admin';
        $admin->email = 'imports_' . uniqid() . '@example.test';
        $admin->password = bcrypt('secret-Password1');
        $admin->admin_role_id = null;
        $admin->save();

        return $admin->refresh();
    }

    private function seedRows(string $source, int $count, string $namePrefix = 'Row'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('supplier_categories')->insert([
                'source' => $source,
                'external_id' => $source . '-' . uniqid('', true),
                'name' => "{$namePrefix} {$source} {$i}",
                'import_enabled' => 0,
            ]);
        }
    }

    private function url(string $query = ''): string
    {
        return '/' . config('hellotree.cms_route_prefix') . '/supplier-categories' . $query;
    }

    public function test_a_source_shows_only_its_rows_and_the_right_total(): void
    {
        $this->seedRows('test-alpha', 3);
        $this->seedRows('test-beta', 2);

        $response = $this->get($this->url('?source=test-alpha'))->assertOk();

        $rows = $response->viewData('rows');
        $this->assertSame(3, $rows->total());
        $this->assertSame(['test-alpha'], $rows->pluck('source')->unique()->values()->all());
    }

    public function test_the_dropdown_lists_each_source_with_its_count_and_marks_the_active_one(): void
    {
        $this->seedRows('test-alpha', 3);
        $this->seedRows('test-beta', 2);

        $response = $this->get($this->url('?source=test-beta'))->assertOk();

        $response->assertSee('test-alpha (3)');
        $response->assertSee('<option value="test-beta" selected>test-beta (2)</option>', false);
    }

    public function test_page_two_stays_filtered_and_its_links_carry_the_source(): void
    {
        $this->seedRows('test-alpha', 12);
        $this->seedRows('test-beta', 12);

        // The template appends $_GET, which the test client does not populate.
        $_GET = ['source' => 'test-alpha'];
        $first = $this->get($this->url('?source=test-alpha'))->assertOk();
        parse_str(parse_url($first->viewData('rows')->url(2), PHP_URL_QUERY), $query);
        $this->assertSame('test-alpha', $query['source'] ?? null);

        $second = $this->get($this->url('?source=test-alpha&page=2'))->assertOk();
        $rows = $second->viewData('rows');
        $this->assertSame(2, $rows->currentPage());
        $this->assertSame(12, $rows->total());
        $this->assertCount(2, $rows->items());
        $this->assertSame(['test-alpha'], $rows->pluck('source')->unique()->values()->all());
    }

    public function test_a_search_matching_another_suppliers_row_does_not_return_it(): void
    {
        $this->seedRows('test-alpha', 2, 'Needle');
        $this->seedRows('test-beta', 2, 'Needle');

        $rows = $this->get($this->url('?source=test-alpha&custom_search=Needle'))->assertOk()->viewData('rows');

        $this->assertSame(2, $rows->total());
        $this->assertSame(['test-alpha'], $rows->pluck('source')->unique()->values()->all());
    }

    public function test_a_bare_url_reuses_the_remembered_source_and_an_empty_one_clears_it(): void
    {
        $this->seedRows('test-alpha', 2);
        $this->seedRows('test-beta', 3);

        $this->get($this->url('?source=test-alpha'))->assertOk();

        // The vendor's post-save redirect and the per-page, search and sort URLs carry no source.
        $this->assertSame(2, $this->get($this->url())->assertOk()->viewData('rows')->total());
        $this->assertSame(2, $this->get($this->url('?per_page=25'))->assertOk()->viewData('rows')->total());

        $cleared = $this->get($this->url('?source='))->assertOk()->viewData('rows')->total();
        $this->assertGreaterThanOrEqual(5, $cleared);
        $this->assertSame($cleared, $this->get($this->url())->assertOk()->viewData('rows')->total());
    }

    public function test_an_unknown_or_array_source_shows_everything_and_forgets_the_stored_one(): void
    {
        $this->seedRows('test-alpha', 2);
        $this->seedRows('test-beta', 3);
        $all = SupplierCategory::withoutGlobalScope('cms_draft_flag')->count();

        $this->get($this->url('?source=test-alpha'))->assertOk();
        $this->assertSame($all, $this->get($this->url('?source=no-such-supplier'))->assertOk()->viewData('rows')->total());
        $this->assertSame($all, $this->get($this->url())->assertOk()->viewData('rows')->total());

        $this->get($this->url('?source=test-alpha'))->assertOk();
        $this->assertSame($all, $this->get($this->url('?source[]=test-alpha'))->assertOk()->viewData('rows')->total());
    }

    public function test_it_combines_with_the_category_filter(): void
    {
        $categoryId = DB::table('categories')->value('id');
        if (!$categoryId) {
            $categoryId = DB::table('categories')->insertGetId(['slug' => 'test-source-filter-' . uniqid()]);
        }

        $this->seedRows('test-alpha', 2);
        $this->seedRows('test-beta', 1);
        DB::table('supplier_categories')->whereIn('source', ['test-alpha', 'test-beta'])
            ->where('name', 'like', '% 1')->update(['category_id' => $categoryId]);

        $rows = $this->get($this->url('?source=test-alpha&' . http_build_query([
            'custom_validation' => [['constraint' => 'whereIn', 'value' => ['category_id', [$categoryId]]]],
        ])))->assertOk()->viewData('rows');

        $this->assertSame(1, $rows->total());
        $this->assertSame('test-alpha', $rows->first()->source);
    }

    public function test_the_dropdown_form_keeps_the_other_list_params_but_not_the_page(): void
    {
        $this->seedRows('test-alpha', 1);

        $response = $this->get($this->url('?' . http_build_query([
            'source' => 'test-alpha',
            'per_page' => 25,
            'custom_search' => 'Row',
            'sort_by' => 'name',
            'sort_by_direction' => 'asc',
            'page' => 1,
            'custom_validation' => [['constraint' => 'whereIn', 'value' => ['category_id', [7]]]],
        ])))->assertOk();

        $html = $response->getContent();
        $start = strpos($html, 'name="source"');
        $form = substr($html, strrpos(substr($html, 0, $start), '<form'), $start - strrpos(substr($html, 0, $start), '<form'));

        $this->assertStringContainsString('name="per_page" value="25"', $form);
        $this->assertStringContainsString('name="custom_search" value="Row"', $form);
        $this->assertStringContainsString('name="sort_by" value="name"', $form);
        $this->assertStringContainsString('name="sort_by_direction" value="asc"', $form);
        $this->assertStringContainsString('name="custom_validation[0][value][1][]" value="7"', $form);
        $this->assertStringNotContainsString('name="page"', $form);
    }

    public function test_the_filter_popup_clear_link_also_clears_the_source(): void
    {
        $this->seedRows('test-alpha', 1);

        $this->get($this->url('?source=test-alpha'))
            ->assertOk()
            ->assertSee('supplier-categories?source=" class="btn btn-secondary btn-sm">Clear</a>', false);
    }

    public function test_the_clear_filter_button_shows_only_while_something_narrows_the_list(): void
    {
        $this->seedRows('test-alpha', 1);

        $this->get($this->url('?source='))->assertOk()->assertDontSee('Clear filter');
        $this->get($this->url('?source=&per_page=25'))->assertOk()->assertDontSee('Clear filter');

        $this->get($this->url('?source=&custom_search=Row'))->assertOk()->assertSee('Clear filter');
        $this->get($this->url('?source=&' . http_build_query([
            'custom_validation' => [['constraint' => 'whereIn', 'value' => ['category_id', [7]]]],
        ])))->assertOk()->assertSee('Clear filter');

        $this->get($this->url('?source=test-alpha'))->assertOk()->assertSee('Clear filter');
        // Remembered from the session, with nothing in the URL.
        $this->get($this->url())->assertOk()->assertSee('Clear filter');
    }

    public function test_the_clear_filter_button_resets_filters_but_keeps_page_size_and_sort(): void
    {
        $this->seedRows('test-alpha', 2);
        $this->seedRows('test-beta', 3);
        $all = SupplierCategory::withoutGlobalScope('cms_draft_flag')->count();

        $response = $this->get($this->url('?source=test-alpha&per_page=25&sort_by=name&sort_by_direction=asc&custom_search=Row&page=1'))->assertOk();

        $this->assertSame(1, preg_match('/href="([^"]+)" class="btn btn-secondary btn-sm ml-2">Clear filter</', $response->getContent(), $match));
        $href = html_entity_decode($match[1]);
        parse_str(parse_url($href, PHP_URL_QUERY), $query);

        $this->assertSame(['per_page' => '25', 'sort_by' => 'name', 'sort_by_direction' => 'asc', 'source' => ''], $query);

        $cleared = $this->get(parse_url($href, PHP_URL_PATH) . '?' . parse_url($href, PHP_URL_QUERY))->assertOk();
        $this->assertSame($all, $cleared->viewData('rows')->total());
        $this->assertSame($all, $this->get($this->url())->assertOk()->viewData('rows')->total());
    }

    public function test_the_scope_does_not_outlive_the_list_call(): void
    {
        $this->seedRows('test-alpha', 1);
        $this->seedRows('test-beta', 1);

        $this->get($this->url('?source=test-alpha'))->assertOk();

        $this->assertSame(1, SupplierCategory::where('source', 'test-beta')->count());
    }
}
