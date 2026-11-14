<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Source of the current time. Injected everywhere so that every age and
 * window can be tested deterministically.
 */
interface Clock
{
    /**
     * Current Unix time in seconds (UTC).
     */
    public function now(): int;
}
