<?php

namespace App\Http\Middleware;

use Closure;
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
 * This lives here rather than in the vendor controller because vendor/ is not
 * tracked or deployed — a patch there would vanish on the next composer install.
 */
class SanitizeCmsListFilters
{
    private const CONSTRAINTS = ['whereIn', 'whereHas'];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function handle(Request $request, Closure $next)
    {
        if (!$request->has('custom_validation') || !$this->isCmsPath($request)) {
            return $next($request);
        }

        $clean = $this->sanitize($request->input('custom_validation'));

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

    private function isCmsPath(Request $request): bool
    {
        $prefix = trim(config('hellotree.cms_route_prefix'), '/');

        return $request->is($prefix, $prefix . '/*');
    }

    /** The sanitized filter list, or null when any entry is not a legitimate filter. */
    private function sanitize($filters): ?array
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
}
