<?php

/**
 * Minimal WP-CLI stand-in. error() and halt() throw CliExit so tests can
 * read the exit code.
 */

declare(strict_types=1);

namespace {
    final class CliExit extends RuntimeException
    {
        public function __construct(public readonly int $exitCode, string $message = '')
        {
            parent::__construct($message);
        }
    }

    final class WP_CLI
    {
        /** @var list<string> */
        public static array $out = [];
        /** @var array<string, object> */
        public static array $commands = [];

        public static function reset(): void
        {
            self::$out = [];
            self::$commands = [];
        }

        public static function line(string $text = ''): void
        {
            self::$out[] = $text;
        }

        public static function success(string $text): void
        {
            self::$out[] = 'Success: ' . $text;
        }

        public static function warning(string $text): void
        {
            self::$out[] = 'Warning: ' . $text;
        }

        /** @return never */
        public static function error(string $text): void
        {
            self::$out[] = 'Error: ' . $text;
            throw new CliExit(1, $text);
        }

        /** @return never */
        public static function halt(int $code): void
        {
            throw new CliExit($code);
        }

        public static function add_command(string $name, object $command): void
        {
            self::$commands[$name] = $command;
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        if ($format === 'json') {
            \WP_CLI::$out[] = (string) json_encode($items);

            return;
        }
        \WP_CLI::$out[] = implode("\t", $fields);
        foreach ($items as $item) {
            $row = [];
            foreach ($fields as $field) {
                $row[] = is_scalar($item[$field] ?? '') ? (string) ($item[$field] ?? '') : '';
            }
            \WP_CLI::$out[] = implode("\t", $row);
        }
    }
}
