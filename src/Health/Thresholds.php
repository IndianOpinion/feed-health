<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Health;

/**
 * Thresholds that apply to one feed, after settings, per-feed overrides
 * and the `feed_health_thresholds` filter. Durations are in seconds.
 */
final class Thresholds
{
    public function __construct(
        public readonly int $failuresInRow = 3,
        public readonly int $windowMinAttempts = 4,
        public readonly int $failureSharePercent = 50,
        public readonly int $uncheckedFloor = 21600,
        public readonly float $quietMultiplier = 2.0,
        public readonly int $quietFloor = 21600,
        public readonly int $quietCeiling = 604800,
        public readonly int $defaultQuiet = 172800,
        public readonly ?int $fixedQuiet = null,
        public readonly int $minHistoryAttempts = 5,
        public readonly int $minHistorySeconds = 86400,
        public readonly int $redundantMinFresh = 10,
        public readonly int $redundantPercent = 90,
        public readonly int $windowHours = 24
    ) {
    }

    /**
     * @return array<string, int|float|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $int = static fn (string $k, int $d): int => isset($values[$k]) && is_numeric($values[$k]) ? max(0, (int) $values[$k]) : $d;
        $base = new self();
        $fixed = $values['fixedQuiet'] ?? null;

        return new self(
            max(1, $int('failuresInRow', $base->failuresInRow)),
            max(1, $int('windowMinAttempts', $base->windowMinAttempts)),
            max(1, min(100, $int('failureSharePercent', $base->failureSharePercent))),
            max(60, $int('uncheckedFloor', $base->uncheckedFloor)),
            isset($values['quietMultiplier']) && is_numeric($values['quietMultiplier']) ? max(1.0, (float) $values['quietMultiplier']) : $base->quietMultiplier,
            max(60, $int('quietFloor', $base->quietFloor)),
            max(60, $int('quietCeiling', $base->quietCeiling)),
            max(60, $int('defaultQuiet', $base->defaultQuiet)),
            is_numeric($fixed) && (int) $fixed > 0 ? (int) $fixed : null,
            $int('minHistoryAttempts', $base->minHistoryAttempts),
            $int('minHistorySeconds', $base->minHistorySeconds),
            max(1, $int('redundantMinFresh', $base->redundantMinFresh)),
            max(1, min(100, $int('redundantPercent', $base->redundantPercent))),
            max(1, $int('windowHours', $base->windowHours))
        );
    }
}
