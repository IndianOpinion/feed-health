<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Exception\CanonicalisationFailed;
use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Value\FetchResult;
use IndianOpinion\FeedHealth\Value\ItemObservation;

/**
 * Receives runs, fetches and items from adapters and importers.
 *
 * Fetches and items are buffered in memory and written when the run ends,
 * when the buffer reaches the flush cap (500 rows), or on shutdown. Run
 * starts are written at once so a run that dies is still seen. No public
 * method throws: every error is caught, counted and logged once per
 * request, and the importer carries on.
 */
final class Recorder
{
    public const FLUSH_CAP = 500;
    public const FUTURE_SLACK = 86400;
    public const TITLE_MAX = 200;

    /**
     * Buffered fetches in arrival order.
     *
     * @var list<array{
     *     adapter: string,
     *     feed_key: string,
     *     result: FetchResult|null,
     *     started: int,
     *     db_id: int|null,
     *     feed_id: int|null,
     *     items: list<array{key: string, outcome: string, title: string, published: int|null}>,
     *     keys: array<string, true>,
     *     rejected: int,
     *     observed: int,
     *     label: string,
     *     url: string
     * }>
     */
    private array $buffer = [];

    /**
     * Index into $buffer of the open fetch per feed.
     *
     * @var array<string, int>
     */
    private array $open = [];

    private int $rows = 0;

    /** @var array<string, bool> */
    private array $knownRuns = [];

    public function __construct(
        private Settings $settings,
        private State $state,
        private Repository $repository,
        private AdapterRegistry $adapters,
        private UrlCanonicalizer $canonicalizer,
        private Clock $clock,
        private Logger $logger
    ) {
    }

    /**
     * Start a run. Returns the run id (generated when none is given).
     */
    public function beginRun(string $adapter, ?string $runId = null): string
    {
        $runId = self::runId($runId);
        if (!$this->settings->enabled()) {
            return $runId;
        }
        try {
            if (!$this->adapters->has($adapter)) {
                $this->invalid('run start for unregistered adapter "' . self::clip($adapter) . '"');

                return $runId;
            }
            if (!$this->tablesReady()) {
                return $runId;
            }
            $this->repository->startRun($runId, $adapter, $this->clock->now());
            $this->knownRuns[$runId] = true;
        } catch (\Throwable $e) {
            $this->internal($e);
        }

        return $runId;
    }

    /**
     * End a run: flush the buffer, close the run row, and let the plugin
     * schedule an end-of-run check.
     */
    public function endRun(string $runId, string $status = 'ok'): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        try {
            $this->flush();
            if (!$this->tablesReady()) {
                return;
            }
            $status = preg_replace('/[^a-z0-9_-]/', '', strtolower($status)) ?: 'ok';
            $ended = $this->repository->endRun(self::runId($runId), substr($status, 0, 20), $this->clock->now());
            if ($ended) {
                do_action('feed_health_run_ended', $runId);
            }
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    public function recordFetch(FetchResult $result): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        try {
            if (!$this->adapters->has($result->adapter)) {
                $this->invalid('fetch for unregistered adapter "' . $result->adapter . '"');

                return;
            }
            $this->buffer[] = [
                'adapter' => $result->adapter,
                'feed_key' => $result->feedKey,
                'result' => $result,
                'started' => $result->startedAt,
                'db_id' => null,
                'feed_id' => null,
                'items' => [],
                'keys' => [],
                'rejected' => 0,
                'observed' => 0,
                'label' => (string) $result->feedLabel,
                'url' => (string) $result->feedUrl,
            ];
            $this->open[self::slot($result->adapter, $result->feedKey)] = array_key_last($this->buffer);
            $this->state->increment('fetches_recorded');
            $this->grow();
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    public function recordItem(ItemObservation $item): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        try {
            if (!$this->adapters->has($item->adapter)) {
                $this->invalid('item for unregistered adapter "' . $item->adapter . '"');

                return;
            }
            $slot = self::slot($item->adapter, $item->feedKey);
            if (!isset($this->open[$slot])) {
                $this->buffer[] = [
                    'adapter' => $item->adapter,
                    'feed_key' => $item->feedKey,
                    'result' => null,
                    'started' => $this->clock->now(),
                    'db_id' => null,
                    'feed_id' => null,
                    'items' => [],
                    'keys' => [],
                    'rejected' => 0,
                    'observed' => 0,
                    'label' => $item->source,
                    'url' => '',
                ];
                $this->open[$slot] = array_key_last($this->buffer);
                $this->grow();
            }
            $index = $this->open[$slot];
            $this->state->increment('items_recorded');

            $raw = apply_filters('feed_health_item_url', $item->url, [
                'url' => $item->url,
                'title' => $item->title,
                'source' => $item->source,
                'feed_key' => $item->feedKey,
                'adapter' => $item->adapter,
            ]);
            $raw = is_string($raw) ? trim($raw) : '';
            $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));
            if ($raw === '' || ($scheme !== 'http' && $scheme !== 'https')) {
                $noUrlKey = 'no_url:' . $raw;
                if (!isset($this->buffer[$index]['keys'][$noUrlKey])) {
                    $this->buffer[$index]['keys'][$noUrlKey] = true;
                    $this->buffer[$index]['rejected']++;
                    $this->buffer[$index]['observed']++;
                }

                return;
            }
            $key = $this->keyFor($raw);
            if (isset($this->buffer[$index]['keys'][$key])) {
                return;
            }
            $this->buffer[$index]['keys'][$key] = true;
            $this->buffer[$index]['observed']++;

            $published = $item->publishedAt;
            if ($published !== null && $published > $this->clock->now() + self::FUTURE_SLACK) {
                $published = null;
                $this->state->increment('bad_date');
            }
            $this->buffer[$index]['items'][] = [
                'key' => $key,
                'outcome' => $item->outcome,
                'title' => mb_substr($item->title, 0, self::TITLE_MAX),
                'published' => $published,
            ];
            $this->grow();
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    /**
     * Action handler: `feed_health_fetch` with a plain array.
     */
    public function onFetchAction(mixed $data): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        try {
            if (!is_array($data)) {
                throw new InvalidValue('Payload is not an array.');
            }
            $this->recordFetch(FetchResult::fromArray($data, $this->clock->now()));
        } catch (InvalidValue $e) {
            $this->invalid('fetch report: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    /**
     * Action handler: `feed_health_item` with a plain array.
     */
    public function onItemAction(mixed $data): void
    {
        if (!$this->settings->enabled()) {
            return;
        }
        try {
            if (!is_array($data)) {
                throw new InvalidValue('Payload is not an array.');
            }
            $this->recordItem(ItemObservation::fromArray($data));
        } catch (InvalidValue $e) {
            $this->invalid('item report: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    /**
     * Action handler: `feed_health_run_start` (adapter key, run id).
     */
    public function onRunStartAction(mixed $adapter = null, mixed $runId = null): void
    {
        if (!is_string($adapter) || $adapter === '') {
            if ($this->settings->enabled()) {
                $this->invalid('run start without adapter key');
            }

            return;
        }
        $this->beginRun($adapter, is_scalar($runId) ? (string) $runId : null);
    }

    /**
     * Action handler: `feed_health_run_end` (adapter key, run id, status).
     */
    public function onRunEndAction(mixed $adapter = null, mixed $runId = null, mixed $status = 'ok'): void
    {
        if (!is_scalar($runId) || (string) $runId === '') {
            if ($this->settings->enabled()) {
                $this->invalid('run end without run id');
            }

            return;
        }
        $this->endRun((string) $runId, is_string($status) ? $status : 'ok');
    }

    /**
     * Write everything buffered. Called on run end, at the flush cap and
     * on shutdown. Never throws.
     */
    public function flush(): void
    {
        try {
            if ($this->buffer === [] && !$this->state->hasPending()) {
                return;
            }
            if (!$this->settings->enabled()) {
                $this->discard();

                return;
            }
            if ($this->buffer !== []) {
                if (!$this->tablesReady()) {
                    $this->discard();
                    $this->state->flush();

                    return;
                }
                $this->state->increment('buffer_flushes');
                foreach (array_keys($this->buffer) as $index) {
                    try {
                        $this->writeEntry($index);
                    } catch (\Throwable $e) {
                        $this->internal($e);
                    }
                }
                $this->compact();
            }
            $this->state->flush();
        } catch (\Throwable $e) {
            $this->internal($e);
        }
    }

    /**
     * Number of buffered rows not yet written (fetches plus items).
     */
    public function pendingRows(): int
    {
        return $this->rows;
    }

    private function grow(): void
    {
        $this->rows++;
        if ($this->rows >= self::FLUSH_CAP) {
            $this->flush();
        }
    }

    /**
     * Drop the buffer without writing it.
     */
    private function discard(): void
    {
        $this->buffer = [];
        $this->open = [];
        $this->rows = 0;
    }

    /**
     * After a flush, keep only the open fetch per feed (already written,
     * with its keys) so later items in the same request attach to it.
     */
    private function compact(): void
    {
        $kept = [];
        $open = [];
        foreach ($this->open as $slot => $index) {
            $entry = $this->buffer[$index] ?? null;
            if ($entry === null || $entry['db_id'] === null) {
                continue;
            }
            $entry['items'] = [];
            $kept[] = $entry;
            $open[$slot] = array_key_last($kept);
        }
        $this->buffer = $kept;
        $this->open = $open;
        $this->rows = 0;
    }

    private function writeEntry(int $index): void
    {
        $entry = $this->buffer[$index];
        $now = $this->clock->now();
        $result = $entry['result'];

        if ($entry['feed_id'] === null) {
            $feed = $this->repository->findFeed($entry['adapter'], $entry['feed_key']);
            if ($feed === null) {
                $feed = $this->repository->createFeed($entry['adapter'], $entry['feed_key'], $entry['label'], $entry['url'], 'new', $now);
            } else {
                $set = [];
                if ($entry['label'] !== '' && $entry['label'] !== (string) $feed['label']) {
                    $set['label'] = mb_substr($entry['label'], 0, 255);
                }
                if ($entry['url'] !== '' && $entry['url'] !== (string) $feed['url']) {
                    $set['url'] = $entry['url'];
                }
                if ($set !== []) {
                    $this->repository->updateFeed((int) $feed['id'], $set);
                }
            }
            $entry['feed_id'] = (int) $feed['id'];
        }
        $feedId = $entry['feed_id'];
        $feed = $this->repository->feed($feedId) ?? ['id' => $feedId];

        $firstWrite = $entry['db_id'] === null;
        if ($firstWrite) {
            $outcome = $result !== null ? $result->outcome : Outcome::OK;
            $runId = $result?->runId;
            if ($runId !== null && !$this->runExists($runId)) {
                $runId = null;
            }
            $entry['db_id'] = $this->repository->insertFetch([
                'feed_id' => $feedId,
                'run_id' => $runId,
                'started_at' => $entry['started'],
                'finished_at' => $result !== null ? $result->finishedAt : max($entry['started'], $now),
                'recorded_at' => $now,
                'outcome' => $outcome,
                'http_status' => $result?->httpStatus,
                'final_url' => $result?->finalUrl,
                'error_code' => $result?->errorCode,
                'error_message' => $result?->errorMessage,
                'items' => max($result !== null ? $result->items : 0, $entry['observed']),
                'bytes' => $result?->bytes,
                'manual' => $result !== null && $result->manual ? 1 : 0,
                'fresh' => 0,
                'repeats' => 0,
                'accepted' => 0,
                'duplicates' => 0,
                'rejected' => 0,
            ]);
        }

        $counts = $this->resolveItems($feedId, $entry['items'], $now);
        $counts['rejected'] += $entry['rejected'];
        if (!$firstWrite) {
            $counts['items'] = count($entry['items']) + $entry['rejected'];
        }
        $entry['rejected'] = 0;
        $this->repository->incrementFetch($entry['db_id'], $counts);

        $this->updateFeedAfter($feed, $result, $entry, $counts, $firstWrite, $now);
        $this->buffer[$index] = $entry;
    }

    /**
     * Decide fresh, repeat and duplicate for each item and update the seen
     * keys. Returns counters for the fetch row.
     *
     * @param list<array{key: string, outcome: string, title: string, published: int|null}> $items
     * @return array<string, int>
     */
    private function resolveItems(int $feedId, array $items, int $now): array
    {
        $counts = ['items' => 0, 'fresh' => 0, 'repeats' => 0, 'accepted' => 0, 'duplicates' => 0, 'rejected' => 0];
        if ($items === []) {
            return $counts;
        }
        $keys = array_map(static fn (array $i): string => $i['key'], $items);
        $seen = $this->repository->seenBy($keys);
        $new = [];
        $repeat = [];
        foreach ($items as $item) {
            $owners = $seen[$item['key']] ?? [];
            if (isset($owners[$feedId])) {
                $counts['repeats']++;
                $repeat[] = $item['key'];
                continue;
            }
            $counts['fresh']++;
            $outcome = $item['outcome'];
            if ($outcome === ItemObservation::UNKNOWN) {
                $outcome = $owners !== [] ? ItemObservation::DUPLICATE : ItemObservation::ACCEPTED;
            }
            match ($outcome) {
                ItemObservation::ACCEPTED => $counts['accepted']++,
                ItemObservation::DUPLICATE => $counts['duplicates']++,
                default => $counts['rejected']++,
            };
            $new[] = $item['key'];
        }
        if ($new !== []) {
            $this->repository->addSeen($feedId, $new, $now);
        }
        if ($repeat !== []) {
            $this->repository->touchSeen($feedId, $repeat, $now);
        }

        return $counts;
    }

    /**
     * @param array<string, int|string|null> $feed
     * @param array{items: list<array{key: string, outcome: string, title: string, published: int|null}>, observed: int, started: int} $entry
     * @param array<string, int> $counts
     */
    private function updateFeedAfter(array $feed, ?FetchResult $result, array $entry, array $counts, bool $firstWrite, int $now): void
    {
        $feedId = (int) $feed['id'];
        $set = [];
        $notes = self::notes($feed['notes'] ?? null);
        $outcome = $result !== null ? $result->outcome : Outcome::OK;

        if ($firstWrite && $outcome !== Outcome::CACHED) {
            $set['last_attempt'] = $result !== null ? $result->startedAt : $now;
            $set['attempts_total'] = (int) ($feed['attempts_total'] ?? 0) + 1;
            $set['last_outcome'] = $outcome;
            if ($result !== null && $result->httpStatus !== null) {
                $set['last_http_status'] = $result->httpStatus;
            }
            if (Outcome::isSuccess($outcome)) {
                $set['last_success'] = $result !== null ? $result->finishedAt : $now;
                $set['last_error'] = null;
            } elseif ($result !== null) {
                $error = trim(($result->errorCode ?? '') . ' ' . ($result->errorMessage ?? ''));
                $set['last_error'] = $error !== '' ? mb_substr($error, 0, 400) : null;
            }
            if ($outcome === Outcome::EMPTY) {
                $streak = (int) ($feed['empty_streak'] ?? 0) + 1;
                $set['empty_streak'] = $streak;
                if ($streak >= 3) {
                    $notes['empty'] = 'feed returns no items (' . $streak . ' empty results in a row)';
                }
            } else {
                $set['empty_streak'] = 0;
                unset($notes['empty']);
            }
            if ($result !== null && $result->finalUrl !== null && $result->finalUrl !== '' && $result->finalUrl !== (string) ($feed['url'] ?? '')) {
                $set['final_url'] = $result->finalUrl;
                $set['redirect_status'] = $result->redirectStatus;
            } elseif (Outcome::isSuccess($outcome)) {
                $set['final_url'] = null;
                $set['redirect_status'] = null;
            }
            unset($notes['not_reached']);
        }

        if (Outcome::isSuccess($outcome) && $entry['items'] !== []) {
            $newest = null;
            foreach ($entry['items'] as $item) {
                if ($item['published'] !== null && ($newest === null || $item['published'] > $newest)) {
                    $newest = $item['published'];
                }
            }
            $set['newest_published_at'] = $newest;
        }

        if ($counts['accepted'] > 0) {
            $set['last_new_at'] = $now;
            $title = $this->newestAcceptedTitle($entry['items']);
            if ($title !== null) {
                $set['last_new_title'] = $title;
            }
        }

        $encoded = $notes === [] ? null : (string) wp_json_encode($notes);
        if ($encoded !== ($feed['notes'] ?? null)) {
            $set['notes'] = $encoded;
        }
        if ($set !== []) {
            $this->repository->updateFeed($feedId, $set);
        }
    }

    /**
     * The title of the newest accepted item: by published time when the
     * items carry dates, otherwise the first accepted item in feed order.
     *
     * @param list<array{key: string, outcome: string, title: string, published: int|null}> $items
     */
    private function newestAcceptedTitle(array $items): ?string
    {
        $best = null;
        foreach ($items as $item) {
            if ($item['outcome'] !== ItemObservation::ACCEPTED && $item['outcome'] !== ItemObservation::UNKNOWN) {
                continue;
            }
            if ($best === null || ($item['published'] !== null && ($best['published'] === null || $item['published'] > $best['published']))) {
                $best = $item;
            }
        }

        return $best === null ? null : mb_substr($best['title'], 0, self::TITLE_MAX);
    }

    private function keyFor(string $url): string
    {
        try {
            $canonical = $this->canonicalizer->canonical($url);
        } catch (CanonicalisationFailed $e) {
            $this->state->increment('canonical_failed');
            $canonical = UrlCanonicalizer::fallback($url);
        }
        $filtered = apply_filters('feed_health_canonical_url', $canonical, $url);
        if (is_string($filtered) && $filtered !== '') {
            $canonical = $filtered;
        }

        return UrlCanonicalizer::key($canonical);
    }

    private function runExists(string $runId): bool
    {
        if (!isset($this->knownRuns[$runId])) {
            $this->knownRuns[$runId] = $this->repository->run($runId) !== null;
        }

        return $this->knownRuns[$runId];
    }

    private function tablesReady(): bool
    {
        if ($this->repository->tablesExist()) {
            return true;
        }
        $this->state->increment('tables_missing');
        $this->logger->once('tables-missing', 'Tables are missing; recording is paused. Use the repair button on the Feed health screen.');

        return false;
    }

    private function invalid(string $message): void
    {
        $this->state->increment('invalid_input');
        $this->logger->once('invalid-input', 'Invalid input rejected: ' . $message);
    }

    private function internal(\Throwable $e): void
    {
        $this->state->increment('internal_error');
        $this->logger->once('internal-error', 'Internal error while recording: ' . get_class($e) . ': ' . $e->getMessage());
    }

    /**
     * @return array<string, string>
     */
    public static function notes(int|string|null $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    private static function slot(string $adapter, string $feedKey): string
    {
        return $adapter . "\0" . $feedKey;
    }

    private static function runId(?string $runId): string
    {
        $runId = $runId !== null ? trim($runId) : '';
        if ($runId === '' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $runId) !== 1) {
            return $runId === '' ? bin2hex(random_bytes(8)) : substr(hash('sha256', $runId), 0, 32);
        }

        return $runId;
    }

    private static function clip(string $text): string
    {
        return mb_substr(preg_replace('/[^\w .:-]/u', '', $text) ?? '', 0, 60);
    }
}
