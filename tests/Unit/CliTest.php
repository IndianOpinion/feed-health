<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Cli\Command;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;

/**
 * Section 5.8: WP-CLI commands, test 83.
 */
final class CliTest extends TestCase
{
    private Command $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
        $this->cli = new Command($this->plugin);
        for ($h = 0; $h < 30; $h++) {
            $this->fetchWithItems('ok', 1);
            $this->clock->advance(3600);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('bad', 'http_error', ['http_status' => 410, 'feed_url' => 'https://feeds.example.com/bad?apikey=XYZ']);
        }
        \WP_CLI::$out = [];
    }

    private function out(): string
    {
        return implode("\n", \WP_CLI::$out);
    }

    private function exitCode(callable $call): int
    {
        try {
            $call();
        } catch (\CliExit $e) {
            return $e->exitCode;
        }

        return 0;
    }

    public function testCommandIsRegisteredUnderWpCli(): void
    {
        \WP_CLI::reset();
        if (!defined('WP_CLI')) {
            define('WP_CLI', true);
        }
        $this->plugin->register(dirname(__DIR__, 2) . '/feed-health.php');
        self::assertInstanceOf(Command::class, \WP_CLI::$commands['feed-health'] ?? null);
    }

    public function testCheckAndDryRun(): void
    {
        $this->plugin->checker->run(true);
        $before = $this->db->dump();
        self::assertSame(0, $this->exitCode(fn () => $this->cli->check([], ['dry-run' => true])));
        self::assertStringContainsString('Feed bad: new -> failing', $this->out());
        self::assertSame($before, $this->db->dump(), 'dry run stores nothing');
        self::assertSame([], \WpStub::$mail, 'dry run sends nothing');

        self::assertSame(0, $this->exitCode(fn () => $this->cli->check([], [])));
        self::assertSame('failing', $this->feed('bad')['status']);
        self::assertStringContainsString('Success: Check ok', $this->out());
        self::assertCount(1, \WpStub::$mail);
    }

    public function testStatusFormatsAndFailOn(): void
    {
        $this->plugin->checker->run();
        \WP_CLI::$out = [];
        self::assertSame(0, $this->exitCode(fn () => $this->cli->status([], ['format' => 'table'])));
        self::assertStringContainsString("failing\t", $this->out());
        self::assertStringNotContainsString('XYZ', $this->out());

        \WP_CLI::$out = [];
        $this->cli->status([], ['format' => 'json']);
        $json = json_decode(\WP_CLI::$out[0], true);
        self::assertSame(1, $json['counts']['failing']);
        self::assertArrayHasKey('fetches_recorded', $json['counters']);
        self::assertStringContainsString('apikey=***', \WP_CLI::$out[0]);

        self::assertSame(1, $this->exitCode(fn () => $this->cli->status([], ['fail-on' => 'failing,unchecked'])));
        self::assertSame(0, $this->exitCode(fn () => $this->cli->status([], ['fail-on' => 'unchecked'])));
        self::assertSame(0, $this->exitCode(fn () => $this->cli->status([], ['status' => 'healthy', 'fail-on' => 'failing'])));
        self::assertSame(0, $this->exitCode(fn () => $this->cli->status([], ['format' => 'csv', 'adapter' => 'core'])));
    }

    public function testFeedMuteUnmuteSnooze(): void
    {
        self::assertSame(0, $this->exitCode(fn () => $this->cli->feed(['test', 'bad'], ['format' => 'json'])));
        self::assertStringContainsString('"feed_key":"bad"', $this->out());
        self::assertSame(1, $this->exitCode(fn () => $this->cli->feed(['test', 'nope'], [])));
        self::assertSame(0, $this->exitCode(fn () => $this->cli->mute(['test', 'bad'])));
        self::assertSame(1, (int) $this->feed('bad')['muted']);
        self::assertSame(0, $this->exitCode(fn () => $this->cli->unmute(['test', 'bad'])));
        self::assertSame(0, (int) $this->feed('bad')['muted']);
        self::assertSame(0, $this->exitCode(fn () => $this->cli->snooze(['test', 'bad'], ['for' => '2h'])));
        self::assertSame($this->clock->now() + 7200, $this->feed('bad')['snoozed_until']);
        self::assertSame(1, $this->exitCode(fn () => $this->cli->snooze(['test', 'bad'], ['for' => 'never'])));
    }

    public function testTestFetchIsForCoreFeedsOnly(): void
    {
        self::assertSame(1, $this->exitCode(fn () => $this->cli->test_fetch(['bad'])));
        \WpStub::$fetchFeed = static function (string $url) {
            do_action('http_api_debug', ['response' => ['code' => 200], 'body' => '<rss/>'], 'response', 'Requests', [], $url);

            return new \FakeFeed([new \FakeFeedItem('https://news.example.com/z', 'Z')]);
        };
        feed_health_fetch_feed('https://feeds.example.com/core.xml');
        $this->plugin->recorder->flush();
        $key = $this->plugin->core->feedKey('https://feeds.example.com/core.xml');
        $this->clock->advance(600);
        self::assertSame(0, $this->exitCode(fn () => $this->cli->test_fetch([$key])));
        self::assertStringContainsString('Test fetch: ok', $this->out());
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame(1, end($rows)['manual']);
    }

    public function testTestAlertPruneAndEvents(): void
    {
        self::assertSame(0, $this->exitCode(fn () => $this->cli->test_alert()));
        self::assertStringContainsString('email: sent to admin@example.com', $this->out());
        \WpStub::$mailResult = false;
        self::assertSame(1, $this->exitCode(fn () => $this->cli->test_alert()));
        \WpStub::$mailResult = true;

        self::assertSame(0, $this->exitCode(fn () => $this->cli->prune()));
        self::assertStringContainsString('Success: Pruned: fetches: 0', $this->out());

        \WP_CLI::$out = [];
        self::assertSame(0, $this->exitCode(fn () => $this->cli->events([], ['kind' => 'operator'])));
        self::assertStringContainsString('test alert sent', $this->out());
        self::assertSame(1, $this->exitCode(fn () => $this->cli->events([], ['since' => 'not a time at all'])));
    }
}
