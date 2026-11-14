<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Admin;

use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Health\Duration;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Operator;
use IndianOpinion\FeedHealth\Outcome;
use IndianOpinion\FeedHealth\Plugin;
use IndianOpinion\FeedHealth\Recorder;
use IndianOpinion\FeedHealth\Settings;
use IndianOpinion\FeedHealth\State;
use IndianOpinion\FeedHealth\UrlCanonicalizer;

/**
 * The Tools > Feed health screen: summary, feed table, feed detail, event
 * log and settings. Server-rendered, no external assets. Every action
 * checks the capability and a nonce; destructive ones need POST and a
 * confirmation box.
 */
final class AdminPage
{
    public const SLUG = 'feed-health';
    public const ACTION = 'feed_health_action';
    public const EXPORT = 'feed_health_export';

    /** Operations that change data: POST only. */
    private const POST_ONLY = ['mute', 'unmute', 'snooze', 'unsnooze', 'reset', 'forget', 'thresholds', 'test_fetch', 'check', 'repair', 'test_alert', 'network_cap'];

    private Dashboard $dashboard;

    public function __construct(private Plugin $plugin)
    {
        $this->dashboard = new Dashboard($plugin);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 10, 0);
        add_action('admin_init', [$this, 'registerSettings'], 10, 0);
        // options.php checks manage_options unless told otherwise: without
        // this, a site that filters the capability shows a settings form
        // its users cannot save.
        add_filter('option_page_capability_feed_health', [$this->plugin, 'capability'], 10, 0);
        add_action('admin_post_' . self::ACTION, [$this, 'handle'], 10, 0);
        add_action('admin_post_' . self::EXPORT, [$this, 'export'], 10, 0);
    }

    public function menu(): void
    {
        add_management_page('Feed health', 'Feed health', $this->plugin->capability(), self::SLUG, [$this, 'render']);
    }

    public function registerSettings(): void
    {
        register_setting('feed_health', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Settings::class, 'sanitize'],
            'default' => Settings::defaults(),
            'show_in_rest' => false,
        ]);
    }

    public function dashboard(): Dashboard
    {
        return $this->dashboard;
    }

    /**
     * Page callback.
     */
    public function render(): void
    {
        echo $this->html($_GET); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /**
     * The whole page for a request, or a refusal without the capability.
     *
     * @param array<mixed> $query
     */
    public function html(array $query): string
    {
        if (!current_user_can($this->plugin->capability())) {
            return '<div class="wrap"><p>' . esc_html('You do not have permission to view this page.') . '</p></div>';
        }
        $query = self::clean($query);
        $view = $query['view'] ?? '';
        $out = '<div class="wrap feed-health">' . $this->styles();
        $out .= '<h1>Feed health</h1>';
        $out .= $this->tabs($view, isset($query['feed']));
        if (isset($query['fh_notice']) && $query['fh_notice'] !== '') {
            $out .= '<div class="notice notice-info"><p>' . esc_html($query['fh_notice']) . '</p></div>';
        }
        $out .= $this->banners();
        if (!$this->tablesReady()) {
            return $out . '</div>';
        }
        if (isset($query['feed']) && (int) $query['feed'] > 0) {
            return $out . $this->detail((int) $query['feed']) . '</div>';
        }

        return $out . match ($view) {
            'events' => $this->events($query),
            'settings' => $this->settingsForm(),
            default => $this->summary() . $this->table($query),
        } . '</div>';
    }

    /**
     * @param array<mixed> $query
     * @return array<string, string>
     */
    private static function clean(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = sanitize_text_field(wp_unslash((string) $value));
            }
        }

        return $out;
    }

    private function tabs(string $view, bool $detail): string
    {
        $tabs = ['' => 'Feeds', 'events' => 'Event log', 'settings' => 'Settings'];
        $out = '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = !$detail && $view === $key ? ' nav-tab-active' : '';
            $out .= '<a class="nav-tab' . $active . '" href="' . esc_url($this->url($key === '' ? [] : ['view' => $key])) . '">' . esc_html($label) . '</a>';
        }

        return $out . '</nav>';
    }

    /**
     * Banners for: kill switch off, checks not running, run incomplete,
     * systemic failure in the last check, tables missing.
     *
     * @return string
     */
    public function banners(): string
    {
        $out = '';
        $state = $this->plugin->state;
        $now = $this->plugin->clock->now();
        if (!$this->plugin->settings->enabled()) {
            $out .= $this->banner('error', 'feed-health is switched off. Nothing is recorded and no checks run. Turn it on under Settings' . (defined('FEED_HEALTH_DISABLED') ? ' and remove the FEED_HEALTH_DISABLED constant from wp-config.php' : '') . '.');
        }
        if (!$this->tablesReady()) {
            $out .= '<div class="notice notice-error"><p>' . esc_html('Tables missing: feed-health cannot record anything.') . '</p>'
                . $this->actionForm('repair', 0, 'Repair tables') . '</div>';
        }
        $lastCheck = (int) $state->get('last_check', 0);
        $since = $lastCheck > 0 ? $lastCheck : (int) $state->get('installed_at', $now);
        $limit = 3 * $this->plugin->intervalSeconds();
        if ($now - $since > $limit) {
            $msg = $lastCheck > 0
                ? sprintf('Checks are not running: the last check was %s ago.', Duration::human($now - $lastCheck))
                : 'Checks are not running: no check has run yet.';
            $msg .= ' If WP-Cron is disabled, add a system cron line such as: */15 * * * * wp --path=/path/to/site cron event run --due-now, or run wp feed-health check from cron.';
            $out .= $this->banner('warning', $msg);
        }
        $run = $state->get('last_incomplete_run');
        if (is_array($run) && isset($run['at']) && $now - (int) $run['at'] < 86400) {
            $out .= $this->banner('warning', sprintf(
                'An import run (%s, adapter %s) started and never finished; %d listed feed(s) were not reached.',
                (string) ($run['run_id'] ?? ''),
                (string) ($run['adapter'] ?? ''),
                (int) ($run['missed'] ?? 0)
            ));
        }
        $systemic = (int) $state->get('last_systemic', 0);
        if ($systemic > 0 && $lastCheck > 0 && $systemic >= $lastCheck - 600 && $now - $systemic < 86400) {
            $out .= $this->banner('error', 'Systemic failure in the last check: most fetched feeds started failing together. Check this site\'s network, DNS and whether its IP address is blocked.');
        }

        return $out;
    }

    private function tablesReady(): bool
    {
        try {
            return $this->plugin->repository->tablesExist();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function banner(string $type, string $message): string
    {
        return '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
    }

    public function summary(): string
    {
        $rows = $this->dashboard->rows();
        $counts = Dashboard::counts($rows);
        $parts = [];
        foreach ($counts as $status => $n) {
            $parts[] = '<a href="' . esc_url($this->url(['status' => $status])) . '"><span class="fh-status fh-' . esc_attr($status) . '">' . esc_html($status) . '</span> ' . (int) $n . '</a>';
        }
        $lastCheck = (int) $this->plugin->state->get('last_check', 0);
        $lastFetch = null;
        try {
            $lastFetch = $this->plugin->repository->lastFetchTime();
        } catch (\Throwable $e) {
            $lastFetch = null;
        }
        $out = '<p class="fh-summary">' . implode(' &middot; ', $parts) . '</p>';
        $out .= '<p>' . esc_html('Last check: ' . ($lastCheck > 0 ? $this->time($lastCheck) : 'never')
            . '. Last recorded fetch: ' . ($lastFetch !== null ? $this->time($lastFetch) : 'never') . '.') . '</p>';
        $out .= '<p>' . $this->actionForm('check', 0, 'Run check now', 'secondary') . ' ' . $this->actionForm('test_alert', 0, 'Send test alert', 'secondary') . '</p>';
        $out .= $this->countersTable();

        return $out;
    }

    private function countersTable(): string
    {
        $state = $this->plugin->state;
        $cells = '';
        foreach (State::COUNTERS as $name) {
            $cells .= '<tr><th scope="row">' . esc_html(str_replace('_', ' ', $name)) . '</th><td>' . (int) $state->counter($name, true) . '</td><td>' . (int) $state->counter($name) . '</td></tr>';
        }

        return '<details><summary>' . esc_html('Internal counters') . '</summary><table class="widefat striped fh-counters"><thead><tr><th>Counter</th><th>Today (UTC)</th><th>30 days</th></tr></thead><tbody>' . $cells . '</tbody></table></details>';
    }

    /**
     * The feed table with filters and pagination.
     *
     * @param array<string, string> $query
     */
    public function table(array $query): string
    {
        $all = $this->dashboard->rows();
        $rows = $this->dashboard->filter($all, $query);
        $total = count($rows);
        $page = max(1, (int) ($query['paged'] ?? 1));
        $pages = max(1, (int) ceil($total / Dashboard::PER_PAGE));
        $page = min($page, $pages);
        $slice = array_slice($rows, ($page - 1) * Dashboard::PER_PAGE, Dashboard::PER_PAGE);

        $out = $this->filters($all, $query);
        $columns = [
            'label' => 'Feed', 'adapter' => 'Adapter', 'status' => 'Status', 'last_attempt' => 'Last attempt',
            'last_success' => 'Last success', 'last_http_status' => 'HTTP', 'attempts_24h' => 'Attempts 24 h',
            'failures_24h' => 'Failures 24 h', 'yield_today' => 'Yield today', 'yield_7d' => 'Yield 7 d',
            'dup_rate_7d' => 'Duplicates 7 d', 'newest_published_age' => 'Newest published', 'last_new_age' => 'Last new item',
        ];
        $out .= '<table class="widefat striped fh-table"><thead><tr>';
        foreach ($columns as $key => $label) {
            $order = ($query['orderby'] ?? '') === $key && ($query['order'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
            $out .= '<th scope="col"><a href="' . esc_url($this->url(array_merge($query, ['orderby' => $key, 'order' => $order, 'paged' => '1']))) . '">' . esc_html($label) . '</a></th>';
        }
        $out .= '<th scope="col">' . esc_html('14 days') . '</th></tr></thead><tbody>';
        if ($slice === []) {
            $out .= '<tr><td colspan="14">' . esc_html($all === [] ? 'No feeds recorded yet. Use feed_health_fetch_feed() in place of fetch_feed(), or report through the feed_health_fetch action.' : 'No feeds match.') . '</td></tr>';
        }
        foreach ($slice as $row) {
            $out .= '<tr>';
            $out .= '<td><a href="' . esc_url($this->url(['feed' => (string) $row['id']])) . '">' . esc_html((string) $row['label']) . '</a><br><small>' . esc_html($this->plugin->redactor->redact((string) $row['url'])) . '</small>' . $this->redirectNote($row) . '</td>';
            $out .= '<td>' . esc_html((string) $row['adapter']) . '</td>';
            $out .= '<td><span class="fh-status fh-' . esc_attr((string) $row['status']) . '">' . esc_html((string) $row['status']) . '</span>'
                . ($row['snoozed_until'] !== null && $row['snoozed_until'] > $this->plugin->clock->now() ? ' <small>' . esc_html('(snoozed)') . '</small>' : '')
                . '<br><small>' . esc_html($this->plugin->redactor->redactText((string) $row['reason'])) . '</small></td>';
            $out .= '<td>' . esc_html($this->ago($row['last_attempt'])) . '</td>';
            $out .= '<td>' . esc_html($this->ago($row['last_success'])) . '</td>';
            $out .= '<td>' . esc_html($row['last_http_status'] === null ? '' : (string) $row['last_http_status']) . '</td>';
            $out .= '<td>' . (int) $row['attempts_24h'] . '</td>';
            $out .= '<td>' . (int) $row['failures_24h'] . '</td>';
            $out .= '<td>' . (int) $row['yield_today'] . '</td>';
            $out .= '<td>' . (int) $row['yield_7d'] . '</td>';
            $out .= '<td>' . esc_html($row['dup_rate_7d'] === null ? '-' : $row['dup_rate_7d'] . '%') . '</td>';
            $out .= '<td>' . esc_html($row['newest_published_age'] === null ? '' : Duration::human((int) $row['newest_published_age'])) . '</td>';
            $out .= '<td>' . esc_html($row['last_new_age'] === null ? '' : Duration::human((int) $row['last_new_age'])) . '</td>';
            $out .= '<td>' . Dashboard::sparkline($row['sparkline']) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';
        $out .= $this->pagination($query, $page, $pages, $total);
        $export = wp_nonce_url(add_query_arg(array_merge(array_intersect_key($query, array_flip(['status', 'adapter', 's', 'orderby', 'order'])), ['action' => self::EXPORT]), admin_url('admin-post.php')), self::EXPORT);
        $out .= '<p><a class="button" href="' . esc_url($export) . '">' . esc_html('Export CSV') . '</a></p>';

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $all
     * @param array<string, string> $query
     */
    private function filters(array $all, array $query): string
    {
        $adapters = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['adapter'], $all)));
        sort($adapters);
        $out = '<form method="get" class="fh-filters"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        $out .= '<label>' . esc_html('Status') . ' <select name="status"><option value="">' . esc_html('All') . '</option>';
        foreach (StatusEvaluator::ALL as $status) {
            $out .= '<option value="' . esc_attr($status) . '"' . selected($query['status'] ?? '', $status, false) . '>' . esc_html($status) . '</option>';
        }
        $out .= '</select></label> <label>' . esc_html('Adapter') . ' <select name="adapter"><option value="">' . esc_html('All') . '</option>';
        foreach ($adapters as $adapter) {
            $out .= '<option value="' . esc_attr($adapter) . '"' . selected($query['adapter'] ?? '', $adapter, false) . '>' . esc_html($adapter) . '</option>';
        }
        $out .= '</select></label> <label>' . esc_html('Search') . ' <input type="search" name="s" value="' . esc_attr($query['s'] ?? '') . '"></label> ';
        $out .= '<button class="button">' . esc_html('Filter') . '</button></form>';

        return $out;
    }

    /**
     * @param array<string, string> $query
     */
    private function pagination(array $query, int $page, int $pages, int $total): string
    {
        $out = '<p class="fh-pages">' . esc_html(sprintf('%d feed(s). Page %d of %d.', $total, $page, $pages));
        if ($page > 1) {
            $out .= ' <a href="' . esc_url($this->url(array_merge($query, ['paged' => (string) ($page - 1)]))) . '">' . esc_html('Previous') . '</a>';
        }
        if ($page < $pages) {
            $out .= ' <a href="' . esc_url($this->url(array_merge($query, ['paged' => (string) ($page + 1)]))) . '">' . esc_html('Next') . '</a>';
        }

        return $out . '</p>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function redirectNote(array $row): string
    {
        $final = $row['final_url'] ?? null;
        if (!is_string($final) || $final === '') {
            return '';
        }
        $out = '';
        if (in_array($row['redirect_status'] ?? null, [301, 308], true)) {
            $out .= '<br><small class="fh-warn">' . esc_html('moved: update the feed URL to ' . $this->plugin->redactor->redact($final)) . '</small>';
        }
        $from = UrlCanonicalizer::registrableHost((string) $row['url']);
        $to = UrlCanonicalizer::registrableHost($final);
        if ($from !== '' && $to !== '' && $from !== $to) {
            $out .= '<br><small class="fh-warn">' . esc_html('warning: redirects to another site (' . $to . '); parked and hijacked domains do this') . '</small>';
        }

        return $out;
    }

    /**
     * One feed: numbers, last 50 attempts, threshold derivation, overrides,
     * status history and the operator actions.
     */
    public function detail(int $feedId): string
    {
        $feed = $this->plugin->repository->feed($feedId);
        if ($feed === null) {
            return $this->banner('error', 'No such feed.');
        }
        $rows = array_values(array_filter($this->dashboard->rows(), static fn (array $r): bool => $r['id'] === $feedId));
        $row = $rows[0] ?? null;
        $r = $this->plugin->redactor;
        $out = '<h2>' . esc_html((string) $feed['label']) . '</h2>';
        $out .= '<p><a href="' . esc_url($this->url()) . '">' . esc_html('Back to all feeds') . '</a></p>';
        $out .= '<table class="form-table fh-detail"><tbody>';
        $facts = [
            'Adapter' => (string) $feed['adapter'],
            'Feed key' => (string) $feed['feed_key'],
            'URL' => $r->redact((string) $feed['url']),
            'Final URL' => $feed['final_url'] === null ? '' : $r->redact((string) $feed['final_url']),
            'Status' => (string) $feed['status'] . ': ' . $r->redactText((string) $feed['reason']),
            'Status since' => $this->time((int) $feed['status_since']),
            'Muted' => (int) $feed['muted'] === 1 ? 'yes' : 'no',
            'Snoozed until' => $feed['snoozed_until'] !== null && (int) $feed['snoozed_until'] > $this->plugin->clock->now() ? $this->time((int) $feed['snoozed_until']) : 'not snoozed',
            'First seen' => $this->time((int) $feed['first_seen']),
            'Last attempt' => $feed['last_attempt'] === null ? 'never' : $this->time((int) $feed['last_attempt']),
            'Last success' => $feed['last_success'] === null ? 'never' : $this->time((int) $feed['last_success']),
            'Last HTTP status' => $feed['last_http_status'] === null ? 'none' : Outcome::describeHttp((int) $feed['last_http_status']),
            'Last error' => $r->redactText((string) ($feed['last_error'] ?? '')),
            'Attempts on record' => (string) (int) $feed['attempts_total'],
            'Last new item' => $feed['last_new_at'] === null ? 'none yet' : $this->ago((int) $feed['last_new_at']) . ': ' . (string) ($feed['last_new_title'] ?? ''),
            'Newest published' => $feed['newest_published_at'] === null ? 'no dates' : $this->ago((int) $feed['newest_published_at']),
        ];
        if ($row !== null) {
            $facts['Attempts and failures, 24 h'] = $row['attempts_24h'] . ' / ' . $row['failures_24h'];
            $facts['Yield today and 7 d'] = $row['yield_today'] . ' / ' . $row['yield_7d'];
            $facts['Duplicate rate 7 d'] = $row['dup_rate_7d'] === null ? '-' : $row['dup_rate_7d'] . '% of ' . $row['fresh_7d'] . ' fresh items';
        }
        foreach (Recorder::notes($feed['notes'] ?? null) as $note) {
            $facts['Note'] = isset($facts['Note']) ? $facts['Note'] . '; ' . $note : $note;
        }
        foreach ($facts as $label => $value) {
            $out .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        if ($row !== null) {
            $out .= $this->redirectNote($row);
        }

        $thresholds = $this->plugin->checker->thresholds($feed);
        $out .= '<h3>' . esc_html('Quiet threshold') . '</h3><p>' . esc_html(sprintf(
            'Threshold %s, derived from: %s. Usual gap: %s. Multiplier %s, floor %s, ceiling %s, default %s.',
            $feed['quiet_threshold'] === null ? 'not computed yet' : Duration::human((int) $feed['quiet_threshold']),
            (string) $feed['threshold_source'] !== '' ? (string) $feed['threshold_source'] : 'not computed yet',
            $feed['usual_gap'] === null ? 'not enough history' : Duration::human((int) $feed['usual_gap']),
            (string) $thresholds->quietMultiplier,
            Duration::human($thresholds->quietFloor),
            Duration::human($thresholds->quietCeiling),
            Duration::human($thresholds->defaultQuiet)
        )) . '</p>';

        $out .= '<h3>' . esc_html('Actions') . '</h3><div class="fh-actions">';
        $adapter = $this->plugin->adapters->get((string) $feed['adapter']);
        if ($adapter !== null && $this->plugin->adapters->isAvailable((string) $feed['adapter']) && $adapter->supportsTestFetch()) {
            $out .= $this->actionForm('test_fetch', $feedId, 'Test fetch now');
        }
        $out .= (int) $feed['muted'] === 1 ? $this->actionForm('unmute', $feedId, 'Unmute') : $this->actionForm('mute', $feedId, 'Mute');
        $out .= $this->snoozeForm($feedId, $feed['snoozed_until'] !== null && (int) $feed['snoozed_until'] > $this->plugin->clock->now());
        $out .= $this->actionForm('reset', $feedId, 'Reset baseline', 'secondary', 'I understand this forgets the learned cadence and seen keys for this feed.');
        $out .= $this->actionForm('forget', $feedId, 'Forget feed', 'delete', 'I understand this deletes this feed and all its history.');
        $out .= '</div>';
        $out .= $this->thresholdForm($feedId, $feed['overrides'] ?? null);

        $out .= '<h3>' . esc_html('Last 50 attempts') . '</h3>';
        $out .= '<table class="widefat striped"><thead><tr>';
        foreach (['Started', 'Outcome', 'HTTP', 'Duration', 'Items', 'Fresh', 'Accepted', 'Duplicates', 'Run', 'Error'] as $h) {
            $out .= '<th>' . esc_html($h) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($this->plugin->repository->recentFetches($feedId, 50) as $f) {
            $out .= '<tr><td>' . esc_html($this->time((int) $f['started_at'])) . ((int) $f['manual'] === 1 ? ' <small>' . esc_html('manual') . '</small>' : '') . '</td>'
                . '<td>' . esc_html((string) $f['outcome']) . '</td>'
                . '<td>' . esc_html($f['http_status'] === null ? '' : Outcome::describeHttp((int) $f['http_status'])) . '</td>'
                . '<td>' . esc_html(((int) $f['finished_at'] - (int) $f['started_at']) . ' s') . '</td>'
                . '<td>' . (int) $f['items'] . '</td><td>' . (int) $f['fresh'] . '</td><td>' . (int) $f['accepted'] . '</td><td>' . (int) $f['duplicates'] . '</td>'
                . '<td>' . esc_html((string) ($f['run_id'] ?? '')) . '</td>'
                . '<td>' . esc_html($r->redactText(trim((string) ($f['error_code'] ?? '') . ' ' . (string) ($f['error_message'] ?? '')))) . '</td></tr>';
        }
        $out .= '</tbody></table>';

        $out .= '<h3>' . esc_html('Status history') . '</h3><ul class="fh-history">';
        foreach ($this->plugin->repository->events(['feed_id' => $feedId, 'kind IN' => ['transition', 'operator']], 50) as $e) {
            $out .= '<li>' . esc_html($this->time((int) $e['created_at']) . ': ' . (string) $e['message']) . '</li>';
        }

        return $out . '</ul>';
    }

    /**
     * @param array<string, string> $query
     */
    public function events(array $query): string
    {
        $where = [];
        $kinds = ['transition', 'notify', 'mail_failed', 'operator', 'error', 'check'];
        if (isset($query['kind']) && in_array($query['kind'], $kinds, true)) {
            $where['kind'] = $query['kind'];
        }
        if (isset($query['event_feed']) && (int) $query['event_feed'] > 0) {
            $where['feed_id'] = (int) $query['event_feed'];
        }
        $page = max(1, (int) ($query['paged'] ?? 1));
        $events = $this->plugin->repository->events($where, 100, ($page - 1) * 100);
        $out = '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="view" value="events">';
        $out .= '<label>' . esc_html('Kind') . ' <select name="kind"><option value="">' . esc_html('All') . '</option>';
        foreach ($kinds as $kind) {
            $out .= '<option value="' . esc_attr($kind) . '"' . selected($query['kind'] ?? '', $kind, false) . '>' . esc_html($kind) . '</option>';
        }
        $out .= '</select></label> <label>' . esc_html('Feed id') . ' <input type="number" min="0" name="event_feed" value="' . esc_attr($query['event_feed'] ?? '') . '"></label> <button class="button">' . esc_html('Filter') . '</button></form>';
        $out .= '<table class="widefat striped"><thead><tr><th>' . esc_html('Time') . '</th><th>' . esc_html('Kind') . '</th><th>' . esc_html('Feed') . '</th><th>' . esc_html('User') . '</th><th>' . esc_html('Message') . '</th></tr></thead><tbody>';
        foreach ($events as $e) {
            $out .= '<tr><td>' . esc_html($this->time((int) $e['created_at'])) . '</td><td>' . esc_html((string) $e['kind']) . '</td><td>'
                . ($e['feed_id'] === null ? '' : '<a href="' . esc_url($this->url(['feed' => (string) $e['feed_id']])) . '">' . (int) $e['feed_id'] . '</a>') . '</td><td>'
                . esc_html($e['user_id'] === null ? '' : (string) $e['user_id']) . '</td><td>' . esc_html($this->plugin->redactor->redactText((string) $e['message'])) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        $next = $this->url(array_merge($query, ['view' => 'events', 'paged' => (string) ($page + 1)]));

        return $out . (count($events) === 100 ? '<p><a href="' . esc_url($next) . '">' . esc_html('Older events') . '</a></p>' : '');
    }

    public function settingsForm(): string
    {
        $values = $this->plugin->settings->all();
        $name = Settings::OPTION;
        ob_start();
        settings_fields('feed_health');
        $fields = (string) ob_get_clean();
        $out = '<form method="post" action="' . esc_url(admin_url('options.php')) . '">' . $fields . '<table class="form-table"><tbody>';
        foreach ($values as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $field = $name . '[' . $key . ']';
            if (is_bool($value)) {
                $input = '<input type="hidden" name="' . esc_attr($field) . '" value="0"><input type="checkbox" name="' . esc_attr($field) . '" value="1"' . checked($value, true, false) . '>';
            } elseif ($key === 'check_interval') {
                $input = '<select name="' . esc_attr($field) . '">';
                foreach (Settings::INTERVALS as $interval) {
                    $input .= '<option value="' . esc_attr($interval) . '"' . selected($value, $interval, false) . '>' . esc_html($interval) . '</option>';
                }
                $input .= '</select>';
            } elseif ($key === 'recipients') {
                $input = '<input type="text" class="regular-text" name="' . esc_attr($field) . '" value="' . esc_attr(implode(', ', is_array($value) ? $value : [])) . '" placeholder="' . esc_attr((string) get_option('admin_email')) . '">';
            } else {
                $input = '<input type="number" step="any" name="' . esc_attr($field) . '" value="' . esc_attr((string) (is_scalar($value) ? $value : '')) . '">';
            }
            $out .= '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $input . '</td></tr>';
        }
        $out .= '</tbody></table>' . get_submit_button('Save settings') . '</form>';
        if (is_multisite() && current_user_can('manage_network_options')) {
            $network = get_site_option(Notifier::NETWORK_OPTION, []);
            $cap = is_array($network) ? (int) ($network['cap'] ?? 0) : 0;
            $out .= '<h2>' . esc_html('Network') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="network_cap">'
                . wp_nonce_field('feed_health_network_cap', '_wpnonce', true, false)
                . '<label>' . esc_html('Daily email cap for the whole network (0 = no network cap)') . ' <input type="number" min="0" max="10000" name="cap" value="' . (int) $cap . '"></label> '
                . get_submit_button('Save network cap', 'secondary', 'submit', false) . '</form>';
        }

        return $out;
    }

    private function snoozeForm(int $feedId, bool $snoozed): string
    {
        if ($snoozed) {
            return $this->actionForm('unsnooze', $feedId, 'Clear snooze');
        }
        $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fh-inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="snooze"><input type="hidden" name="feed" value="' . (int) $feedId . '">'
            . wp_nonce_field('feed_health_snooze', '_wpnonce', true, false) . '<select name="for">';
        foreach (['1h' => '1 hour', '1d' => '1 day', '1w' => '1 week'] as $key => $label) {
            $out .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }

        return $out . '</select> <button class="button">' . esc_html('Snooze') . '</button></form>';
    }

    private function thresholdForm(int $feedId, int|string|null $raw): string
    {
        $overrides = \IndianOpinion\FeedHealth\Checker::overrides($raw);

        return '<h3>' . esc_html('Per-feed thresholds') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="op" value="thresholds"><input type="hidden" name="feed" value="' . (int) $feedId . '">'
            . wp_nonce_field('feed_health_thresholds', '_wpnonce', true, false)
            . '<label>' . esc_html('Quiet threshold (hours, empty = adaptive)') . ' <input type="number" min="1" max="2160" name="quiet_hours" value="' . esc_attr(isset($overrides['quiet_hours']) ? (string) $overrides['quiet_hours'] : '') . '"></label> '
            . '<label>' . esc_html('Failures in a row (empty = default)') . ' <input type="number" min="1" max="50" name="failures_in_row" value="' . esc_attr(isset($overrides['failures_in_row']) ? (string) $overrides['failures_in_row'] : '') . '"></label> '
            . '<button class="button">' . esc_html('Save thresholds') . '</button></form>';
    }

    private function actionForm(string $op, int $feedId, string $label, string $style = 'secondary', string $confirm = ''): string
    {
        $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="fh-inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="op" value="' . esc_attr($op) . '">'
            . ($feedId > 0 ? '<input type="hidden" name="feed" value="' . (int) $feedId . '">' : '')
            . wp_nonce_field('feed_health_' . $op, '_wpnonce', true, false);
        if ($confirm !== '') {
            $out .= '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html($confirm) . '</label> ';
        }

        return $out . '<button class="button button-' . esc_attr($style) . '">' . esc_html($label) . '</button></form>';
    }

    /**
     * admin-post handler: checks, performs, redirects back.
     */
    public function handle(): void
    {
        $request = self::clean($_POST);
        $op = $request['op'] ?? '';
        try {
            $message = $this->perform($op, $request, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), get_current_user_id());
        } catch (InvalidValue $e) {
            $message = $e->getMessage();
        }
        $args = ['fh_notice' => $message];
        if (isset($request['feed']) && $op !== 'forget' && (int) $request['feed'] > 0) {
            $args['feed'] = $request['feed'];
        }
        wp_safe_redirect($this->url($args));
        exit;
    }

    /**
     * Perform one operator action after the capability, method and nonce
     * checks. Returns a message for the operator.
     *
     * @param array<string, string> $request
     * @throws InvalidValue
     */
    public function perform(string $op, array $request, string $method, int $userId): string
    {
        if (!current_user_can($this->plugin->capability())) {
            wp_die(esc_html('You do not have permission to do that.'), '', ['response' => 403]);
        }
        if (in_array($op, self::POST_ONLY, true) && strtoupper($method) !== 'POST') {
            throw new InvalidValue('This action needs a form submission.');
        }
        $nonce = $request['_wpnonce'] ?? '';
        if (wp_verify_nonce($nonce, 'feed_health_' . $op) === false) {
            wp_die(esc_html('The link has expired. Go back and try again.'), '', ['response' => 403]);
        }
        $op_ = $this->plugin->operator;
        $feedId = (int) ($request['feed'] ?? 0);
        $confirmed = ($request['confirm'] ?? '') === '1';

        return match ($op) {
            'mute' => $this->done(static fn () => $op_->mute($feedId, $userId), 'Feed muted.'),
            'unmute' => $this->done(static fn () => $op_->unmute($feedId, $userId), 'Feed unmuted.'),
            'snooze' => 'Alerts snoozed until ' . $this->time($op_->snooze($feedId, $userId, $request['for'] ?? '1d')) . '.',
            'unsnooze' => $this->done(static fn () => $op_->unsnooze($feedId, $userId), 'Snooze cleared.'),
            'reset' => $confirmed ? $this->done(static fn () => $op_->resetBaseline($feedId, $userId), 'Baseline reset.') : throw new InvalidValue('Tick the confirmation box to reset the baseline.'),
            'forget' => $this->done(static fn () => $op_->forget($feedId, $userId, $confirmed), 'Feed forgotten.'),
            'thresholds' => $this->done(static fn () => $op_->setThresholds(
                $feedId,
                $userId,
                ($request['quiet_hours'] ?? '') === '' ? null : (int) $request['quiet_hours'],
                ($request['failures_in_row'] ?? '') === '' ? null : (int) $request['failures_in_row']
            ), 'Thresholds saved.'),
            'test_fetch' => 'Test fetch: ' . $op_->testFetch($feedId, $userId)['message'] . '.',
            'check' => $this->runCheck($userId),
            'repair' => $this->repair($userId),
            'test_alert' => 'Test alert: ' . Notifier::resultsText($this->plugin->notifier->testAlert($userId)) . '.',
            'network_cap' => $this->networkCap($request, $userId),
            default => throw new InvalidValue('Unknown action.'),
        };
    }

    private function done(callable $action, string $message): string
    {
        $action();

        return $message;
    }

    private function runCheck(int $userId): string
    {
        $this->plugin->repository->addEvent($this->plugin->clock->now(), 'operator', 'check run from the dashboard', null, $userId);
        $summary = $this->plugin->checker->run();

        return sprintf('Check %s: %d feeds, %d transitions, %d notices.', $summary['status'], $summary['feeds'], count($summary['transitions']), $summary['notified']);
    }

    private function repair(int $userId): string
    {
        $ok = $this->plugin->install();
        if ($ok) {
            $this->plugin->repository->addEvent($this->plugin->clock->now(), 'operator', 'tables repaired', null, $userId);
        }

        return $ok ? 'Tables created.' : 'Repair failed; see the PHP error log.';
    }

    /**
     * @param array<string, string> $request
     */
    private function networkCap(array $request, int $userId): string
    {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            throw new InvalidValue('Only a network administrator can set the network cap.');
        }
        $cap = max(0, min(10000, (int) ($request['cap'] ?? 0)));
        $value = get_site_option(Notifier::NETWORK_OPTION, []);
        $value = is_array($value) ? $value : [];
        $value['cap'] = $cap;
        update_site_option(Notifier::NETWORK_OPTION, $value);
        $this->plugin->repository->addEvent($this->plugin->clock->now(), 'operator', 'network email cap set to ' . $cap, null, $userId);

        return 'Network cap saved.';
    }

    /**
     * admin-post handler for the CSV export.
     */
    public function export(): void
    {
        $query = self::clean($_GET);
        $csv = $this->exportCsv($query);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feed-health-' . gmdate('Ymd-His', $this->plugin->clock->now()) . '.csv"');
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV body, cells neutralised.
        exit;
    }

    /**
     * The CSV for a request, after the capability and nonce checks.
     *
     * @param array<string, string> $query
     */
    public function exportCsv(array $query): string
    {
        if (!current_user_can($this->plugin->capability())) {
            wp_die(esc_html('You do not have permission to do that.'), '', ['response' => 403]);
        }
        if (wp_verify_nonce($query['_wpnonce'] ?? '', self::EXPORT) === false) {
            wp_die(esc_html('The link has expired. Go back and try again.'), '', ['response' => 403]);
        }

        return $this->dashboard->csv($this->dashboard->filter($this->dashboard->rows(), $query));
    }

    /**
     * @param array<string, string> $args
     */
    private function url(array $args = []): string
    {
        $args = array_filter($args, static fn ($v): bool => $v !== '' && $v !== null);

        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('tools.php'));
    }

    private function time(int $time): string
    {
        return Notifier::siteTime($time);
    }

    private function ago(?int $time): string
    {
        if ($time === null) {
            return 'never';
        }

        return Duration::human($this->plugin->clock->now() - $time) . ' ago';
    }

    private function styles(): string
    {
        return '<style>.feed-health .fh-status{display:inline-block;padding:0 6px;border-radius:3px;background:#f0f0f1;font-weight:600}'
            . '.feed-health .fh-failing{background:#fcf0f1;color:#8a2424}.feed-health .fh-unchecked,.feed-health .fh-quiet{background:#fcf9e8;color:#6b4d00}'
            . '.feed-health .fh-healthy{background:#edfaef;color:#14532d}.feed-health .fh-degraded,.feed-health .fh-redundant{background:#f6f7f7;color:#50575e}'
            . '.feed-health .fh-inline{display:inline-block;margin:0 8px 8px 0}.feed-health .fh-warn{color:#8a2424}.feed-health .fh-filters{margin:8px 0}'
            . '.feed-health .fh-table td{vertical-align:top}.feed-health .feed-health-spark{color:#2271b1}</style>';
    }
}
