<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Core\CoreAdapter;
use IndianOpinion\FeedHealth\Outcome;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;

/**
 * Section 4.5: the reference adapter for core fetch_feed(), and the
 * outcome classification of section 4.3 as the adapter applies it.
 */
final class CoreAdapterTest extends TestCase
{
    private const URL = 'https://feeds.example.com/world.xml';

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
    }

    /**
     * Script fetch_feed(): fire wp_feed_options, then http_api_debug with
     * the response (unless null: a cache hit), then return the result.
     *
     * @param mixed $response
     * @param mixed $result
     */
    private function script($response, $result): void
    {
        \WpStub::$fetchFeed = static function (string $url) use ($response, $result) {
            $feed = $result instanceof \FakeFeed ? $result : new \FakeFeed();
            do_action_ref_array('wp_feed_options', [&$feed, $url]);
            if ($response !== null) {
                do_action('http_api_debug', $response, 'response', 'Requests', [], $url);
            }

            return $result;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(int $code, string $body = '<rss/>', ?\FakeHttpResponse $http = null): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'body' => $body, 'headers' => [], 'http_response' => $http];
    }

    private static function feedWith(int $n, ?int $date = null): \FakeFeed
    {
        $items = [];
        for ($i = 0; $i < $n; $i++) {
            $items[] = new \FakeFeedItem('https://news.example.com/story-' . $i, 'Story ' . $i, $date);
        }

        return new \FakeFeed($items, 'World news');
    }

    /** Test 27 (cases 29, 30). */
    public function testWrapperReturnsExactlyWhatFetchFeedReturnedAndRecordsOneFetch(): void
    {
        $feed = self::feedWith(3, $this->clock->now() - 600);
        $this->script(self::response(200), $feed);
        $returned = feed_health_fetch_feed(self::URL);
        self::assertSame($feed, $returned);
        $this->plugin->recorder->flush();
        $fetches = $this->rows(Schema::FETCHES);
        self::assertCount(1, $fetches);
        self::assertSame(Outcome::OK, $fetches[0]['outcome']);
        self::assertSame(3, $fetches[0]['items']);
        self::assertSame(3, $fetches[0]['accepted']);
        $row = $this->feed($this->plugin->core->feedKey(self::URL), 'core');
        self::assertSame('World news', $row['label']);
        self::assertSame(self::URL, $row['url']);

        $error = new \WP_Error('simplepie-error', 'A feed could not be found.');
        $this->script(self::response(404, 'Not found'), $error);
        self::assertSame($error, feed_health_fetch_feed(self::URL));
        $this->plugin->recorder->flush();
        $fetches = $this->rows(Schema::FETCHES);
        self::assertCount(2, $fetches);
        self::assertSame(Outcome::HTTP_ERROR, $fetches[1]['outcome']);
        self::assertSame(404, $fetches[1]['http_status']);
    }

    public function testWrapperUsesCallerKeyAsGivenAndLabel(): void
    {
        $this->script(self::response(200), self::feedWith(1));
        feed_health_fetch_feed(self::URL, 'my-own-key', 'World desk');
        $this->plugin->recorder->flush();
        $row = $this->feed('my-own-key', 'core');
        self::assertSame('World desk', $row['label']);
    }

    /** Test 28 (case 31). */
    public function testPassiveModeRecordsUnwrappedCallsWithItemsCountedNotKeyed(): void
    {
        $this->script(self::response(200), self::feedWith(4));
        fetch_feed(self::URL);
        do_action('shutdown');
        self::assertSame([], $this->rows(Schema::FETCHES), 'passive mode is off by default');

        \WpStub::reset();
        add_filter('feed_health_adapters', static fn (array $l): array => $l);
        $this->plugin = $this->newPlugin();
        $this->plugin->install();
        $this->settings(['core_passive' => true]);
        $this->boot();
        $this->script(self::response(200), self::feedWith(4));
        fetch_feed(self::URL);
        $this->script(self::response(500, 'oops'), new \WP_Error('simplepie-error', 'bad'));
        fetch_feed('https://feeds.example.com/second.xml');
        do_action('shutdown');
        $fetches = $this->rows(Schema::FETCHES);
        self::assertCount(2, $fetches);
        self::assertSame(4, $fetches[0]['items']);
        self::assertSame(0, $fetches[0]['fresh']);
        self::assertSame(0, $fetches[0]['accepted']);
        self::assertSame([], $this->rows(Schema::SEEN));
        self::assertSame(Outcome::HTTP_ERROR, $fetches[1]['outcome']);
        self::assertSame(500, $fetches[1]['http_status']);
    }

    public function testPassiveModeDoesNotDoubleRecordWrappedCalls(): void
    {
        $this->settings(['core_passive' => true]);
        \WpStub::$hooks = [];
        $this->plugin = $this->newPlugin();
        add_filter('feed_health_adapters', static fn (array $l): array => $l);
        $this->boot();
        $this->script(self::response(200), self::feedWith(2));
        feed_health_fetch_feed(self::URL);
        do_action('shutdown');
        self::assertCount(1, $this->rows(Schema::FETCHES));
    }

    /** Test 29 (case 32). */
    public function testCacheHitWithNoHttpRequestRecordsCached(): void
    {
        $this->script(null, self::feedWith(2));
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();
        self::assertSame(Outcome::CACHED, $this->rows(Schema::FETCHES)[0]['outcome']);
        self::assertNull($this->feed($this->plugin->core->feedKey(self::URL), 'core')['last_attempt']);
    }

    /** Test 30 (case 33). */
    public function testThreeWpErrorShapesMapToThreeOutcomes(): void
    {
        $error = new \WP_Error('simplepie-error', 'failed');
        self::assertSame(Outcome::TRANSPORT_ERROR, CoreAdapter::classify($error, new \WP_Error('http_request_failed', 'cURL error 28: timed out'))['outcome']);
        self::assertSame(Outcome::TRANSPORT_ERROR, CoreAdapter::classify($error, null)['outcome']);
        self::assertSame(Outcome::HTTP_ERROR, CoreAdapter::classify($error, self::response(403))['outcome']);
        self::assertSame(Outcome::PARSE_ERROR, CoreAdapter::classify($error, self::response(200, '<html>challenge</html>'))['outcome']);
        $t = CoreAdapter::classify($error, new \WP_Error('http_request_failed', 'cURL error 6: Could not resolve host'));
        self::assertSame('http_request_failed', $t['error_code']);
        self::assertStringContainsString('resolve host', (string) $t['error_message']);
    }

    /** Test 18 (case 19): HTML, empty body and broken XML are parse errors. */
    public function testTwoHundredWithoutAFeedIsAParseErrorAndAFailure(): void
    {
        foreach (['<html><body>Checking your browser</body></html>', '', '<rss><channel><item>'] as $i => $body) {
            $this->script(self::response(200, $body), new \WP_Error('simplepie-error', 'not a feed'));
            feed_health_fetch_feed('https://feeds.example.com/p' . $i);
        }
        $this->plugin->recorder->flush();
        foreach ($this->rows(Schema::FETCHES) as $row) {
            self::assertSame(Outcome::PARSE_ERROR, $row['outcome']);
            self::assertSame(200, $row['http_status']);
            self::assertTrue(Outcome::isFailure((string) $row['outcome']));
        }
    }

    public function testValidFeedWithNoItemsIsEmptyAnd304IsNotModified(): void
    {
        self::assertSame(Outcome::EMPTY, CoreAdapter::classify(new \FakeFeed([]), self::response(200))['outcome']);
        self::assertSame(Outcome::NOT_MODIFIED, CoreAdapter::classify(new \FakeFeed([]), self::response(304, ''))['outcome']);
        self::assertSame(Outcome::OK, CoreAdapter::classify(self::feedWith(1), self::response(200))['outcome']);
    }

    /** Test 21 (case 22). */
    public function testPermanentRedirectShowsMovedAndCrossHostWarns(): void
    {
        $http = new \FakeHttpResponse('https://parked.example.org/feed', [301]);
        $this->script(self::response(200, '<rss/>', $http), self::feedWith(1));
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();
        $feed = $this->feed($this->plugin->core->feedKey(self::URL), 'core');
        self::assertSame('https://parked.example.org/feed', $feed['final_url']);
        self::assertSame(301, $feed['redirect_status']);
        $page = (new \IndianOpinion\FeedHealth\Admin\AdminPage($this->plugin))->html([]);
        self::assertStringContainsString('moved: update the feed URL', $page);
        self::assertStringContainsString('warning: redirects to another site (example.org)', $page);

        $same = new \FakeHttpResponse('https://feeds.example.com/new-world.xml', [302]);
        $this->script(self::response(200, '<rss/>', $same), self::feedWith(1));
        feed_health_fetch_feed('https://feeds.example.com/other.xml');
        $this->plugin->recorder->flush();
        $other = $this->feed($this->plugin->core->feedKey('https://feeds.example.com/other.xml'), 'core');
        self::assertSame(302, $other['redirect_status']);
    }

    /** Test 31 (case 34). */
    public function testMissingDateIsNullAndFutureDateIsBad(): void
    {
        $items = [
            new \FakeFeedItem('https://news.example.com/a', 'A', null),
            new \FakeFeedItem('https://news.example.com/b', 'B', $this->clock->now() + 25 * 3600),
        ];
        $this->script(self::response(200), new \FakeFeed($items));
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();
        self::assertNull($this->feed($this->plugin->core->feedKey(self::URL), 'core')['newest_published_at']);
        self::assertSame(1, $this->counter('bad_date'));

        $this->script(self::response(200), new \FakeFeed([new \FakeFeedItem('https://news.example.com/c', 'C', $this->clock->now() - 3600)]));
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();
        self::assertSame($this->clock->now() - 3600, $this->feed($this->plugin->core->feedKey(self::URL), 'core')['newest_published_at']);
    }

    public function testWrapperIsPlainFetchFeedWhenSwitchedOff(): void
    {
        $this->settings(['enabled' => false]);
        $feed = self::feedWith(1);
        $this->script(self::response(200), $feed);
        self::assertSame($feed, feed_health_fetch_feed(self::URL));
        $this->settings(['enabled' => true, 'core_adapter' => false]);
        self::assertSame($feed, feed_health_fetch_feed(self::URL));
        $this->plugin->recorder->flush();
        self::assertSame([], $this->rows(Schema::FETCHES));
    }

    public function testCoreAdapterListsOnlyFeedsItHasSeen(): void
    {
        self::assertSame([], iterator_to_array((function () {
            yield from $this->plugin->core->feeds();
        })()));
        $this->script(self::response(200), self::feedWith(1));
        feed_health_fetch_feed(self::URL);
        $this->plugin->recorder->flush();
        $list = [];
        foreach ($this->plugin->core->feeds() as $d) {
            $list[] = $d->url;
        }
        self::assertSame([self::URL], $list);
    }
}
