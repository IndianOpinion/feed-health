<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Removes credentials from URLs before they leave the database: user info
 * goes, and values of query parameters whose name looks secret become ***.
 */
final class Redactor
{
    public const PATTERNS = ['key', 'token', 'secret', 'password', 'passwd', 'pass', 'signature', 'sig', 'auth', 'credential', 'session'];

    public function redact(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }
        $url = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1', trim($url));
        $hashAt = strpos($url, '#');
        $fragment = $hashAt === false ? '' : substr($url, $hashAt);
        $base = $hashAt === false ? $url : substr($url, 0, $hashAt);
        $queryAt = strpos($base, '?');
        if ($queryAt === false) {
            return $base . $fragment;
        }
        $patterns = $this->patterns();
        $pairs = [];
        foreach (explode('&', substr($base, $queryAt + 1)) as $pair) {
            $bits = explode('=', $pair, 2);
            $name = strtolower(rawurldecode($bits[0]));
            if (isset($bits[1]) && $this->matches($name, $patterns)) {
                $pairs[] = $bits[0] . '=***';
                continue;
            }
            $pairs[] = $pair;
        }

        return substr($base, 0, $queryAt) . '?' . implode('&', $pairs) . $fragment;
    }

    /**
     * Redact every URL found inside free text (for error messages).
     */
    public function redactText(string $text): string
    {
        return (string) preg_replace_callback(
            '#https?://[^\s"\'<>]+#i',
            fn (array $m): string => $this->redact($m[0]),
            $text
        );
    }

    /**
     * @param list<string> $patterns
     */
    private function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $list = function_exists('apply_filters') ? apply_filters('feed_health_redact_params', self::PATTERNS) : self::PATTERNS;
        $out = [];
        foreach (is_array($list) ? $list : self::PATTERNS as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $out[] = strtolower($pattern);
            }
        }

        return $out;
    }
}
