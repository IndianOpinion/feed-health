<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Adapter;
use IndianOpinion\FeedHealth\AdapterRegistry;
use IndianOpinion\FeedHealth\Checker;
use IndianOpinion\FeedHealth\Logger;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Recorder;
use IndianOpinion\FeedHealth\Settings;
use IndianOpinion\FeedHealth\SimpleAdapter;
use IndianOpinion\FeedHealth\State;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;
use IndianOpinion\FeedHealth\Uninstaller;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\FetchResult;

/**
 * Activation, deactivation, uninstall, must-use mode, adapter registry.
 */
final class LifecycleTest extends TestCase
{
    /** Test 80 (case 92). */
    public function testDeactivationClearsScheduleAndReactivationRestoresWithDataIntact(): void
    {
        $this->plugin->activate();
        self::assertNotFalse(wp_next_scheduled(Plugin::CHECK_HOOK));
        self::assertSame('hourly', wp_get_schedule(Plugin::CHECK_HOOK));
        self::assertNotFalse(wp_next_scheduled(Plugin::DAILY_HOOK));
        $this->fetchWithItems('a', 2);
        $this->plugin->scheduleAfterRun();

        $this->plugin->deactivate();
        self::assertFalse(wp_next_scheduled(Plugin::CHECK_HOOK));
        self::assertFalse(wp_next_scheduled(Plugin::DAILY_HOOK));
        self::assertFalse(wp_next_scheduled(Plugin::AFTER_RUN_HOOK));

        $this->plugin->activate();
        self::assertNotFalse(wp_next_scheduled(Plugin::CHECK_HOOK));
        self::assertCount(1, $this->rows(Schema::FETCHES));
        self::assertSame(2, (int) $this->rows(Schema::FETCHES)[0]['accepted']);
    }

    public function testIntervalSettingReschedules(): void
    {
        $this->plugin->activate();
        $this->settings(['check_interval' => 'twicedaily']);
        $this->plugin->reschedule();
        self::assertSame('twicedaily', wp_get_schedule(Plugin::CHECK_HOOK));
    }

    /** Test 84 (section 6.4). */
    public function testUninstallDropsTablesOptionsAndEventsOnEverySite(): void
    {
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        $this->plugin->activate(true);
        switch_to_blog(2);
        self::assertTrue($this->plugin->repository->tablesExist(), 'network activation installs each site');
        $this->fetch('b');
        $this->plugin->settings->save(['enabled' => true]);
        restore_current_blog();
        $this->fetch('a');
        $this->plugin->state->set('x', 1);
        $this->plugin->settings->save(['enabled' => true]);
        update_site_option(Notifier::NETWORK_OPTION, ['cap' => 5]);
        add_option(Checker::LOCK_OPTION, '1');

        Uninstaller::run($this->plugin);
        foreach ([1, 2] as $site) {
            switch_to_blog($site);
            self::assertFalse($this->plugin->repository->tablesExist());
            self::assertFalse(get_option(Settings::OPTION));
            self::assertFalse(get_option(State::OPTION));
            self::assertFalse(get_option(Checker::LOCK_OPTION));
            self::assertFalse(wp_next_scheduled(Plugin::CHECK_HOOK));
            self::assertFalse(wp_next_scheduled(Plugin::DAILY_HOOK));
            restore_current_blog();
        }
        self::assertFalse(get_site_option(Notifier::NETWORK_OPTION));
        self::assertSame([], $this->db->tables);
    }

    /** Test 85 (section 10). */
    public function testMustUseModeInstallsOnFirstAdminLoad(): void
    {
        $this->db->tables = [];
        delete_option(State::OPTION);
        $this->plugin = $this->newPlugin();
        $this->boot();
        self::assertFalse($this->plugin->repository->tablesExist());
        do_action('admin_init');
        self::assertTrue($this->plugin->repository->tablesExist());
        self::assertSame(Schema::VERSION, $this->plugin->state->get('schema'));
        self::assertNotFalse(wp_next_scheduled(Plugin::CHECK_HOOK));
        self::assertSame(1, count(\WpStub::$privacy), 'privacy text offered');
    }

    public function testSettingsAndStateAutoload(): void
    {
        $this->plugin->settings->save(['enabled' => true]);
        $this->plugin->state->set('k', 'v');
        self::assertTrue(\WpStub::$autoload[1][Settings::OPTION]);
        self::assertFalse(\WpStub::$autoload[1][State::OPTION]);
        self::assertLessThan(2048, strlen((string) serialize(get_option(Settings::OPTION))), 'autoloaded data under 2 KB');
    }

    /** Test 81 (section 5.1). */
    public function testDuplicateAdapterKeyIsRejectedAndUnavailableAdapterIsListedNotBooted(): void
    {
        $unavailable = new class () implements Adapter {
            public bool $booted = false;

            public function key(): string
            {
                return 'offline';
            }

            public function label(): string
            {
                return 'Offline importer';
            }

            public function isAvailable(): bool
            {
                return false;
            }

            public function boot(Recorder $recorder): void
            {
                $this->booted = true;
            }

            public function feeds(): iterable
            {
                return [new FeedDescriptor('offline', 'x', 'https://x.example.com/')];
            }

            public function supportsTestFetch(): bool
            {
                return false;
            }

            public function testFetch(FeedDescriptor $feed): FetchResult
            {
                throw new \LogicException('no');
            }
        };
        $first = new SimpleAdapter('dup', 'First');
        $second = new SimpleAdapter('dup', 'Second');
        add_filter('feed_health_adapters', static fn (array $l): array => array_merge($l, [$first, $second, $unavailable, 'junk', ['key' => 'BAD KEY']]));
        $registry = new AdapterRegistry([], new Logger());
        $all = $registry->all();
        self::assertSame($first, $all['dup']);
        self::assertArrayHasKey('offline', $all);
        self::assertStringContainsString('duplicate key "dup"', $this->phpLog());
        $registry->boot($this->plugin->recorder);
        self::assertFalse($unavailable->booted);
        self::assertFalse($registry->isBooted('offline'));
        self::assertTrue($registry->isBooted('dup'));
        self::assertFalse($registry->isAvailable('offline'));
    }

    public function testCronCallbacksCatchEverything(): void
    {
        add_filter('feed_health_expected_feeds', static function (): array {
            throw new \RuntimeException('adapter blew up');
        });
        $this->plugin->cronCheck();
        $this->plugin->cronDaily();
        self::assertNotSame([], $this->events('error'));
    }
}
