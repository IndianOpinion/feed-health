<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Health;

/**
 * Human durations for reasons and emails: "45 min", "31 h", "9 d".
 */
final class Duration
{
    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 120 * 60) {
            return max(1, intdiv($seconds + 30, 60)) . ' min';
        }
        if ($seconds < 72 * 3600) {
            return intdiv($seconds + 1800, 3600) . ' h';
        }

        return intdiv($seconds + 43200, 86400) . ' d';
    }
}
