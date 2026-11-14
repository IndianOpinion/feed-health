<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Admin;

use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Repository;

/**
 * Builds the rows the dashboard, the CSV export and `wp feed-health status`
 * show. Older days come from daily rollups, today from fetch rows.
 */
final class Dashboard
{
    public const PER_PAGE = 50;
    public const SORTABLE = [
        'label', 'adapter', 'status', 'last_attempt', 'last_success', 'last_http_status',
        'attempts_24h', 'failures_24h', 'yield_today', 'yield_7d', 'dup_rate_7d',
        'newest_published_age', 'last_new_age',
    ];

    public function __construct(private Plugin $plugin)
    {
    }

    /**
     * Every feed with its numbers.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $repo = $this->plugin->repository;
        $now = $this->plugin->clock->now();
        $todayStart = (int) strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
        $today = $repo->windowTotals($todayStart, $now + 1);
        $last24 = $repo->windowTotals($now - 86400, $now + 1);
        $days = [];
        for ($i = 13; $i >= 1; $i--) {
            $days[] = gmdate('Y-m-d', $todayStart - $i * 86400);
        }
        $rollups = [];
        foreach ($repo->daily($days[0]) as $row) {
            $rollups[(int) $row['feed_id']][(string) $row['day']] = $row;
        }

        $out = [];
        foreach ($repo->feeds() as $feed) {
            $id = (int) $feed['id'];
            $spark = [];
            $fresh7 = 0;
            $dup7 = 0;
            $yield7 = 0;
            foreach ($days as $i => $day) {
                $r = $rollups[$id][$day] ?? null;
                $accepted = $r === null ? 0 : (int) $r['accepted'];
                $spark[] = $accepted;
                if ($i >= 7) {
                    $yield7 += $accepted;
                    $fresh7 += $r === null ? 0 : (int) $r['fresh'];
                    $dup7 += $r === null ? 0 : (int) $r['duplicates'];
                }
            }
            $t = $today[$id] ?? Repository::emptyTotals();
            $spark[] = $t['accepted'];
            $yield7 += $t['accepted'];
            $fresh7 += $t['fresh'];
            $dup7 += $t['duplicates'];
            $w = $last24[$id] ?? Repository::emptyTotals();
            $age = static fn (int|string|null $time): ?int => $time === null ? null : max(0, $now - (int) $time);

            $out[] = [
                'id' => $id,
                'label' => (string) $feed['label'],
                'adapter' => (string) $feed['adapter'],
                'feed_key' => (string) $feed['feed_key'],
                'url' => (string) $feed['url'],
                'final_url' => $feed['final_url'] === null ? null : (string) $feed['final_url'],
                'redirect_status' => $feed['redirect_status'] === null ? null : (int) $feed['redirect_status'],
                'status' => (string) $feed['status'],
                'reason' => (string) $feed['reason'],
                'muted' => (int) $feed['muted'] === 1,
                'snoozed_until' => $feed['snoozed_until'] === null ? null : (int) $feed['snoozed_until'],
                'last_attempt' => $feed['last_attempt'] === null ? null : (int) $feed['last_attempt'],
                'last_success' => $feed['last_success'] === null ? null : (int) $feed['last_success'],
                'last_http_status' => $feed['last_http_status'] === null ? null : (int) $feed['last_http_status'],
                'attempts_24h' => $w['attempts'],
                'failures_24h' => $w['failures'],
                'yield_today' => $t['accepted'],
                'yield_7d' => $yield7,
                'fresh_7d' => $fresh7,
                'duplicates_7d' => $dup7,
                'dup_rate_7d' => $fresh7 > 0 ? round($dup7 * 100 / $fresh7, 1) : null,
                'newest_published_age' => $age($feed['newest_published_at']),
                'last_new_age' => $age($feed['last_new_at']),
                'last_new_title' => (string) ($feed['last_new_title'] ?? ''),
                'sparkline' => $spark,
                'notes' => (string) ($feed['notes'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Filter, search and sort rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $query Keys: status, adapter, s, orderby, order.
     * @return list<array<string, mixed>>
     */
    public function filter(array $rows, array $query): array
    {
        $status = is_string($query['status'] ?? null) ? $query['status'] : '';
        $adapter = is_string($query['adapter'] ?? null) ? $query['adapter'] : '';
        $search = is_string($query['s'] ?? null) ? strtolower(trim($query['s'])) : '';
        $rows = array_values(array_filter($rows, static function (array $row) use ($status, $adapter, $search): bool {
            if ($status !== '' && $row['status'] !== $status) {
                return false;
            }
            if ($adapter !== '' && $row['adapter'] !== $adapter) {
                return false;
            }
            if ($search !== '') {
                $hay = strtolower($row['label'] . ' ' . $row['url']);

                return str_contains($hay, $search);
            }

            return true;
        }));
        $orderby = is_string($query['orderby'] ?? null) && in_array($query['orderby'], self::SORTABLE, true) ? $query['orderby'] : 'status';
        $desc = strtolower(is_string($query['order'] ?? null) ? $query['order'] : 'asc') === 'desc';
        $rank = array_flip(StatusEvaluator::ALL);
        usort($rows, static function (array $a, array $b) use ($orderby, $desc, $rank): int {
            if ($orderby === 'status') {
                $cmp = [($rank[$a['status']] ?? 99), strtolower($a['label'])] <=> [($rank[$b['status']] ?? 99), strtolower($b['label'])];
            } elseif ($orderby === 'label' || $orderby === 'adapter') {
                $cmp = strcasecmp((string) $a[$orderby], (string) $b[$orderby]);
            } else {
                $x = $a[$orderby];
                $y = $b[$orderby];
                $cmp = $x === null && $y === null ? 0 : ($x === null ? 1 : ($y === null ? -1 : $x <=> $y));
            }

            return $desc ? -$cmp : $cmp;
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function counts(array $rows): array
    {
        $counts = array_fill_keys(StatusEvaluator::ALL, 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * CSV of the given rows. URLs are redacted.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        $columns = ['label', 'adapter', 'feed_key', 'url', 'status', 'reason', 'last_attempt', 'last_success', 'last_http_status',
            'attempts_24h', 'failures_24h', 'yield_today', 'yield_7d', 'dup_rate_7d', 'newest_published_age_s', 'last_new_age_s'];
        fputcsv($handle, $columns, ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [
                self::cell((string) $row['label']),
                $row['adapter'],
                self::cell((string) $row['feed_key']),
                $this->plugin->redactor->redact((string) $row['url']),
                $row['status'],
                self::cell($this->plugin->redactor->redactText((string) $row['reason'])),
                $row['last_attempt'] === null ? '' : gmdate('c', (int) $row['last_attempt']),
                $row['last_success'] === null ? '' : gmdate('c', (int) $row['last_success']),
                $row['last_http_status'] ?? '',
                $row['attempts_24h'],
                $row['failures_24h'],
                $row['yield_today'],
                $row['yield_7d'],
                $row['dup_rate_7d'] ?? '',
                $row['newest_published_age'] ?? '',
                $row['last_new_age'] ?? '',
            ];
            fputcsv($handle, $line, ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralise spreadsheet formulas in untrusted text cells.
     */
    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /**
     * A 14-day sparkline as inline SVG.
     *
     * @param list<int> $values
     */
    public static function sparkline(array $values): string
    {
        $w = 84;
        $h = 18;
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max(1, max($values));
        $points = [];
        foreach ($values as $i => $v) {
            $x = round($i * ($w - 2) / ($n - 1) + 1, 1);
            $y = round($h - 1 - ($v / $max) * ($h - 2), 1);
            $points[] = $x . ',' . $y;
        }
        $title = 'New items per day, last 14 days: ' . implode(', ', array_map('strval', $values));

        return '<svg class="feed-health-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr($title) . '">'
            . '<title>' . esc_html($title) . '</title>'
            . '<polyline fill="none" stroke="currentColor" stroke-width="1.5" points="' . esc_attr(implode(' ', $points)) . '"/></svg>';
    }
}
