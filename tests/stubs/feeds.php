<?php

/**
 * Fake feed objects with the SimplePie methods the core adapter calls.
 */

declare(strict_types=1);

final class FakeFeedItem
{
    public function __construct(private string $link, private string $title = '', private ?int $date = null)
    {
    }

    public function get_permalink(): string
    {
        return $this->link;
    }

    public function get_title(): string
    {
        return $this->title;
    }

    public function get_date(string $format = 'U'): ?string
    {
        return $this->date === null ? null : (string) $this->date;
    }
}

final class FakeFeed
{
    /** @param list<FakeFeedItem> $items */
    public function __construct(private array $items = [], private string $title = 'Fake feed', private string $error = '')
    {
    }

    /** @return list<FakeFeedItem> */
    public function get_items(int $start = 0, int $end = 0): array
    {
        return $this->items;
    }

    public function get_item_quantity(int $max = 0): int
    {
        return count($this->items);
    }

    public function get_title(): string
    {
        return $this->title;
    }

    public function error(): string
    {
        return $this->error;
    }
}

/**
 * Stands in for WP_HTTP_Requests_Response, for redirect history.
 */
final class FakeHttpResponse
{
    /** @param list<int> $hops */
    public function __construct(private string $finalUrl, private array $hops)
    {
    }

    public function get_response_object(): object
    {
        $history = [];
        foreach ($this->hops as $code) {
            $hop = new stdClass();
            $hop->status_code = $code;
            $history[] = $hop;
        }
        $raw = new stdClass();
        $raw->url = $this->finalUrl;
        $raw->history = $history;

        return $raw;
    }
}
