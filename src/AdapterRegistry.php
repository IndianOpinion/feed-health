<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Value\Input;

/**
 * Collects adapters from the `feed_health_adapters` filter, rejects bad and
 * duplicate keys (first registration wins) and boots the available ones.
 */
final class AdapterRegistry
{
    /** @var array<string, Adapter>|null */
    private ?array $adapters = null;

    /** @var array<string, true> */
    private array $booted = [];

    /**
     * @param list<Adapter> $builtIn Adapters the plugin itself ships.
     */
    public function __construct(private array $builtIn, private Logger $logger)
    {
    }

    /**
     * @return array<string, Adapter>
     */
    public function all(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }
        $list = apply_filters('feed_health_adapters', $this->builtIn);
        $out = [];
        foreach (is_array($list) ? $list : $this->builtIn as $adapter) {
            if (is_array($adapter) && isset($adapter['key']) && is_string($adapter['key'])) {
                try {
                    $adapter = new SimpleAdapter($adapter['key'], is_string($adapter['label'] ?? null) ? $adapter['label'] : '');
                } catch (\Throwable $e) {
                    $this->logger->once('adapter-invalid', 'Adapter rejected: invalid key.');
                    continue;
                }
            }
            if (!$adapter instanceof Adapter) {
                $this->logger->once('adapter-type', 'Adapter rejected: not an Adapter object.');
                continue;
            }
            try {
                $key = Input::adapterKey($adapter->key());
            } catch (\Throwable $e) {
                $this->logger->once('adapter-invalid', 'Adapter rejected: invalid key.');
                continue;
            }
            if (isset($out[$key])) {
                $this->logger->once('adapter-dup-' . $key, 'Adapter rejected: duplicate key "' . $key . '"; the first registration wins.');
                continue;
            }
            $out[$key] = $adapter;
        }
        $this->adapters = $out;

        return $out;
    }

    public function get(string $key): ?Adapter
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function isAvailable(string $key): bool
    {
        $adapter = $this->get($key);
        if ($adapter === null) {
            return false;
        }
        try {
            return $adapter->isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Boot every available adapter once.
     */
    public function boot(Recorder $recorder): void
    {
        foreach ($this->all() as $key => $adapter) {
            if (isset($this->booted[$key]) || !$this->isAvailable($key)) {
                continue;
            }
            $this->booted[$key] = true;
            try {
                $adapter->boot($recorder);
            } catch (\Throwable $e) {
                $this->logger->once('adapter-boot-' . $key, 'Adapter "' . $key . '" failed to boot: ' . $e->getMessage());
            }
        }
    }

    public function isBooted(string $key): bool
    {
        return isset($this->booted[$key]);
    }

    /**
     * Forget the cached list (tests, settings changes).
     */
    public function reset(): void
    {
        $this->adapters = null;
        $this->booted = [];
    }
}
