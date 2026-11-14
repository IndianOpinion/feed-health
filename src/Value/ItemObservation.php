<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Value;

use IndianOpinion\FeedHealth\Exception\InvalidValue;

/**
 * One item seen in one fetch. The body is accepted for symmetry with other
 * importer-facing tools and dropped at once: it is never kept or stored.
 */
final class ItemObservation
{
    public const ACCEPTED = 'accepted';
    public const DUPLICATE = 'duplicate';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';
    public const OUTCOMES = [self::ACCEPTED, self::DUPLICATE, self::REJECTED, self::UNKNOWN];

    public readonly string $adapter;
    public readonly string $feedKey;
    public readonly string $outcome;
    public readonly string $title;
    public readonly string $source;

    /**
     * @throws InvalidValue
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        string $adapter,
        string $feedKey,
        public readonly string $url,
        string $title = '',
        string $source = '',
        ?string $body = null,
        public readonly ?int $publishedAt = null,
        string $outcome = self::UNKNOWN,
        public readonly ?string $reason = null
    ) {
        unset($body);
        $this->adapter = Input::adapterKey($adapter);
        $this->feedKey = Input::feedKey($feedKey);
        $outcome = strtolower(trim($outcome));
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidValue('Item outcome must be accepted, duplicate, rejected or unknown.');
        }
        $this->outcome = $outcome;
        $this->title = trim(strip_tags($title));
        $this->source = trim(strip_tags($source));
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidValue
     */
    public static function fromArray(array $data): self
    {
        foreach (['adapter', 'feed_key'] as $required) {
            if (!isset($data[$required]) || $data[$required] === '') {
                throw new InvalidValue('Missing ' . $required . '.');
            }
        }
        if (!array_key_exists('url', $data)) {
            throw new InvalidValue('Missing url.');
        }
        $outcome = $data['outcome'] ?? self::UNKNOWN;

        return new self(
            Input::string($data['adapter']),
            Input::feedKey($data['feed_key']),
            Input::string($data['url']),
            Input::string($data['title'] ?? ''),
            Input::string($data['source'] ?? ($data['source_name'] ?? '')),
            null,
            Input::time($data['published_at'] ?? null),
            is_string($outcome) && $outcome !== '' ? $outcome : self::UNKNOWN,
            Input::optionalString($data['reason'] ?? null, 100)
        );
    }
}
