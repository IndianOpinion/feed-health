<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Health;

/**
 * Per-feed cadence: the usual gap between new items and the usual interval
 * between attempts, and the thresholds derived from them.
 */
final class Baseline
{
    public const MIN_GAPS = 5;
    public const MIN_INTERVALS = 3;

    /**
     * Gaps in seconds between consecutive times (sorted, zero gaps kept out).
     *
     * @param list<int> $times
     * @return list<int>
     */
    public static function gaps(array $times): array
    {
        sort($times);
        $gaps = [];
        $previous = null;
        foreach ($times as $time) {
            if ($previous !== null && $time > $previous) {
                $gaps[] = $time - $previous;
            }
            $previous = $time;
        }

        return $gaps;
    }

    /**
     * Nearest-rank percentile (p in 0..100) of a list. Null when empty.
     *
     * @param list<int> $values
     */
    public static function percentile(array $values, float $p): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = (int) ceil(($p / 100) * count($values));
        $rank = max(1, min(count($values), $rank));

        return $values[$rank - 1];
    }

    /**
     * @param list<int> $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : intdiv($values[$mid - 1] + $values[$mid], 2);
    }

    /**
     * The quiet threshold in seconds and how it was derived.
     *
     * @param list<int> $gaps Gaps between arrivals over the baseline window.
     * @return array{threshold: int, usual_gap: int|null, source: string}
     */
    public static function quietThreshold(
        array $gaps,
        float $multiplier,
        int $floor,
        int $ceiling,
        int $default,
        ?int $fixed = null
    ): array {
        $usual = count($gaps) >= self::MIN_GAPS ? self::percentile($gaps, 90) : null;
        if ($fixed !== null && $fixed > 0) {
            return ['threshold' => $fixed, 'usual_gap' => $usual, 'source' => 'per-feed fixed value'];
        }
        if ($usual === null) {
            return [
                'threshold' => $default,
                'usual_gap' => null,
                'source' => sprintf('default (%d of %d gaps on record)', count($gaps), self::MIN_GAPS),
            ];
        }
        $raw = (int) round($usual * $multiplier);
        $threshold = max($floor, min($ceiling, $raw));
        $source = sprintf('90th percentile gap %s x %s', Duration::human($usual), rtrim(rtrim(number_format($multiplier, 2, '.', ''), '0'), '.'));
        if ($threshold === $floor && $raw < $floor) {
            $source .= ', raised to the floor';
        } elseif ($threshold === $ceiling && $raw > $ceiling) {
            $source .= ', capped at the ceiling';
        }

        return ['threshold' => $threshold, 'usual_gap' => $usual, 'source' => $source];
    }

    /**
     * The unchecked limit: 3 times the median attempt interval, with a floor.
     *
     * @param list<int> $intervals
     */
    public static function uncheckedLimit(array $intervals, int $floor): int
    {
        if (count($intervals) < self::MIN_INTERVALS) {
            return $floor;
        }

        return max($floor, 3 * (int) self::median($intervals));
    }
}
