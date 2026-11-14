<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Value;

use IndianOpinion\FeedHealth\Exception\InvalidValue;

/**
 * A change of status for one feed, or a notice derived from one.
 */
final class Transition
{
    public const ALERT = 'alert';
    public const RECOVERY = 'recovery';
    public const STILL = 'still';
    public const SYSTEMIC = 'systemic';
    public const NONE = 'none';
    public const KINDS = [self::ALERT, self::RECOVERY, self::STILL, self::SYSTEMIC, self::NONE];

    /**
     * @param array<string, int|string|null> $feed Feed summary: id, adapter, feed_key, label, url, last_http_status, last_success.
     * @throws InvalidValue
     */
    public function __construct(
        public readonly string $id,
        public readonly array $feed,
        public readonly string $from,
        public readonly string $to,
        public readonly string $reason,
        public readonly int $time,
        public readonly string $kind = self::ALERT,
        public readonly bool $flapping = false
    ) {
        if (preg_match('/^[a-f0-9]{8,32}$/', $id) !== 1) {
            throw new InvalidValue('Transition id must be 8 to 32 hex characters.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidValue('Unknown transition kind.');
        }
    }

    public function withKind(string $kind, bool $flapping = false): self
    {
        return new self($this->id, $this->feed, $this->from, $this->to, $this->reason, $this->time, $kind, $flapping);
    }

    public function feedId(): int
    {
        return (int) ($this->feed['id'] ?? 0);
    }

    public function label(): string
    {
        return (string) ($this->feed['label'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'feed' => $this->feed,
            'from' => $this->from,
            'to' => $this->to,
            'reason' => $this->reason,
            'time' => $this->time,
            'kind' => $this->kind,
            'flapping' => $this->flapping,
        ];
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidValue
     */
    public static function fromArray(array $data): self
    {
        $feed = [];
        foreach ((array) ($data['feed'] ?? []) as $key => $value) {
            $feed[(string) $key] = is_int($value) || is_string($value) || $value === null ? $value : (string) json_encode($value);
        }

        return new self(
            Input::string($data['id'] ?? ''),
            $feed,
            Input::string($data['from'] ?? ''),
            Input::string($data['to'] ?? ''),
            Input::string($data['reason'] ?? ''),
            (int) ($data['time'] ?? 0),
            Input::string($data['kind'] ?? self::ALERT),
            (bool) ($data['flapping'] ?? false)
        );
    }
}
