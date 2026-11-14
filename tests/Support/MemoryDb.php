<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Support;

use IndianOpinion\FeedHealth\Exception\StorageError;
use IndianOpinion\FeedHealth\Storage\Db;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Storage\WpdbDb;

/**
 * In-memory Db with the same semantics as WpdbDb: unique keys, NULL
 * comparisons, ordering, limits and grouped aggregates. Counts every call
 * and can be told to fail.
 */
final class MemoryDb implements Db
{
    /** @var array<string, array<int, array<string, int|string|null>>> */
    public array $tables = [];
    /** @var array<string, int> */
    private array $autoIncrement = [];
    public int $calls = 0;
    public ?string $failOn = null;
    public int $failAfter = -1;

    /** @var callable(): string */
    private $prefix;

    /**
     * @param (callable(): string)|null $prefix
     */
    public function __construct(?callable $prefix = null)
    {
        $this->prefix = $prefix ?? static fn (): string => 'wp_';
    }

    public function tableName(string $table): string
    {
        return ($this->prefix)() . $table;
    }

    private function hit(string $op): void
    {
        $this->calls++;
        if ($this->failOn !== null && ($this->failOn === '*' || $this->failOn === $op)) {
            if ($this->failAfter <= 0) {
                throw new StorageError('Simulated database failure on ' . $op . '.');
            }
            $this->failAfter--;
        }
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    private function &rows(string $table): array
    {
        $name = $this->tableName($table);
        if (!array_key_exists($name, $this->tables)) {
            throw new StorageError("Table '" . $name . "' doesn't exist");
        }

        return $this->tables[$name];
    }

    public function insert(string $table, array $row): int
    {
        $this->hit('insert');
        $inserted = $this->put($table, $row, false);
        if ($inserted === 0) {
            throw new StorageError('Duplicate entry for a unique key.');
        }

        return $inserted;
    }

    public function insertIgnore(string $table, array $rows): int
    {
        $this->hit('insert');
        $n = 0;
        foreach ($rows as $row) {
            if ($this->put($table, $row, true) > 0) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function put(string $table, array $row, bool $ignore): int
    {
        $rows = &$this->rows($table);
        $name = $this->tableName($table);
        $row = array_merge(self::defaults($table), $row);
        foreach (self::uniques($table) as $columns) {
            foreach ($rows as $existing) {
                $same = true;
                foreach ($columns as $c) {
                    if ((string) $existing[$c] !== (string) $row[$c]) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    return 0;
                }
            }
        }
        $id = ($this->autoIncrement[$name] ?? 0) + 1;
        $this->autoIncrement[$name] = $id;
        $row['id'] = $id;
        $rows[$id] = $row;

        return $id;
    }

    public function update(string $table, array $set, array $where): int
    {
        $this->hit('update');
        $rows = &$this->rows($table);
        $n = 0;
        foreach ($rows as $id => $row) {
            if (self::matches($row, $where)) {
                $rows[$id] = array_merge($row, $set);
                $n++;
            }
        }

        return $n;
    }

    public function increment(string $table, array $amounts, array $where): int
    {
        $this->hit('update');
        $rows = &$this->rows($table);
        $n = 0;
        foreach ($rows as $id => $row) {
            if (self::matches($row, $where)) {
                foreach ($amounts as $col => $by) {
                    $rows[$id][$col] = (int) $row[$col] + $by;
                }
                $n++;
            }
        }

        return $n;
    }

    public function delete(string $table, array $where): int
    {
        $this->hit('delete');
        $rows = &$this->rows($table);
        $n = 0;
        foreach ($rows as $id => $row) {
            if (self::matches($row, $where)) {
                unset($rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function select(string $table, array $where = [], array $columns = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        $this->hit('select');
        $out = array_values(array_filter($this->rows($table), static fn (array $r): bool => self::matches($r, $where)));
        if ($orderBy !== []) {
            usort($out, static function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $col => $dir) {
                    $cmp = $a[$col] <=> $b[$col];
                    if ($cmp !== 0) {
                        return strtoupper($dir) === 'DESC' ? -$cmp : $cmp;
                    }
                }

                return 0;
            });
        }
        if ($limit !== null) {
            $out = array_slice($out, $offset, $limit);
        }
        if ($columns !== []) {
            $out = array_map(static fn (array $r): array => array_intersect_key($r, array_flip($columns)), $out);
        }

        return array_values($out);
    }

    public function count(string $table, array $where = []): int
    {
        $this->hit('select');

        return count(array_filter($this->rows($table), static fn (array $r): bool => self::matches($r, $where)));
    }

    public function aggregate(string $table, array $where, array $groupBy, array $aggregates): array
    {
        $this->hit('select');
        $groups = [];
        foreach ($this->rows($table) as $row) {
            if (!self::matches($row, $where)) {
                continue;
            }
            $key = implode("\0", array_map(static fn (string $c): string => (string) $row[$c], $groupBy));
            $groups[$key][] = $row;
        }
        if ($groupBy === [] && $groups === []) {
            $groups[''] = [];
        }
        $out = [];
        foreach ($groups as $rows) {
            $result = [];
            foreach ($groupBy as $c) {
                $result[$c] = $rows[0][$c];
            }
            foreach ($aggregates as $alias => [$fn, $col]) {
                $values = array_map(static fn (array $r): int => (int) ($r[$col] ?? 0), $rows);
                $result[$alias] = match (strtoupper($fn)) {
                    'COUNT' => count($rows),
                    'SUM' => $rows === [] ? null : array_sum($values),
                    'MAX' => $rows === [] ? null : max($values),
                    'MIN' => $rows === [] ? null : min($values),
                    default => throw new StorageError('Unsupported aggregate.'),
                };
            }
            $out[] = $result;
        }

        return $out;
    }

    public function tablesExist(array $tables): bool
    {
        $this->hit('select');
        foreach ($tables as $t) {
            if (!array_key_exists($this->tableName($t), $this->tables)) {
                return false;
            }
        }

        return true;
    }

    public function install(array $definitions): void
    {
        $this->hit('install');
        foreach (array_keys($definitions) as $t) {
            $this->tables[$this->tableName($t)] ??= [];
        }
    }

    public function drop(array $tables): void
    {
        $this->hit('drop');
        foreach ($tables as $t) {
            unset($this->tables[$this->tableName($t)]);
        }
    }

    /**
     * @param array<string, int|string|null> $row
     * @param array<string, mixed> $where
     */
    private static function matches(array $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            [$col, $op] = WpdbDb::splitCondition($key);
            $actual = $row[$col] ?? null;
            if ($op === 'IN' || $op === 'NOT IN') {
                $list = array_map('strval', is_array($value) ? $value : [$value]);
                $in = $actual !== null && in_array((string) $actual, $list, true);
                if ($op === 'IN' ? !$in : $in) {
                    return false;
                }
                continue;
            }
            if ($value === null) {
                $ok = $op === '=' ? $actual === null : $actual !== null;
                if (!$ok) {
                    return false;
                }
                continue;
            }
            if ($actual === null) {
                return false;
            }
            $a = is_int($value) ? (int) $actual : (string) $actual;
            $b = is_bool($value) ? (int) $value : $value;
            $ok = match ($op) {
                '=' => $a == $b,
                '!=' => $a != $b,
                '<' => $a < $b,
                '<=' => $a <= $b,
                '>' => $a > $b,
                '>=' => $a >= $b,
                default => throw new StorageError('Unsupported operator.'),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Column defaults parsed from the schema.
     *
     * @return array<string, int|string|null>
     */
    private static function defaults(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $out = [];
        foreach (explode(",\n", Schema::definitions()[$table] ?? '') as $line) {
            if (preg_match('/^([a-z_]+) (\w+)/', $line, $m) !== 1 || in_array($m[1], ['id'], true) || str_starts_with($line, 'PRIMARY') || str_starts_with($line, 'KEY') || str_starts_with($line, 'UNIQUE')) {
                continue;
            }
            if (preg_match("/DEFAULT '([^']*)'/", $line, $d) === 1) {
                $out[$m[1]] = $d[1];
            } elseif (preg_match('/DEFAULT (-?\d+)/', $line, $d) === 1) {
                $out[$m[1]] = (int) $d[1];
            } else {
                $out[$m[1]] = null;
            }
        }

        return $cache[$table] = $out;
    }

    /**
     * @return list<list<string>>
     */
    private static function uniques(string $table): array
    {
        $out = [];
        foreach (explode(",\n", Schema::definitions()[$table] ?? '') as $line) {
            if (preg_match('/^UNIQUE KEY \w+ \(([^)]+)\)/', $line, $m) === 1) {
                $out[] = explode(',', $m[1]);
            }
        }

        return $out;
    }

    /**
     * Every stored string, for "nothing stored" assertions.
     */
    public function dump(): string
    {
        return (string) json_encode($this->tables);
    }
}
