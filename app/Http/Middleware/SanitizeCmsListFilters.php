<?php

namespace App\Http\Middleware;

use Closure;
use Hellotreedigital\Cms\Models\CmsPage;
use Illuminate\Http\Request;

/**
 * Narrows the vendor CMS list filter (`custom_validation`) to the only shape its
 * own filter form ever sends: whereIn / whereHas on a named field, with a list of
 * row ids.
 *
 * The vendor CmsPageController::index() passes each entry's `constraint` straight to
 * call_user_func_array() on the page's Eloquent builder, with `value` as the
 * arguments. A GET /admin/{page} (which AdminMiddleware authorizes with only the
 * browse right) could therefore call whereRaw() with arbitrary SQL, or update() /
 * delete() on the whole table: a read-only role could set every credits_balance,
 * and an outsider could do the same by getting any logged-in admin to open a link.
 * Extra positional values also reached where()'s $boolean, which is concatenated
 * into the SQL, and the whereHas branch implodes the ids into a whereRaw unbound.
 *
 * Filters and sorting are also held to the fields the page actually offers. Filtering or
 * sorting on a hidden column answers questions about its values, one request at a time.
 *
 * This lives here rather than in the vendor controller because vendor/ is not
 * tracked or deployed — a patch there would vanish on the next composer install.
 */
class SanitizeCmsListFilters
{
    private const CONSTRAINTS = ['whereIn', 'whereHas'];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /** Sortable without being a registered field: the vendor's own defaults. */
    private const SYSTEM_SORT_COLUMNS = ['id', 'ht_pos', 'created_at', 'updated_at'];

    public function handle(Request $request, Closure $next)
    {
        if ((!$request->has('custom_validation') && !$request->has('sort_by')) || !$this->isCmsPath($request)) {
            return $next($request);
        }

        $fields = $this->pageFields($request);

        if ($request->has('sort_by') && !$this->sortable($request->input('sort_by'), $fields)) {
            foreach (['sort_by', 'sort_by_direction'] as $key) {
                $request->query->remove($key);
                $request->request->remove($key);
            }
        }

        if (!$request->has('custom_validation')) {
            return $next($request);
        }

        $clean = $this->sanitize($request->input('custom_validation'), $fields);

        if ($clean === null) {
            abort(400, 'Invalid list filter.');
        }

        // index() reads request('custom_validation'), which checks the body before
        // the query string, so both have to carry the sanitized copy.
        if ($request->query->has('custom_validation')) {
            $request->query->set('custom_validation', $clean);
        }
        if ($request->request->has('custom_validation')) {
            $request->request->set('custom_validation', $clean);
        }

        return $next($request);
    }

    /**
     * The page's registered fields keyed by name, or null when this path is not a generated
     * CMS page. The vendor list action only exists for those, so there is nothing to hold it to.
     *
     * @return array<string,array>|null
     */
    private function pageFields(Request $request): ?array
    {
        $page = CmsPage::where('route', $request->segment(2))->where('custom_page', 0)->first();
        if (!$page) {
            return null;
        }

        $fields = is_array($page->fields) ? $page->fields : json_decode((string) $page->fields, true);

        return array_column(is_array($fields) ? $fields : [], null, 'name');
    }

    private function sortable($column, ?array $fields): bool
    {
        if ($fields === null) {
            return true;
        }
        if (!is_string($column)) {
            return false;
        }
        if (in_array($column, self::SYSTEM_SORT_COLUMNS, true)) {
            return true;
        }

        return isset($fields[$column]) && empty($fields[$column]['hide_index']);
    }

    private function isCmsPath(Request $request): bool
    {
        $prefix = trim(config('hellotree.cms_route_prefix'), '/');

        return $request->is($prefix, $prefix . '/*');
    }

    /** The sanitized filter list, or null when any entry is not a legitimate filter. */
    private function sanitize($filters, ?array $fields): ?array
    {
        if (!is_array($filters)) {
            return null;
        }

        $clean = [];

        foreach ($filters as $i => $filter) {
            if (!is_array($filter) || !in_array($filter['constraint'] ?? null, self::CONSTRAINTS, true)) {
                return null;
            }

            $value = $filter['value'] ?? null;
            $field = is_array($value) ? ($value[0] ?? null) : null;

            // Exactly [field, ids]: a third positional value is what reached where()'s $boolean.
            if (!is_string($field) || !preg_match(self::IDENTIFIER, $field) || count(array_diff_key($value, [0 => 0, 1 => 1]))) {
                return null;
            }

            if ($fields !== null && !$this->offeredAsFilter($fields[$field] ?? null, $filter)) {
                return null;
            }

            $ids = [];
            foreach ((array) ($value[1] ?? []) as $id) {
                if ($id === '' || $id === null) {
                    continue; // an untouched filter select submits nothing usable
                }
                if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id)) {
                    return null;
                }
                $ids[] = (int) $id;
            }

            $entry = ['constraint' => $filter['constraint'], 'value' => [$field, $ids]];

            if (isset($filter['table'])) {
                if (!is_string($filter['table']) || !preg_match(self::IDENTIFIER, $filter['table'])) {
                    return null;
                }
                $entry['table'] = $filter['table'];
            }

            $clean[$i] = $entry;
        }

        return $clean;
    }

    /** The list page builds its filter form from these fields, with this table on each. */
    private function offeredAsFilter(?array $field, array $filter): bool
    {
        if (!$field || !in_array($field['form_field'] ?? null, ['select', 'select multiple'], true)) {
            return false;
        }

        return !isset($filter['table']) || $filter['table'] === ($field['form_field_additionals_1'] ?? null);
    }
}
