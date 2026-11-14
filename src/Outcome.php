<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

/**
 * Fetch outcomes and how they count.
 */
final class Outcome
{
    public const OK = 'ok';
    public const NOT_MODIFIED = 'not_modified';
    public const HTTP_ERROR = 'http_error';
    public const TRANSPORT_ERROR = 'transport_error';
    public const PARSE_ERROR = 'parse_error';
    public const EMPTY = 'empty';
    public const CACHED = 'cached';
    public const FAILURE_OTHER = 'failure_other';

    public const ALL = [
        self::OK,
        self::NOT_MODIFIED,
        self::HTTP_ERROR,
        self::TRANSPORT_ERROR,
        self::PARSE_ERROR,
        self::EMPTY,
        self::CACHED,
        self::FAILURE_OTHER,
    ];

    public const FAILURES = [self::HTTP_ERROR, self::TRANSPORT_ERROR, self::PARSE_ERROR, self::FAILURE_OTHER];
    public const SUCCESSES = [self::OK, self::NOT_MODIFIED, self::EMPTY];

    /**
     * Known outcomes pass through; anything else becomes failure_other.
     */
    public static function normalise(string $outcome): string
    {
        $outcome = strtolower(trim($outcome));

        return in_array($outcome, self::ALL, true) ? $outcome : self::FAILURE_OTHER;
    }

    public static function isFailure(string $outcome): bool
    {
        return in_array($outcome, self::FAILURES, true);
    }

    public static function isSuccess(string $outcome): bool
    {
        return in_array($outcome, self::SUCCESSES, true);
    }

    /**
     * A short label for an HTTP error status.
     */
    public static function httpLabel(?int $status): string
    {
        if ($status === null) {
            return '';
        }

        return match (true) {
            $status === 401, $status === 403 => 'blocked',
            $status === 404, $status === 410 => 'gone',
            $status === 429 => 'rate limited',
            $status >= 500 && $status <= 599 => 'server error',
            default => '',
        };
    }

    /**
     * "HTTP 403 blocked", "HTTP 418", or "" when there is no status.
     */
    public static function describeHttp(?int $status): string
    {
        if ($status === null) {
            return '';
        }

        return trim('HTTP ' . $status . ' ' . self::httpLabel($status));
    }
}
