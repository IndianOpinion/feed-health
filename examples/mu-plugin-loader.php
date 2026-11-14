<?php

/**
 * Must-use loader for feed-health.
 *
 * Copy this file to wp-content/mu-plugins/feed-health-loader.php and put the
 * plugin folder at wp-content/mu-plugins/feed-health/. Activation hooks do
 * not run for must-use plugins, so the tables are created on the first
 * wp-admin page load (or the first scheduled check).
 *
 * Plugin Name: Feed Health (must-use loader)
 * Description: Loads feed-health from wp-content/mu-plugins/feed-health/.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/feed-health/feed-health.php';
