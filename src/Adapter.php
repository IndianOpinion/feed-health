<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\FetchResult;

/**
 * Connects one importer to feed-health. Register instances through the
 * `feed_health_adapters` filter.
 */
interface Adapter
{
    /**
     * Lower case, 1 to 40 characters from a-z 0-9 _ -.
     */
    public function key(): string;

    /**
     * Human label for the dashboard.
     */
    public function label(): string;

    /**
     * False when the importer this adapter serves is not active.
     * Unavailable adapters are listed but not booted.
     */
    public function isAvailable(): bool;

    /**
     * Hook into the importer and report through the recorder.
     */
    public function boot(Recorder $recorder): void;

    /**
     * Feeds the importer has configured. May be empty.
     *
     * @return iterable<FeedDescriptor>
     */
    public function feeds(): iterable;

    public function supportsTestFetch(): bool;

    /**
     * Fetch one feed now and return what happened. Only called when
     * supportsTestFetch() is true. The implementation records the attempt
     * itself (marked manual) or returns it for the caller to record.
     */
    public function testFetch(FeedDescriptor $feed): FetchResult;
}
