<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Exception\StorageError;
use IndianOpinion\FeedHealth\Storage\Db;
use IndianOpinion\FeedHealth\Storage\Schema;

/**
 * Every read and write of the plugin's tables. Rows are plain arrays.
 *
 * @phpstan-type Row array<string, int|string|null>
 */
final class Repository
{
    public function __construct(private Db $db)
    {
    }

    public function db(): Db
    {
        return $this->db;
    }

    public function tablesExist(): bool
    {
        return $this->db->tablesExist(Schema::tables());
    }

    public function install(): void
    {
        $this->db->install(Schema::definitions());
    }

    public function dropAll(): void
    {
        $this->db->drop(Schema::tables());
    }

    // Feeds ------------------------------------------------------------

    /**
     * @return array<string, int|string|null>|null
     */
    public function findFeed(string $adapter, string $feedKey): ?array
    {
        $rows = $this->db->select(Schema::FEEDS, ['adapter' => $adapter, 'feed_key' => $feedKey], [], [], 1);

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function feed(int $id): ?array
    {
        $rows = $this->db->select(Schema::FEEDS, ['id' => $id], [], [], 1);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, int|string|null>>
     */
    public function feeds(array $where = []): array
    {
        return $this->db->select(Schema::FEEDS, $where, [], ['id' => 'ASC']);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function createFeed(string $adapter, string $feedKey, string $label, string $url, string $status, int $now): array
    {
        $row = [
            'adapter' => $adapter,
            'feed_key' => $feedKey,
            'label' => mb_substr($label !== '' ? $label : $feedKey, 0, 255),
            'url' => $url,
            'status' => $status,
            'reason' => $status === 'new' ? 'not enough history yet' : '',
            'status_since' => $now,
            'first_seen' => $now,
            'baseline_since' => $now,
        ];
        $row['id'] = $this->db->insert(Schema::FEEDS, $row);
        $fresh = $this->feed((int) $row['id']);

        return $fresh ?? $row;
    }

    /**
     * @param array<string, int|string|null> $set
     */
    public function updateFeed(int $id, array $set): void
    {
        $this->db->update(Schema::FEEDS, $set, ['id' => $id]);
    }

    /**
     * Remove a feed and every row that belongs to it.
     */
    public function deleteFeed(int $id): void
    {
        $this->db->delete(Schema::FETCHES, ['feed_id' => $id]);
        $this->db->delete(Schema::SEEN, ['feed_id' => $id]);
        $this->db->delete(Schema::DAILY, ['feed_id' => $id]);
        $this->db->delete(Schema::EVENTS, ['feed_id' => $id]);
        $this->db->delete(Schema::FEEDS, ['id' => $id]);
    }

    // Fetches ----------------------------------------------------------

    /**
     * @param array<string, int|string|null> $row
     */
    public function insertFetch(array $row): int
    {
        return $this->db->insert(Schema::FETCHES, $row);
    }

    /**
     * @param array<string, int> $amounts
     */
    public function incrementFetch(int $id, array $amounts): void
    {
        $amounts = array_filter($amounts, static fn (int $n): bool => $n !== 0);
        if ($amounts !== []) {
            $this->db->increment(Schema::FETCHES, $amounts, ['id' => $id]);
        }
    }

    /**
     * Most recent attempts first.
     *
     * @return list<array<string, int|string|null>>
     */
    public function recentFetches(int $feedId, int $limit, bool $nonCachedOnly = false): array
    {
        $where = ['feed_id' => $feedId];
        if ($nonCachedOnly) {
            $where['outcome !='] = Outcome::CACHED;
        }

        return $this->db->select(Schema::FETCHES, $where, [], ['started_at' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function fetchesBetween(int $from, int $until, ?int $feedId = null): array
    {
        $where = ['started_at >=' => $from, 'started_at <' => $until];
        if ($feedId !== null) {
            $where['feed_id'] = $feedId;
        }

        return $this->db->select(Schema::FETCHES, $where, [], ['started_at' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The fetch rows a status needs, oldest first: start time, outcome,
     * HTTP status, error code and accepted count. One indexed query.
     *
     * @return list<array<string, int|string|null>>
     */
    public function history(int $feedId, int $since): array
    {
        return $this->db->select(
            Schema::FETCHES,
            ['feed_id' => $feedId, 'started_at >=' => $since],
            ['started_at', 'outcome', 'http_status', 'error_code', 'accepted'],
            ['started_at' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * Per-feed totals over a window of fetch rows.
     *
     * @return array<int, array<string, int>>
     */
    public function windowTotals(int $from, int $until): array
    {
        $rows = $this->db->aggregate(
            Schema::FETCHES,
            ['started_at >=' => $from, 'started_at <' => $until],
            ['feed_id', 'outcome'],
            [
                'n' => ['COUNT', '*'],
                'items' => ['SUM', 'items'],
                'fresh' => ['SUM', 'fresh'],
                'accepted' => ['SUM', 'accepted'],
                'duplicates' => ['SUM', 'duplicates'],
                'rejected' => ['SUM', 'rejected'],
            ]
        );
        $out = [];
        foreach ($rows as $row) {
            $feedId = (int) $row['feed_id'];
            $outcome = (string) $row['outcome'];
            $t = $out[$feedId] ?? self::emptyTotals();
            $n = (int) $row['n'];
            if ($outcome !== Outcome::CACHED) {
                $t['attempts'] += $n;
            }
            if (Outcome::isFailure($outcome)) {
                $t['failures'] += $n;
                $bucket = match ($outcome) {
                    Outcome::HTTP_ERROR => 'http_errors',
                    Outcome::TRANSPORT_ERROR => 'transport_errors',
                    Outcome::PARSE_ERROR => 'parse_errors',
                    default => 'other_failures',
                };
                $t[$bucket] += $n;
            }
            foreach (['items', 'fresh', 'accepted', 'duplicates', 'rejected'] as $col) {
                $t[$col] += (int) $row[$col];
            }
            $out[$feedId] = $t;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    public static function emptyTotals(): array
    {
        return [
            'attempts' => 0, 'failures' => 0, 'http_errors' => 0, 'transport_errors' => 0,
            'parse_errors' => 0, 'other_failures' => 0, 'items' => 0, 'fresh' => 0,
            'accepted' => 0, 'duplicates' => 0, 'rejected' => 0,
        ];
    }

    public function lastFetchTime(): ?int
    {
        $rows = $this->db->aggregate(Schema::FETCHES, [], [], ['t' => ['MAX', 'recorded_at']]);
        $t = $rows[0]['t'] ?? null;

        return $t === null ? null : (int) $t;
    }

    public function earliestFetchTime(): ?int
    {
        $rows = $this->db->aggregate(Schema::FETCHES, [], [], ['t' => ['MIN', 'started_at']]);
        $t = $rows[0]['t'] ?? null;

        return $t === null ? null : (int) $t;
    }

    // Seen keys --------------------------------------------------------

    /**
     * Which feeds have seen each key.
     *
     * @param list<string> $keys
     * @return array<string, array<int, true>>
     */
    public function seenBy(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $rows = $this->db->select(Schema::SEEN, ['seen_key IN' => $chunk], ['feed_id', 'seen_key']);
            foreach ($rows as $row) {
                $out[(string) $row['seen_key']][(int) $row['feed_id']] = true;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     */
    public function addSeen(int $feedId, array $keys, int $now): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $key) {
                $rows[] = ['feed_id' => $feedId, 'seen_key' => $key, 'first_seen' => $now, 'last_seen' => $now];
            }
            $this->db->insertIgnore(Schema::SEEN, $rows);
        }
    }

    /**
     * @param list<string> $keys
     */
    public function touchSeen(int $feedId, array $keys, int $now): void
    {
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $this->db->update(Schema::SEEN, ['last_seen' => $now], ['feed_id' => $feedId, 'seen_key IN' => $chunk]);
        }
    }

    public function clearSeen(int $feedId): void
    {
        $this->db->delete(Schema::SEEN, ['feed_id' => $feedId]);
    }

    public function seenCount(?int $feedId = null): int
    {
        return $this->db->count(Schema::SEEN, $feedId === null ? [] : ['feed_id' => $feedId]);
    }

    // Runs -------------------------------------------------------------

    public function startRun(string $runId, string $adapter, int $now): void
    {
        $this->db->insertIgnore(Schema::RUNS, [[
            'run_id' => $runId,
            'adapter' => $adapter,
            'started_at' => $now,
            'ended_at' => null,
            'status' => 'running',
        ]]);
    }

    public function endRun(string $runId, string $status, int $now): bool
    {
        return $this->db->update(Schema::RUNS, ['status' => $status, 'ended_at' => $now], ['run_id' => $runId]) > 0;
    }

    public function markRun(string $runId, string $status): void
    {
        $this->db->update(Schema::RUNS, ['status' => $status], ['run_id' => $runId]);
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function run(string $runId): ?array
    {
        $rows = $this->db->select(Schema::RUNS, ['run_id' => $runId], [], [], 1);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function staleRuns(int $startedBefore): array
    {
        return $this->db->select(Schema::RUNS, ['status' => 'running', 'started_at <' => $startedBefore]);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function runs(int $since): array
    {
        return $this->db->select(Schema::RUNS, ['started_at >=' => $since], [], ['started_at' => 'DESC']);
    }

    /**
     * Feed ids with at least one fetch inside a window.
     *
     * @return array<int, true>
     */
    public function feedsFetchedBetween(int $from, int $until): array
    {
        $rows = $this->db->aggregate(Schema::FETCHES, ['started_at >=' => $from, 'started_at <=' => $until], ['feed_id'], ['n' => ['COUNT', '*']]);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['feed_id']] = true;
        }

        return $out;
    }

    // Events -----------------------------------------------------------

    /**
     * @param array<string, mixed>|null $data
     */
    public function addEvent(
        int $time,
        string $kind,
        string $message,
        ?int $feedId = null,
        ?int $userId = null,
        ?string $transitionId = null,
        int $sent = 0,
        ?array $data = null
    ): int {
        return $this->db->insert(Schema::EVENTS, [
            'created_at' => $time,
            'kind' => mb_substr($kind, 0, 20),
            'feed_id' => $feedId,
            'user_id' => $userId,
            'message' => mb_substr($message, 0, 2000),
            'transition_id' => $transitionId,
            'sent' => $sent,
            'data' => $data === null ? null : (string) wp_json_encode($data),
        ]);
    }

    /**
     * @param array<string, mixed> $where
     * @return list<array<string, int|string|null>>
     */
    public function events(array $where = [], int $limit = 100, int $offset = 0): array
    {
        return $this->db->select(Schema::EVENTS, $where, [], ['created_at' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    /**
     * @param array<string, mixed> $where
     */
    public function countEvents(array $where = []): int
    {
        return $this->db->count(Schema::EVENTS, $where);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function updateEvent(int $id, int $sent, ?array $data = null): void
    {
        $set = ['sent' => $sent];
        if ($data !== null) {
            $set['data'] = (string) wp_json_encode($data);
        }
        $this->db->update(Schema::EVENTS, $set, ['id' => $id]);
    }

    // Daily rollups ----------------------------------------------------

    /**
     * Replace the rollup rows of one UTC day with sums of its fetch rows.
     */
    public function rollupDay(string $day): int
    {
        $from = (int) strtotime($day . ' 00:00:00 UTC');
        $totals = $this->windowTotals($from, $from + 86400);
        $this->db->delete(Schema::DAILY, ['day' => $day]);
        $rows = [];
        foreach ($totals as $feedId => $t) {
            $rows[] = ['feed_id' => $feedId, 'day' => $day] + $t;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->insertIgnore(Schema::DAILY, $chunk);
        }

        return count($totals);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function daily(string $fromDay, ?int $feedId = null): array
    {
        $where = ['day >=' => $fromDay];
        if ($feedId !== null) {
            $where['feed_id'] = $feedId;
        }

        return $this->db->select(Schema::DAILY, $where, [], ['day' => 'ASC']);
    }

    // Retention --------------------------------------------------------

    /**
     * Delete rows past their retention.
     *
     * @param array<string, int> $days Keys: fetches, seen, daily, runs, events.
     * @return array<string, int> Rows deleted per table.
     * @throws StorageError
     */
    public function prune(array $days, int $now): array
    {
        $cut = static fn (string $k): int => $now - max(1, $days[$k] ?? 30) * 86400;

        return [
            'fetches' => $this->db->delete(Schema::FETCHES, ['started_at <' => $cut('fetches')]),
            'seen' => $this->db->delete(Schema::SEEN, ['last_seen <' => $cut('seen')]),
            'daily' => $this->db->delete(Schema::DAILY, ['day <' => gmdate('Y-m-d', $cut('daily'))]),
            'runs' => $this->db->delete(Schema::RUNS, ['started_at <' => $cut('runs'), 'status !=' => 'running']),
            'events' => $this->db->delete(Schema::EVENTS, ['created_at <' => $cut('events')]),
        ];
    }
}
