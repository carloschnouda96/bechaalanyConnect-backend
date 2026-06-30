<?php

namespace App\Services\Suppliers\Connectors;

/**
 * 1xpanel adapter (https://1xpanel.com/api/v2). A standard Perfect Panel v2 SMM
 * panel, so all behaviour comes from PerfectPanelConnector — this class only
 * pins the key. Config lives under `services.1xpanel.*` (env prefix ONEXPANEL_).
 */
class OneXPanelConnector extends PerfectPanelConnector
{
    public const KEY = '1xpanel';

    public function key(): string
    {
        return self::KEY;
    }
}
