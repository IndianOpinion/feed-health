<?php

/**
 * Public functions. Loaded by the plugin file.
 */

declare(strict_types=1);

use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Recorder;

if (!function_exists('feed_health')) {
    /**
     * The plugin's services.
     */
    function feed_health(): Plugin
    {
        return Plugin::instance();
    }
}

if (!function_exists('feed_health_recorder')) {
    /**
     * The recorder for this request.
     */
    function feed_health_recorder(): Recorder
    {
        return Plugin::instance()->recorder;
    }
}

if (!function_exists('feed_health_fetch_feed')) {
    /**
     * Drop-in for fetch_feed() that records the attempt and its items.
     * Returns exactly what fetch_feed() returned.
     *
     * @param string|string[] $url
     * @param string|null $feedKey Your own stable key; default is a hash of the canonical URL.
     * @param string|null $label Display label; default is the feed title.
     * @return \SimplePie\SimplePie|\WP_Error|mixed
     */
    function feed_health_fetch_feed(string|array $url, ?string $feedKey = null, ?string $label = null): mixed
    {
        return Plugin::instance()->core->fetch($url, $feedKey, $label);
    }
}
