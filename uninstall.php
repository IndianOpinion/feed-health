<?php

/**
 * Uninstall: drop every table, delete the options and clear the scheduled
 * events, on each site of a network. Deactivation keeps data; this does not.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

\IndianOpinion\FeedHealth\Uninstaller::run();
