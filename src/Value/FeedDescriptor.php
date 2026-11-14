<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Value;

use IndianOpinion\FeedHealth\Exception\InvalidValue;

/**
 * One feed an importer has configured.
 */
final class FeedDescriptor
{
    public readonly string $adapter;
    public readonly string $feedKey;
    public readonly string $url;
    public readonly string $label;

    /**
     * @throws InvalidValue
     */
    public function __construct(
        string $adapter,
        string $feedKey,
        string $url,
        string $label = '',
        public readonly bool $enabled = true,
        public readonly ?int $expectedIntervalMinutes = null
    ) {
        $this->adapter = Input::adapterKey($adapter);
        $this->feedKey = Input::feedKey($feedKey);
        $this->url = trim($url);
        $this->label = mb_substr(trim($label) !== '' ? trim($label) : $this->url, 0, 255);
        if ($expectedIntervalMinutes !== null && $expectedIntervalMinutes < 1) {
            throw new InvalidValue('Expected interval must be at least one minute.');
        }
    }
}
