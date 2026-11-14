<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Internal state in one non-autoloaded option: schema version, per-day
 * counters (kept 30 days), check bookkeeping and the email cap tally.
 *
 * Counter increments are buffered in memory and written by flush(), so a
 * recording call does not write the option once per item.
 */
final class State
{
    public const OPTION = 'feed_health_state';
    public const KEEP_DAYS = 30;

    /** Counter names shown on the dashboard and in `status --format=json`. */
    public const COUNTERS = [
        'fetches_recorded',
        'items_recorded',
        'invalid_input',
        'internal_error',
        'canonical_failed',
        'bad_date',
        'emails_sent',
        'emails_failed',
        'emails_held',
        'checks_run',
        'checks_skipped',
        'tables_missing',
        'buffer_flushes',
    ];

    /** @var array<string, int> */
    private array $pending = [];

    public function __construct(private Clock $clock)
    {
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $value = get_option(self::OPTION, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data): void
    {
        update_option(self::OPTION, $data, false);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->load()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $data = $this->load();
        $data[$key] = $value;
        $this->store($data);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): void
    {
        $data = array_merge($this->load(), $values);
        $this->store($data);
    }

    public function increment(string $counter, int $by = 1): void
    {
        $this->pending[$counter] = ($this->pending[$counter] ?? 0) + $by;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Write buffered counter increments. Never throws.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        $data = $this->load();
        $counters = isset($data['counters']) && is_array($data['counters']) ? $data['counters'] : [];
        $day = gmdate('Y-m-d', $this->clock->now());
        $today = isset($counters[$day]) && is_array($counters[$day]) ? $counters[$day] : [];
        foreach ($pending as $name => $by) {
            $today[$name] = (int) ($today[$name] ?? 0) + $by;
        }
        $counters[$day] = $today;
        $cutoff = gmdate('Y-m-d', $this->clock->now() - self::KEEP_DAYS * 86400);
        foreach (array_keys($counters) as $key) {
            if ((string) $key < $cutoff) {
                unset($counters[$key]);
            }
        }
        ksort($counters);
        $data['counters'] = $counters;
        $this->store($data);
    }

    /**
     * Counters per UTC day, including buffered increments for today.
     *
     * @return array<string, array<string, int>>
     */
    public function counters(): array
    {
        $data = $this->load();
        $counters = isset($data['counters']) && is_array($data['counters']) ? $data['counters'] : [];
        $out = [];
        foreach ($counters as $day => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $name => $n) {
                $out[(string) $day][(string) $name] = (int) $n;
            }
        }
        $day = gmdate('Y-m-d', $this->clock->now());
        foreach ($this->pending as $name => $by) {
            $out[$day][$name] = ($out[$day][$name] ?? 0) + $by;
        }

        return $out;
    }

    /**
     * Sum of one counter over the kept days, or over today only.
     */
    public function counter(string $name, bool $todayOnly = false): int
    {
        $sum = 0;
        $today = gmdate('Y-m-d', $this->clock->now());
        foreach ($this->counters() as $day => $values) {
            if ($todayOnly && $day !== $today) {
                continue;
            }
            $sum += $values[$name] ?? 0;
        }

        return $sum;
    }
}
