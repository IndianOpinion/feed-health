<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Integration;

use IndianOpinion\FeedHealth\Admin\Dashboard;
use IndianOpinion\FeedHealth\Exception\StorageError;
use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Storage\WpdbDb;
use IndianOpinion\FeedHealth\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * The real SQL against MySQL or MariaDB. Runs only when
 * FEED_HEALTH_TEST_DSN is set, for example
 * "mysql:host=127.0.0.1;port=3306;dbname=feedhealth" (CI) or
 * "mysql:unix_socket=/path/mysqld.sock;dbname=feedhealth".
 * FEED_HEALTH_TEST_USER and FEED_HEALTH_TEST_PASSWORD are optional.
 */
final class MysqlTest extends TestCase
{
    private FrozenClock $clock;
    private Plugin $plugin;
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $dsn = getenv('FEED_HEALTH_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set FEED_HEALTH_TEST_DSN to run the MySQL integration tests.');
        }
        \WpStub::reset();
        $user = getenv('FEED_HEALTH_TEST_USER');
        $password = getenv('FEED_HEALTH_TEST_PASSWORD');
        $pdo = new \PDO($dsn, is_string($user) && $user !== '' ? $user : 'root', is_string($password) ? $password : '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->wpdb = new \wpdb($pdo);
        $this->wpdb->prefix = 'fhtest_';
        $GLOBALS['wpdb'] = $this->wpdb;
        add_filter('feed_health_adapters', static fn (array $l): array => array_merge($l, [['key' => 'test']]));
        $this->clock = new FrozenClock();
        $this->plugin = new Plugin(new WpdbDb($this->wpdb), $this->clock);
        Plugin::setInstance($this->plugin);
        $this->plugin->repository->dropAll();
        $this->plugin->repository->install();
    }

    protected function tearDown(): void
    {
        if (isset($this->plugin)) {
            $this->plugin->repository->dropAll();
        }
        Plugin::setInstance(null);
    }

    private function fetch(string $key, string $outcome = 'ok', array $items = [], array $extra = []): void
    {
        $r = $this->plugin->recorder;
        $r->onFetchAction(array_merge(['adapter' => 'test', 'feed_key' => $key, 'outcome' => $outcome, 'feed_url' => 'https://feeds.example.com/' . $key, 'feed_label' => 'Feed ' . $key, 'items' => count($items)], $extra));
        foreach ($items as $url) {
            $r->onItemAction(['adapter' => 'test', 'feed_key' => $key, 'url' => $url, 'title' => 'T ' . $url]);
        }
        $r->flush();
    }

    public function testSchemaInstallsAndIsIdempotent(): void
    {
        self::assertTrue($this->plugin->repository->tablesExist());
        self::assertTrue($this->plugin->install(), 'second install is a no-op');
        $this->plugin->repository->dropAll();
        self::assertFalse((new WpdbDb($this->wpdb))->tablesExist(Schema::tables()));
    }

    public function testRecordingCheckRollupAndPruneOnRealSql(): void
    {
        for ($h = 0; $h < 30; $h++) {
            $this->fetch('a', 'ok', ['https://news.example.com/a' . $h, 'https://news.example.com/shared' . $h]);
            $this->fetch('b', 'ok', ['https://www.news.example.com/shared' . $h . '/?utm_source=x']);
            $this->clock->advance(3600);
        }
        $run = $this->plugin->recorder->beginRun('test', 'run-1');
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('b', 'http_error', [], ['http_status' => 403, 'run_id' => $run, 'error_message' => "it's <b>blocked</b>"]);
        }
        $this->fetch('c', 'ok', [], ['run_id' => 'unknown-run']);
        $this->plugin->recorder->endRun($run, 'ok');

        $repo = $this->plugin->repository;
        $a = $repo->findFeed('test', 'a');
        $b = $repo->findFeed('test', 'b');
        self::assertNotNull($a);
        self::assertNotNull($b);
        $totals = $repo->windowTotals(0, $this->clock->now() + 1);
        self::assertSame(60, $totals[(int) $a['id']]['accepted']);
        self::assertSame(30, $totals[(int) $b['id']]['duplicates'], 'canonical keys matched across feeds');
        self::assertSame(3, $totals[(int) $b['id']]['http_errors']);
        $last = $repo->recentFetches((int) $b['id'], 1)[0];
        self::assertSame("it's blocked", $last['error_message']);
        self::assertSame('run-1', $last['run_id']);
        $c = $repo->findFeed('test', 'c');
        self::assertNotNull($c);
        self::assertNull($repo->recentFetches((int) $c['id'], 1)[0]['run_id']);

        $summary = $this->plugin->checker->run();
        self::assertSame('ok', $summary['status'], $summary['message']);
        self::assertSame('failing', $repo->findFeed('test', 'b')['status'] ?? null);
        self::assertSame('healthy', $repo->findFeed('test', 'a')['status'] ?? null);
        self::assertCount(1, \WpStub::$mail);

        $this->clock->advance(86400);
        $deleted = $this->plugin->checker->maintenance(true);
        self::assertArrayHasKey('fetches', $deleted);
        $daily = $repo->daily('2000-01-01');
        self::assertNotSame([], $daily);
        $rows = (new Dashboard($this->plugin))->rows();
        self::assertCount(3, $rows);

        $repo->deleteFeed((int) $a['id']);
        self::assertNull($repo->findFeed('test', 'a'));
        self::assertSame(0, $repo->seenCount((int) $a['id']));
    }

    public function testDatabaseErrorsBecomeStorageErrors(): void
    {
        $this->expectException(StorageError::class);
        (new WpdbDb($this->wpdb))->select('feedhealth_missing');
    }
}
