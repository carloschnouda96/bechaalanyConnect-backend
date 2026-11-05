<?php

namespace Tests\Feature\Cms;

use App\Http\Middleware\SanitizeCmsListFilters;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The vendor CMS list action calls whatever query-builder method ?custom_validation
 * names. SanitizeCmsListFilters must let the vendor filter form's own requests
 * through and refuse everything else before that action runs.
 */
class CmsListFilterSanitizationTest extends TestCase
{
    private function filter(string $path, array $query): Request
    {
        $request = Request::create($path, 'GET', $query);

        return (new SanitizeCmsListFilters())->handle($request, fn ($r) => $r);
    }

    private function assertRejected(array $filter): void
    {
        try {
            $this->filter('/admin/orders', ['custom_validation' => [$filter]]);
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());

            return;
        }
        $this->fail('Filter was not rejected: ' . json_encode($filter));
    }

    public function test_the_vendor_filter_forms_own_requests_pass_with_ids_cast_to_int(): void
    {
        $request = $this->filter('/admin/orders', ['custom_validation' => [
            ['constraint' => 'whereIn', 'table' => 'statuses', 'value' => ['statuses_id', ['1', '3']]],
            ['constraint' => 'whereIn', 'table' => 'users', 'value' => ['users_id']],
        ]]);

        $this->assertSame([
            ['constraint' => 'whereIn', 'value' => ['statuses_id', [1, 3]], 'table' => 'statuses'],
            ['constraint' => 'whereIn', 'value' => ['users_id', []], 'table' => 'users'],
        ], $request->input('custom_validation'));
    }

    public function test_a_where_has_filter_on_a_real_select_multiple_field_passes(): void
    {
        $request = $this->filter('/admin/products', ['custom_validation' => [
            ['constraint' => 'whereHas', 'table' => 'products', 'value' => ['related_products', ['7']]],
        ]]);

        $this->assertSame([7], $request->input('custom_validation')[0]['value'][1]);
    }

    public function test_a_builder_write_is_refused(): void
    {
        $this->assertRejected(['constraint' => 'update', 'value' => [['credits_balance' => 9999], 'x']]);
        $this->assertRejected(['constraint' => 'delete', 'value' => ['id', ['1']]]);
    }

    public function test_raw_sql_is_refused(): void
    {
        $this->assertRejected(['constraint' => 'whereRaw', 'value' => ['1=1 or (select 1)', ['1']]]);
        $this->assertRejected(['constraint' => 'whereHas', 'table' => 'tags', 'value' => ['tags', ['1) or (1=1']]]);
        $this->assertRejected(['constraint' => 'whereIn', 'value' => ['id) or (1', ['1']]]);
        $this->assertRejected(['constraint' => 'whereHas', 'table' => 'tags x on 1=1 --', 'value' => ['tags', ['1']]]);
    }

    public function test_an_extra_positional_argument_is_refused(): void
    {
        // A third value became where()/whereIn()'s $boolean, which is concatenated into the SQL.
        $this->assertRejected(['constraint' => 'whereIn', 'value' => ['id', ['1'], 'or 1=1 or']]);
    }

    /** A filter on a column the page does not offer as a filter is an oracle on its values. */
    public function test_a_filter_on_a_field_the_page_does_not_offer_is_refused(): void
    {
        $this->assertRejected(['constraint' => 'whereIn', 'value' => ['client_order_id', ['1']]]);
        $this->assertRejected(['constraint' => 'whereIn', 'value' => ['credits_applied_status', ['1']]]);
        $this->assertRejected(['constraint' => 'whereIn', 'table' => 'admins', 'value' => ['statuses_id', ['1']]]);
    }

    /** Sorting by a hidden column discloses its order; the vendor would accept any column. */
    public function test_sorting_by_a_hidden_field_is_dropped(): void
    {
        $request = $this->filter('/admin/users', ['sort_by' => 'password_reset_token', 'sort_by_direction' => 'asc']);

        $this->assertNull($request->query('sort_by'));
        $this->assertNull($request->query('sort_by_direction'));
    }

    public function test_sorting_by_a_listed_field_is_kept(): void
    {
        $request = $this->filter('/admin/users', ['sort_by' => 'email', 'sort_by_direction' => 'asc']);

        $this->assertSame('email', $request->query('sort_by'));
    }

    public function test_requests_outside_the_cms_are_left_alone(): void
    {
        $filters = [['constraint' => 'update', 'value' => ['x', 'y']]];

        $request = $this->filter('/api/en/search', ['custom_validation' => $filters]);

        $this->assertSame($filters, $request->input('custom_validation'));
    }
}
