<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Plugin settings: one small autoloaded option, merged over defaults,
 * validated on save and filterable at read time. Nothing is cached in this
 * object, so the kill switch is always read at call time.
 */
final class Settings
{
    public const OPTION = 'feed_health_settings';

    /**
     * Numeric settings: key => [default, min, max].
     *
     * @var array<string, array{0: int|float, 1: int|float, 2: int|float}>
     */
    private const NUMBERS = [
        'failures_in_row' => [3, 1, 50],
        'failure_window_hours' => [24, 1, 168],
        'failure_window_min_attempts' => [4, 1, 1000],
        'failure_share_percent' => [50, 1, 100],
        'unchecked_floor_hours' => [6, 1, 720],
        'default_quiet_hours' => [48, 1, 2160],
        'quiet_multiplier' => [2.0, 1.0, 20.0],
        'quiet_floor_hours' => [6, 1, 720],
        'quiet_ceiling_hours' => [168, 1, 2160],
        'min_history_attempts' => [5, 0, 1000],
        'min_history_hours' => [24, 0, 720],
        'redundant_min_fresh' => [10, 1, 100000],
        'redundant_percent' => [90, 1, 100],
        'stale_run_hours' => [2, 1, 168],
        'cooldown_hours' => [24, 0, 720],
        'reminder_hours' => [72, 0, 2160],
        'daily_email_cap' => [10, 0, 1000],
        'retention_fetches_days' => [30, 15, 3650],
        'retention_seen_days' => [30, 1, 3650],
        'retention_daily_days' => [400, 8, 3650],
        'retention_runs_days' => [30, 1, 3650],
        'retention_events_days' => [90, 1, 3650],
    ];

    /**
     * @var array<string, bool>
     */
    private const BOOLEANS = [
        'enabled' => true,
        'core_adapter' => true,
        'core_passive' => false,
        'channel_email' => true,
        'channel_log' => true,
        'alert_failing' => true,
        'alert_unchecked' => true,
        'alert_quiet' => true,
        'alert_redundant' => false,
        'recovery' => true,
        'php_error_log' => false,
        'dashboard_widget' => true,
    ];

    public const INTERVALS = ['hourly', 'twicedaily', 'daily'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $out = self::BOOLEANS;
        foreach (self::NUMBERS as $key => [$default]) {
            $out[$key] = $default;
        }
        $out['check_interval'] = 'hourly';
        $out['recipients'] = [];

        return $out;
    }

    /**
     * Every setting after defaults, stored values and the settings filter.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $values = self::sanitize(is_array($stored) ? $stored : []);
        $filtered = apply_filters('feed_health_settings', $values);

        return self::sanitize(is_array($filtered) ? $filtered : $values);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    /**
     * Kill switch. A FEED_HEALTH_DISABLED constant set to true forces it off.
     */
    public function enabled(): bool
    {
        if (defined('FEED_HEALTH_DISABLED') && constant('FEED_HEALTH_DISABLED') === true) {
            return false;
        }

        return $this->bool('enabled');
    }

    /**
     * Email recipients: the stored list, or the site admin email.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $list = $this->get('recipients');
        $list = is_array($list) ? $list : [];
        if ($list === []) {
            $admin = (string) get_option('admin_email', '');
            $list = $admin !== '' && is_email($admin) !== false ? [$admin] : [];
        }
        $filtered = apply_filters('feed_health_alert_recipients', $list);
        $out = [];
        foreach (is_array($filtered) ? $filtered : $list as $email) {
            if (is_string($email) && is_email($email) !== false) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize($values), true);
    }

    /**
     * Validate and clamp. Unknown keys are dropped; missing keys take
     * their defaults.
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::BOOLEANS as $key => $default) {
            $out[$key] = array_key_exists($key, $input) ? self::toBool($input[$key]) : $default;
        }
        foreach (self::NUMBERS as $key => [$default, $min, $max]) {
            $value = $input[$key] ?? $default;
            $number = is_numeric($value) ? (is_float($default) ? (float) $value : (int) $value) : $default;
            $number = max($min, min($max, $number));
            $out[$key] = is_float($default) ? (float) $number : (int) $number;
        }
        if ($out['quiet_ceiling_hours'] < $out['quiet_floor_hours']) {
            $out['quiet_ceiling_hours'] = $out['quiet_floor_hours'];
        }
        $interval = $input['check_interval'] ?? 'hourly';
        $out['check_interval'] = is_string($interval) && in_array($interval, self::INTERVALS, true) ? $interval : 'hourly';
        $out['recipients'] = self::emails($input['recipients'] ?? []);

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function emails(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $email) {
            if (!is_string($email)) {
                continue;
            }
            $email = trim($email);
            if ($email !== '' && is_email($email) !== false) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
