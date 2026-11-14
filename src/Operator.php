<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;

/**
 * Operator actions on one feed. Each writes an `operator` event with the
 * user id. Shared by the dashboard and WP-CLI.
 */
final class Operator
{
    public const TEST_FETCH_INTERVAL = 300;
    public const SNOOZE_CHOICES = ['1h' => 3600, '1d' => 86400, '1w' => 604800];

    public function __construct(
        private Repository $repository,
        private AdapterRegistry $adapters,
        private Clock $clock
    ) {
    }

    /**
     * @return array<string, int|string|null>
     * @throws InvalidValue
     */
    public function feed(int $feedId): array
    {
        $feed = $this->repository->feed($feedId);
        if ($feed === null) {
            throw new InvalidValue('No such feed.');
        }

        return $feed;
    }

    /**
     * @return array<string, int|string|null>
     * @throws InvalidValue
     */
    public function find(string $adapter, string $feedKey): array
    {
        $feed = $this->repository->findFeed($adapter, $feedKey);
        if ($feed === null) {
            throw new InvalidValue('No such feed.');
        }

        return $feed;
    }

    public function mute(int $feedId, int $userId): void
    {
        $feed = $this->feed($feedId);
        $now = $this->clock->now();
        $this->repository->updateFeed($feedId, [
            'muted' => 1,
            'status' => StatusEvaluator::PAUSED,
            'reason' => 'muted by the operator',
            'status_since' => $now,
        ]);
        $this->log($feed, $userId, 'muted');
    }

    public function unmute(int $feedId, int $userId): void
    {
        $feed = $this->feed($feedId);
        $this->repository->updateFeed($feedId, ['muted' => 0, 'status' => StatusEvaluator::NEW, 'reason' => 'unmuted; waiting for the next check', 'status_since' => $this->clock->now()]);
        $this->log($feed, $userId, 'unmuted');
    }

    /**
     * Suppress alerts for a period: "1h", "1d", "1w", or a number of seconds.
     */
    public function snooze(int $feedId, int $userId, string $for): int
    {
        $seconds = self::duration($for);
        $feed = $this->feed($feedId);
        $until = $this->clock->now() + $seconds;
        $this->repository->updateFeed($feedId, ['snoozed_until' => $until]);
        $this->log($feed, $userId, 'snoozed until ' . gmdate('Y-m-d H:i', $until) . ' UTC');

        return $until;
    }

    public function unsnooze(int $feedId, int $userId): void
    {
        $feed = $this->feed($feedId);
        $this->repository->updateFeed($feedId, ['snoozed_until' => null]);
        $this->log($feed, $userId, 'snooze cleared');
    }

    /**
     * Forget learned cadence and seen keys. History rows stay.
     */
    public function resetBaseline(int $feedId, int $userId): void
    {
        $feed = $this->feed($feedId);
        $this->repository->clearSeen($feedId);
        $this->repository->updateFeed($feedId, [
            'baseline_since' => $this->clock->now(),
            'usual_gap' => null,
            'quiet_threshold' => null,
            'threshold_source' => 'reset by the operator',
        ]);
        $this->log($feed, $userId, 'baseline reset');
    }

    /**
     * Remove a feed and every row that belongs to it. Needs $confirmed.
     *
     * @throws InvalidValue
     */
    public function forget(int $feedId, int $userId, bool $confirmed): void
    {
        if (!$confirmed) {
            throw new InvalidValue('Forgetting a feed needs confirmation.');
        }
        $feed = $this->feed($feedId);
        $this->repository->deleteFeed($feedId);
        $this->repository->addEvent(
            $this->clock->now(),
            'operator',
            sprintf('forgot feed %s (%s/%s)', (string) $feed['label'], (string) $feed['adapter'], (string) $feed['feed_key']),
            null,
            $userId
        );
    }

    /**
     * Store per-feed fixed values. Null clears a value.
     */
    public function setThresholds(int $feedId, int $userId, ?int $quietHours, ?int $failuresInRow): void
    {
        $feed = $this->feed($feedId);
        $overrides = [];
        if ($quietHours !== null) {
            if ($quietHours < 1 || $quietHours > 2160) {
                throw new InvalidValue('Quiet threshold must be 1 to 2160 hours.');
            }
            $overrides['quiet_hours'] = $quietHours;
        }
        if ($failuresInRow !== null) {
            if ($failuresInRow < 1 || $failuresInRow > 50) {
                throw new InvalidValue('Failures in a row must be 1 to 50.');
            }
            $overrides['failures_in_row'] = $failuresInRow;
        }
        $this->repository->updateFeed($feedId, ['overrides' => $overrides === [] ? null : (string) wp_json_encode($overrides)]);
        $this->log($feed, $userId, 'thresholds set: ' . ($overrides === [] ? 'cleared' : (string) wp_json_encode($overrides)));
    }

    /**
     * Fetch one feed now through its adapter, rate-limited per feed.
     *
     * @return array{outcome: string, http_status: int|null, items: int, message: string}
     * @throws InvalidValue
     */
    public function testFetch(int $feedId, int $userId): array
    {
        $feed = $this->feed($feedId);
        $adapter = $this->adapters->get((string) $feed['adapter']);
        if ($adapter === null || !$this->adapters->isAvailable((string) $feed['adapter']) || !$adapter->supportsTestFetch()) {
            throw new InvalidValue('This feed\'s adapter does not support test fetch.');
        }
        $now = $this->clock->now();
        $last = $feed['last_manual_at'] === null ? null : (int) $feed['last_manual_at'];
        if ($last !== null && $now - $last < self::TEST_FETCH_INTERVAL && $now >= $last) {
            throw new InvalidValue(sprintf('Test fetch is limited to once per feed per %d minutes. Try again in %d s.', intdiv(self::TEST_FETCH_INTERVAL, 60), self::TEST_FETCH_INTERVAL - ($now - $last)));
        }
        $this->repository->updateFeed($feedId, ['last_manual_at' => $now]);
        $descriptor = new FeedDescriptor((string) $feed['adapter'], (string) $feed['feed_key'], (string) $feed['url'], (string) $feed['label']);
        $result = $adapter->testFetch($descriptor);
        $message = $result->outcome . ($result->httpStatus !== null ? ' (HTTP ' . $result->httpStatus . ')' : '') . ', ' . $result->items . ' items';
        $this->log($feed, $userId, 'test fetch: ' . $message);

        return ['outcome' => $result->outcome, 'http_status' => $result->httpStatus, 'items' => $result->items, 'message' => $message];
    }

    public static function duration(string $for): int
    {
        $for = strtolower(trim($for));
        if (isset(self::SNOOZE_CHOICES[$for])) {
            return self::SNOOZE_CHOICES[$for];
        }
        if (preg_match('/^(\d+)\s*([mhdw]?)$/', $for, $m) === 1) {
            $n = (int) $m[1];
            $seconds = match ($m[2]) {
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                'w' => $n * 604800,
                default => $n,
            };
            if ($seconds >= 60 && $seconds <= 90 * 86400) {
                return $seconds;
            }
        }
        throw new InvalidValue('Duration must be 1h, 1d, 1w, or a number with m, h, d or w, up to 90 days.');
    }

    /**
     * @param array<string, int|string|null> $feed
     */
    private function log(array $feed, int $userId, string $what): void
    {
        $this->repository->addEvent($this->clock->now(), 'operator', sprintf('%s: %s', (string) $feed['label'], $what), (int) $feed['id'], $userId);
    }
}
