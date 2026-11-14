<?php

/**
 * Example: list the feeds you expect, so a feed that is never fetched shows
 * as "unchecked" instead of not showing at all. Also shows a per-feed
 * threshold rule and a site rule on the final status.
 */

declare(strict_types=1);

use IndianOpinion\FeedHealth\Value\FeedDescriptor;

add_filter('feed_health_expected_feeds', static function (array $feeds): array {
    $feeds[] = new FeedDescriptor('core', 'site-world', 'https://feeds.example.com/world.xml', 'World desk');
    $feeds[] = new FeedDescriptor('core', 'site-weekly', 'https://feeds.example.com/weekly.xml', 'Weekly column');

    return $feeds;
});

// A weekly column is quiet for days by design.
add_filter('feed_health_thresholds', static function (array $thresholds, array $feed): array {
    if (($feed['feed_key'] ?? '') === 'site-weekly') {
        $thresholds['fixedQuiet'] = 9 * DAY_IN_SECONDS;
    }

    return $thresholds;
}, 10, 2);
