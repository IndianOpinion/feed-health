<?php

/**
 * A wpdb stand-in. With a PDO connection it runs queries for real (the
 * MySQL integration tests); without one it records queries and returns
 * scripted results (the SQL builder tests).
 */

declare(strict_types=1);

class wpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var list<string> */
    public array $queries = [];
    /** @var list<mixed> */
    public array $scripted = [];

    public function __construct(public ?PDO $pdo = null)
    {
    }

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%%|%d|%s|%f/', function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $value = $args[$i++] ?? '';
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            if ($m[0] === '%f') {
                return (string) (float) $value;
            }

            return $this->quote((string) $value);
        }, $query);
    }

    private function quote(string $value): string
    {
        return $this->pdo !== null ? $this->pdo->quote($value) : "'" . addslashes($value) . "'";
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /** @return int|bool */
    public function query(string $sql)
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ($this->pdo === null) {
            $next = array_shift($this->scripted);

            return $next === null ? 1 : $next;
        }
        try {
            $n = $this->pdo->exec($sql);
            $this->insert_id = (int) $this->pdo->lastInsertId();

            return $n === false ? false : $n;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
    }

    /** @return list<array<string, mixed>>|null */
    public function get_results(string $sql, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ($this->pdo === null) {
            $next = array_shift($this->scripted);

            return is_array($next) ? $next : [];
        }
        try {
            $stmt = $this->pdo->query($sql);

            return $stmt === false ? null : $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();

            return null;
        }
    }

    /** @return mixed */
    public function get_var(string $sql)
    {
        $rows = $this->get_results($sql);
        if ($rows === null || $rows === []) {
            return null;
        }
        $first = reset($rows);

        return is_array($first) ? reset($first) : null;
    }
}

/**
 * @param string|list<string> $queries
 * @return array<string, string>
 */
function dbDelta($queries = '', bool $execute = true): array
{
    global $wpdb;
    foreach ((array) $queries as $sql) {
        $wpdb->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql));
    }

    return [];
}
