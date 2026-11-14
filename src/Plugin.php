<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Admin\AdminPage;
use IndianOpinion\FeedHealth\Admin\Widget;
use IndianOpinion\FeedHealth\Cli\Command;
use IndianOpinion\FeedHealth\Core\CoreAdapter;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Notify\EmailFormatter;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Storage\Db;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Storage\WpdbDb;

/**
 * Builds the services and wires them into WordPress.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CHECK_HOOK = 'feed_health_check';
    public const AFTER_RUN_HOOK = 'feed_health_check_after_run';
    public const DAILY_HOOK = 'feed_health_daily';
    public const AFTER_RUN_DELAY = 60;

    private static ?Plugin $instance = null;

    public readonly Settings $settings;
    public readonly State $state;
    public readonly Repository $repository;
    public readonly Logger $logger;
    public readonly Redactor $redactor;
    public readonly UrlCanonicalizer $canonicalizer;
    public readonly CoreAdapter $core;
    public readonly AdapterRegistry $adapters;
    public readonly Recorder $recorder;
    public readonly Notifier $notifier;
    public readonly Checker $checker;
    public readonly Operator $operator;

    public function __construct(Db $db, public readonly Clock $clock)
    {
        $this->settings = new Settings();
        $this->state = new State($clock);
        $this->repository = new Repository($db);
        $this->logger = new Logger($this->repository, $clock);
        $this->redactor = new Redactor();
        $this->canonicalizer = new UrlCanonicalizer();
        $this->core = new CoreAdapter($this->settings, $this->repository, $this->canonicalizer, $clock);
        $this->adapters = new AdapterRegistry([$this->core], $this->logger);
        $this->recorder = new Recorder($this->settings, $this->state, $this->repository, $this->adapters, $this->canonicalizer, $clock, $this->logger);
        $this->notifier = new Notifier($this->repository, $this->settings, $this->state, $clock, $this->logger, new EmailFormatter($this->redactor), $this->redactor);
        $this->checker = new Checker($this->repository, $this->settings, $this->state, $this->adapters, $this->notifier, new StatusEvaluator(), $clock, $this->logger);
        $this->operator = new Operator($this->repository, $this->adapters, $clock);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            global $wpdb;
            self::$instance = new self(new WpdbDb($wpdb), new SystemClock());
        }

        return self::$instance;
    }

    /**
     * Replace the shared instance (tests, or a site that wants its own clock).
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    /**
     * Hook everything. Called once from the plugin file.
     */
    public function register(string $file): void
    {
        add_action('plugins_loaded', [$this, 'bootAdapters'], 99, 0);

        add_action('feed_health_fetch', [$this->recorder, 'onFetchAction'], 10, 1);
        add_action('feed_health_item', [$this->recorder, 'onItemAction'], 10, 1);
        add_action('feed_health_run_start', [$this->recorder, 'onRunStartAction'], 10, 2);
        add_action('feed_health_run_end', [$this->recorder, 'onRunEndAction'], 10, 3);
        add_action('feed_health_run_ended', [$this, 'scheduleAfterRun'], 10, 0);
        add_action('shutdown', [$this->recorder, 'flush'], 1000, 0);

        add_action(self::CHECK_HOOK, [$this, 'cronCheck'], 10, 0);
        add_action(self::AFTER_RUN_HOOK, [$this, 'cronCheck'], 10, 0);
        add_action(self::DAILY_HOOK, [$this, 'cronDaily'], 10, 0);
        add_action('init', [$this, 'ensureScheduled'], 20, 0);
        add_action('update_option_' . Settings::OPTION, [$this, 'reschedule'], 10, 0);

        add_action('admin_init', [$this, 'maybeInstall'], 1, 0);
        add_action('admin_init', [$this, 'privacyText'], 20, 0);

        $admin = new AdminPage($this);
        $admin->register();
        (new Widget($this))->register();

        register_activation_hook($file, [$this, 'activate']);
        register_deactivation_hook($file, [$this, 'deactivate']);

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('feed-health', new Command($this));
        }
    }

    public function bootAdapters(): void
    {
        $this->adapters->boot($this->recorder);
    }

    /**
     * Activation: install tables (on every site when network-activated)
     * and schedule the check.
     */
    public function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                $this->installSite();
                restore_current_blog();
            }

            return;
        }
        $this->installSite();
    }

    public function installSite(): void
    {
        $this->install();
        $this->schedule();
    }

    /**
     * Deactivation clears the scheduled events and keeps all data.
     */
    public function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CHECK_HOOK);
        wp_clear_scheduled_hook(self::AFTER_RUN_HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
    }

    /**
     * Create or upgrade the tables and record the schema version.
     */
    public function install(): bool
    {
        try {
            $this->repository->install();
            $this->state->setMany(['schema' => Schema::VERSION, 'installed_at' => $this->state->get('installed_at', $this->clock->now())]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->php('Install failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Must-use mode and network sites: activation hooks do not run, so
     * install on the first admin load when the schema is missing or old.
     */
    public function maybeInstall(): void
    {
        if ((int) $this->state->get('schema', 0) < Schema::VERSION) {
            $this->installSite();
        }
    }

    public function schedule(): void
    {
        $interval = (string) $this->settings->get('check_interval');
        if (!wp_next_scheduled(self::CHECK_HOOK)) {
            wp_schedule_event($this->clock->now() + 300, $interval, self::CHECK_HOOK);
        }
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event($this->clock->now() + 900, 'daily', self::DAILY_HOOK);
        }
    }

    /**
     * Keep the events scheduled when data exists (for example after an
     * object cache flush lost the cron option).
     */
    public function ensureScheduled(): void
    {
        if ((int) $this->state->get('schema', 0) >= Schema::VERSION) {
            $this->schedule();
        }
    }

    /**
     * The check interval setting changed: move the event.
     */
    public function reschedule(): void
    {
        $interval = (string) $this->settings->get('check_interval');
        $current = wp_get_schedule(self::CHECK_HOOK);
        if ($current !== $interval) {
            wp_clear_scheduled_hook(self::CHECK_HOOK);
            wp_schedule_event($this->clock->now() + 60, $interval, self::CHECK_HOOK);
        }
    }

    /**
     * End of an import run: queue one check a minute later. WordPress drops
     * a duplicate single event inside ten minutes, and the check's own lock
     * stops two checks from overlapping.
     */
    public function scheduleAfterRun(): void
    {
        if (!wp_next_scheduled(self::AFTER_RUN_HOOK)) {
            wp_schedule_single_event($this->clock->now() + self::AFTER_RUN_DELAY, self::AFTER_RUN_HOOK);
        }
    }

    /**
     * Cron callback. Catches everything: an uncaught error would kill this
     * tick and every later one with the same bug.
     */
    public function cronCheck(): void
    {
        try {
            $this->maybeInstall();
            $this->checker->run();
        } catch (\Throwable $e) {
            $this->logger->php('Check callback failed: ' . $e->getMessage());
        }
    }

    /**
     * Daily callback: roll up, prune, and run a check as a fallback.
     */
    public function cronDaily(): void
    {
        try {
            $this->maybeInstall();
            $this->checker->maintenance(true);
            $this->checker->run();
        } catch (\Throwable $e) {
            $this->logger->php('Daily callback failed: ' . $e->getMessage());
        }
    }

    public function privacyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('Feed health', wp_kses_post(
            '<p>This site monitors the news feeds it imports. For that it stores feed addresses, '
            . 'one-way hashes of article addresses, the title of the newest article per feed, and the '
            . 'user id of site staff who change feed settings. It stores nothing about visitors.</p>'
        ));
    }

    public function capability(): string
    {
        $cap = apply_filters('feed_health_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /**
     * Check interval in seconds.
     */
    public function intervalSeconds(): int
    {
        return match ((string) $this->settings->get('check_interval')) {
            'twicedaily' => 43200,
            'daily' => 86400,
            default => 3600,
        };
    }
}
