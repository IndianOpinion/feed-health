<?php

/**
 * Runnable demo without WordPress: `composer install && php examples/demo.php`.
 *
 * Uses the test stubs and the in-memory database to simulate two days of
 * three feeds (one healthy, one that starts failing, one that goes quiet),
 * then prints each feed's status and the alert email.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/tests/bootstrap.php';

use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Tests\Support\FrozenClock;
use IndianOpinion\FeedHealth\Tests\Support\MemoryDb;

WpStub::reset();
add_filter('feed_health_adapters', static fn (array $l): array => array_merge($l, [['key' => 'demo', 'label' => 'Demo importer']]));
$clock = new FrozenClock();
$plugin = new Plugin(new MemoryDb(), $clock);
Plugin::setInstance($plugin);
$plugin->install();

$report = static function (string $feed, string $outcome, int $items = 0, ?int $status = null) use ($plugin, $clock): void {
    $plugin->recorder->onFetchAction(['adapter' => 'demo', 'feed_key' => $feed, 'feed_label' => ucfirst($feed) . ' feed', 'feed_url' => 'https://feeds.example.com/' . $feed . '?token=demo', 'outcome' => $outcome, 'http_status' => $status, 'items' => $items]);
    for ($i = 0; $i < $items; $i++) {
        $plugin->recorder->onItemAction(['adapter' => 'demo', 'feed_key' => $feed, 'url' => 'https://news.example.com/' . $feed . '/' . $clock->now() . '/' . $i, 'title' => 'Story ' . $i]);
    }
    $plugin->recorder->flush();
};

for ($hour = 0; $hour < 48; $hour++) {
    $report('steady', 'ok', 2, 200);
    $report('broken', $hour < 45 ? 'ok' : 'http_error', $hour < 45 ? 1 : 0, $hour < 45 ? 200 : 403);
    $report('fading', 'ok', $hour < 30 ? 1 : 0, 200);
    $clock->advance(3600);
    if ($hour % 6 === 5) {
        $plugin->checker->run();
    }
}
$plugin->checker->run();

foreach ($plugin->repository->feeds() as $feed) {
    printf("%-14s %-9s %s\n", $feed['label'], $feed['status'], $feed['reason']);
}
echo "\nEmails sent: " . count(WpStub::$mail) . "\n";
foreach (WpStub::$mail as $mail) {
    echo "\nSubject: " . $mail['subject'] . "\n" . $mail['body'];
}
