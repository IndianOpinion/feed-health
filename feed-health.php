<?php

/**
 * Plugin Name:       Feed Health
 * Plugin URI:        https://github.com/IndianOpinion/feed-health
 * Description:       Tracks per-feed yield, fetch failures, HTTP status, last-item age and duplicate rate, shows a dashboard, and emails or logs when a feed goes quiet or starts failing.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       feed-health
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('FEED_HEALTH_FILE')) {
    return;
}

define('FEED_HEALTH_FILE', __FILE__);

require_once __DIR__ . '/autoload.php';

\IndianOpinion\FeedHealth\Plugin::instance()->register(__FILE__);
