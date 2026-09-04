<?php

namespace App\Services\Cms;

/**
 * Renders one CMS field value as text.
 *
 * The vendor's generic templates interpolate every attribute they have no dedicated
 * branch for, and both `htmlspecialchars()` (Blade's `{{ }}`) and `strip_tags()` take a
 * `string`. That is fine until a model casts a registered column to `array` — three do
 * (`user_notifications.data`, `orders.external_response`,
 * `products_variations.external_qty_values`) — at which point the page dies with
 * "Argument #1 ($string) must be of type string, array given".
 *
 * The casts are not the thing to remove: application code reads those columns as arrays
 * and writes them as arrays. Only the display side needs a string, so it is the display
 * side that converts.
 */
class FieldDisplay
{
    /** JSON that a person has to read in a table cell, so: readable over compact. */
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function text($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return (string) json_encode($value, self::JSON_FLAGS);
        }

        if ($value instanceof \JsonSerializable || $value instanceof \stdClass) {
            return (string) json_encode($value, self::JSON_FLAGS);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
