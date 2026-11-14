<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;

/**
 * Section 4.8: operator actions.
 */
final class OperatorTest extends TestCase
{
    private const URL = 'https://feeds.example.com/core.xml';

    private function coreFeedId(): int
    {
        $this->boot();
        \WpStub::$fetchFeed = static function (string $url) {
            do_action('http_api_debug', ['response' => ['code' => 200], 'body' => '<rss/>'], 'response', 'Requests', [], $url);

            return new \FakeFeed([new \FakeFeedItem('https://news.example.com/x', 'X')]);
        };
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();

        return (int) $this->feed($this->plugin->core->feedKey(self::URL), 'core')['id'];
    }

    /** Test 42 (case 47). */
    public function testTestFetchRecordsManualAttemptAndIsRateLimited(): void
    {
        $id = $this->coreFeedId();
        $result = $this->plugin->operator->testFetch($id, 3);
        self::assertSame('ok', $result['outcome']);
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame(1, end($rows)['manual']);
        $this->clock->advance(120);
        try {
            $this->plugin->operator->testFetch($id, 3);
            self::fail('second click inside 5 minutes must be refused');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('once per feed per 5 minutes', $e->getMessage());
        }
        $this->clock->advance(200);
        $this->plugin->operator->testFetch($id, 3);
        self::assertCount(3, $this->rows(Schema::FETCHES));
    }

    public function testTestFetchIsRefusedForAdaptersWithoutIt(): void
    {
        $this->fetch('a');
        $this->expectException(InvalidValue::class);
        $this->plugin->operator->testFetch((int) $this->feed('a')['id'], 1);
    }

    /** Test 43 (cases 48, 49). */
    public function testMuteMakesPausedAndSnoozeKeepsStatus(): void
    {
        $this->fetch('a');
        $id = (int) $this->feed('a')['id'];
        $this->plugin->operator->mute($id, 5);
        self::assertSame('paused', $this->feed('a')['status']);
        $this->fetch('a', 'http_error', ['http_status' => 500]);
        $this->plugin->checker->run();
        self::assertSame('paused', $this->feed('a')['status'], 'recording continues, status stays paused');
        self::assertCount(2, $this->rows(Schema::FETCHES));
        $this->plugin->operator->unmute($id, 5);
        $until = $this->plugin->operator->snooze($id, 5, '1h');
        self::assertSame($this->clock->now() + 3600, $until);
        $this->plugin->checker->run();
        self::assertNotSame('paused', $this->feed('a')['status']);
    }

    /** Test 44 (cases 50, 51). */
    public function testResetClearsSeenAndGapsButNotHistoryAndForgetNeedsConfirmation(): void
    {
        $this->fetchWithItems('a', 3);
        $this->fetchWithItems('b', 1);
        $this->plugin->checker->run();
        $id = (int) $this->feed('a')['id'];
        $this->plugin->operator->resetBaseline($id, 2);
        self::assertSame(0, $this->plugin->repository->seenCount($id));
        self::assertNull($this->feed('a')['usual_gap']);
        self::assertSame($this->clock->now(), $this->feed('a')['baseline_since']);
        self::assertCount(2, $this->rows(Schema::FETCHES), 'history stays');

        try {
            $this->plugin->operator->forget($id, 2, false);
            self::fail('forget without confirmation must be refused');
        } catch (InvalidValue $e) {
            self::assertNotNull($this->plugin->repository->feed($id));
        }
        $this->plugin->operator->forget($id, 2, true);
        self::assertNull($this->plugin->repository->feed($id));
        foreach ([Schema::FETCHES, Schema::SEEN, Schema::DAILY] as $table) {
            foreach ($this->rows($table) as $row) {
                self::assertNotSame($id, $row['feed_id']);
            }
        }
        foreach ($this->rows(Schema::EVENTS) as $row) {
            self::assertNotSame($id, $row['feed_id']);
        }
        self::assertNotNull($this->plugin->repository->findFeed('test', 'b'));
    }

    /** Test 45 (cases 52, 53). */
    public function testPerFeedThresholdsApplyAndEveryActionWritesAnEventWithUserId(): void
    {
        $this->fetchWithItems('a', 1);
        $id = (int) $this->feed('a')['id'];
        $op = $this->plugin->operator;
        $op->setThresholds($id, 9, 12, 2);
        $t = $this->plugin->checker->thresholds($this->feed('a'));
        self::assertSame(12 * 3600, $t->fixedQuiet);
        self::assertSame(2, $t->failuresInRow);
        $op->mute($id, 9);
        $op->unmute($id, 9);
        $op->snooze($id, 9, '1w');
        $op->unsnooze($id, 9);
        $op->resetBaseline($id, 9);
        $op->setThresholds($id, 9, null, null);
        $events = $this->events('operator');
        self::assertCount(7, $events);
        foreach ($events as $event) {
            self::assertSame(9, $event['user_id']);
            self::assertSame($id, $event['feed_id']);
        }
        $op->forget($id, 9, true);
        $events = $this->events('operator');
        self::assertSame(9, end($events)['user_id']);
        self::assertStringContainsString('forgot feed', (string) end($events)['message']);
    }

    public function testThresholdAndDurationValidation(): void
    {
        $this->fetch('a');
        $id = (int) $this->feed('a')['id'];
        foreach ([[0, null], [null, 0], [99999, null]] as [$q, $f]) {
            try {
                $this->plugin->operator->setThresholds($id, 1, $q, $f);
                self::fail('out of range must be refused');
            } catch (InvalidValue $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(5400, \IndianOpinion\FeedHealth\Operator::duration('90m'));
        $this->expectException(InvalidValue::class);
        \IndianOpinion\FeedHealth\Operator::duration('forever');
    }
}
