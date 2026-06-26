<?php

namespace App\Services\Suppliers;

use App\Services\Suppliers\Connectors\SwiftConnector;
use App\Services\Suppliers\Connectors\YassenConnector;
use App\Services\Suppliers\Contracts\SupplierConnector;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a product/order's `external_source` key to its SupplierConnector.
 * Connectors are built through the container so their own dependencies (HTTP
 * clients) are injected. Bound as a singleton in AppServiceProvider.
 */
class SupplierRegistry
{
    /** @var array<string, class-string<SupplierConnector>> */
    private array $map = [
        YassenConnector::KEY => YassenConnector::class,
        SwiftConnector::KEY => SwiftConnector::class,
    ];

    public function __construct(private Container $container)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    public function get(?string $key): ?SupplierConnector
    {
        if ($key === null || !isset($this->map[$key])) {
            return null;
        }
        return $this->container->make($this->map[$key]);
    }

    /**
     * Every registered connector.
     *
     * @return SupplierConnector[]
     */
    public function all(): array
    {
        return array_map(fn ($class) => $this->container->make($class), array_values($this->map));
    }

    /**
     * Connectors whose sync is both enabled and configured.
     *
     * @return SupplierConnector[]
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (SupplierConnector $c) => $c->isEnabled() && $c->isConfigured()
        ));
    }
}
