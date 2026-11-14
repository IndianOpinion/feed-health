<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * The real clock.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
