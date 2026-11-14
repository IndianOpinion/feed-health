<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Support;

use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Settings;
use IndianOpinion\FeedHealth\Storage\Schema;

/**
 * Fresh WordPress stub state, a frozen clock (2026-01-01 00:00 UTC), an
 * in-memory database with the tables installed, and a "test" adapter.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected FrozenClock $clock;
    protected MemoryDb $db;
    protected Plugin $plugin;
    protected string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        \WP_CLI::reset();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'fh-log');
        ini_set('error_log', $this->errorLog);
        add_filter('feed_health_adapters', static function (array $list): array {
            $list[] = ['key' => 'test', 'label' => 'Test importer'];

            return $list;
        });
        $this->clock = new FrozenClock();
        $this->db = new MemoryDb(static fn (): string => \WpStub::$prefix);
        $this->plugin = $this->newPlugin();
        $this->plugin->install();
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        if ($this->errorLog !== '' && is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
        ini_restore('error_log');
        parent::tearDown();
    }

    protected function newPlugin(): Plugin
    {
        $plugin = new Plugin($this->db, $this->clock);
        Plugin::setInstance($plugin);

        return $plugin;
    }

    /**
     * Register the plugin's hooks and boot adapters, as WordPress would.
     */
    protected function boot(): void
    {
        $this->plugin->register(dirname(__DIR__, 2) . '/feed-health.php');
        do_action('plugins_loaded');
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        $current = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, array_merge(is_array($current) ? $current : [], $values));
    }

    /**
     * Report one fetch through the action and flush.
     *
     * @param array<string, mixed> $fields
     */
    protected function fetch(string $feedKey, string $outcome = 'ok', array $fields = [], bool $flush = true): void
    {
        $this->plugin->recorder->onFetchAction(array_merge([
            'adapter' => 'test',
            'feed_key' => $feedKey,
            'outcome' => $outcome,
            'started_at' => $this->clock->now(),
            'finished_at' => $this->clock->now(),
            'feed_url' => 'https://feeds.example.com/' . $feedKey,
            'feed_label' => 'Feed ' . $feedKey,
        ], $fields));
        if ($flush) {
            $this->plugin->recorder->flush();
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function item(string $feedKey, string $url, array $fields = []): void
    {
        $this->plugin->recorder->onItemAction(array_merge([
            'adapter' => 'test',
            'feed_key' => $feedKey,
            'url' => $url,
            'title' => 'Title for ' . $url,
            'source' => 'Source',
        ], $fields));
    }

    /**
     * A fetch with fresh accepted items, flushed.
     */
    protected function fetchWithItems(string $feedKey, int $count, string $outcome = 'ok', string $prefix = ''): void
    {
        $this->fetch($feedKey, $outcome, ['items' => $count], false);
        for ($i = 0; $i < $count; $i++) {
            $this->item($feedKey, 'https://news.example.com/' . $feedKey . '/' . $prefix . $this->clock->now() . '-' . $i);
        }
        $this->plugin->recorder->flush();
    }

    /**
     * @return array<string, int|string|null>
     */
    protected function feed(string $feedKey, string $adapter = 'test'): array
    {
        $feed = $this->plugin->repository->findFeed($adapter, $feedKey);
        self::assertNotNull($feed, 'feed ' . $feedKey . ' exists');

        return $feed;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    protected function rows(string $table): array
    {
        return array_values($this->db->tables[\WpStub::$prefix . $table] ?? []);
    }

    protected function events(string $kind): array
    {
        return array_values(array_filter($this->rows(Schema::EVENTS), static fn (array $e): bool => $e['kind'] === $kind));
    }

    protected function phpLog(): string
    {
        return is_file($this->errorLog) ? (string) file_get_contents($this->errorLog) : '';
    }

    protected function counter(string $name): int
    {
        $this->plugin->state->flush();

        return $this->plugin->state->counter($name);
    }
}
