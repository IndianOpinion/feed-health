<?php

/**
 * Example: an importer that reports through actions only, with no PHP
 * coupling to feed-health. If feed-health is not active, the do_action()
 * calls do nothing.
 */

declare(strict_types=1);

// Register an adapter key once. A plain array is enough.
add_filter('feed_health_adapters', static function (array $adapters): array {
    $adapters[] = ['key' => 'my-importer', 'label' => 'My importer'];

    return $adapters;
});

/**
 * Your importer's run, reduced to the reporting calls.
 *
 * @param array<string, string> $sources feed key => feed URL
 */
function my_importer_run(array $sources): void
{
    $runId = 'run-' . gmdate('YmdHis');
    do_action('feed_health_run_start', 'my-importer', $runId);

    foreach ($sources as $feedKey => $url) {
        $started = time();
        $response = wp_safe_remote_get($url, ['timeout' => 15]);
        $status = is_wp_error($response) ? null : (int) wp_remote_retrieve_response_code($response);
        $items = is_wp_error($response) ? [] : my_importer_parse((string) wp_remote_retrieve_body($response));

        do_action('feed_health_fetch', [
            'adapter' => 'my-importer',
            'feed_key' => $feedKey,
            'feed_url' => $url,
            'run_id' => $runId,
            'started_at' => $started,
            'finished_at' => time(),
            'outcome' => is_wp_error($response) ? 'transport_error' : ($status >= 400 ? 'http_error' : ($items === null ? 'parse_error' : ($items === [] ? 'empty' : 'ok'))),
            'http_status' => $status,
            'error_code' => is_wp_error($response) ? $response->get_error_code() : null,
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'items' => is_array($items) ? count($items) : 0,
        ]);

        foreach ($items ?? [] as $item) {
            $imported = my_importer_import($item);
            do_action('feed_health_item', [
                'adapter' => 'my-importer',
                'feed_key' => $feedKey,
                'url' => $item['link'],
                'title' => $item['title'],
                'source' => $item['source'],
                'published_at' => $item['date'],
                // Say what you did, or 'unknown' to let feed-health decide.
                'outcome' => $imported ? 'accepted' : 'duplicate',
            ]);
        }
    }

    do_action('feed_health_run_end', 'my-importer', $runId, 'ok');
}

/**
 * @return list<array{link: string, title: string, source: string, date: int|null}>|null Null when the body is not a feed.
 */
function my_importer_parse(string $body): ?array
{
    return str_contains($body, '<rss') || str_contains($body, '<feed') ? [] : null;
}

/**
 * @param array{link: string, title: string, source: string, date: int|null} $item
 */
function my_importer_import(array $item): bool
{
    return $item['link'] !== '';
}
