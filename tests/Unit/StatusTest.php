<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Health\Baseline;
use IndianOpinion\FeedHealth\Health\Metrics;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Health\Thresholds;
use PHPUnit\Framework\TestCase;

/**
 * Section 4.6 and 4.7: status rules, precedence and the adaptive quiet
 * threshold, on the pure evaluator.
 */
final class StatusTest extends TestCase
{
    private const NOW = 1_767_225_600;
    private const H = 3600;
    private const D = 86400;

    /**
     * @param array<string, mixed> $o
     */
    private static function m(array $o = []): Metrics
    {
        $ok = ['outcome' => 'ok', 'http_status' => 200, 'error_code' => null];

        return new Metrics(
            self::NOW,
            $o['firstSeen'] ?? self::NOW - 30 * self::D,
            array_key_exists('lastAttempt', $o) ? $o['lastAttempt'] : self::NOW - 600,
            $o['attemptsTotal'] ?? 500,
            $o['recent'] ?? [$ok, $ok, $ok],
            $o['windowAttempts'] ?? 24,
            $o['windowFailures'] ?? 0,
            $o['intervals'] ?? array_fill(0, 100, self::H),
            $o['gaps'] ?? array_fill(0, 50, 2 * self::H),
            array_key_exists('lastNewAt', $o) ? $o['lastNewAt'] : self::NOW - self::H,
            $o['newestPublishedAt'] ?? null,
            $o['fresh7d'] ?? 100,
            $o['duplicates7d'] ?? 0,
            $o['muted'] ?? false,
            $o['enabled'] ?? true
        );
    }

    private static function failed(int $status = 403): array
    {
        return ['outcome' => 'http_error', 'http_status' => $status, 'error_code' => null];
    }

    private static function eval(Metrics $m, ?Thresholds $t = null): array
    {
        return (new StatusEvaluator())->evaluate($m, $t ?? new Thresholds());
    }

    public function testHealthy(): void
    {
        self::assertSame('healthy', self::eval(self::m())['status']);
    }

    /** Test 34 (case 37). */
    public function testPrecedence(): void
    {
        $both = self::m(['recent' => [self::failed(), self::failed(), self::failed()], 'lastNewAt' => self::NOW - 10 * self::D]);
        self::assertSame('failing', self::eval($both)['status']);

        $newQuiet = self::m(['firstSeen' => self::NOW - 2 * self::H, 'attemptsTotal' => 2, 'lastNewAt' => null]);
        self::assertSame('new', self::eval($newQuiet)['status']);

        $newRedundant = self::m(['firstSeen' => self::NOW - 2 * self::H, 'attemptsTotal' => 3, 'fresh7d' => 50, 'duplicates7d' => 50]);
        self::assertSame('new', self::eval($newRedundant)['status']);

        $newFailing = self::m(['firstSeen' => self::NOW - self::H, 'attemptsTotal' => 3, 'recent' => [self::failed(), self::failed(), self::failed()]]);
        self::assertSame('failing', self::eval($newFailing)['status']);

        self::assertSame('paused', self::eval(self::m(['muted' => true, 'recent' => [self::failed(), self::failed(), self::failed()]]))['status']);
        self::assertSame('paused', self::eval(self::m(['enabled' => false]))['status']);

        $quietAndRedundant = self::m(['lastNewAt' => self::NOW - 10 * self::D, 'fresh7d' => 50, 'duplicates7d' => 50]);
        self::assertSame('quiet', self::eval($quietAndRedundant)['status']);

        $redundantAndDegraded = self::m(['fresh7d' => 50, 'duplicates7d' => 50, 'recent' => [self::failed(500)]]);
        self::assertSame('redundant', self::eval($redundantAndDegraded)['status']);
    }

    /** Test 35 (case 38). */
    public function testFailingRules(): void
    {
        $ok = ['outcome' => 'ok', 'http_status' => 200, 'error_code' => null];
        self::assertSame('failing', self::eval(self::m(['recent' => [self::failed(), self::failed(), self::failed()]]))['status']);
        self::assertSame('degraded', self::eval(self::m(['recent' => [self::failed(), self::failed(), $ok]]))['status']);
        self::assertSame('failing', self::eval(self::m(['recent' => [$ok], 'windowAttempts' => 4, 'windowFailures' => 2]))['status']);
        self::assertSame('degraded', self::eval(self::m(['recent' => [$ok], 'windowAttempts' => 3, 'windowFailures' => 2]))['status'], 'half of 3 is below the 4-attempt minimum');
        self::assertSame('degraded', self::eval(self::m(['recent' => [$ok], 'windowAttempts' => 4, 'windowFailures' => 1]))['status']);
    }

    /** Test 36 (case 39). */
    public function testUncheckedRules(): void
    {
        // Median interval 1 h: limit is the 6 h floor.
        self::assertSame('healthy', self::eval(self::m(['lastAttempt' => self::NOW - 5 * self::H]))['status']);
        self::assertSame('unchecked', self::eval(self::m(['lastAttempt' => self::NOW - 7 * self::H]))['status']);
        // Median interval 4 h: limit is 12 h.
        $four = array_fill(0, 20, 4 * self::H);
        self::assertSame('healthy', self::eval(self::m(['intervals' => $four, 'lastAttempt' => self::NOW - 11 * self::H, 'gaps' => $four]))['status']);
        self::assertSame('unchecked', self::eval(self::m(['intervals' => $four, 'lastAttempt' => self::NOW - 13 * self::H, 'gaps' => $four]))['status']);
        // Fewer than 3 intervals: the floor alone.
        self::assertSame('unchecked', self::eval(self::m(['intervals' => [20 * self::H, 20 * self::H], 'lastAttempt' => self::NOW - 7 * self::H]))['status']);
        self::assertSame('unchecked', self::eval(self::m(['lastAttempt' => null]))['status']);
        self::assertSame(6 * self::H, Baseline::uncheckedLimit([self::H, self::H], 6 * self::H));
        self::assertSame(12 * self::H, Baseline::uncheckedLimit([4 * self::H, 4 * self::H, 4 * self::H], 6 * self::H));
    }

    /** Test 37 (cases 40, 44, 45). */
    public function testAdaptiveQuietThreshold(): void
    {
        $two = array_fill(0, 20, 2 * self::H);
        self::assertSame(6 * self::H, Baseline::quietThreshold($two, 2.0, 6 * self::H, 7 * self::D, 48 * self::H)['threshold']);
        self::assertSame('healthy', self::eval(self::m(['gaps' => $two, 'lastNewAt' => self::NOW - 5 * self::H]))['status']);
        self::assertSame('quiet', self::eval(self::m(['gaps' => $two, 'lastNewAt' => self::NOW - 7 * self::H]))['status']);

        $ten = array_fill(0, 20, 10 * self::H);
        self::assertSame(20 * self::H, Baseline::quietThreshold($ten, 2.0, 6 * self::H, 7 * self::D, 48 * self::H)['threshold']);
        $tenIntervals = array_fill(0, 20, self::H);
        self::assertSame('healthy', self::eval(self::m(['gaps' => $ten, 'intervals' => $tenIntervals, 'lastNewAt' => self::NOW - 19 * self::H]))['status']);
        self::assertSame('quiet', self::eval(self::m(['gaps' => $ten, 'intervals' => $tenIntervals, 'lastNewAt' => self::NOW - 21 * self::H]))['status']);

        $tenDays = array_fill(0, 6, 10 * self::D);
        $q = Baseline::quietThreshold($tenDays, 2.0, 6 * self::H, 7 * self::D, 48 * self::H);
        self::assertSame(7 * self::D, $q['threshold']);
        self::assertStringContainsString('ceiling', $q['source']);
        self::assertSame('quiet', self::eval(self::m(['gaps' => $tenDays, 'lastNewAt' => self::NOW - 7 * self::D - self::H]))['status']);
        self::assertSame('healthy', self::eval(self::m(['gaps' => $tenDays, 'lastNewAt' => self::NOW - 6 * self::D]))['status']);

        // The 90th percentile, not the median: a nightly lull stays inside the usual range.
        $day = array_merge(array_fill(0, 17, self::H), [8 * self::H, 8 * self::H, 8 * self::H]);
        self::assertSame(8 * self::H, Baseline::percentile($day, 90));
        self::assertSame(self::H, Baseline::median($day));
    }

    /** Test 38 (case 41). */
    public function testFrozenFeedContent(): void
    {
        $frozen = self::eval(self::m(['newestPublishedAt' => self::NOW - 8 * self::D, 'lastNewAt' => self::NOW - self::H]));
        self::assertSame('quiet', $frozen['status']);
        self::assertStringContainsString('feed content is frozen', $frozen['reason']);
        self::assertSame('healthy', self::eval(self::m(['newestPublishedAt' => null]))['status']);
        self::assertSame('healthy', self::eval(self::m(['newestPublishedAt' => self::NOW - 6 * self::D]))['status']);
    }

    /** Test 39 (case 42). */
    public function testRedundant(): void
    {
        self::assertSame('redundant', self::eval(self::m(['fresh7d' => 10, 'duplicates7d' => 9]))['status']);
        self::assertSame('healthy', self::eval(self::m(['fresh7d' => 9, 'duplicates7d' => 9]))['status']);
        self::assertSame('healthy', self::eval(self::m(['fresh7d' => 10, 'duplicates7d' => 8]))['status']);
    }

    /** Test 40 (case 43). */
    public function testReasonsCarryTheirNumbers(): void
    {
        self::assertSame('3 failures in a row: HTTP 403 blocked', self::eval(self::m(['recent' => [self::failed(), self::failed(), self::failed()]]))['reason']);
        $quiet = self::eval(self::m(['gaps' => array_fill(0, 20, 4 * self::H), 'intervals' => array_fill(0, 20, self::H), 'lastNewAt' => self::NOW - 31 * self::H]));
        self::assertSame('no new item for 31 h; usual gap is 4 h', $quiet['reason']);
        self::assertSame('no fetch attempt for 7 h; limit is 6 h', self::eval(self::m(['lastAttempt' => self::NOW - 7 * self::H]))['reason']);
        self::assertSame('90% of 10 fresh items in 7 d were duplicates', self::eval(self::m(['fresh7d' => 10, 'duplicates7d' => 9]))['reason']);
        $share = self::eval(self::m(['recent' => [self::failed(503)], 'windowAttempts' => 6, 'windowFailures' => 4]));
        self::assertSame('4 of 6 attempts failed in 24 h: HTTP 503 server error', $share['reason']);
        $transport = self::eval(self::m(['recent' => array_fill(0, 3, ['outcome' => 'transport_error', 'http_status' => null, 'error_code' => 'http_request_failed'])]));
        self::assertSame('3 failures in a row: no answer (http_request_failed)', $transport['reason']);
    }

    /** Test 41 (case 46): defaults and overrides. */
    public function testDefaultThresholdWithFewGapsAndOverrides(): void
    {
        $few = [self::H, self::H, self::H, self::H];
        $q = Baseline::quietThreshold($few, 2.0, 6 * self::H, 7 * self::D, 48 * self::H);
        self::assertSame(48 * self::H, $q['threshold']);
        self::assertNull($q['usual_gap']);
        self::assertStringContainsString('default', $q['source']);
        $fixed = Baseline::quietThreshold(array_fill(0, 20, self::H), 2.0, 6 * self::H, 7 * self::D, 48 * self::H, 3 * self::H);
        self::assertSame(3 * self::H, $fixed['threshold']);
        self::assertSame('per-feed fixed value', $fixed['source']);
        $t = Thresholds::fromArray(['fixedQuiet' => 3 * self::H]);
        self::assertSame('quiet', self::eval(self::m(['lastNewAt' => self::NOW - 4 * self::H]), $t)['status']);
    }
}
