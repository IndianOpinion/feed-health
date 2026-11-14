<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Notify;

use IndianOpinion\FeedHealth\Health\StatusEvaluator;
use IndianOpinion\FeedHealth\Redactor;
use IndianOpinion\FeedHealth\Value\Transition;

/**
 * Builds the plain-text alert email. Readable on a phone without opening
 * wp-admin. Every URL is redacted.
 */
final class EmailFormatter
{
    public function __construct(private Redactor $redactor)
    {
    }

    /**
     * @param list<Transition> $entries
     */
    public function subject(string $site, array $entries): string
    {
        $counts = [];
        foreach ($entries as $entry) {
            $label = match ($entry->kind) {
                Transition::RECOVERY => 'recovered',
                Transition::SYSTEMIC => 'systemic failure',
                Transition::STILL => 'still ' . $entry->to,
                default => $entry->to,
            };
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $order = array_merge(
            ['systemic failure'],
            StatusEvaluator::ALERTING,
            ['recovered'],
            array_map(static fn (string $s): string => 'still ' . $s, StatusEvaluator::ALL)
        );
        $parts = [];
        foreach ($order as $label) {
            if (isset($counts[$label])) {
                $parts[] = $label === 'systemic failure' ? 'systemic failure' : $counts[$label] . ' ' . $label;
                unset($counts[$label]);
            }
        }
        foreach ($counts as $label => $n) {
            $parts[] = $n . ' ' . $label;
        }

        return 'Feed health on ' . self::oneLine($site) . ': ' . implode(', ', $parts);
    }

    /**
     * @param list<Transition> $entries
     */
    public function body(string $site, array $entries, string $checkedAt, string $dashboardUrl, int $heldBack = 0): string
    {
        $lines = ['Feed health on ' . self::oneLine($site), 'Checked ' . $checkedAt, ''];
        if ($heldBack > 0) {
            $lines[] = sprintf('%d notification%s held back by the daily email cap since the last email. See the event log.', $heldBack, $heldBack === 1 ? ' was' : 's were');
            $lines[] = '';
        }
        foreach ($entries as $entry) {
            array_push($lines, ...$this->section($entry, $dashboardUrl));
            $lines[] = '';
        }
        $lines[] = 'Dashboard: ' . $dashboardUrl;

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    public function section(Transition $entry, string $dashboardUrl): array
    {
        if ($entry->kind === Transition::SYSTEMIC) {
            return array_merge(
                ['SYSTEMIC FAILURE', $entry->reason],
                array_map(fn (string $line): string => '  ' . $this->redactor->redactText($line), self::listLines($entry))
            );
        }
        $feed = $entry->feed;
        $heading = match ($entry->kind) {
            Transition::RECOVERY => 'RECOVERED',
            Transition::STILL => 'STILL ' . strtoupper($entry->to),
            default => strtoupper($entry->to),
        };
        if ($entry->flapping) {
            $heading .= ' (FLAPPING: alerts held until the status holds for 6 h)';
        }
        $lastStatus = $feed['last_http_status'] ?? null;
        $lastSuccess = $feed['last_success_text'] ?? null;
        $id = (int) ($feed['id'] ?? 0);

        return [
            $heading . ': ' . self::oneLine((string) ($feed['label'] ?? '')),
            '  Status: ' . $entry->to . ($entry->from !== '' && $entry->kind !== Transition::STILL ? ' (was ' . $entry->from . ')' : ''),
            '  Reason: ' . self::oneLine($this->redactor->redactText($entry->reason)),
            '  Last HTTP status: ' . ($lastStatus === null || $lastStatus === '' ? 'none' : (string) $lastStatus),
            '  Last success: ' . ($lastSuccess === null || $lastSuccess === '' ? 'never' : (string) $lastSuccess),
            '  Feed: ' . $this->redactor->redact((string) ($feed['url'] ?? '')),
            '  Details: ' . ($id > 0 ? self::detailUrl($dashboardUrl, $id) : $dashboardUrl),
        ];
    }

    public static function detailUrl(string $dashboardUrl, int $feedId): string
    {
        return $dashboardUrl . (str_contains($dashboardUrl, '?') ? '&' : '?') . 'feed=' . $feedId;
    }

    /**
     * @return list<string>
     */
    private static function listLines(Transition $entry): array
    {
        $raw = $entry->feed['list'] ?? '';
        $list = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($list) ? $list : [] as $line) {
            if (is_string($line)) {
                $out[] = self::oneLine($line);
            }
        }

        return $out;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}
