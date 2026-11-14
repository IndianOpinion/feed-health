<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Storage;

use IndianOpinion\FeedHealth\Exception\StorageError;

/**
 * Db over WordPress's $wpdb. Every value goes through $wpdb->prepare().
 * Column and table names are checked against a strict pattern, never
 * interpolated from input.
 */
final class WpdbDb implements Db
{
    private const NAME = '/^[a-z_][a-z0-9_]{0,63}$/';
    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN'];
    private const FUNCTIONS = ['SUM', 'COUNT', 'MAX', 'MIN'];

    /** @var array<string, true> */
    private array $knownTables = [];

    public function __construct(private \wpdb $wpdb)
    {
    }

    public function tableName(string $table): string
    {
        $this->assertName($table);
        $prefix = (string) $this->wpdb->prefix;

        return $prefix . $table;
    }

    public function insert(string $table, array $row): int
    {
        $this->run($this->insertSql($table, [$row], false));

        return (int) $this->wpdb->insert_id;
    }

    public function insertIgnore(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return $this->run($this->insertSql($table, $rows, true));
    }

    public function update(string $table, array $set, array $where): int
    {
        if ($set === []) {
            return 0;
        }
        $parts = [];
        $args = [];
        foreach ($set as $column => $value) {
            $this->assertName($column);
            $parts[] = '`' . $column . '` = ' . $this->placeholder($value, $args);
        }
        $sql = 'UPDATE `' . $this->tableName($table) . '` SET ' . implode(', ', $parts)
            . $this->whereSql($where, $args);

        return $this->run($this->prepare($sql, $args));
    }

    public function increment(string $table, array $amounts, array $where): int
    {
        if ($amounts === []) {
            return 0;
        }
        $parts = [];
        $args = [];
        foreach ($amounts as $column => $amount) {
            $this->assertName($column);
            $parts[] = '`' . $column . '` = `' . $column . '` + %d';
            $args[] = $amount;
        }
        $sql = 'UPDATE `' . $this->tableName($table) . '` SET ' . implode(', ', $parts)
            . $this->whereSql($where, $args);

        return $this->run($this->prepare($sql, $args));
    }

    public function delete(string $table, array $where): int
    {
        $args = [];
        $sql = 'DELETE FROM `' . $this->tableName($table) . '`' . $this->whereSql($where, $args);

        return $this->run($this->prepare($sql, $args));
    }

    public function select(
        string $table,
        array $where = [],
        array $columns = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0
    ): array {
        $cols = '*';
        if ($columns !== []) {
            foreach ($columns as $column) {
                $this->assertName($column);
            }
            $cols = '`' . implode('`, `', $columns) . '`';
        }
        $args = [];
        $sql = 'SELECT ' . $cols . ' FROM `' . $this->tableName($table) . '`' . $this->whereSql($where, $args);
        $sql .= $this->orderSql($orderBy);
        if ($limit !== null) {
            $sql .= ' LIMIT %d OFFSET %d';
            $args[] = max(0, $limit);
            $args[] = max(0, $offset);
        }

        return $this->results($this->prepare($sql, $args));
    }

    public function count(string $table, array $where = []): int
    {
        $args = [];
        $sql = 'SELECT COUNT(*) AS n FROM `' . $this->tableName($table) . '`' . $this->whereSql($where, $args);
        $rows = $this->results($this->prepare($sql, $args));

        return (int) ($rows[0]['n'] ?? 0);
    }

    public function aggregate(string $table, array $where, array $groupBy, array $aggregates): array
    {
        $select = [];
        foreach ($groupBy as $column) {
            $this->assertName($column);
            $select[] = '`' . $column . '`';
        }
        foreach ($aggregates as $alias => [$function, $column]) {
            $this->assertName($alias);
            $function = strtoupper($function);
            if (!in_array($function, self::FUNCTIONS, true)) {
                throw new StorageError('Unsupported aggregate function.');
            }
            if ($function === 'COUNT') {
                $select[] = 'COUNT(*) AS `' . $alias . '`';
                continue;
            }
            $this->assertName($column);
            $select[] = $function . '(`' . $column . '`) AS `' . $alias . '`';
        }
        $args = [];
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $this->tableName($table) . '`'
            . $this->whereSql($where, $args);
        if ($groupBy !== []) {
            $sql .= ' GROUP BY `' . implode('`, `', $groupBy) . '`';
        }

        return $this->results($this->prepare($sql, $args));
    }

    public function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            $name = $this->tableName($table);
            if (isset($this->knownTables[$name])) {
                continue;
            }
            $like = $this->wpdb->esc_like($name);
            $found = $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if ($this->wpdb->last_error !== '') {
                throw new StorageError((string) $this->wpdb->last_error);
            }
            if ($found !== $name) {
                return false;
            }
            $this->knownTables[$name] = true;
        }

        return true;
    }

    public function install(array $definitions): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $collate = (string) $this->wpdb->get_charset_collate();
        $sql = [];
        foreach ($definitions as $table => $body) {
            $sql[] = 'CREATE TABLE ' . $this->tableName($table) . " (\n" . $body . "\n) " . $collate . ';';
        }
        dbDelta($sql);
        if ($this->wpdb->last_error !== '') {
            throw new StorageError((string) $this->wpdb->last_error);
        }
        $this->knownTables = [];
    }

    public function drop(array $tables): void
    {
        foreach ($tables as $table) {
            $this->run('DROP TABLE IF EXISTS `' . $this->tableName($table) . '`');
        }
        $this->knownTables = [];
    }

    /**
     * @param list<array<string, int|string|null>> $rows
     */
    private function insertSql(string $table, array $rows, bool $ignore): string
    {
        $columns = array_keys($rows[0]);
        foreach ($columns as $column) {
            $this->assertName($column);
        }
        $args = [];
        $tuples = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                throw new StorageError('Rows in one insert must share their columns.');
            }
            $values = [];
            foreach ($row as $value) {
                $values[] = $this->placeholder($value, $args);
            }
            $tuples[] = '(' . implode(', ', $values) . ')';
        }
        $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $this->tableName($table) . '` (`'
            . implode('`, `', $columns) . '`) VALUES ' . implode(', ', $tuples);

        return $this->prepare($sql, $args);
    }

    /**
     * @param array<string, mixed> $where
     * @param list<int|string> $args
     */
    private function whereSql(array $where, array &$args): string
    {
        if ($where === []) {
            return '';
        }
        $parts = [];
        foreach ($where as $key => $value) {
            [$column, $operator] = self::splitCondition($key);
            $this->assertName($column);
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new StorageError('Unsupported operator.');
            }
            if ($operator === 'IN' || $operator === 'NOT IN') {
                $list = is_array($value) ? array_values($value) : [$value];
                if ($list === []) {
                    $parts[] = $operator === 'IN' ? '1 = 0' : '1 = 1';
                    continue;
                }
                $holders = [];
                foreach ($list as $item) {
                    $holders[] = $this->placeholder(self::scalar($item), $args);
                }
                $parts[] = '`' . $column . '` ' . $operator . ' (' . implode(', ', $holders) . ')';
                continue;
            }
            if ($value === null) {
                if ($operator !== '=' && $operator !== '!=') {
                    throw new StorageError('Null only compares with = or !=.');
                }
                $parts[] = '`' . $column . '` IS ' . ($operator === '=' ? '' : 'NOT ') . 'NULL';
                continue;
            }
            $parts[] = '`' . $column . '` ' . $operator . ' ' . $this->placeholder(self::scalar($value), $args);
        }

        return ' WHERE ' . implode(' AND ', $parts);
    }

    /**
     * @param array<string, string> $orderBy
     */
    private function orderSql(array $orderBy): string
    {
        if ($orderBy === []) {
            return '';
        }
        $parts = [];
        foreach ($orderBy as $column => $direction) {
            $this->assertName($column);
            $parts[] = '`' . $column . '` ' . (strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC');
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param list<int|string> $args
     */
    private function placeholder(int|string|null $value, array &$args): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $args[] = $value;

        return is_int($value) ? '%d' : '%s';
    }

    /**
     * @param list<int|string> $args
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            return $sql;
        }

        return (string) $this->wpdb->prepare($sql, ...$args);
    }

    private function run(string $sql): int
    {
        $result = $this->wpdb->query($sql);
        if ($result === false || $this->wpdb->last_error !== '') {
            throw new StorageError($this->wpdb->last_error !== '' ? (string) $this->wpdb->last_error : 'Query failed.');
        }

        return (int) $result;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function results(string $sql): array
    {
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $this->wpdb->last_error !== '') {
            throw new StorageError($this->wpdb->last_error !== '' ? (string) $this->wpdb->last_error : 'Query failed.');
        }
        $out = [];
        foreach ($rows as $row) {
            $clean = [];
            foreach ((array) $row as $key => $value) {
                $clean[(string) $key] = is_int($value) || $value === null ? $value : (string) $value;
            }
            $out[] = $clean;
        }

        return $out;
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new StorageError('Invalid identifier.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function splitCondition(string $key): array
    {
        $key = trim($key);
        if (preg_match('/^([a-z_][a-z0-9_]*)\s*(.*)$/i', $key, $m) !== 1) {
            return [$key, '='];
        }
        $operator = strtoupper(trim($m[2]));

        return [$m[1], $operator === '' ? '=' : $operator];
    }

    private static function scalar(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value)) {
            return (string) $value;
        }
        throw new StorageError('Unsupported value type in a condition.');
    }
}
