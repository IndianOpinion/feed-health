<?php

/**
 * Example: a site's own import code, using the reference adapter.
 *
 * Swap fetch_feed() for feed_health_fetch_feed(). The return value is
 * exactly what fetch_feed() returns; every attempt and item is recorded.
 * Put this in a site plugin; it runs on a WP-Cron event of its own.
 */

declare(strict_types=1);

add_action('my_site_import_feeds', static function (): void {
    $feeds = [
        'world' => ['https://feeds.example.com/world.xml', 'World desk'],
        'sport' => ['https://feeds.example.com/sport.xml', 'Sport desk'],
    ];
    foreach ($feeds as $key => [$url, $label]) {
        // Your own stable key keeps the history if the URL changes later.
        $feed = feed_health_fetch_feed($url, 'site-' . $key, $label);
        if (is_wp_error($feed)) {
            continue;
        }
        foreach ($feed->get_items() as $item) {
            // ... create posts as before ...
            unset($item);
        }
    }
});
