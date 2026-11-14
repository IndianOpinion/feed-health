<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Core;

use IndianOpinion\FeedHealth\Adapter;
use IndianOpinion\FeedHealth\Clock;
use IndianOpinion\FeedHealth\Outcome;
use IndianOpinion\FeedHealth\Recorder;
use IndianOpinion\FeedHealth\Repository;
use IndianOpinion\FeedHealth\Settings;
use IndianOpinion\FeedHealth\UrlCanonicalizer;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\FetchResult;
use IndianOpinion\FeedHealth\Value\ItemObservation;

/**
 * Reference adapter for WordPress core's fetch_feed().
 *
 * Wrapper mode: site code calls fetch() (or feed_health_fetch_feed()) in
 * place of fetch_feed(). The wrapper times the call, ties the HTTP response
 * core made for that URL to it through the `http_api_debug` action,
 * classifies the outcome, reports every item as `unknown`, and returns
 * exactly what fetch_feed() returned.
 *
 * Passive mode (setting, off by default): the `wp_feed_options` action
 * marks the start of every fetch_feed() call on the site. The SimplePie
 * object it passes is kept, and the call is finalised when the next
 * fetch_feed() starts or at shutdown. Items are counted, not keyed.
 */
final class CoreAdapter implements Adapter
{
    public const KEY = 'core';

    private ?Recorder $recorder = null;

    /**
     * Responses captured for the call in progress.
     *
     * @var list<array{url: string, response: mixed}>|null
     */
    private ?array $capture = null;

    private bool $listening = false;

    /**
     * The passive call awaiting finalisation.
     *
     * @var array{url: string, feed: object, started: float, responses: list<array{url: string, response: mixed}>}|null
     */
    private ?array $passive = null;

    public function __construct(
        private Settings $settings,
        private Repository $repository,
        private UrlCanonicalizer $canonicalizer,
        private Clock $clock
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'WordPress core feeds';
    }

    public function isAvailable(): bool
    {
        return function_exists('fetch_feed') && $this->settings->bool('core_adapter');
    }

    public function boot(Recorder $recorder): void
    {
        $this->recorder = $recorder;
        if ($this->settings->bool('core_passive')) {
            add_action('wp_feed_options', [$this, 'onFeedOptions'], 10, 2);
            add_action('shutdown', [$this, 'finalisePassive'], 1, 0);
        }
    }

    public function feeds(): iterable
    {
        $out = [];
        foreach ($this->repository->feeds(['adapter' => self::KEY]) as $feed) {
            $out[] = new FeedDescriptor(self::KEY, (string) $feed['feed_key'], (string) $feed['url'], (string) $feed['label'], (int) $feed['enabled'] === 1);
        }

        return $out;
    }

    public function supportsTestFetch(): bool
    {
        return true;
    }

    public function testFetch(FeedDescriptor $feed): FetchResult
    {
        [, $result] = $this->observe($feed->url, $feed->feedKey, $feed->label, true);
        if ($result === null) {
            $now = $this->clock->now();
            $result = new FetchResult(self::KEY, $feed->feedKey, $now, $now, Outcome::FAILURE_OTHER, null, null, 'not_recorded', 'feed-health is switched off or the core adapter is disabled.');
        }
        $this->recorder?->flush();

        return $result;
    }

    /**
     * Drop-in for fetch_feed(). Returns exactly what fetch_feed() returned.
     *
     * @param string|string[] $url
     * @return \SimplePie\SimplePie|\WP_Error|mixed
     */
    public function fetch(string|array $url, ?string $feedKey = null, ?string $label = null): mixed
    {
        if (is_array($url)) {
            return fetch_feed($url);
        }
        [$result] = $this->observe($url, $feedKey, $label, false);

        return $result;
    }

    /**
     * @return array{0: mixed, 1: FetchResult|null}
     */
    private function observe(string $url, ?string $feedKey, ?string $label, bool $manual): array
    {
        if ($this->recorder === null || !$this->settings->enabled() || !$this->isAvailable()) {
            return [fetch_feed($url), null];
        }
        $this->finalisePassive();
        $started = microtime(true);
        $previous = $this->capture;
        $this->capture = [];
        $this->listen();
        try {
            $result = fetch_feed($url);
        } finally {
            $responses = $this->capture ?? [];
            $this->capture = $previous;
        }

        $record = null;
        try {
            $record = $this->report($url, $feedKey, $label, $result, $responses, $started, $manual, true);
        } catch (\Throwable $e) {
            // The recorder counts its own errors; anything here is ours.
            unset($e);
        }

        return [$result, $record];
    }

    /**
     * `http_api_debug` listener: keep each response made while a wrapped
     * or passive fetch is in progress.
     */
    public function onHttpDebug(mixed $response, mixed $context = null, mixed $class = null, mixed $args = null, mixed $url = null): void
    {
        if ($context !== 'response') {
            return;
        }
        $entry = ['url' => is_string($url) ? $url : '', 'response' => $response];
        if ($this->capture !== null) {
            $this->capture[] = $entry;

            return;
        }
        if ($this->passive !== null) {
            $this->passive['responses'][] = $entry;
        }
    }

    /**
     * `wp_feed_options` listener (passive mode).
     */
    public function onFeedOptions(mixed $feed = null, mixed $url = null): void
    {
        if ($this->capture !== null || !is_object($feed) || !is_string($url) || $url === '') {
            return;
        }
        $this->finalisePassive();
        if (!$this->settings->enabled()) {
            return;
        }
        $this->listen();
        $this->passive = ['url' => $url, 'feed' => $feed, 'started' => microtime(true), 'responses' => []];
    }

    /**
     * Record the passive call in progress, if any. Runs at the next
     * fetch_feed() start and on shutdown.
     */
    public function finalisePassive(): void
    {
        $pending = $this->passive;
        $this->passive = null;
        if ($pending === null || $this->recorder === null) {
            return;
        }
        try {
            $feed = $pending['feed'];
            $error = method_exists($feed, 'error') ? $feed->error() : null;
            $result = is_string($error) && $error !== '' || (is_array($error) && $error !== [])
                ? new \WP_Error('simplepie-error', is_array($error) ? implode('; ', array_map('strval', $error)) : (string) $error)
                : $feed;
            $this->report($pending['url'], null, null, $result, $pending['responses'], $pending['started'], false, false);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    private function listen(): void
    {
        if (!$this->listening) {
            add_action('http_api_debug', [$this, 'onHttpDebug'], 10, 5);
            $this->listening = true;
        }
    }

    /**
     * The key of a feed URL: a hash of its canonical form with every query
     * parameter kept and sorted.
     */
    public function feedKey(string $url): string
    {
        try {
            return UrlCanonicalizer::key($this->canonicalizer->canonicalFeed($url));
        } catch (\Throwable $e) {
            return UrlCanonicalizer::key(UrlCanonicalizer::fallback($url));
        }
    }

    /**
     * @param list<array{url: string, response: mixed}> $responses
     */
    private function report(
        string $url,
        ?string $feedKey,
        ?string $label,
        mixed $result,
        array $responses,
        float $started,
        bool $manual,
        bool $withItems
    ): ?FetchResult {
        if ($this->recorder === null) {
            return null;
        }
        $key = $feedKey !== null && trim($feedKey) !== '' ? $feedKey : $this->feedKey($url);
        $c = self::classify($result, self::primary($url, $responses));
        $now = $this->clock->now();
        $startedAt = max(0, $now - (int) round(microtime(true) - $started));
        $feedTitle = '';
        $items = [];
        if (is_object($result) && !$result instanceof \WP_Error) {
            $feedTitle = method_exists($result, 'get_title') ? self::text($result->get_title()) : '';
            if (method_exists($result, 'get_items')) {
                $list = $result->get_items();
                $items = is_array($list) ? $list : [];
            }
        }
        $fetch = new FetchResult(
            self::KEY,
            $key,
            $startedAt,
            $now,
            $c['outcome'],
            $c['http_status'],
            $c['final_url'],
            $c['error_code'],
            $c['error_message'],
            $c['outcome'] === Outcome::PARSE_ERROR ? 0 : count($items),
            $c['bytes'],
            $manual,
            null,
            $url,
            $label !== null && $label !== '' ? $label : ($feedTitle !== '' ? $feedTitle : $url),
            $c['redirect_status']
        );
        $this->recorder->recordFetch($fetch);
        if (!$withItems) {
            return $fetch;
        }
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $date = method_exists($item, 'get_date') ? $item->get_date('U') : null;
            $this->recorder->recordItem(new ItemObservation(
                self::KEY,
                $key,
                method_exists($item, 'get_permalink') ? self::text($item->get_permalink()) : '',
                method_exists($item, 'get_title') ? self::text($item->get_title()) : '',
                $feedTitle,
                null,
                is_numeric($date) ? (int) $date : null
            ));
        }

        return $fetch;
    }

    /**
     * The response that belongs to the feed URL: an exact URL match, else
     * the first response made during the call.
     *
     * @param list<array{url: string, response: mixed}> $responses
     */
    private static function primary(string $url, array $responses): mixed
    {
        foreach ($responses as $entry) {
            if ($entry['url'] === $url) {
                return $entry['response'];
            }
        }

        return $responses[0]['response'] ?? null;
    }

    /**
     * Map a fetch_feed() result and the HTTP response behind it to an
     * outcome.
     *
     * @return array{outcome: string, http_status: int|null, error_code: string|null, error_message: string|null, final_url: string|null, redirect_status: int|null, bytes: int|null}
     */
    public static function classify(mixed $result, mixed $response): array
    {
        $out = ['outcome' => Outcome::OK, 'http_status' => null, 'error_code' => null, 'error_message' => null, 'final_url' => null, 'redirect_status' => null, 'bytes' => null];
        $status = null;
        if (is_array($response)) {
            $code = $response['response']['code'] ?? null;
            $status = is_numeric($code) ? (int) $code : null;
            $out['http_status'] = $status;
            $body = $response['body'] ?? null;
            $out['bytes'] = is_string($body) ? strlen($body) : null;
            [$out['final_url'], $out['redirect_status']] = self::redirect($response);
        }

        if ($result instanceof \WP_Error) {
            $out['error_code'] = (string) $result->get_error_code();
            $out['error_message'] = $result->get_error_message();
            if ($response instanceof \WP_Error) {
                $out['outcome'] = Outcome::TRANSPORT_ERROR;
                $out['error_code'] = (string) $response->get_error_code();
                $out['error_message'] = $response->get_error_message();
            } elseif ($status === null) {
                $out['outcome'] = Outcome::TRANSPORT_ERROR;
            } elseif ($status === 304) {
                $out['outcome'] = Outcome::NOT_MODIFIED;
            } elseif ($status >= 400) {
                $out['outcome'] = Outcome::HTTP_ERROR;
            } else {
                $out['outcome'] = Outcome::PARSE_ERROR;
            }

            return $out;
        }

        if ($response === null) {
            $out['outcome'] = Outcome::CACHED;

            return $out;
        }
        if ($response instanceof \WP_Error) {
            // Core answered from a stale cache after a transport failure.
            $out['outcome'] = Outcome::TRANSPORT_ERROR;
            $out['error_code'] = (string) $response->get_error_code();
            $out['error_message'] = $response->get_error_message();

            return $out;
        }
        if ($status === 304) {
            $out['outcome'] = Outcome::NOT_MODIFIED;
        } elseif ($status !== null && $status >= 400) {
            $out['outcome'] = Outcome::HTTP_ERROR;
        } else {
            $count = is_object($result) && method_exists($result, 'get_item_quantity') ? (int) $result->get_item_quantity() : 0;
            $out['outcome'] = $count === 0 ? Outcome::EMPTY : Outcome::OK;
        }

        return $out;
    }

    /**
     * Final URL and the first redirect status, when the response says.
     *
     * @param array<mixed> $response
     * @return array{0: string|null, 1: int|null}
     */
    private static function redirect(array $response): array
    {
        $http = $response['http_response'] ?? null;
        if (!is_object($http) || !method_exists($http, 'get_response_object')) {
            return [null, null];
        }
        $raw = $http->get_response_object();
        if (!is_object($raw)) {
            return [null, null];
        }
        $final = isset($raw->url) && is_string($raw->url) ? $raw->url : null;
        $status = null;
        $history = isset($raw->history) && is_array($raw->history) ? $raw->history : [];
        foreach ($history as $hop) {
            if (is_object($hop) && isset($hop->status_code) && is_numeric($hop->status_code)) {
                $code = (int) $hop->status_code;
                if ($status === null || $code === 301 || $code === 308) {
                    $status = $code;
                }
            }
        }
        if ($history === []) {
            return [null, null];
        }

        return [$final, $status];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }
}
