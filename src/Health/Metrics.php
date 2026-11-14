<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Health;

/**
 * Everything the status rules need to know about one feed at one moment.
 * Built by the checker from the plugin's own tables; pure data.
 */
final class Metrics
{
    /**
     * @param list<array{outcome: string, http_status: int|null, error_code: string|null}> $recent
     *        Most recent non-cached attempts first.
     * @param list<int> $intervals Seconds between non-cached attempts (7 days).
     * @param list<int> $gaps Seconds between new-item arrivals (14 days).
     */
    public function __construct(
        public readonly int $now,
        public readonly int $firstSeen,
        public readonly ?int $lastAttempt,
        public readonly int $attemptsTotal,
        public readonly array $recent,
        public readonly int $windowAttempts,
        public readonly int $windowFailures,
        public readonly array $intervals,
        public readonly array $gaps,
        public readonly ?int $lastNewAt,
        public readonly ?int $newestPublishedAt,
        public readonly int $fresh7d,
        public readonly int $duplicates7d,
        public readonly bool $muted = false,
        public readonly bool $enabled = true
    ) {
    }

    /**
     * Seconds since a past time, never negative.
     */
    public function age(?int $time): ?int
    {
        return $time === null ? null : max(0, $this->now - $time);
    }

    /**
     * True when some recorded time lies in the future (the clock moved back).
     */
    public function clockWentBack(): bool
    {
        foreach ([$this->firstSeen, $this->lastAttempt, $this->lastNewAt] as $time) {
            if ($time !== null && $time > $this->now) {
                return true;
            }
        }

        return false;
    }
}
