<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Exception\CanonicalisationFailed;
use IndianOpinion\FeedHealth\Redactor;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;
use IndianOpinion\FeedHealth\UrlCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Section 4.4 (canonical URL and seen keys) and 4.12 (redaction).
 */
final class CanonicalTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function samePairs(): array
    {
        $base = 'https://news.example.com/world/story-1';

        return [
            'scheme' => [$base, 'http://news.example.com/world/story-1'],
            'www' => ['https://www.example.com/a', 'https://example.com/a'],
            'm.' => ['https://m.example.com/a', 'https://example.com/a'],
            'mobile.' => ['https://mobile.example.com/a', 'https://example.com/a'],
            'amp.' => ['https://amp.example.com/a', 'https://example.com/a'],
            'host case' => [$base, 'https://NEWS.Example.COM/world/story-1'],
            'default port' => [$base, 'https://news.example.com:443/world/story-1'],
            'http port' => [$base, 'http://news.example.com:80/world/story-1'],
            'fragment' => [$base, $base . '#comments'],
            'trailing slash' => [$base, $base . '/'],
            'amp path' => [$base, $base . '/amp'],
            'amp path slash' => [$base, $base . '/amp/'],
            'amp=1' => [$base, $base . '?amp=1'],
            'utm' => [$base, $base . '?utm_source=rss&utm_medium=feed&utm_campaign=x'],
            'fbclid gclid' => [$base, $base . '?fbclid=abc&gclid=def'],
            'mc and ref' => [$base, $base . '?mc_cid=1&mc_eid=2&ref=home&cmpid=rss'],
            'param order' => [$base . '?a=1&b=2', $base . '?b=2&a=1'],
            'percent case' => ['https://example.com/caf%c3%a9', 'https://example.com/caf%C3%A9'],
            'tracking with real param' => [$base . '?id=7', $base . '?utm_source=x&id=7'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differentPairs(): array
    {
        return [
            'path' => ['https://example.com/a', 'https://example.com/b'],
            'meaningful param' => ['https://example.com/a?id=1', 'https://example.com/a?id=2'],
            'host' => ['https://example.com/a', 'https://example.org/a'],
            'other port' => ['https://example.com/a', 'https://example.com:8443/a'],
            'subdomain' => ['https://news.example.com/a', 'https://sport.example.com/a'],
        ];
    }

    /** Test 23 (case 24). */
    #[DataProvider('samePairs')]
    public function testVariantsShareAKey(string $a, string $b): void
    {
        $c = new UrlCanonicalizer();
        self::assertSame(UrlCanonicalizer::key($c->canonical($a)), UrlCanonicalizer::key($c->canonical($b)), $c->canonical($a) . ' vs ' . $c->canonical($b));
    }

    /** Test 23 (case 24). */
    #[DataProvider('differentPairs')]
    public function testDifferentArticlesDoNotShareAKey(string $a, string $b): void
    {
        $c = new UrlCanonicalizer();
        self::assertNotSame($c->canonical($a), $c->canonical($b));
    }

    public function testTrackingListIsFilterable(): void
    {
        add_filter('feed_health_tracking_params', static fn (array $list): array => array_merge($list, ['sessionref']));
        $c = new UrlCanonicalizer();
        self::assertSame($c->canonical('https://example.com/a'), $c->canonical('https://example.com/a?sessionref=9'));
    }

    /** Test 24 (cases 25, 26). */
    public function testRawUrlFilterRewritesAWrapperAndCanonicalFilterReplacesTheKey(): void
    {
        add_filter('feed_health_item_url', static function (string $url): string {
            if (str_starts_with($url, 'https://wrapper.example.net/r?u=')) {
                return rawurldecode(substr($url, strlen('https://wrapper.example.net/r?u=')));
            }

            return $url;
        });
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://wrapper.example.net/r?u=' . rawurlencode('https://real.example.com/story'));
        $this->plugin->recorder->flush();
        $this->fetch('b', 'ok', [], false);
        $this->item('b', 'https://real.example.com/story');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame(1, $rows[1]['duplicates'], 'the unwrapped URL matched feed a');

        add_filter('feed_health_canonical_url', static fn (string $canonical): string => 'fixed-key', 10, 2);
        $this->fetch('c', 'ok', [], false);
        $this->item('c', 'https://one.example.com/x');
        $this->item('c', 'https://two.example.com/y');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame(1, end($rows)['fresh'], 'both URLs mapped to the same replaced key');
    }

    /** Test 25 (case 27). */
    public function testUnparseableUrlFallsBackAndIsCounted(): void
    {
        $c = new UrlCanonicalizer();
        try {
            $c->canonical('https:///nohost');
            self::fail('expected a failure');
        } catch (CanonicalisationFailed $e) {
            self::assertSame('https:///nohost', UrlCanonicalizer::fallback('  HTTPS:///NoHost '));
        }
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://bad host.example.com/a story');
        $this->item('a', ' HTTPS://BAD HOST.example.com/A STORY ');
        $this->plugin->recorder->flush();
        self::assertSame(2, $this->counter('canonical_failed'));
        self::assertSame(1, $this->rows(Schema::FETCHES)[0]['fresh'], 'fallback forms match');
    }

    /** Test 26 (case 28). */
    public function testSeenKeyPastRetentionIsPrunedAndItemCountsFreshAgain(): void
    {
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/old');
        $this->plugin->recorder->flush();
        $this->clock->advance(10 * 86400);
        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/recent');
        $this->plugin->recorder->flush();
        $this->clock->advance(21 * 86400);
        $this->plugin->checker->maintenance(true);
        $keys = $this->rows(Schema::SEEN);
        self::assertCount(1, $keys, 'only the key seen within 30 days stays');

        $this->fetch('a', 'ok', [], false);
        $this->item('a', 'https://news.example.com/old');
        $this->plugin->recorder->flush();
        $rows = $this->rows(Schema::FETCHES);
        self::assertSame(1, end($rows)['fresh']);
        self::assertSame(1, end($rows)['accepted']);
    }

    /** Test 32 (case 35). */
    public function testFeedKeyIsStableAcrossSchemeAndWwwAndDiffersByQuery(): void
    {
        $core = $this->plugin->core;
        self::assertSame($core->feedKey('https://www.example.com/feed/'), $core->feedKey('http://example.com/feed'));
        self::assertSame($core->feedKey('https://example.com/feed?b=2&a=1'), $core->feedKey('https://example.com/feed?a=1&b=2'));
        self::assertNotSame($core->feedKey('https://example.com/feed?cat=1'), $core->feedKey('https://example.com/feed?cat=2'));
        self::assertNotSame($core->feedKey('https://example.com/feed'), $core->feedKey('https://example.com/feed?utm_source=x'), 'feed URLs keep every parameter');
    }

    /** Test 74, unit part (case 84). */
    public function testRedactionRemovesUserInfoAndSecretParameters(): void
    {
        $r = new Redactor();
        $url = 'https://user:pass@feeds.example.com/rss?apiKey=abc123&cat=world&access_token=t0k&Signature=zz#x';
        $out = $r->redact($url);
        self::assertSame('https://feeds.example.com/rss?apiKey=***&cat=world&access_token=***&Signature=***#x', $out);
        self::assertSame('see https://feeds.example.com/x?token=*** now', $r->redactText('see https://feeds.example.com/x?token=abc now'));
        add_filter('feed_health_redact_params', static fn (array $p): array => array_merge($p, ['client']));
        self::assertSame('https://e.com/?client=***', $r->redact('https://e.com/?client=me'));
    }

    public function testRegistrableHost(): void
    {
        self::assertSame('example.com', UrlCanonicalizer::registrableHost('https://news.example.com/x'));
        self::assertSame('example.co.uk', UrlCanonicalizer::registrableHost('https://www.example.co.uk/x'));
        self::assertSame('example.co.in', UrlCanonicalizer::registrableHost('https://feeds.example.co.in/'));
    }
}
