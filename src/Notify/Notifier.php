<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Notify;

use IndianOpinion\FeedHealth\Clock;
use IndianOpinion\FeedHealth\Health\Duration;
use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Logger;
use IndianOpinion\FeedHealth\Redactor;
use IndianOpinion\FeedHealth\Repository;
use IndianOpinion\FeedHealth\Settings;
use IndianOpinion\FeedHealth\State;
use IndianOpinion\FeedHealth\Value\Transition;

/**
 * Turns status transitions into notifications: classification, cooldown,
 * reminders, flapping, systemic failure, batching, the daily cap, retries
 * and the channels (email and log).
 *
 * Transitions are stored as events with a send state, so a check that dies
 * part way never sends one twice: only events still pending are sent, and
 * they are marked as sent right after the channel accepts them.
 */
final class Notifier
{
    public const PENDING = 0;
    public const SENT = 1;
    public const SUPPRESSED = 2;
    public const HELD = 3;
    public const GAVE_UP = 4;

    public const MAX_TRIES = 3;
    public const FLAP_CHANGES = 4;
    public const FLAP_WINDOW = 86400;
    public const FLAP_SETTLE = 21600;
    public const NETWORK_OPTION = 'feed_health_network';

    private const RECOVERABLE = [StatusEvaluator::FAILING, StatusEvaluator::UNCHECKED, StatusEvaluator::QUIET];
    private const RECOVERED_TO = [StatusEvaluator::HEALTHY, StatusEvaluator::DEGRADED];

    public function __construct(
        private Repository $repository,
        private Settings $settings,
        private State $state,
        private Clock $clock,
        private Logger $logger,
        private EmailFormatter $formatter,
        private Redactor $redactor
    ) {
    }

    /**
     * Store one transition as an event, decide whether it may be sent, and
     * fire `feed_health_transition`. Returns the event id.
     *
     * @param array<string, int|string|null> $feed The feed row after the change.
     */
    public function record(Transition $t, array $feed): int
    {
        $now = $this->clock->now();
        $alertState = self::alertState($feed['alert_state'] ?? null);
        [$kind, $sent, $note, $flapping] = $this->classify($t, $feed, $alertState, $now);
        if ($flapping && empty($alertState['flapping_since'])) {
            $alertState['flapping_since'] = $now;
            $this->repository->updateFeed((int) $feed['id'], ['alert_state' => (string) wp_json_encode($alertState)]);
        }
        $t = $t->withKind($kind, $flapping);
        $message = sprintf('%s: %s to %s: %s', $t->label(), $t->from, $t->to, $this->redactor->redactText($t->reason));
        if ($note !== '') {
            $message .= ' [' . $note . ']';
        }
        $id = $this->repository->addEvent($now, 'transition', $message, $t->feedId(), null, $t->id, $sent, $t->toArray() + ['tries' => 0]);
        if ($this->settings->bool('channel_log') && $this->settings->bool('php_error_log')) {
            $this->logger->php('transition ' . $message);
        }
        do_action('feed_health_transition', $t);

        return $id;
    }

    /**
     * @param array<string, int|string|null> $feed
     * @param array{sent: array<string, int>, flapping_since: int|null} $alertState
     * @return array{0: string, 1: int, 2: string, 3: bool}
     */
    private function classify(Transition $t, array $feed, array $alertState, int $now): array
    {
        $kind = Transition::NONE;
        if (in_array($t->to, $this->alertOn(), true) && $t->from !== StatusEvaluator::PAUSED) {
            $kind = Transition::ALERT;
        } elseif (
            in_array($t->from, self::RECOVERABLE, true)
            && in_array($t->to, self::RECOVERED_TO, true)
            && $this->settings->bool('recovery')
        ) {
            $kind = Transition::RECOVERY;
        }

        if ((int) ($feed['muted'] ?? 0) === 1 || $t->to === StatusEvaluator::PAUSED) {
            return [$kind, self::SUPPRESSED, 'muted', false];
        }
        if ((int) ($feed['snoozed_until'] ?? 0) > $now) {
            return [$kind, self::SUPPRESSED, 'snoozed', false];
        }

        $changes = $this->repository->countEvents([
            'kind' => 'transition',
            'feed_id' => (int) $feed['id'],
            'created_at >=' => $now - self::FLAP_WINDOW,
        ]) + 1;
        if (!empty($alertState['flapping_since'])) {
            return [$kind, self::SUPPRESSED, 'flapping', false];
        }
        if ($changes > self::FLAP_CHANGES) {
            if ($kind === Transition::NONE || !$this->allowed($t->withKind($kind, true))) {
                return [$kind, self::SUPPRESSED, 'flapping', true];
            }

            return [$kind, self::PENDING, 'flapping', true];
        }

        if ($kind === Transition::NONE) {
            return [$kind, self::SUPPRESSED, 'not an alerting change', false];
        }
        $cooldownKey = $kind === Transition::RECOVERY ? 'recovery' : $t->to;
        $last = $alertState['sent'][$cooldownKey] ?? null;
        $cooldown = $this->settings->int('cooldown_hours') * 3600;
        if ($last !== null && $cooldown > 0 && $now - $last < $cooldown) {
            return [$kind, self::SUPPRESSED, 'cooldown', false];
        }
        if (!$this->allowed($t->withKind($kind))) {
            return [$kind, self::SUPPRESSED, 'filtered', false];
        }

        return [$kind, self::PENDING, '', false];
    }

    private function allowed(Transition $t): bool
    {
        return (bool) apply_filters('feed_health_should_notify', true, $t);
    }

    /**
     * Statuses that alert on entry, from settings.
     *
     * @return list<string>
     */
    public function alertOn(): array
    {
        $out = [];
        foreach (StatusEvaluator::ALERTING as $status) {
            if ($this->settings->bool('alert_' . $status)) {
                $out[] = $status;
            }
        }

        return $out;
    }

    /**
     * Reminder and settle notices for feeds still in an alerting status.
     * Clears the flapping mark of feeds that have held one status for 6 h.
     *
     * @param list<array<string, int|string|null>> $feeds
     * @return list<Transition>
     */
    public function reminders(array $feeds): array
    {
        $now = $this->clock->now();
        $reminder = $this->settings->int('reminder_hours') * 3600;
        $alertOn = $this->alertOn();
        $out = [];
        foreach ($feeds as $feed) {
            $status = (string) $feed['status'];
            $since = (int) $feed['status_since'];
            $state = self::alertState($feed['alert_state'] ?? null);
            $settled = false;
            if (!empty($state['flapping_since'])) {
                if ($now - $since < self::FLAP_SETTLE) {
                    continue;
                }
                $state['flapping_since'] = null;
                $this->repository->updateFeed((int) $feed['id'], ['alert_state' => (string) wp_json_encode($state)]);
                $settled = true;
            }
            if (!in_array($status, $alertOn, true) || (int) $feed['muted'] === 1 || (int) ($feed['snoozed_until'] ?? 0) > $now) {
                continue;
            }
            $last = $state['sent'][$status] ?? null;
            $due = $reminder > 0 && $now - $since >= $reminder && ($last === null || $now - $last >= $reminder);
            if (!$due && !$settled) {
                continue;
            }
            $reason = (string) $feed['reason'];
            $reason .= $settled ? ' (settled after flapping)' : ' (for ' . Duration::human($now - $since) . ')';
            $out[] = new Transition(self::newId(), self::summary($feed), $status, $status, $reason, $now, Transition::STILL);
        }

        return $out;
    }

    /**
     * Send everything pending through the enabled channels.
     *
     * @param list<string> $thisCheck Transition ids recorded in this check.
     * @param list<Transition> $still Reminder notices built this check.
     * @param int $fetchedFeeds Feeds with an attempt in the failure window.
     * @return array{entries: int, channels: array<string, string>}
     */
    public function dispatch(array $thisCheck, array $still, int $fetchedFeeds): array
    {
        $now = $this->clock->now();
        $pending = $this->repository->events(['kind' => 'transition', 'sent' => self::PENDING], 500);
        $entries = [];
        $eventIds = [];
        $tries = [];
        foreach (array_reverse($pending) as $event) {
            $data = json_decode((string) $event['data'], true);
            if (!is_array($data)) {
                $this->repository->updateEvent((int) $event['id'], self::GAVE_UP);
                continue;
            }
            try {
                $t = Transition::fromArray($data);
            } catch (\Throwable $e) {
                $this->repository->updateEvent((int) $event['id'], self::GAVE_UP);
                continue;
            }
            $feed = $this->repository->feed($t->feedId());
            if ($feed !== null) {
                $t = new Transition($t->id, self::summary($feed), $t->from, $t->to, $t->reason, $t->time, $t->kind, $t->flapping);
            }
            $entries[] = $t;
            $eventIds[$t->id] = (int) $event['id'];
            $tries[$t->id] = (int) ($data['tries'] ?? 0);
        }

        $entries = $this->systemic($entries, $thisCheck, $fetchedFeeds);
        $all = array_merge($entries, $still);
        if ($all === []) {
            return ['entries' => 0, 'channels' => []];
        }

        $results = [];
        if ($this->settings->bool('channel_log')) {
            foreach ($all as $entry) {
                $line = $this->logLine($entry);
                $this->repository->addEvent($now, 'notify', $line, $entry->feedId() ?: null, null, $entry->id, self::SENT, null);
                if ($this->settings->bool('php_error_log')) {
                    $this->logger->php($line);
                }
            }
            $results['log'] = 'written';
        }

        $delivered = true;
        if ($this->settings->bool('channel_email')) {
            $results['email'] = $this->email($all);
            $delivered = $results['email'] === 'sent';
            if ($results['email'] === 'held') {
                foreach ($eventIds as $id) {
                    $this->repository->updateEvent($id, self::HELD);
                }
                do_action('feed_health_notify', $all, $results);

                return ['entries' => count($all), 'channels' => $results];
            }
        }

        if ($delivered) {
            foreach ($eventIds as $id) {
                $this->repository->updateEvent($id, self::SENT);
            }
            $this->markSent($all, $now);
        } else {
            foreach ($entries as $entry) {
                if (!isset($eventIds[$entry->id])) {
                    continue;
                }
                $count = $tries[$entry->id] + 1;
                $data = $entry->toArray() + ['tries' => $count];
                if ($count >= self::MAX_TRIES) {
                    $this->repository->updateEvent($eventIds[$entry->id], self::GAVE_UP, $data);
                    $this->logger->event('mail_failed', sprintf('Gave up on the notice for %s after %d tries.', $entry->label(), $count), $entry->feedId() ?: null);
                } else {
                    $this->repository->updateEvent($eventIds[$entry->id], self::PENDING, $data);
                }
            }
            $this->carrySystemicIds($entries, $eventIds, $tries);
        }

        do_action('feed_health_notify', $all, $results);

        return ['entries' => count($all), 'channels' => $results];
    }

    /**
     * Systemic entries carry the ids of the transitions they replaced, so
     * a failed send retries every one of them.
     *
     * @param list<Transition> $entries
     * @param array<string, int> $eventIds
     * @param array<string, int> $tries
     */
    private function carrySystemicIds(array $entries, array $eventIds, array $tries): void
    {
        foreach ($entries as $entry) {
            if ($entry->kind !== Transition::SYSTEMIC) {
                continue;
            }
            $ids = json_decode((string) ($entry->feed['ids'] ?? '[]'), true);
            foreach (is_array($ids) ? $ids : [] as $tid) {
                if (!is_string($tid) || !isset($eventIds[$tid])) {
                    continue;
                }
                $count = ($tries[$tid] ?? 0) + 1;
                $this->repository->updateEvent($eventIds[$tid], $count >= self::MAX_TRIES ? self::GAVE_UP : self::PENDING);
            }
        }
    }

    /**
     * Replace per-feed failing alerts with one systemic alert when at least
     * half of the fetched feeds (and at least 3) went failing in this check.
     *
     * @param list<Transition> $entries
     * @param list<string> $thisCheck
     * @return list<Transition>
     */
    private function systemic(array $entries, array $thisCheck, int $fetchedFeeds): array
    {
        $failing = [];
        foreach ($entries as $entry) {
            if ($entry->kind === Transition::ALERT && $entry->to === StatusEvaluator::FAILING && in_array($entry->id, $thisCheck, true)) {
                $failing[] = $entry;
            }
        }
        $n = count($failing);
        if ($n < 3 || $n * 2 < max(1, $fetchedFeeds)) {
            return $entries;
        }
        $lines = [];
        $ids = [];
        $feedIds = [];
        foreach ($failing as $entry) {
            $lines[] = $entry->label() . ': ' . $entry->reason;
            $ids[] = $entry->id;
            $feedIds[] = $entry->feedId();
        }
        $reason = sprintf(
            '%d of %d fetched feeds started failing in the same check. The problem is probably on this site\'s side: its network, its DNS, or a block on its IP address. Check those before the feeds.',
            $n,
            $fetchedFeeds
        );
        $systemic = new Transition(
            self::newId(),
            ['id' => 0, 'label' => 'Systemic failure', 'list' => (string) wp_json_encode($lines), 'ids' => (string) wp_json_encode($ids), 'feed_ids' => (string) wp_json_encode($feedIds)],
            '',
            StatusEvaluator::FAILING,
            $reason,
            $this->clock->now(),
            Transition::SYSTEMIC
        );
        $this->state->set('last_systemic', $this->clock->now());
        $out = [$systemic];
        foreach ($entries as $entry) {
            if (!in_array($entry->id, $ids, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<Transition> $entries
     * @return string sent, failed, held, or no_recipients
     */
    private function email(array $entries): string
    {
        $recipients = $this->settings->recipients();
        if ($recipients === []) {
            return 'no_recipients';
        }
        $now = $this->clock->now();
        $day = self::siteDay($now);
        $tally = $this->state->get('email_tally', []);
        $tally = is_array($tally) && ($tally['day'] ?? '') === $day ? $tally : ['day' => $day, 'sent' => 0];
        $cap = $this->settings->int('daily_email_cap');
        $network = $this->networkTally($day);
        $overNetwork = $network !== null && $network['cap'] > 0 && $network['sent'] >= $network['cap'];
        if ((int) $tally['sent'] >= $cap || $overNetwork) {
            $held = (int) $this->state->get('held_back', 0) + count($entries);
            $this->state->set('held_back', $held);
            $this->state->increment('emails_held');
            $this->logger->event('notify', sprintf('email cap reached: %d notice(s) held back (%s cap).', count($entries), $overNetwork ? 'network' : 'site'));

            return 'held';
        }

        $site = (string) get_bloginfo('name');
        $heldBack = (int) $this->state->get('held_back', 0);
        $message = [
            'to' => $recipients,
            'subject' => $this->formatter->subject($site !== '' ? $site : (string) home_url(), $entries),
            'body' => $this->formatter->body($site, $entries, self::siteTime($now), self::dashboardUrl(), $heldBack),
            'headers' => ['Content-Type: text/plain; charset=UTF-8'],
        ];
        $filtered = apply_filters('feed_health_alert_email', $message, $entries);
        if (is_array($filtered)) {
            $message = array_merge($message, $filtered);
        }
        $ok = wp_mail($message['to'], (string) $message['subject'], (string) $message['body'], $message['headers']);
        if (!$ok) {
            $this->state->increment('emails_failed');
            $this->logger->event('mail_failed', 'wp_mail() returned false; the notice will be retried at the next check.');

            return 'failed';
        }
        $this->state->increment('emails_sent');
        $this->state->setMany([
            'email_tally' => ['day' => $day, 'sent' => (int) $tally['sent'] + 1],
            'held_back' => 0,
        ]);
        if ($network !== null) {
            $this->bumpNetwork($day);
        }

        return 'sent';
    }

    /**
     * Send a sample notice through every enabled channel and report what
     * each returned. Ignores the cap.
     *
     * @return array<string, string>
     */
    public function testAlert(?int $userId = null): array
    {
        $now = $this->clock->now();
        $sample = new Transition(self::newId(), [
            'id' => 0,
            'label' => 'Sample feed (test alert)',
            'url' => 'https://example.com/feed?token=example',
            'last_http_status' => 403,
            'last_success_text' => self::siteTime($now - 7200),
        ], StatusEvaluator::HEALTHY, StatusEvaluator::FAILING, '3 failures in a row: HTTP 403 blocked', $now);
        $results = [];
        if ($this->settings->bool('channel_log')) {
            $this->repository->addEvent($now, 'notify', 'test alert: ' . $this->logLine($sample), null, $userId, null, self::SENT, null);
            if ($this->settings->bool('php_error_log')) {
                $this->logger->php('test alert: ' . $this->logLine($sample));
            }
            $results['log'] = 'written';
        } else {
            $results['log'] = 'disabled';
        }
        if ($this->settings->bool('channel_email')) {
            $recipients = $this->settings->recipients();
            if ($recipients === []) {
                $results['email'] = 'no_recipients';
            } else {
                $site = (string) get_bloginfo('name');
                $ok = wp_mail(
                    $recipients,
                    '[test] ' . $this->formatter->subject($site, [$sample]),
                    "This is a test alert from feed-health. Nothing is wrong.\n\n" . $this->formatter->body($site, [$sample], self::siteTime($now), self::dashboardUrl()),
                    ['Content-Type: text/plain; charset=UTF-8']
                );
                $results['email'] = $ok ? 'sent to ' . implode(', ', $recipients) : 'failed: wp_mail() returned false';
            }
        } else {
            $results['email'] = 'disabled';
        }
        $this->repository->addEvent($now, 'operator', 'test alert sent: ' . self::resultsText($results), null, $userId);

        return $results;
    }

    /**
     * @param list<Transition> $entries
     */
    private function markSent(array $entries, int $now): void
    {
        $keys = [];
        foreach ($entries as $entry) {
            if ($entry->kind === Transition::SYSTEMIC) {
                $ids = json_decode((string) ($entry->feed['feed_ids'] ?? '[]'), true);
                foreach (is_array($ids) ? $ids : [] as $feedId) {
                    if (is_int($feedId)) {
                        $keys[$feedId][] = StatusEvaluator::FAILING;
                    }
                }
                continue;
            }
            if ($entry->feedId() > 0) {
                $keys[$entry->feedId()][] = $entry->kind === Transition::RECOVERY ? 'recovery' : $entry->to;
            }
        }
        foreach ($keys as $feedId => $list) {
            $feed = $this->repository->feed($feedId);
            if ($feed === null) {
                continue;
            }
            $state = self::alertState($feed['alert_state'] ?? null);
            foreach ($list as $key) {
                $state['sent'][$key] = $now;
            }
            $this->repository->updateFeed($feedId, ['alert_state' => (string) wp_json_encode($state)]);
        }
    }

    private function logLine(Transition $entry): string
    {
        $prefix = match ($entry->kind) {
            Transition::RECOVERY => 'recovered',
            Transition::STILL => 'still ' . $entry->to,
            Transition::SYSTEMIC => 'systemic failure',
            default => $entry->to,
        };
        if ($entry->flapping) {
            $prefix .= ' (flapping)';
        }

        return sprintf('%s: %s: %s', $prefix, $entry->label(), $this->redactor->redactText($entry->reason));
    }

    /**
     * @return array{cap: int, sent: int}|null Null on a single site.
     */
    private function networkTally(string $day): ?array
    {
        if (!is_multisite()) {
            return null;
        }
        $value = get_site_option(self::NETWORK_OPTION, []);
        $value = is_array($value) ? $value : [];
        $cap = (int) ($value['cap'] ?? 0);
        $sent = ($value['day'] ?? '') === $day ? (int) ($value['sent'] ?? 0) : 0;

        return ['cap' => $cap, 'sent' => $sent];
    }

    private function bumpNetwork(string $day): void
    {
        $value = get_site_option(self::NETWORK_OPTION, []);
        $value = is_array($value) ? $value : [];
        $sent = ($value['day'] ?? '') === $day ? (int) ($value['sent'] ?? 0) : 0;
        $value['day'] = $day;
        $value['sent'] = $sent + 1;
        update_site_option(self::NETWORK_OPTION, $value);
    }

    /**
     * @param array<string, string> $results
     */
    public static function resultsText(array $results): string
    {
        $parts = [];
        foreach ($results as $channel => $result) {
            $parts[] = $channel . ': ' . $result;
        }

        return implode('; ', $parts);
    }

    /**
     * @return array{sent: array<string, int>, flapping_since: int|null}
     */
    public static function alertState(int|string|null $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $decoded = is_array($decoded) ? $decoded : [];
        $sent = [];
        foreach ((array) ($decoded['sent'] ?? []) as $key => $time) {
            if (is_numeric($time)) {
                $sent[(string) $key] = (int) $time;
            }
        }
        $flapping = $decoded['flapping_since'] ?? null;

        return ['sent' => $sent, 'flapping_since' => is_numeric($flapping) ? (int) $flapping : null];
    }

    /**
     * The fields of a feed row that a notice needs.
     *
     * @param array<string, int|string|null> $feed
     * @return array<string, int|string|null>
     */
    public static function summary(array $feed): array
    {
        $success = $feed['last_success'] ?? null;

        return [
            'id' => (int) ($feed['id'] ?? 0),
            'adapter' => (string) ($feed['adapter'] ?? ''),
            'feed_key' => (string) ($feed['feed_key'] ?? ''),
            'label' => (string) ($feed['label'] ?? ''),
            'url' => (string) ($feed['url'] ?? ''),
            'last_http_status' => isset($feed['last_http_status']) ? (int) $feed['last_http_status'] : null,
            'last_success' => $success === null ? null : (int) $success,
            'last_success_text' => $success === null ? null : self::siteTime((int) $success),
        ];
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function siteDay(int $time): string
    {
        return (string) wp_date('Y-m-d', $time);
    }

    public static function siteTime(int $time): string
    {
        return (string) wp_date('Y-m-d H:i T', $time);
    }

    public static function dashboardUrl(): string
    {
        return (string) admin_url('tools.php?page=feed-health');
    }
}
