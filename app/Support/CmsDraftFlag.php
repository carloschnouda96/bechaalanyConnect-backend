<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The vendor save reads draft_cms_field through $request->input(), which also sees the
 * query string and a JSON body. Set, it skips the page's validation, nulls missing fields
 * and hides the row, so it is refused on any page holding money or identity.
 */
class CmsDraftFlag
{
    public static function strip(Request $request): void
    {
        $request->query->remove('draft_cms_field');
        $request->request->remove('draft_cms_field');

        if ($request->isJson()) {
            $request->json()->remove('draft_cms_field');
        }
    }
}
