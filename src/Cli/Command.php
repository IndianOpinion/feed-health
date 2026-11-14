<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Cli;

use IndianOpinion\FeedHealth\Admin\Dashboard;
use IndianOpinion\FeedHealth\Core\CoreAdapter;
use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\State;

/**
 * Inspect and operate feed-health.
 *
 * Exit code 0 on success, 1 on error.
 */
final class Command
{
    private Dashboard $dashboard;

    public function __construct(private Plugin $plugin)
    {
        $this->dashboard = new Dashboard($plugin);
    }

    /**
     * Show every feed's status.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Only feeds with this status.
     *
     * [--adapter=<adapter>]
     * : Only feeds from this adapter.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * [--fail-on=<statuses>]
     * : Exit 1 when any listed feed has one of these comma-separated statuses.
     *
     * ## EXAMPLES
     *
     *     wp feed-health status --fail-on=failing,unchecked
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $rows = $this->dashboard->filter($this->dashboard->rows(), [
            'status' => $assoc['status'] ?? '',
            'adapter' => $assoc['adapter'] ?? '',
        ]);
        $format = $assoc['format'] ?? 'table';
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row['id'],
                'adapter' => $row['adapter'],
                'feed_key' => $row['feed_key'],
                'label' => $row['label'],
                'status' => $row['status'],
                'reason' => $this->plugin->redactor->redactText((string) $row['reason']),
                'last_http' => $row['last_http_status'] ?? '',
                'attempts_24h' => $row['attempts_24h'],
                'failures_24h' => $row['failures_24h'],
                'yield_7d' => $row['yield_7d'],
                'dup_rate_7d' => $row['dup_rate_7d'] ?? '',
                'url' => $this->plugin->redactor->redact((string) $row['url']),
            ];
        }
        if ($format === 'json') {
            $counters = [];
            foreach (State::COUNTERS as $name) {
                $counters[$name] = ['today' => $this->plugin->state->counter($name, true), 'days_30' => $this->plugin->state->counter($name)];
            }
            \WP_CLI::line((string) wp_json_encode([
                'counts' => Dashboard::counts($rows),
                'last_check' => (int) $this->plugin->state->get('last_check', 0),
                'counters' => $counters,
                'feeds' => $items,
            ]));
        } else {
            \WP_CLI\Utils\format_items($format, $items, ['id', 'adapter', 'label', 'status', 'reason', 'last_http', 'attempts_24h', 'failures_24h', 'yield_7d', 'dup_rate_7d']);
        }
        if (isset($assoc['fail-on']) && $assoc['fail-on'] !== '') {
            $fail = array_filter(array_map('trim', explode(',', $assoc['fail-on'])));
            foreach ($rows as $row) {
                if (in_array($row['status'], $fail, true)) {
                    \WP_CLI::halt(1);
                }
            }
        }
    }

    /**
     * Show one feed.
     *
     * ## OPTIONS
     *
     * <adapter>
     * : Adapter key.
     *
     * <feed-key>
     * : Feed key.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function feed(array $args, array $assoc): void
    {
        $feed = $this->find($args);
        $rows = array_values(array_filter($this->dashboard->rows(), static fn (array $r): bool => $r['id'] === (int) $feed['id']));
        $row = $rows[0] ?? [];
        $data = [
            'id' => (int) $feed['id'],
            'adapter' => (string) $feed['adapter'],
            'feed_key' => (string) $feed['feed_key'],
            'label' => (string) $feed['label'],
            'url' => $this->plugin->redactor->redact((string) $feed['url']),
            'status' => (string) $feed['status'],
            'reason' => $this->plugin->redactor->redactText((string) $feed['reason']),
            'quiet_threshold_s' => $feed['quiet_threshold'],
            'threshold_source' => (string) $feed['threshold_source'],
            'usual_gap_s' => $feed['usual_gap'],
            'last_attempt' => $feed['last_attempt'] === null ? '' : gmdate('c', (int) $feed['last_attempt']),
            'last_success' => $feed['last_success'] === null ? '' : gmdate('c', (int) $feed['last_success']),
            'last_http_status' => $feed['last_http_status'] ?? '',
            'attempts_24h' => $row['attempts_24h'] ?? 0,
            'failures_24h' => $row['failures_24h'] ?? 0,
            'yield_7d' => $row['yield_7d'] ?? 0,
            'dup_rate_7d' => $row['dup_rate_7d'] ?? '',
            'muted' => (int) $feed['muted'] === 1 ? 'yes' : 'no',
        ];
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode($data));

            return;
        }
        $items = [];
        foreach ($data as $field => $value) {
            $items[] = ['field' => $field, 'value' => is_scalar($value) || $value === null ? (string) $value : ''];
        }
        \WP_CLI\Utils\format_items('table', $items, ['field', 'value']);
    }

    /**
     * Run a check now.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Compute and print transitions; store and send nothing.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $dry = !empty($assoc['dry-run']);
        $summary = $this->plugin->checker->run($dry);
        foreach ($summary['transitions'] as $t) {
            $feed = is_array($t['feed'] ?? null) ? $t['feed'] : [];
            \WP_CLI::line(sprintf('%s: %s -> %s: %s', (string) ($feed['label'] ?? ''), (string) $t['from'], (string) $t['to'], $this->plugin->redactor->redactText((string) $t['reason'])));
        }
        if ($summary['status'] === 'error') {
            \WP_CLI::error($summary['message']);
        }
        \WP_CLI::success(sprintf(
            '%s%s: %d feeds, %d transitions, %d notices, %d ms.',
            $dry ? 'Dry run ' : 'Check ',
            $summary['status'],
            $summary['feeds'],
            count($summary['transitions']),
            $summary['notified'],
            $summary['duration_ms']
        ));
    }

    /**
     * Fetch one reference-adapter feed now and record the attempt.
     *
     * ## OPTIONS
     *
     * <feed-key>
     * : Feed key of a core adapter feed.
     *
     * @subcommand test-fetch
     * @param list<string> $args
     */
    public function test_fetch(array $args): void
    {
        $feed = $this->find([CoreAdapter::KEY, $args[0] ?? '']);
        try {
            $result = $this->plugin->operator->testFetch((int) $feed['id'], get_current_user_id());
        } catch (InvalidValue $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI::success('Test fetch: ' . $result['message'] . '.');
    }

    /**
     * Mute a feed: no alerts, status paused.
     *
     * <adapter>
     * : Adapter key.
     *
     * <feed-key>
     * : Feed key.
     *
     * @param list<string> $args
     */
    public function mute(array $args): void
    {
        $feed = $this->find($args);
        $this->plugin->operator->mute((int) $feed['id'], get_current_user_id());
        \WP_CLI::success('Muted.');
    }

    /**
     * Unmute a feed.
     *
     * <adapter>
     * : Adapter key.
     *
     * <feed-key>
     * : Feed key.
     *
     * @param list<string> $args
     */
    public function unmute(array $args): void
    {
        $feed = $this->find($args);
        $this->plugin->operator->unmute((int) $feed['id'], get_current_user_id());
        \WP_CLI::success('Unmuted.');
    }

    /**
     * Suppress alerts for a feed for a while.
     *
     * <adapter>
     * : Adapter key.
     *
     * <feed-key>
     * : Feed key.
     *
     * [--for=<duration>]
     * : 1h, 1d, 1w, or a number with m, h, d or w.
     * ---
     * default: 1d
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function snooze(array $args, array $assoc): void
    {
        $feed = $this->find($args);
        try {
            $until = $this->plugin->operator->snooze((int) $feed['id'], get_current_user_id(), $assoc['for'] ?? '1d');
        } catch (InvalidValue $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI::success('Snoozed until ' . gmdate('Y-m-d H:i', $until) . ' UTC.');
    }

    /**
     * Send a sample alert through every enabled channel.
     *
     * @subcommand test-alert
     */
    public function test_alert(): void
    {
        $results = $this->plugin->notifier->testAlert(get_current_user_id());
        foreach ($results as $channel => $result) {
            \WP_CLI::line($channel . ': ' . $result);
        }
        if (isset($results['email']) && str_starts_with($results['email'], 'failed')) {
            \WP_CLI::error('The email channel failed.');
        }
        \WP_CLI::success('Test alert sent.');
    }

    /**
     * Roll up finished days and delete rows past their retention now.
     */
    public function prune(): void
    {
        $deleted = $this->plugin->checker->maintenance(true);
        \WP_CLI::success('Pruned: ' . Notifier::resultsText(array_map('strval', $deleted)) . '.');
    }

    /**
     * List events, newest first.
     *
     * [--since=<time>]
     * : Only events after this time (anything strtotime() reads).
     *
     * [--kind=<kind>]
     * : transition, notify, mail_failed, operator, error or check.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function events(array $args, array $assoc): void
    {
        $where = [];
        if (isset($assoc['since'])) {
            $since = strtotime($assoc['since']);
            if ($since === false) {
                \WP_CLI::error('Unreadable --since.');

                return;
            }
            $where['created_at >='] = $since;
        }
        if (isset($assoc['kind'])) {
            $where['kind'] = $assoc['kind'];
        }
        $items = [];
        foreach ($this->plugin->repository->events($where, 500) as $e) {
            $items[] = [
                'time' => gmdate('Y-m-d H:i:s', (int) $e['created_at']),
                'kind' => (string) $e['kind'],
                'feed_id' => $e['feed_id'] ?? '',
                'user_id' => $e['user_id'] ?? '',
                'message' => $this->plugin->redactor->redactText((string) $e['message']),
            ];
        }
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $items, ['time', 'kind', 'feed_id', 'user_id', 'message']);
    }

    /**
     * @param list<string> $args
     * @return array<string, int|string|null>
     */
    private function find(array $args): array
    {
        if (count($args) < 2 || $args[0] === '' || $args[1] === '') {
            \WP_CLI::error('Give an adapter key and a feed key.');
        }
        $feed = $this->plugin->repository->findFeed((string) $args[0], (string) $args[1]);
        if ($feed === null) {
            \WP_CLI::error('No such feed.');
        }

        return $feed ?? [];
    }

    /**
     * Statuses a monitoring script can pass to --fail-on.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return StatusEvaluator::ALL;
    }
}
