<?php

/**
 * Example: a full adapter for an importer you maintain. It lists the
 * importer's configured feeds (so feeds that are never fetched show as
 * "unchecked") and reports through the recorder.
 */

declare(strict_types=1);

namespace MySite\Import;

use IndianOpinion\FeedHealth\Adapter;
use IndianOpinion\FeedHealth\Recorder;
use IndianOpinion\FeedHealth\Value\FeedDescriptor;
use IndianOpinion\FeedHealth\Value\FetchResult;
use IndianOpinion\FeedHealth\Value\ItemObservation;

final class MyImporterAdapter implements Adapter
{
    private ?Recorder $recorder = null;

    public function key(): string
    {
        return 'my-importer';
    }

    public function label(): string
    {
        return 'My importer';
    }

    public function isAvailable(): bool
    {
        return function_exists('my_importer_sources');
    }

    public function boot(Recorder $recorder): void
    {
        $this->recorder = $recorder;
        add_action('my_importer_fetched', [$this, 'onFetched'], 10, 3);
    }

    /**
     * @param list<array{link: string, title: string}> $items
     */
    public function onFetched(string $sourceId, int $status, array $items): void
    {
        if ($this->recorder === null) {
            return;
        }
        $now = time();
        $this->recorder->recordFetch(new FetchResult('my-importer', $sourceId, $now, $now, $status >= 400 ? 'http_error' : 'ok', $status, null, null, null, count($items)));
        foreach ($items as $item) {
            $this->recorder->recordItem(new ItemObservation('my-importer', $sourceId, $item['link'], $item['title']));
        }
    }

    public function feeds(): iterable
    {
        foreach (my_importer_sources() as $id => $source) {
            yield new FeedDescriptor('my-importer', (string) $id, $source['url'], $source['name'], $source['active']);
        }
    }

    public function supportsTestFetch(): bool
    {
        return false;
    }

    public function testFetch(FeedDescriptor $feed): FetchResult
    {
        throw new \LogicException('Not supported.');
    }
}

\add_filter('feed_health_adapters', static function (array $adapters): array {
    $adapters[] = new MyImporterAdapter();

    return $adapters;
});
