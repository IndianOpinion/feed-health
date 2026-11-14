<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Exception\CanonicalisationFailed;

/**
 * Turns an article or feed URL into a canonical string and a fixed-length
 * key, so that one article reached through several URL variants counts once.
 *
 * Pure: no network, no WordPress calls except the tracking parameter filter.
 */
final class UrlCanonicalizer
{
    /** Host labels dropped when they lead the host name. */
    private const HOST_PREFIXES = ['www', 'm', 'mobile', 'amp'];

    /** Query parameters that only switch an AMP view. */
    private const AMP_PARAMS = ['amp', '_amp', 'amp_js_v', 'amp_gsa', 'outputtype'];

    /** Tracking parameters dropped by default (plus every utm_* name). */
    public const TRACKING_PARAMS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'cmpid',
        'igshid', 'yclid', '_ga', 'ito', 'ocid', 'smid', 'cmp', 'ns_source', 'ns_mchannel',
        'ns_campaign', 'rss', 'src',
    ];

    /**
     * Canonical form of an item URL: no scheme, no tracking, sorted query.
     *
     * @throws CanonicalisationFailed
     */
    public function canonical(string $url): string
    {
        return $this->build($url, false);
    }

    /**
     * Canonical form of a feed URL: the same rules, but every query
     * parameter is kept (feeds select content by query) and only sorted.
     *
     * @throws CanonicalisationFailed
     */
    public function canonicalFeed(string $url): string
    {
        return $this->build($url, true);
    }

    /**
     * The fixed-length hex key of a canonical string.
     */
    public static function key(string $canonical): string
    {
        return substr(hash('sha256', $canonical), 0, 32);
    }

    /**
     * Fallback when a URL cannot be parsed: trimmed and lower-cased.
     */
    public static function fallback(string $url): string
    {
        return strtolower(trim($url));
    }

    /**
     * Approximate registrable host (last two labels, or three under common
     * two-letter country second-level domains). No public suffix list.
     */
    public static function registrableHost(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $labels = explode('.', strtolower(rtrim($host, '.')));
        $n = count($labels);
        if ($n <= 2) {
            return implode('.', $labels);
        }
        $second = $labels[$n - 2];
        $tld = $labels[$n - 1];
        $take = strlen($tld) === 2 && in_array($second, ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu', 'nic', 'res', 'gen', 'ltd', 'plc', 'or', 'ne', 'go'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    private function build(string $url, bool $feed): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/\s/', $url) === 1) {
            throw new CanonicalisationFailed('Empty or spaced URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw new CanonicalisationFailed('URL has no host.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new CanonicalisationFailed('Not an http or https URL.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $labels = explode('.', $host);
        if (count($labels) > 2 && in_array($labels[0], self::HOST_PREFIXES, true)) {
            array_shift($labels);
            $host = implode('.', $labels);
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== 80 && $port !== 443) {
            $host .= ':' . $port;
        }

        $path = self::percentCase($parts['path'] ?? '');
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = (string) preg_replace('#/amp/?$#i', '', $path);
        $path = rtrim($path, '/');

        $query = $this->query($parts['query'] ?? '', $feed);

        return $host . $path . ($query !== '' ? '?' . $query : '');
    }

    private function query(string $query, bool $feed): string
    {
        if ($query === '') {
            return '';
        }
        $tracking = $feed ? [] : $this->trackingParams();
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $bits = explode('=', $pair, 2);
            $name = self::percentCase($bits[0]);
            $value = self::percentCase($bits[1] ?? '');
            $lower = strtolower(rawurldecode($name));
            if (!$feed) {
                if (str_starts_with($lower, 'utm_') || in_array($lower, $tracking, true)) {
                    continue;
                }
                if (in_array($lower, self::AMP_PARAMS, true)) {
                    continue;
                }
            }
            $pairs[] = [$name, $value, isset($bits[1])];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($pairs as [$name, $value, $hasValue]) {
            $out[] = $hasValue ? $name . '=' . $value : $name;
        }

        return implode('&', $out);
    }

    /**
     * @return list<string>
     */
    private function trackingParams(): array
    {
        $list = apply_filters('feed_health_tracking_params', self::TRACKING_PARAMS);
        $out = [];
        foreach (is_array($list) ? $list : self::TRACKING_PARAMS as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = strtolower($name);
            }
        }

        return $out;
    }

    private static function percentCase(string $value): string
    {
        return (string) preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $m): string => strtoupper($m[0]),
            $value
        );
    }
}
