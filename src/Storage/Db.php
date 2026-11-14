<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Storage;

use IndianOpinion\FeedHealth\Exception\StorageError;

/**
 * The narrow table gateway the plugin uses for every read and write.
 *
 * Table names are short names without the site prefix (for example
 * "feedhealth_feeds"). Conditions are maps of "column" or "column op" to a
 * value. Supported operators: =, !=, <, <=, >, >=, IN, NOT IN. A null value
 * with = or != means IS NULL or IS NOT NULL. An empty IN list matches
 * nothing.
 *
 * Every method throws StorageError when the database rejects the query.
 *
 * @phpstan-type Row array<string, int|string|null>
 * @phpstan-type Where array<string, mixed>
 */
interface Db
{
    /**
     * @param array<string, int|string|null> $row
     * @return int The new auto-increment id.
     * @throws StorageError
     */
    public function insert(string $table, array $row): int;

    /**
     * Insert several rows, skipping rows that collide with a unique key.
     *
     * @param list<array<string, int|string|null>> $rows All rows share the same columns.
     * @return int Rows inserted.
     * @throws StorageError
     */
    public function insertIgnore(string $table, array $rows): int;

    /**
     * @param array<string, int|string|null> $set
     * @param array<string, mixed> $where
     * @return int Rows matched.
     * @throws StorageError
     */
    public function update(string $table, array $set, array $where): int;

    /**
     * Add the given amounts to integer columns.
     *
     * @param array<string, int> $amounts
     * @param array<string, mixed> $where
     * @throws StorageError
     */
    public function increment(string $table, array $amounts, array $where): int;

    /**
     * @param array<string, mixed> $where
     * @return int Rows deleted.
     * @throws StorageError
     */
    public function delete(string $table, array $where): int;

    /**
     * @param array<string, mixed> $where
     * @param list<string> $columns Empty means every column.
     * @param array<string, string> $orderBy Column to "ASC" or "DESC".
     * @return list<array<string, int|string|null>>
     * @throws StorageError
     */
    public function select(
        string $table,
        array $where = [],
        array $columns = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0
    ): array;

    /**
     * @param array<string, mixed> $where
     * @throws StorageError
     */
    public function count(string $table, array $where = []): int;

    /**
     * Grouped aggregates. Each aggregate is alias => [function, column],
     * with function one of SUM, COUNT, MAX, MIN. COUNT ignores the column.
     *
     * @param array<string, mixed> $where
     * @param list<string> $groupBy
     * @param array<string, array{0: string, 1: string}> $aggregates
     * @return list<array<string, int|string|null>>
     * @throws StorageError
     */
    public function aggregate(string $table, array $where, array $groupBy, array $aggregates): array;

    /**
     * True when every listed table exists.
     *
     * @param list<string> $tables
     */
    public function tablesExist(array $tables): bool;

    /**
     * Create or upgrade the tables from their definitions.
     *
     * @param array<string, string> $definitions Short table name to the
     *        column and key lines of a CREATE TABLE statement.
     * @throws StorageError
     */
    public function install(array $definitions): void;

    /**
     * Drop the listed tables if they exist.
     *
     * @param list<string> $tables
     * @throws StorageError
     */
    public function drop(array $tables): void;

    /**
     * The full table name with the current site prefix.
     */
    public function tableName(string $table): string;
}
