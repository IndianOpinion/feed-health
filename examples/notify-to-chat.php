<?php

/**
 * Example: send each batch of notices to a chat webhook as well as email.
 * The webhook URL lives in wp-config.php, not in the database.
 */

declare(strict_types=1);

use IndianOpinion\FeedHealth\Value\Transition;

add_action('feed_health_notify', static function (array $entries, array $results): void {
    if (!defined('MY_CHAT_WEBHOOK')) {
        return;
    }
    $lines = [];
    foreach ($entries as $entry) {
        if ($entry instanceof Transition) {
            $lines[] = sprintf('%s: %s (%s)', $entry->label(), $entry->to, $entry->reason);
        }
    }
    wp_remote_post((string) constant('MY_CHAT_WEBHOOK'), [
        'timeout' => 5,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => (string) wp_json_encode(['text' => "Feed health\n" . implode("\n", $lines)]),
    ]);
}, 10, 2);
