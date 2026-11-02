<?php

namespace App\Services\Suppliers\Connectors;

use App\Services\PerfectPanel\PerfectPanelServicePage;

/**
 * 1xpanel adapter (https://1xpanel.com/api/v2). A standard Perfect Panel v2 SMM
 * panel, so all behaviour comes from PerfectPanelConnector — this class only
 * pins the key and strips the panel's own branding ("Mix 1XPanel 🚩…") from
 * service names. Config lives under `services.1xpanel.*` (env prefix ONEXPANEL_).
 */
class OneXPanelConnector extends PerfectPanelConnector
{
    public const KEY = '1xpanel';

    public function key(): string
    {
        return self::KEY;
    }

    protected function displayName(string $name): string
    {
        return PerfectPanelServicePage::sanitizeName($name) ?: $name;
    }
}
