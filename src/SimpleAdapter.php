<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\FetchResult;
use IndianOpinion\FeedHealth\Value\Input;

/**
 * An adapter for importers that report only through the plugin's actions.
 * It registers a key and a label and does nothing else. The
 * `feed_health_adapters` filter also accepts a plain array
 * `['key' => ..., 'label' => ...]`, which becomes one of these.
 */
final class SimpleAdapter implements Adapter
{
    private string $key;

    /**
     * @param (callable(): iterable<FeedDescriptor>)|null $feeds
     * @throws InvalidValue
     */
    public function __construct(string $key, private string $label = '', private $feeds = null)
    {
        $this->key = Input::adapterKey($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : $this->key;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function boot(Recorder $recorder): void
    {
    }

    public function feeds(): iterable
    {
        return $this->feeds !== null ? ($this->feeds)() : [];
    }

    public function supportsTestFetch(): bool
    {
        return false;
    }

    public function testFetch(FeedDescriptor $feed): FetchResult
    {
        throw new \LogicException('This adapter does not support test fetch.');
    }
}
