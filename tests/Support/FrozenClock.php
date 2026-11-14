<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Support;

use IndianOpinion\FeedHealth\Clock;

final class FrozenClock implements Clock
{
    public function __construct(public int $time = 1_767_225_600)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }

    public function set(int $time): void
    {
        $this->time = $time;
    }
}
