<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Exception\StorageError;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Storage\WpdbDb;
use PHPUnit\Framework\TestCase;

/**
 * The SQL WpdbDb builds, against a recording wpdb.
 */
final class WpdbDbTest extends TestCase
{
    private \wpdb $wpdb;
    private WpdbDb $db;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
        $this->db = new WpdbDb($this->wpdb);
    }

    private function last(): string
    {
        return (string) end($this->wpdb->queries);
    }

    public function testSelectWithConditionsOrderAndLimit(): void
    {
        $this->wpdb->scripted = [[['id' => '1', 'label' => 'x']]];
        $rows = $this->db->select(Schema::FEEDS, ['adapter' => 'core', 'status IN' => ['failing', "q'x"], 'last_attempt <' => 5, 'muted' => null], ['id', 'label'], ['id' => 'DESC'], 10, 20);
        self::assertSame(
            "SELECT `id`, `label` FROM `wp_feedhealth_feeds` WHERE `adapter` = 'core' AND `status` IN ('failing', 'q\\'x') AND `last_attempt` < 5 AND `muted` IS NULL ORDER BY `id` DESC LIMIT 10 OFFSET 20",
            $this->last()
        );
        self::assertSame([['id' => '1', 'label' => 'x']], $rows);
    }

    public function testInsertIgnoreUpdateIncrementDelete(): void
    {
        $this->db->insertIgnore(Schema::SEEN, [
            ['feed_id' => 1, 'seen_key' => 'abc', 'first_seen' => 5, 'last_seen' => 5],
            ['feed_id' => 1, 'seen_key' => 'def', 'first_seen' => 5, 'last_seen' => 5],
        ]);
        self::assertSame("INSERT IGNORE INTO `wp_feedhealth_seen` (`feed_id`, `seen_key`, `first_seen`, `last_seen`) VALUES (1, 'abc', 5, 5), (1, 'def', 5, 5)", $this->last());
        $this->db->update(Schema::FEEDS, ['final_url' => null, 'status' => 'quiet'], ['id' => 3]);
        self::assertSame("UPDATE `wp_feedhealth_feeds` SET `final_url` = NULL, `status` = 'quiet' WHERE `id` = 3", $this->last());
        $this->db->increment(Schema::FETCHES, ['fresh' => 2, 'accepted' => 1], ['id' => 9]);
        self::assertSame('UPDATE `wp_feedhealth_fetches` SET `fresh` = `fresh` + 2, `accepted` = `accepted` + 1 WHERE `id` = 9', $this->last());
        $this->db->delete(Schema::SEEN, ['last_seen <' => 100, 'seen_key IN' => []]);
        self::assertSame('DELETE FROM `wp_feedhealth_seen` WHERE `last_seen` < 100 AND 1 = 0', $this->last());
    }

    public function testAggregate(): void
    {
        $this->db->aggregate(Schema::FETCHES, ['started_at >=' => 1], ['feed_id', 'outcome'], ['n' => ['COUNT', '*'], 'fresh' => ['SUM', 'fresh']]);
        self::assertSame('SELECT `feed_id`, `outcome`, COUNT(*) AS `n`, SUM(`fresh`) AS `fresh` FROM `wp_feedhealth_fetches` WHERE `started_at` >= 1 GROUP BY `feed_id`, `outcome`', $this->last());
    }

    public function testIdentifiersAreChecked(): void
    {
        $this->expectException(StorageError::class);
        $this->db->select(Schema::FEEDS, ['id; DROP TABLE x' => 1]);
    }

    public function testOperatorsAreChecked(): void
    {
        $this->expectException(StorageError::class);
        $this->db->select(Schema::FEEDS, ['id LIKE' => '%']);
    }

    public function testDatabaseErrorBecomesStorageError(): void
    {
        $this->wpdb->scripted = [false];
        $this->expectException(StorageError::class);
        $this->db->delete(Schema::FEEDS, ['id' => 1]);
    }

    public function testPrefixFollowsTheCurrentSite(): void
    {
        $this->wpdb->prefix = 'wp_3_';
        self::assertSame('wp_3_feedhealth_runs', $this->db->tableName(Schema::RUNS));
    }
}
