<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Value;

use IndianOpinion\FeedHealth\Exception\InvalidValue;

/**
 * Small validation helpers shared by the value objects.
 *
 * @internal
 */
final class Input
{
    public const ADAPTER_KEY = '/^[a-z0-9_-]{1,40}$/';

    public static function adapterKey(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::ADAPTER_KEY, $value) !== 1) {
            throw new InvalidValue('Adapter key must be 1 to 40 characters from a-z 0-9 _ -.');
        }

        return $value;
    }

    public static function feedKey(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidValue('Feed key is required.');
        }
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidValue('Feed key must be 1 to 191 printable characters.');
        }

        return $value;
    }

    /**
     * A time as Unix seconds: int, numeric string, date string or DateTimeInterface.
     */
    public static function time(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            if (preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
            $time = strtotime($value);
            if ($time === false) {
                throw new InvalidValue('Unreadable time.');
            }

            return $time;
        }
        throw new InvalidValue('Unreadable time.');
    }

    public static function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) $value;
        }
        throw new InvalidValue('Expected a whole number.');
    }

    public static function optionalString(mixed $value, int $max = 0): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidValue('Expected text.');
        }
        $value = (string) $value;
        if ($max > 0 && mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    public static function string(mixed $value, int $max = 0): string
    {
        return self::optionalString($value, $max) ?? '';
    }
}
