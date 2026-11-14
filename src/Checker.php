<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Health\Baseline;
use IndianOpinion\FeedHealth\Health\Metrics;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Health\Thresholds;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\Transition;

/**
 * One check: mark stale runs, compute every feed's status, record the
 * transitions, send notifications, roll up and prune once a day.
 *
 * Reads only the plugin's own tables. Never fetches a feed. Holds a lock
 * with a time limit. Catches every error.
 */
final class Checker
{
    public const LOCK_OPTION = 'feed_health_lock';
    public const LOCK_LIMIT = 600;
    public const DAY = 86400;

    public function __construct(
        private Repository $repository,
        private Settings $settings,
        private State $state,
        private AdapterRegistry $adapters,
        private Notifier $notifier,
        private StatusEvaluator $evaluator,
        private Clock $clock,
        private Logger $logger
    ) {
    }

    /**
     * Run a check. A dry run computes and returns transitions but stores
     * and sends nothing.
     *
     * @return array{status: string, feeds: int, transitions: list<array<string, mixed>>, notified: int, duration_ms: int, message: string}
     */
    public function run(bool $dryRun = false): array
    {
        $started = microtime(true);
        $summary = ['status' => 'ok', 'feeds' => 0, 'transitions' => [], 'notified' => 0, 'duration_ms' => 0, 'message' => ''];
        if (!$this->settings->enabled()) {
            $summary['status'] = 'disabled';
            $summary['message'] = 'feed-health is switched off';

            return $summary;
        }
        if (!$dryRun && !$this->lock()) {
            $this->state->increment('checks_skipped');
            $this->state->flush();
            $this->logger->event('check', 'skipped: already running');
            $summary['status'] = 'skipped';
            $summary['message'] = 'skipped: already running';

            return $summary;
        }
        try {
            if (!$this->repository->tablesExist()) {
                $this->state->increment('tables_missing');
                $summary['status'] = 'error';
                $summary['message'] = 'tables missing';

                return $summary;
            }
            $summary = $this->evaluate($dryRun, $summary);
        } catch (\Throwable $e) {
            $summary['status'] = 'error';
            $summary['message'] = 'check failed: ' . $e->getMessage();
            if (!$dryRun) {
                $this->state->increment('internal_error');
                $this->logger->event('error', 'Check failed part way: ' . get_class($e) . ': ' . $e->getMessage());
            }
        } finally {
            $summary['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            if (!$dryRun) {
                $this->state->increment('checks_run');
                $this->state->setMany(['last_check' => $this->clock->now(), 'last_check_summary' => [
                    'status' => $summary['status'],
                    'feeds' => $summary['feeds'],
                    'transitions' => count($summary['transitions']),
                    'notified' => $summary['notified'],
                    'duration_ms' => $summary['duration_ms'],
                ]]);
                $this->state->flush();
                $this->unlock();
            }
        }
        if (!$dryRun && $summary['status'] === 'ok') {
            $this->logger->event('check', sprintf(
                'check done in %d ms: %d feeds evaluated, %d transitions, %d notices sent',
                $summary['duration_ms'],
                $summary['feeds'],
                count($summary['transitions']),
                $summary['notified']
            ), null, null, ['duration_ms' => $summary['duration_ms'], 'feeds' => $summary['feeds']]);
            do_action('feed_health_check_complete', $summary);
        }

        return $summary;
    }

    /**
     * @param array{status: string, feeds: int, transitions: list<array<string, mixed>>, notified: int, duration_ms: int, message: string} $summary
     * @return array{status: string, feeds: int, transitions: list<array<string, mixed>>, notified: int, duration_ms: int, message: string}
     */
    private function evaluate(bool $dryRun, array $summary): array
    {
        $now = $this->clock->now();
        $descriptors = $this->descriptors();
        if (!$dryRun) {
            $this->markStaleRuns($now, $descriptors);
            $this->syncFeeds($descriptors, $now);
        }

        $windowHours = $this->settings->int('failure_window_hours');
        $window = $this->repository->windowTotals($now - $windowHours * 3600, $now + 1);
        $week = $this->repository->windowTotals($now - 7 * self::DAY, $now + 1);
        $enabled = [];
        foreach ($descriptors as $d) {
            $enabled[$d->adapter . "\0" . $d->feedKey] = $d->enabled;
        }

        $transitions = [];
        $thisCheck = [];
        $clockNoted = false;
        $feeds = $this->repository->feeds();
        foreach ($feeds as $feed) {
            $feedId = (int) $feed['id'];
            $metrics = $this->metrics($feed, $window[$feedId] ?? Repository::emptyTotals(), $week[$feedId] ?? Repository::emptyTotals(), $enabled, $now);
            if ($metrics->clockWentBack() && !$clockNoted) {
                $clockNoted = true;
                if (!$dryRun && !$this->state->get('clock_back_noted', false)) {
                    $this->state->set('clock_back_noted', true);
                    $this->logger->event('error', 'The server clock moved backwards: some recorded times are in the future. Ages are shown as zero until the clock catches up.');
                }
            }
            $thresholds = $this->thresholds($feed);
            $result = $this->evaluator->evaluate($metrics, $thresholds);
            $filtered = apply_filters('feed_health_status', [$result['status'], $result['reason']], $feed, $metrics);
            if (is_array($filtered) && isset($filtered[0]) && in_array($filtered[0], StatusEvaluator::ALL, true)) {
                $result['status'] = (string) $filtered[0];
                $result['reason'] = isset($filtered[1]) && is_string($filtered[1]) ? $filtered[1] : $result['reason'];
            }
            $summary['feeds']++;

            $old = (string) $feed['status'];
            $set = [
                'reason' => mb_substr($result['reason'], 0, 255),
                'usual_gap' => $result['quiet']['usual_gap'],
                'quiet_threshold' => $result['quiet']['threshold'],
                'threshold_source' => mb_substr($result['quiet']['source'], 0, 100),
            ];
            if ($old !== $result['status']) {
                $set['status'] = $result['status'];
                $set['status_since'] = $now;
                $t = new Transition(Notifier::newId(), Notifier::summary($feed), $old, $result['status'], $result['reason'], $now);
                $transitions[] = $t->toArray();
                if (!$dryRun) {
                    $this->repository->updateFeed($feedId, $set);
                    $feed = array_merge($feed, $set);
                    $this->notifier->record($t, $feed);
                    $thisCheck[] = $t->id;
                }
                continue;
            }
            if (!$dryRun) {
                $changed = [];
                foreach ($set as $column => $value) {
                    if ((string) ($feed[$column] ?? '') !== (string) ($value ?? '') || (($feed[$column] ?? null) === null) !== ($value === null)) {
                        $changed[$column] = $value;
                    }
                }
                if ($changed !== []) {
                    $this->repository->updateFeed($feedId, $changed);
                }
            }
        }
        if (!$clockNoted && !$dryRun && $this->state->get('clock_back_noted', false)) {
            $this->state->set('clock_back_noted', false);
        }
        $summary['transitions'] = $transitions;
        if ($dryRun) {
            return $summary;
        }

        $still = $this->notifier->reminders($this->repository->feeds());
        $fetched = 0;
        foreach ($window as $totals) {
            if ($totals['attempts'] > 0) {
                $fetched++;
            }
        }
        $sent = $this->notifier->dispatch($thisCheck, $still, $fetched);
        $summary['notified'] = $sent['entries'];

        $this->maintenance(false);

        return $summary;
    }

    /**
     * Roll up finished days and prune past retention. Runs at most once
     * per UTC day unless forced.
     *
     * @return array<string, int>
     */
    public function maintenance(bool $force): array
    {
        $now = $this->clock->now();
        $today = gmdate('Y-m-d', $now);
        if (!$force && $this->state->get('last_maintenance_day') === $today) {
            return [];
        }
        $this->rollup($now);
        $deleted = $this->repository->prune([
            'fetches' => $this->settings->int('retention_fetches_days'),
            'seen' => $this->settings->int('retention_seen_days'),
            'daily' => $this->settings->int('retention_daily_days'),
            'runs' => $this->settings->int('retention_runs_days'),
            'events' => $this->settings->int('retention_events_days'),
        ], $now);
        $this->state->set('last_maintenance_day', $today);
        $total = array_sum($deleted);
        if ($total > 0) {
            $this->logger->event('check', 'pruned ' . $total . ' rows past retention', null, null, $deleted);
        }

        return $deleted;
    }

    /**
     * Roll up every finished UTC day not rolled up yet.
     */
    public function rollup(int $now): int
    {
        $yesterday = gmdate('Y-m-d', $now - self::DAY);
        $last = $this->state->get('last_rolled_day');
        if (is_string($last) && $last !== '') {
            $start = gmdate('Y-m-d', (int) strtotime($last . ' 00:00:00 UTC') + self::DAY);
        } else {
            $earliest = $this->repository->earliestFetchTime();
            if ($earliest === null) {
                return 0;
            }
            $start = gmdate('Y-m-d', $earliest);
        }
        $floor = gmdate('Y-m-d', $now - $this->settings->int('retention_daily_days') * self::DAY);
        if ($start < $floor) {
            $start = $floor;
        }
        $days = 0;
        for ($day = $start; $day <= $yesterday; $day = gmdate('Y-m-d', (int) strtotime($day . ' 00:00:00 UTC') + self::DAY)) {
            $this->repository->rollupDay($day);
            $this->state->set('last_rolled_day', $day);
            $days++;
        }

        return $days;
    }

    /**
     * @param array<string, int|string|null> $feed
     * @param array<string, int> $window
     * @param array<string, int> $week
     * @param array<string, bool> $enabled
     */
    public function metrics(array $feed, array $window, array $week, array $enabled, int $now): Metrics
    {
        $feedId = (int) $feed['id'];
        $failuresInRow = max(1, $this->thresholds($feed)->failuresInRow);
        $baselineSince = max((int) $feed['baseline_since'], $now - 14 * self::DAY);
        $weekAgo = $now - 7 * self::DAY;
        $attempts = [];
        $arrivals = [];
        $nonCached = [];
        foreach ($this->repository->history($feedId, $now - 14 * self::DAY) as $row) {
            $started = (int) $row['started_at'];
            if ((int) $row['accepted'] > 0 && $started >= $baselineSince) {
                $arrivals[] = $started;
            }
            if ($row['outcome'] === Outcome::CACHED) {
                continue;
            }
            $nonCached[] = $row;
            if ($started >= $weekAgo) {
                $attempts[] = $started;
            }
        }
        $lastRows = array_reverse(array_slice($nonCached, -$failuresInRow));
        if (count($lastRows) < $failuresInRow) {
            $lastRows = $this->repository->recentFetches($feedId, $failuresInRow, true);
        }
        $recent = [];
        foreach ($lastRows as $row) {
            $recent[] = [
                'outcome' => (string) $row['outcome'],
                'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
                'error_code' => $row['error_code'] === null ? null : (string) $row['error_code'],
            ];
        }
        $intervals = Baseline::gaps($attempts);
        $gaps = Baseline::gaps($arrivals);
        $key = $feed['adapter'] . "\0" . $feed['feed_key'];

        return new Metrics(
            $now,
            (int) $feed['first_seen'],
            $feed['last_attempt'] === null ? null : (int) $feed['last_attempt'],
            (int) $feed['attempts_total'],
            $recent,
            $window['attempts'],
            $window['failures'],
            $intervals,
            $gaps,
            $feed['last_new_at'] === null ? null : (int) $feed['last_new_at'],
            $feed['newest_published_at'] === null ? null : (int) $feed['newest_published_at'],
            $week['fresh'],
            $week['duplicates'],
            (int) $feed['muted'] === 1,
            ($enabled[$key] ?? true) && (int) ($feed['enabled'] ?? 1) === 1
        );
    }

    /**
     * Thresholds for one feed: settings, then per-feed overrides, then the
     * `feed_health_thresholds` filter.
     *
     * @param array<string, int|string|null> $feed
     */
    public function thresholds(array $feed): Thresholds
    {
        $s = $this->settings;
        $values = [
            'failuresInRow' => $s->int('failures_in_row'),
            'windowMinAttempts' => $s->int('failure_window_min_attempts'),
            'failureSharePercent' => $s->int('failure_share_percent'),
            'uncheckedFloor' => $s->int('unchecked_floor_hours') * 3600,
            'quietMultiplier' => $s->float('quiet_multiplier'),
            'quietFloor' => $s->int('quiet_floor_hours') * 3600,
            'quietCeiling' => $s->int('quiet_ceiling_hours') * 3600,
            'defaultQuiet' => $s->int('default_quiet_hours') * 3600,
            'fixedQuiet' => null,
            'minHistoryAttempts' => $s->int('min_history_attempts'),
            'minHistorySeconds' => $s->int('min_history_hours') * 3600,
            'redundantMinFresh' => $s->int('redundant_min_fresh'),
            'redundantPercent' => $s->int('redundant_percent'),
            'windowHours' => $s->int('failure_window_hours'),
        ];
        $overrides = self::overrides($feed['overrides'] ?? null);
        if (isset($overrides['quiet_hours'])) {
            $values['fixedQuiet'] = $overrides['quiet_hours'] * 3600;
        }
        if (isset($overrides['failures_in_row'])) {
            $values['failuresInRow'] = $overrides['failures_in_row'];
        }
        $filtered = apply_filters('feed_health_thresholds', $values, $feed);

        return Thresholds::fromArray(is_array($filtered) ? $filtered : $values);
    }

    /**
     * @return array{quiet_hours?: int, failures_in_row?: int}
     */
    public static function overrides(int|string|null $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($decoded)) {
            if (isset($decoded['quiet_hours']) && is_numeric($decoded['quiet_hours']) && (int) $decoded['quiet_hours'] > 0) {
                $out['quiet_hours'] = (int) $decoded['quiet_hours'];
            }
            if (isset($decoded['failures_in_row']) && is_numeric($decoded['failures_in_row']) && (int) $decoded['failures_in_row'] > 0) {
                $out['failures_in_row'] = (int) $decoded['failures_in_row'];
            }
        }

        return $out;
    }

    /**
     * Feeds the adapters list plus the feeds the site expects.
     *
     * @return list<FeedDescriptor>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->adapters->all() as $key => $adapter) {
            if (!$this->adapters->isAvailable($key)) {
                continue;
            }
            try {
                foreach ($adapter->feeds() as $descriptor) {
                    if ($descriptor instanceof FeedDescriptor && $descriptor->adapter === $key) {
                        $out[] = $descriptor;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->once('feeds-' . $key, 'Adapter "' . $key . '" failed to list feeds: ' . $e->getMessage());
            }
        }
        /** @var mixed $expected */
        $expected = apply_filters('feed_health_expected_feeds', []);
        foreach (is_array($expected) ? $expected : [] as $descriptor) {
            if ($descriptor instanceof FeedDescriptor) {
                $out[] = $descriptor;
            }
        }

        return $out;
    }

    /**
     * Create records for listed feeds never seen, and keep the enabled flag
     * in step with the importer.
     *
     * @param list<FeedDescriptor> $descriptors
     */
    private function syncFeeds(array $descriptors, int $now): void
    {
        $done = [];
        foreach ($descriptors as $d) {
            $slot = $d->adapter . "\0" . $d->feedKey;
            if (isset($done[$slot])) {
                continue;
            }
            $done[$slot] = true;
            $feed = $this->repository->findFeed($d->adapter, $d->feedKey);
            if ($feed === null) {
                $feed = $this->repository->createFeed($d->adapter, $d->feedKey, $d->label, $d->url, StatusEvaluator::UNCHECKED, $now);
                $this->repository->updateFeed((int) $feed['id'], ['reason' => 'never fetched', 'enabled' => $d->enabled ? 1 : 0]);
                continue;
            }
            if ((int) $feed['enabled'] !== ($d->enabled ? 1 : 0)) {
                $this->repository->updateFeed((int) $feed['id'], ['enabled' => $d->enabled ? 1 : 0]);
            }
        }
    }

    /**
     * Mark runs still running past the limit as incomplete, and note the
     * listed feeds that had no fetch inside the run's window.
     *
     * @param list<FeedDescriptor> $descriptors
     */
    private function markStaleRuns(int $now, array $descriptors): void
    {
        $limit = $this->settings->int('stale_run_hours') * 3600;
        foreach ($this->repository->staleRuns($now - $limit) as $run) {
            $runId = (string) $run['run_id'];
            $adapter = (string) $run['adapter'];
            $this->repository->markRun($runId, 'incomplete');
            $reached = $this->repository->feedsFetchedBetween((int) $run['started_at'], $now);
            $missed = 0;
            foreach ($descriptors as $d) {
                if ($d->adapter !== $adapter) {
                    continue;
                }
                $feed = $this->repository->findFeed($d->adapter, $d->feedKey);
                if ($feed === null || isset($reached[(int) $feed['id']])) {
                    continue;
                }
                $notes = Recorder::notes($feed['notes'] ?? null);
                $notes['not_reached'] = 'not reached in run ' . $runId . ' started ' . gmdate('Y-m-d H:i', (int) $run['started_at']) . ' UTC';
                $this->repository->updateFeed((int) $feed['id'], ['notes' => (string) wp_json_encode($notes)]);
                $missed++;
            }
            $this->state->set('last_incomplete_run', ['run_id' => $runId, 'adapter' => $adapter, 'at' => $now, 'missed' => $missed]);
            $this->logger->event('error', sprintf('Run %s (%s) never finished; marked incomplete. %d listed feed(s) were not reached.', $runId, $adapter, $missed));
        }
    }

    private function lock(): bool
    {
        $now = $this->clock->now();
        if (add_option(self::LOCK_OPTION, (string) $now, '', false)) {
            return true;
        }
        $held = (int) get_option(self::LOCK_OPTION, 0);
        if ($held > 0 && $now - $held < self::LOCK_LIMIT) {
            return false;
        }
        update_option(self::LOCK_OPTION, (string) $now, false);
        $this->logger->event('check', 'took over an expired check lock');

        return true;
    }

    private function unlock(): void
    {
        delete_option(self::LOCK_OPTION);
    }
}
