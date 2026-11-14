<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Outcome;
use IndianOpinion\FeedHealth\Recorder;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;
use IndianOpinion\FeedHealth\Value\FetchResult;
use IndianOpinion\FeedHealth\Value\ItemObservation;

/**
 * Section 4.2 and 4.3: recording runs, fetches and items; fetch outcomes.
 */
final class RecorderTest extends TestCase
{
    /** Test 1 (case 1). */
    public function testRecordedFetchStoresEveryFieldAndRecordTime(): void
    {
        $started = $this->clock->now() - 5;
        $this->clock->advance(10);
        $this->fetch('a', 'http_error', [
            'started_at' => $started,
            'finished_at' => $started + 3,
            'http_status' => 503,
            'final_url' => 'https://feeds.example.com/a2',
            'error_code' => 'http_503',
            'error_message' => 'Service unavailable',
            'items' => 0,
            'bytes' => 1234,
            'manual' => true,
        ]);
        $rows = $this->rows(Schema::FETCHES);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame($started, $row['started_at']);
        self::assertSame($started + 3, $row['finished_at']);
        self::assertSame($this->clock->now(), $row['recorded_at']);
        self::assertSame('http_error', $row['outcome']);
        self::assertSame(503, $row['http_status']);
        self::assertSame('https://feeds.example.com/a2', $row['final_url']);
        self::assertSame('http_503', $row['error_code']);
        self::assertSame('Service unavailable', $row['error_message']);
        self::assertSame(1234, $row['bytes']);
        self::assertSame(1, $row['manual']);
        self::assertSame((int) $this->feed('a')['id'], $row['feed_id']);
    }

    /** Test 2 (case 2). */
    public function testFetchWithoutFeedKeyOrWithUnknownAdapterIsRejectedQuietly(): void
    {
        $this->plugin->recorder->onFetchAction(['adapter' => 'test', 'outcome' => 'ok']);
        $this->plugin->recorder->onFetchAction(['adapter' => 'nobody', 'feed_key' => 'x', 'outcome' => 'ok']);
        $this->plugin->recorder->recordFetch(new FetchResult('nobody', 'x', 1, 1, 'ok'));
        $this->plugin->recorder->flush();
        self::assertSame([], $this->rows(Schema::FETCHES));
        self::assertSame(3, $this->counter('invalid_input'));
        self::assertSame(1, substr_count($this->phpLog(), 'Invalid input rejected'), 'logged once per request');
    }

    /** Test 3 (case 3). */
    public function testFirstFetchCreatesFeedWithStatusNew(): void
    {
        $this->fetch('a');
        $feed = $this->feed('a');
        self::assertSame('new', $feed['status']);
        self::assertSame('Feed a', $feed['label']);
        self::assertSame('https://feeds.example.com/a', $feed['url']);
    }

    /** Test 4 (case 4). */
    public function testChangedUrlForSameKeyUpdatesRecordAndKeepsHistory(): void
    {
        $this->fetch('a');
        $id = (int) $this->feed('a')['id'];
        $this->clock->advance(60);
        $this->fetch('a', 'ok', ['feed_url' => 'https://moved.example.org/feed', 'feed_label' => 'Renamed']);
        $feed = $this->feed('a');
        self::assertSame($id, (int) $feed['id']);
        self::assertSame('https://moved.example.org/feed', $feed['url']);
        self::assertSame('Renamed', $feed['label']);
        $rows = $this->rows(Schema::FETCHES);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($id, $row['feed_id']);
        }
    }

    /** Test 5 (case 5). */
    public function testItemsWithoutOpenFetchCreateImplicitOkFetchClosedAtFlush(): void
    {
        $this->item('a', 'https://news.example.com/1');
        $this->item('a', 'https://news.example.com/2');
        $this->clock->advance(30);
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertCount(1, $rows);
        self::assertSame(Outcome::OK, $rows[0]['outcome']);
        self::assertSame(2, $rows[0]['items']);
        self::assertSame(2, $rows[0]['accepted']);
        self::assertSame($this->clock->now(), $rows[0]['finished_at']);
        self::assertSame($this->clock->now() - 30, $rows[0]['started_at']);
    }

    /** Test 6 (case 6). */
    public function testCountersMatchAScriptedMix(): void
    {
        // Feed b already supplied article 3.
        $this->fetch('b', 'ok', [], false);
        $this->item('b', 'https://news.example.com/3');
        $this->plugin->recorder->flush();
        // Feed a lists 1 and 2 once.
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/1');
        $this->item('a', 'https://news.example.com/2');
        $this->plugin->recorder->flush();

        $this->fetch('a', 'ok', ['items' => 6], false);
        $this->item('a', 'https://news.example.com/1');            // repeat
        $this->item('a', 'https://news.example.com/3');            // duplicate of b
        $this->item('a', 'https://news.example.com/4');            // accepted
        $this->item('a', 'https://news.example.com/5', ['outcome' => 'rejected']);
        $this->item('a', 'https://news.example.com/6', ['outcome' => 'duplicate']);
        $this->item('a', '');                                       // no url
        $this->plugin->recorder->flush();

        $rows = $this->rows(Schema::FETCHES);
        $last = end($rows);
        self::assertSame(6, $last['items']);
        self::assertSame(4, $last['fresh']);
        self::assertSame(1, $last['repeats']);
        self::assertSame(1, $last['accepted']);
        self::assertSame(2, $last['duplicates']);
        self::assertSame(2, $last['rejected']);
    }

    /** Test 7 (case 7). */
    public function testImporterOutcomeIsKeptAndUnknownIsResolved(): void
    {
        $this->fetch('b', 'ok', [], false);
        $this->item('b', 'https://news.example.com/shared');
        $this->plugin->recorder->flush();

        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/shared', ['outcome' => 'accepted']);
        $this->item('a', 'https://news.example.com/own', ['outcome' => 'duplicate']);
        $this->item('a', 'https://news.example.com/new');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        $last = end($rows);
        self::assertSame(2, $last['accepted'], 'given accepted kept; unknown and unseen resolves to accepted');
        self::assertSame(1, $last['duplicates'], 'given duplicate kept');

        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/new');
        $this->item('a', 'https://news.example.com/other-b');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        $last = end($rows);
        self::assertSame(1, $last['repeats']);
        self::assertSame(1, $last['accepted']);
    }

    /** Test 8 (case 8). */
    public function testItemWithoutUrlOrWithFtpUrlIsRejected(): void
    {
        $this->fetch('a', 'ok', [], false);
        $this->item('a', '');
        $this->item('a', 'ftp://files.example.com/a.txt');
        $this->item('a', 'javascript:alert(1)');
        $this->plugin->recorder->flush();
        $row = $this->rows(Schema::FETCHES)[0];
        self::assertSame(3, $row['rejected']);
        self::assertSame(0, $row['fresh']);
        self::assertSame([], $this->rows(Schema::SEEN));
    }

    /** Test 9 (case 9). */
    public function testSameItemTwiceInOneFetchCountsOnce(): void
    {
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/1');
        $this->item('a', 'http://www.news.example.com/1/?utm_source=x');
        $this->plugin->recorder->flush();
        $row = $this->rows(Schema::FETCHES)[0];
        self::assertSame(1, $row['fresh']);
        self::assertSame(1, $row['accepted']);
        self::assertCount(1, $this->rows(Schema::SEEN));
    }

    /** Test 10 (case 10). */
    public function testBodyIsNeverStored(): void
    {
        $marker = 'SECRET-BODY-TEXT-' . bin2hex(random_bytes(4));
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/1', ['body' => $marker]);
        $this->plugin->recorder->recordItem(new ItemObservation('test', 'a', 'https://news.example.com/2', 't', 's', $marker));
        $this->plugin->recorder->flush();
        $this->plugin->checker->run();
        self::assertStringNotContainsString($marker, $this->db->dump());
        self::assertStringNotContainsString($marker, (string) json_encode(\WpStub::$options));
        self::assertStringNotContainsString($marker, (string) json_encode(\WpStub::$siteOptions));
    }

    /** Test 11 (case 11). */
    public function testOnlyNewestAcceptedTitleIsStoredAndTruncated(): void
    {
        $long = str_repeat('x', 250);
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/old', ['title' => 'Older', 'published_at' => $this->clock->now() - 7200]);
        $this->item('a', 'https://news.example.com/new', ['title' => $long, 'published_at' => $this->clock->now() - 60]);
        $this->plugin->recorder->flush();
        $feed = $this->feed('a');
        self::assertSame(str_repeat('x', 200), $feed['last_new_title']);
        self::assertStringNotContainsString('Older', $this->db->dump());
    }

    /** Test 13 (case 13). */
    public function testFetchWithUnknownRunIdIsStoredWithoutRunLink(): void
    {
        $run = $this->plugin->recorder->beginRun('test', 'run-1');
        $this->fetch('a', 'ok', ['run_id' => $run]);
        $this->fetch('b', 'ok', ['run_id' => 'no-such-run']);
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame('run-1', $rows[0]['run_id']);
        self::assertNull($rows[1]['run_id']);
        self::assertCount(2, $rows);
    }

    /** Test 14 (case 14). */
    public function testKillSwitchOffMakesZeroDatabaseCalls(): void
    {
        $this->settings(['enabled' => false]);
        $calls = $this->db->calls;
        $r = $this->plugin->recorder;
        $run = $r->beginRun('test');
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/1');
        $r->recordFetch(new FetchResult('test', 'a', 1, 2, 'ok'));
        do_action('feed_health_fetch', ['adapter' => 'test', 'feed_key' => 'a', 'outcome' => 'ok']);
        $r->endRun($run);
        $r->flush();
        self::assertSame($calls, $this->db->calls);
        self::assertSame(0, $r->pendingRows());
    }

    public function testKillSwitchConstantIsReadAtCallTime(): void
    {
        $this->settings(['enabled' => true]);
        self::assertTrue($this->plugin->settings->enabled());
        $this->settings(['enabled' => false]);
        self::assertFalse($this->plugin->settings->enabled(), 'no cached switch');
    }

    /** Test 15 (cases 15, 16). */
    public function testOkAndNotModifiedAreSuccessesAnd304KeepsLastNewItem(): void
    {
        $this->fetchWithItems('a', 2);
        $lastNew = $this->feed('a')['last_new_at'];
        $this->clock->advance(3600);
        $this->fetch('a', 'not_modified', ['http_status' => 304]);
        $feed = $this->feed('a');
        self::assertSame($lastNew, $feed['last_new_at']);
        self::assertSame($this->clock->now(), $feed['last_success']);
        self::assertTrue(Outcome::isSuccess('ok'));
        self::assertTrue(Outcome::isSuccess('not_modified'));
        self::assertFalse(Outcome::isFailure('not_modified'));
    }

    /** Test 16 (case 17). */
    public function testHttpStatusesMapToHttpErrorWithLabels(): void
    {
        $labels = [401 => 'blocked', 403 => 'blocked', 404 => 'gone', 410 => 'gone', 429 => 'rate limited', 500 => 'server error', 503 => 'server error'];
        foreach ($labels as $status => $label) {
            $this->fetch('h' . $status, 'http_error', ['http_status' => $status]);
            self::assertSame('http_error', $this->feed('h' . $status)['last_outcome']);
            self::assertSame($status, $this->feed('h' . $status)['last_http_status']);
            self::assertSame($label, Outcome::httpLabel($status));
            self::assertSame('HTTP ' . $status . ' ' . $label, Outcome::describeHttp($status));
        }
        self::assertTrue(Outcome::isFailure('http_error'));
    }

    /** Test 17 (case 18). */
    public function testTransportErrorStoresCodeAndTruncatedMessage(): void
    {
        $this->fetch('a', 'transport_error', ['error_code' => 'http_request_failed', 'error_message' => str_repeat('timeout ', 100)]);
        $row = $this->rows(Schema::FETCHES)[0];
        self::assertSame('http_request_failed', $row['error_code']);
        self::assertSame(300, mb_strlen((string) $row['error_message']));
        self::assertTrue(Outcome::isFailure('transport_error'));
    }

    /** Test 19 (case 20). */
    public function testThreeEmptyResultsAddNoteAndAreNotFailures(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->fetch('a', 'empty');
            $this->clock->advance(600);
        }
        self::assertStringNotContainsString('no items', (string) $this->feed('a')['notes']);
        $this->fetch('a', 'empty');
        self::assertStringContainsString('feed returns no items', (string) $this->feed('a')['notes']);
        self::assertFalse(Outcome::isFailure('empty'));
        self::assertNull($this->feed('a')['last_new_at']);
        $this->fetchWithItems('a', 1);
        self::assertStringNotContainsString('no items', (string) $this->feed('a')['notes']);
    }

    /** Test 20 (case 21). */
    public function testCachedChangesNeitherFailureCountersNorLastAttempt(): void
    {
        $this->fetch('a');
        $before = $this->feed('a');
        $this->clock->advance(600);
        $this->fetch('a', 'cached');
        $after = $this->feed('a');
        self::assertSame($before['last_attempt'], $after['last_attempt']);
        self::assertSame($before['attempts_total'], $after['attempts_total']);
        $totals = $this->plugin->repository->windowTotals(0, $this->clock->now() + 1);
        self::assertSame(1, $totals[(int) $after['id']]['attempts']);
        self::assertSame(0, $totals[(int) $after['id']]['failures']);
        self::assertFalse(Outcome::isFailure('cached'));
        self::assertFalse(Outcome::isSuccess('cached'));
    }

    /** Test 22 (case 23). */
    public function testUnknownOutcomeIsStoredAsFailureOther(): void
    {
        $this->fetch('a', 'exploded');
        $row = $this->rows(Schema::FETCHES)[0];
        self::assertSame('failure_other', $row['outcome']);
        self::assertSame('outcome:exploded', $row['error_code']);
        self::assertTrue(Outcome::isFailure('failure_other'));
    }

    /** Test 31, recorder part (case 34): future dates are dropped and counted. */
    public function testFutureDateIsTreatedAsMissingAndCounted(): void
    {
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/future', ['published_at' => $this->clock->now() + 25 * 3600]);
        $this->item('a', 'https://news.example.com/undated');
        $this->plugin->recorder->flush();
        self::assertNull($this->feed('a')['newest_published_at']);
        self::assertSame(1, $this->counter('bad_date'));
    }

    /** Test 76 (cases 86, 88). */
    public function testDatabaseExceptionIsCaughtCountedAndLoggedOnce(): void
    {
        $this->db->failOn = 'insert';
        $this->fetch('a');
        $this->fetch('b');
        $this->item('c', 'https://news.example.com/1');
        $this->plugin->recorder->flush();
        $this->db->failOn = null;
        self::assertGreaterThanOrEqual(2, $this->counter('internal_error'));
        self::assertSame(1, substr_count($this->phpLog(), 'Internal error while recording'));
    }

    /** Test 77, recording part (case 87). */
    public function testMissingTablesMakeRecordingDoNothingAndCount(): void
    {
        $this->plugin->repository->dropAll();
        $this->fetch('a');
        self::assertSame(1, $this->counter('tables_missing'));
        self::assertFalse($this->plugin->repository->tablesExist());
        $this->plugin->install();
        $this->fetch('a');
        self::assertCount(1, $this->rows(Schema::FETCHES));
    }

    /** Test 82 (section 5.4). */
    public function testActionsAcceptPlainArraysIgnoreUnknownKeysAndRejectMissingKeys(): void
    {
        $this->boot();
        do_action('feed_health_run_start', 'test', 'r1');
        do_action('feed_health_fetch', ['adapter' => 'test', 'feed_key' => 'a', 'outcome' => 'ok', 'run_id' => 'r1', 'whatever' => [1, 2]]);
        do_action('feed_health_item', ['adapter' => 'test', 'feed_key' => 'a', 'url' => 'https://news.example.com/1', 'title' => 'T', 'body' => 'B', 'source_name' => 'S', 'published_at' => '2025-12-31T12:00:00Z', 'outcome' => 'unknown', 'extra' => true]);
        do_action('feed_health_fetch', ['adapter' => 'test', 'outcome' => 'ok']);
        do_action('feed_health_item', ['adapter' => 'test', 'feed_key' => 'a']);
        do_action('feed_health_run_end', 'test', 'r1', 'ok');
        $fetches = $this->rows(Schema::FETCHES);
        self::assertCount(1, $fetches);
        self::assertSame(1, $fetches[0]['accepted']);
        self::assertSame('r1', $fetches[0]['run_id']);
        self::assertSame('ok', $this->rows(Schema::RUNS)[0]['status']);
        self::assertSame(2, $this->counter('invalid_input'));
    }

    public function testFlushCapWritesEarly(): void
    {
        $this->fetch('a', 'ok', [], false);
        for ($i = 0; $i < Recorder::FLUSH_CAP + 10; $i++) {
            $this->item('a', 'https://news.example.com/n' . $i);
        }
        self::assertLessThan(Recorder::FLUSH_CAP, $this->plugin->recorder->pendingRows());
        self::assertNotSame([], $this->rows(Schema::FETCHES), 'written before the end of the request');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertCount(1, $rows, 'items after a cap flush attach to the same fetch');
        self::assertSame(Recorder::FLUSH_CAP + 10, $rows[0]['accepted']);
        self::assertSame(Recorder::FLUSH_CAP + 10, $rows[0]['items']);
    }

    /**
     * A fetch larger than the flush cap: the reported item count is not
     * counted twice, and the newest item and title come from the whole
     * fetch, not from the last chunk written.
     */
    public function testFetchAcrossCapFlushesKeepsTotalsAndNewest(): void
    {
        $total = Recorder::FLUSH_CAP + 20;
        $now = $this->clock->now();
        $this->fetch('a', 'ok', ['items' => $total], false);
        for ($i = 0; $i < $total; $i++) {
            // Newest first, as feeds list them.
            $this->item('a', 'https://news.example.com/big/' . $i, ['published_at' => $now - $i * 60, 'title' => 'Story ' . $i]);
        }
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertCount(1, $rows);
        self::assertSame($total, $rows[0]['items']);
        self::assertSame($total, $rows[0]['accepted']);
        $feed = $this->feed('a');
        self::assertSame($now, $feed['newest_published_at']);
        self::assertSame('Story 0', $feed['last_new_title']);
        self::assertSame(1, $feed['attempts_total']);
    }

    public function testRunEndFlushesAndQueuesOneCheck(): void
    {
        $this->boot();
        $run = $this->plugin->recorder->beginRun('test');
        $this->fetch('a', 'ok', ['run_id' => $run], false);
        self::assertSame([], $this->rows(Schema::FETCHES));
        $this->plugin->recorder->endRun($run, 'ok');
        self::assertCount(1, $this->rows(Schema::FETCHES));
        self::assertNotFalse(wp_next_scheduled('feed_health_check_after_run'));
    }
}
