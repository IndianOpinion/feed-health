<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Health;

use IndianOpinion\FeedHealth\Outcome;

/**
 * Pure status rules. Given a feed's metrics and thresholds, returns one
 * status and a reason built from the numbers.
 *
 * Precedence: paused, failing, unchecked, quiet, redundant, degraded, new,
 * healthy. A new feed can be failing or unchecked but never quiet or
 * redundant, because it has no baseline yet.
 */
final class StatusEvaluator
{
    public const PAUSED = 'paused';
    public const FAILING = 'failing';
    public const UNCHECKED = 'unchecked';
    public const QUIET = 'quiet';
    public const REDUNDANT = 'redundant';
    public const DEGRADED = 'degraded';
    public const NEW = 'new';
    public const HEALTHY = 'healthy';

    public const ALL = [
        self::PAUSED, self::FAILING, self::UNCHECKED, self::QUIET,
        self::REDUNDANT, self::DEGRADED, self::NEW, self::HEALTHY,
    ];

    /** Statuses that can alert. */
    public const ALERTING = [self::FAILING, self::UNCHECKED, self::QUIET, self::REDUNDANT];

    /**
     * @return array{status: string, reason: string, quiet: array{threshold: int, usual_gap: int|null, source: string}, unchecked_limit: int}
     */
    public function evaluate(Metrics $m, Thresholds $t): array
    {
        $quiet = Baseline::quietThreshold($m->gaps, $t->quietMultiplier, $t->quietFloor, $t->quietCeiling, $t->defaultQuiet, $t->fixedQuiet);
        $uncheckedLimit = Baseline::uncheckedLimit($m->intervals, $t->uncheckedFloor);
        $result = fn (string $status, string $reason): array => [
            'status' => $status,
            'reason' => $reason,
            'quiet' => $quiet,
            'unchecked_limit' => $uncheckedLimit,
        ];

        if ($m->muted) {
            return $result(self::PAUSED, 'muted by the operator');
        }
        if (!$m->enabled) {
            return $result(self::PAUSED, 'disabled in the importer');
        }

        $failing = $this->failing($m, $t);
        if ($failing !== null) {
            return $result(self::FAILING, $failing);
        }

        if ($m->lastAttempt === null) {
            return $result(self::UNCHECKED, 'never fetched');
        }
        $sinceAttempt = (int) $m->age($m->lastAttempt);
        if ($sinceAttempt > $uncheckedLimit) {
            return $result(self::UNCHECKED, sprintf(
                'no fetch attempt for %s; limit is %s',
                Duration::human($sinceAttempt),
                Duration::human($uncheckedLimit)
            ));
        }

        $isNew = $m->attemptsTotal < $t->minHistoryAttempts || (int) $m->age($m->firstSeen) < $t->minHistorySeconds;

        if (!$isNew) {
            $sinceNew = (int) $m->age($m->lastNewAt ?? $m->firstSeen);
            if ($sinceNew > $quiet['threshold']) {
                $reason = sprintf('no new item for %s; ', Duration::human($sinceNew));
                $reason .= $quiet['usual_gap'] !== null && $t->fixedQuiet === null
                    ? sprintf('usual gap is %s', Duration::human($quiet['usual_gap']))
                    : sprintf('threshold is %s', Duration::human($quiet['threshold']));

                return $result(self::QUIET, $reason);
            }
            if ($m->newestPublishedAt !== null) {
                $published = (int) $m->age($m->newestPublishedAt);
                if ($published > $t->quietCeiling) {
                    return $result(self::QUIET, sprintf('feed content is frozen: newest item published %s ago', Duration::human($published)));
                }
            }
            if ($m->fresh7d >= $t->redundantMinFresh && $m->duplicates7d * 100 >= $t->redundantPercent * $m->fresh7d) {
                return $result(self::REDUNDANT, sprintf(
                    '%d%% of %d fresh items in 7 d were duplicates',
                    (int) floor($m->duplicates7d * 100 / $m->fresh7d),
                    $m->fresh7d
                ));
            }
        }

        $last = $m->recent[0] ?? null;
        if ($last !== null && Outcome::isFailure($last['outcome'])) {
            return $result(self::DEGRADED, 'last attempt failed: ' . self::describe($last));
        }
        if ($m->windowFailures > 0) {
            return $result(self::DEGRADED, sprintf('%d of %d attempts failed in %d h', $m->windowFailures, $m->windowAttempts, $t->windowHours));
        }

        if ($isNew) {
            return $result(self::NEW, sprintf(
                'learning: %d of %d attempts, %s of %s on record',
                min($m->attemptsTotal, $t->minHistoryAttempts),
                $t->minHistoryAttempts,
                Duration::human((int) $m->age($m->firstSeen)),
                Duration::human($t->minHistorySeconds)
            ));
        }

        // No ages in this reason: it would change at every check and force
        // a write per feed. The dashboard shows the ages in their own columns.
        return $result(self::HEALTHY, 'fetching and yielding as usual');
    }

    private function failing(Metrics $m, Thresholds $t): ?string
    {
        $n = $t->failuresInRow;
        $last = array_slice($m->recent, 0, $n);
        if (count($last) >= $n) {
            $all = true;
            foreach ($last as $attempt) {
                if (!Outcome::isFailure($attempt['outcome'])) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return sprintf('%d failures in a row: %s', $n, self::describe($last[0]));
            }
        }
        if (
            $m->windowAttempts >= $t->windowMinAttempts
            && $m->windowFailures * 100 >= $t->failureSharePercent * $m->windowAttempts
        ) {
            $detail = isset($m->recent[0]) && Outcome::isFailure($m->recent[0]['outcome']) ? ': ' . self::describe($m->recent[0]) : '';

            return sprintf('%d of %d attempts failed in %d h%s', $m->windowFailures, $m->windowAttempts, $t->windowHours, $detail);
        }

        return null;
    }

    /**
     * @param array{outcome: string, http_status: int|null, error_code: string|null} $attempt
     */
    public static function describe(array $attempt): string
    {
        return match ($attempt['outcome']) {
            Outcome::HTTP_ERROR => Outcome::describeHttp($attempt['http_status']) ?: 'HTTP error',
            Outcome::TRANSPORT_ERROR => 'no answer' . ($attempt['error_code'] !== null && $attempt['error_code'] !== '' ? ' (' . $attempt['error_code'] . ')' : ''),
            Outcome::PARSE_ERROR => 'not a feed' . ($attempt['http_status'] !== null ? ' (HTTP ' . $attempt['http_status'] . ' with no parsable feed)' : ''),
            default => 'failed' . ($attempt['error_code'] !== null && $attempt['error_code'] !== '' ? ' (' . $attempt['error_code'] . ')' : ''),
        };
    }
}
