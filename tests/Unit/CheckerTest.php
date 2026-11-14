<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Admin\Dashboard;
use IndianOpinion\FeedHealth\Checker;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;

/**
 * Section 4.9 (checks), case 12 (stale runs), case 36 (expected feeds),
 * 4.13 failure modes that concern checks.
 */
final class CheckerTest extends TestCase
{
    /**
     * Build a steady history: hourly fetches with new items, for a number of days.
     */
    private function history(string $feedKey, int $days, int $everyHours = 1, int $itemsEvery = 1): void
    {
        $steps = intdiv($days * 24, $everyHours);
        for ($i = 0; $i < $steps; $i++) {
            if ($i % $itemsEvery === 0) {
                $this->fetchWithItems($feedKey, 2);
            } else {
                $this->fetch($feedKey, 'not_modified', ['http_status' => 304]);
            }
            $this->clock->advance($everyHours * 3600);
        }
    }

    /** Test 48 (case 57). */
    public function testCheckStoresStatusesAndReturnsTransitions(): void
    {
        $this->history('a', 3);
        $this->history('b', 0);
        $summary = $this->plugin->checker->run();
        self::assertSame('ok', $summary['status']);
        self::assertSame('healthy', $this->feed('a')['status']);
        $first = array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], $summary['transitions']);
        self::assertSame(['new>healthy'], $first);

        for ($i = 0; $i < 3; $i++) {
            $this->fetch('a', 'http_error', ['http_status' => 403]);
            $this->clock->advance(3600);
        }
        $summary = $this->plugin->checker->run();
        self::assertSame(['healthy>failing'], array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], $summary['transitions']));
        self::assertSame('failing', $this->feed('a')['status']);
        self::assertSame('3 failures in a row: HTTP 403 blocked', $this->feed('a')['reason']);
        self::assertNotNull($this->feed('a')['quiet_threshold']);
    }

    /** Test 46 (cases 54, 56). */
    public function testCheckReadsOnlyPluginTablesAndMakesNoHttpCall(): void
    {
        $this->history('a', 2);
        $this->plugin->checker->run();
        self::assertSame([], \WpStub::$httpCalls);
        self::assertSame(0, \WpStub::$fetchFeedCalls);
    }

    /** Test 47 (case 55). */
    public function testSecondCheckWhileLockedIsSkippedAndExpiredLockIsTakenOver(): void
    {
        add_option(Checker::LOCK_OPTION, (string) $this->clock->now(), '', false);
        $summary = $this->plugin->checker->run();
        self::assertSame('skipped', $summary['status']);
        self::assertSame(1, $this->counter('checks_skipped'));
        self::assertNotSame([], array_filter($this->events('check'), static fn (array $e): bool => $e['message'] === 'skipped: already running'));

        $this->clock->advance(Checker::LOCK_LIMIT + 1);
        $summary = $this->plugin->checker->run();
        self::assertSame('ok', $summary['status']);
        self::assertFalse(get_option(Checker::LOCK_OPTION), 'lock released');
    }

    /** Test 12 (case 12). */
    public function testStaleRunBecomesIncompleteAndUnreachedFeedsAreNoted(): void
    {
        add_filter('feed_health_expected_feeds', static fn (array $l): array => [
            new FeedDescriptor('test', 'a', 'https://feeds.example.com/a'),
            new FeedDescriptor('test', 'b', 'https://feeds.example.com/b'),
        ]);
        $run = $this->plugin->recorder->beginRun('test');
        $this->fetch('a', 'ok', ['run_id' => $run]);
        $this->clock->advance(3600);
        $this->plugin->checker->run();
        self::assertSame('running', $this->rows(Schema::RUNS)[0]['status'], 'inside the limit');

        $this->clock->advance(3601);
        $this->plugin->checker->run();
        self::assertSame('incomplete', $this->rows(Schema::RUNS)[0]['status']);
        self::assertStringContainsString('not reached in run ' . $run, (string) $this->feed('b')['notes']);
        self::assertStringNotContainsString('not reached', (string) $this->feed('a')['notes']);
    }

    /** Test 33 (case 36). */
    public function testExpectedFeedWithNoFetchShowsUnchecked(): void
    {
        add_filter('feed_health_expected_feeds', static fn (array $l): array => [new FeedDescriptor('test', 'never', 'https://feeds.example.com/never', 'Never fetched')]);
        $this->plugin->checker->run();
        $feed = $this->feed('never');
        self::assertSame('unchecked', $feed['status']);
        self::assertSame('never fetched', $feed['reason']);
        $rows = (new Dashboard($this->plugin))->rows();
        self::assertSame('unchecked', $rows[0]['status']);
    }

    public function testAdapterReportedDisabledFeedIsPaused(): void
    {
        $this->history('a', 2);
        add_filter('feed_health_expected_feeds', static fn (array $l): array => [new FeedDescriptor('test', 'a', 'https://feeds.example.com/a', 'A', false)]);
        $this->plugin->checker->run();
        self::assertSame('paused', $this->feed('a')['status']);
        self::assertSame('disabled in the importer', $this->feed('a')['reason']);
    }

    /** Test 49 (case 58). */
    public function testRollupsMatchFetchRowsAndDashboardAgreesAfterPruning(): void
    {
        $this->settings(['retention_fetches_days' => 15]);
        $this->history('a', 5, 3);
        $this->fetch('a', 'http_error', ['http_status' => 500]);
        $this->clock->advance(86400);
        $this->plugin->checker->maintenance(true);
        $fetches = $this->rows(Schema::FETCHES);
        $daily = $this->rows(Schema::DAILY);
        self::assertSame(array_sum(array_column($fetches, 'accepted')), array_sum(array_column($daily, 'accepted')));
        self::assertSame(count($fetches), array_sum(array_column($daily, 'attempts')));
        self::assertSame(1, array_sum(array_column($daily, 'failures')));
        self::assertSame(1, array_sum(array_column($daily, 'http_errors')));
        self::assertSame(array_sum(array_column($fetches, 'items')), array_sum(array_column($daily, 'items')));

        $dashboard = new Dashboard($this->plugin);
        $before = $dashboard->rows()[0];
        // Prune every fetch row older than today; rollups must carry the numbers.
        $this->db->delete(Schema::FETCHES, ['started_at <' => (int) strtotime(gmdate('Y-m-d', $this->clock->now()) . ' 00:00:00 UTC')]);
        $after = $dashboard->rows()[0];
        self::assertSame($before['yield_7d'], $after['yield_7d']);
        self::assertSame($before['sparkline'], $after['sparkline']);
        self::assertSame($before['dup_rate_7d'], $after['dup_rate_7d']);
    }

    /** Test 50 (case 59). */
    public function testRowsPastRetentionArePrunedAndRowsInsideKept(): void
    {
        $this->settings(['retention_fetches_days' => 15, 'retention_events_days' => 5, 'retention_runs_days' => 5, 'retention_daily_days' => 10]);
        $run = $this->plugin->recorder->beginRun('test');
        $this->plugin->recorder->endRun($run);
        $this->fetchWithItems('old', 1);
        $this->plugin->repository->addEvent($this->clock->now(), 'check', 'old event');
        $this->clock->advance(20 * 86400);
        $this->fetchWithItems('new', 1);
        $this->plugin->repository->addEvent($this->clock->now(), 'check', 'new event');
        $this->plugin->checker->maintenance(true);
        $feeds = array_column($this->rows(Schema::FETCHES), 'feed_id');
        self::assertSame([(int) $this->feed('new')['id']], $feeds);
        self::assertSame([], $this->rows(Schema::RUNS));
        $messages = array_column($this->rows(Schema::EVENTS), 'message');
        self::assertNotContains('old event', $messages);
        self::assertContains('new event', $messages);
        foreach ($this->rows(Schema::DAILY) as $row) {
            self::assertGreaterThanOrEqual(gmdate('Y-m-d', $this->clock->now() - 10 * 86400), $row['day']);
        }
    }

    /** Test 51 (cases 60, 89). */
    public function testExceptionInTheMiddleIsCaughtAndNoTransitionIsSentTwice(): void
    {
        $this->history('a', 2);
        $this->history('b', 0);
        $this->plugin->checker->run();
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('a', 'http_error', ['http_status' => 500]);
            $this->fetch('b', 'http_error', ['http_status' => 500]);
            $this->clock->advance(600);
        }
        \WpStub::$mail = [];
        $calls = 0;
        add_filter('feed_health_thresholds', static function (array $t, array $feed) use (&$calls): array {
            $calls++;
            if ($calls === 4) {
                throw new \RuntimeException('boom in the middle');
            }

            return $t;
        }, 10, 2);
        $summary = $this->plugin->checker->run();
        self::assertSame('error', $summary['status']);
        self::assertNotSame([], array_filter($this->events('error'), static fn (array $e): bool => str_contains((string) $e['message'], 'boom in the middle')));
        self::assertSame([], \WpStub::$mail, 'nothing sent from a failed check');
        self::assertFalse(get_option(Checker::LOCK_OPTION), 'lock released after a failure');

        $summary = $this->plugin->checker->run();
        self::assertSame('ok', $summary['status']);
        $sent = count(\WpStub::$mail);
        self::assertSame(1, $sent);
        $this->plugin->checker->run();
        self::assertCount($sent, \WpStub::$mail, 'no transition sent twice');
        $transitionIds = array_column($this->events('transition'), 'transition_id');
        self::assertSame(count($transitionIds), count(array_unique($transitionIds)));
    }

    /** Test 78 (case 90). */
    public function testClockMovingBackwardsGivesZeroAgesAndOneEvent(): void
    {
        $this->history('a', 2);
        $this->clock->advance(-7200);
        $this->plugin->checker->run();
        $this->plugin->checker->run();
        $rows = (new Dashboard($this->plugin))->rows();
        self::assertSame(0, $rows[0]['last_new_age']);
        $notes = array_filter($this->events('error'), static fn (array $e): bool => str_contains((string) $e['message'], 'clock moved backwards'));
        self::assertCount(1, $notes);
    }

    /** Test 79 (case 91). */
    public function testSameStatusesWithAndWithoutObjectCache(): void
    {
        $results = [];
        foreach ([false, true] as $cache) {
            $this->setUp();
            \WpStub::$objectCache = $cache;
            $this->history('a', 2);
            for ($i = 0; $i < 3; $i++) {
                $this->fetch('b', 'transport_error');
                $this->clock->advance(600);
            }
            $this->plugin->checker->run();
            $results[] = [$this->feed('a')['status'], $this->feed('b')['status'], $this->feed('a')['reason']];
        }
        self::assertSame($results[0], $results[1]);
    }

    public function testDryRunStoresAndSendsNothing(): void
    {
        $this->history('a', 2);
        $before = $this->db->dump();
        $summary = $this->plugin->checker->run(true);
        self::assertSame($before, $this->db->dump());
        self::assertSame([], \WpStub::$mail);
        self::assertSame(['new>healthy'], array_map(static fn (array $t): string => $t['from'] . '>' . $t['to'], $summary['transitions']));
    }

    public function testPerFeedOverridesAndFilterApply(): void
    {
        $this->history('a', 2);
        $id = (int) $this->feed('a')['id'];
        $this->plugin->operator->setThresholds($id, 1, null, 1);
        $this->fetch('a', 'http_error', ['http_status' => 500]);
        $this->plugin->checker->run();
        self::assertSame('failing', $this->feed('a')['status'], 'one failure is enough with the override');

        add_filter('feed_health_thresholds', static function (array $t): array {
            $t['failuresInRow'] = 5;

            return $t;
        });
        $this->plugin->checker->run();
        self::assertSame('degraded', $this->feed('a')['status'], 'the filter has the last word');
    }

    public function testStatusFilterHasFinalSay(): void
    {
        $this->history('a', 2);
        add_filter('feed_health_status', static fn (array $s): array => ['quiet', 'forced by a site rule'], 10, 3);
        $this->plugin->checker->run();
        self::assertSame('quiet', $this->feed('a')['status']);
        self::assertSame('forced by a site rule', $this->feed('a')['reason']);
    }

    public function testQuietFeedAfterItsUsualGap(): void
    {
        $this->history('a', 3, 1, 2);
        $this->plugin->checker->run();
        self::assertSame('healthy', $this->feed('a')['status']);
        self::assertSame(7200, (int) $this->feed('a')['usual_gap']);
        for ($i = 0; $i < 7; $i++) {
            $this->fetch('a', 'not_modified', ['http_status' => 304]);
            $this->clock->advance(3600);
        }
        $this->plugin->checker->run();
        self::assertSame('quiet', $this->feed('a')['status']);
        self::assertStringContainsString('usual gap is 2 h', (string) $this->feed('a')['reason']);
    }
}
