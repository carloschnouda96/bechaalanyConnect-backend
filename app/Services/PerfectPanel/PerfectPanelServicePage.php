<?php

namespace App\Services\PerfectPanel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the customer-facing service details a Perfect Panel shows on its public
 * `/services` page — the description (Quality / Speed / Refill / Link / Start
 * time / Drop + free-form notes) and the "Average time" — none of which the v2
 * API returns (`action=services` stops at name/rate/min/max/flags).
 *
 * The page is public (no login) and server-rendered: every service is a
 * `<div class="service-item" data-service-id="N">` whose description sits in a
 * `serviceModalText: `…`` JS template literal, double-HTML-escaped and split
 * with `<br />`. On 1xpanel it is ~43 MB / ~13 s for ~7,250 services, which is
 * why the result is cached and why any failure returns [] instead of throwing:
 * these details are decoration, and the catalog sync must never fail on them.
 *
 * Supplier branding is stripped here (sanitizeName / the line filter) so it
 * never reaches the database, let alone the storefront.
 */
class PerfectPanelServicePage
{
    /** Description labels mapped onto our field names. */
    private const LABELS = [
        'quality' => 'quality',
        'speed' => 'speed',
        'refill' => 'refill',
        'guarantee' => 'refill',
        'link' => 'link',
        'start time' => 'start_time',
        'start' => 'start_time',
        'drop' => 'drop',
    ];

    public const CACHE_TTL_SECONDS = 6 * 3600;

    /**
     * @return array<string, array<string, mixed>> keyed by supplier service id
     */
    public function fetch(string $baseUrl, string $label = 'Perfect Panel'): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BechaalanyConnect catalog sync)'])
                ->timeout(90)
                ->get(rtrim($baseUrl, '/') . '/services');
        } catch (\Throwable $e) {
            Log::warning("{$label} services page could not be fetched; service details left unchanged.", ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->failed()) {
            Log::warning("{$label} services page returned HTTP {$response->status()}; service details left unchanged.");
            return [];
        }

        $details = $this->parse($response->body());
        if ($details === []) {
            Log::warning("{$label} services page had no parsable services (layout changed?); service details left unchanged.");
        }

        return $details;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parse(string $html): array
    {
        $out = [];
        foreach (array_slice(explode('class="service-item"', $html), 1) as $item) {
            if (!preg_match('/data-service-id="(\d+)"/', $item, $m)) {
                continue;
            }
            $id = $m[1];

            $text = preg_match('/serviceModalText:\s*`(.*?)`/s', $item, $t) ? $t[1] : '';
            $avg = preg_match('#Average time:</span>\s*<strong>(.*?)</strong>#s', $item, $a)
                ? $this->clean($a[1])
                : '';

            $out[$id] = $this->parseDescription($text) + [
                'average_time' => $avg === '' || stripos($avg, 'new service') !== false ? null : $avg,
            ];
        }

        return $out;
    }

    /**
     * @return array{quality:?string,speed:?string,refill:?string,link:?string,start_time:?string,drop:?string,notes:array<int,string>}
     */
    public function parseDescription(string $escaped): array
    {
        $fields = array_fill_keys(['quality', 'speed', 'refill', 'link', 'start_time', 'drop'], null);
        $notes = [];

        $text = html_entity_decode(html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (preg_split('#<br\s*/?>|\r\n|\n#i', $text) as $line) {
            $line = $this->clean(strip_tags($line));
            if ($line === '' || $this->isBranding($line)) {
                continue;
            }

            if (preg_match('/^([A-Za-z ]{2,20}):\s*(.+)$/u', $line, $kv)) {
                $key = self::LABELS[strtolower(trim($kv[1]))] ?? null;
                if ($key !== null && $fields[$key] === null) {
                    $fields[$key] = $this->clean($kv[2]);
                    continue;
                }
            }

            $notes[] = $line;
        }

        return $fields + ['notes' => $notes];
    }

    /** "Mix 1XPanel 🚩Premium Members …" → "Premium Members …". */
    public static function sanitizeName(string $name): string
    {
        $name = preg_replace('/^\s*mix\s+/iu', '', $name);
        $name = preg_replace('/\b1\s*x\s*panel\b\s*🚩?/iu', '', $name);
        $name = preg_replace('/\s+/u', ' ', (string) $name);

        return trim((string) $name, " \t-–|");
    }

    /** Lines that would expose who we buy from (provider line, their CDN links, their name) or that are panel-internal. */
    private function isBranding(string $line): bool
    {
        return (bool) preg_match('/provider|screenshot|perfectcdn\.com|1\s*x\s*panel/iu', $line)
            || (bool) preg_match('#^https?://\S+$#i', $line)
            // The panel's internal search tags, e.g. "~recommended~drop~exclusive".
            || str_starts_with($line, '~');
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
