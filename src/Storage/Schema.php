<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Storage;

/**
 * Table definitions. Times are Unix seconds (UTC) in BIGINT columns so that
 * no query depends on the database's time zone or date functions.
 *
 * Index lengths: adapter (40) plus feed_key (191) in utf8mb4 is 924 bytes,
 * inside the 3072-byte limit of InnoDB DYNAMIC rows on MySQL 5.7 and
 * MariaDB 10.4.
 */
final class Schema
{
    public const VERSION = 1;

    public const FEEDS = 'feedhealth_feeds';
    public const FETCHES = 'feedhealth_fetches';
    public const SEEN = 'feedhealth_seen';
    public const DAILY = 'feedhealth_daily';
    public const RUNS = 'feedhealth_runs';
    public const EVENTS = 'feedhealth_events';

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [self::FEEDS, self::FETCHES, self::SEEN, self::DAILY, self::RUNS, self::EVENTS];
    }

    /**
     * Column and key lines per table, in the format dbDelta() expects
     * (two spaces after PRIMARY KEY, one definition per line).
     *
     * @return array<string, string>
     */
    public static function definitions(): array
    {
        return [
            self::FEEDS => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'adapter varchar(40) NOT NULL',
                'feed_key varchar(191) NOT NULL',
                "label varchar(255) NOT NULL DEFAULT ''",
                'url text NULL',
                'final_url text NULL',
                'redirect_status smallint(5) unsigned NULL',
                'enabled tinyint(1) NOT NULL DEFAULT 1',
                "status varchar(20) NOT NULL DEFAULT 'new'",
                "reason varchar(255) NOT NULL DEFAULT ''",
                'status_since bigint(20) NOT NULL DEFAULT 0',
                'muted tinyint(1) NOT NULL DEFAULT 0',
                'snoozed_until bigint(20) NULL',
                'overrides text NULL',
                'usual_gap int(11) NULL',
                'quiet_threshold int(11) NULL',
                "threshold_source varchar(100) NOT NULL DEFAULT ''",
                'baseline_since bigint(20) NOT NULL DEFAULT 0',
                'first_seen bigint(20) NOT NULL DEFAULT 0',
                'last_attempt bigint(20) NULL',
                'last_success bigint(20) NULL',
                'last_http_status smallint(5) unsigned NULL',
                'last_outcome varchar(20) NULL',
                'last_error text NULL',
                'attempts_total int(11) unsigned NOT NULL DEFAULT 0',
                'empty_streak int(11) unsigned NOT NULL DEFAULT 0',
                'last_new_at bigint(20) NULL',
                'last_new_title varchar(255) NULL',
                'newest_published_at bigint(20) NULL',
                'notes text NULL',
                'alert_state text NULL',
                'last_manual_at bigint(20) NULL',
                'PRIMARY KEY  (id)',
                'UNIQUE KEY adapter_feed (adapter,feed_key)',
                'KEY status (status)',
            ]),
            self::FETCHES => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'feed_id bigint(20) unsigned NOT NULL',
                'run_id varchar(64) NULL',
                'started_at bigint(20) NOT NULL',
                'finished_at bigint(20) NOT NULL',
                'recorded_at bigint(20) NOT NULL',
                'outcome varchar(20) NOT NULL',
                'http_status smallint(5) unsigned NULL',
                'final_url text NULL',
                'error_code varchar(100) NULL',
                'error_message varchar(300) NULL',
                'items int(11) unsigned NOT NULL DEFAULT 0',
                'bytes int(11) unsigned NULL',
                'manual tinyint(1) NOT NULL DEFAULT 0',
                'fresh int(11) unsigned NOT NULL DEFAULT 0',
                'repeats int(11) unsigned NOT NULL DEFAULT 0',
                'accepted int(11) unsigned NOT NULL DEFAULT 0',
                'duplicates int(11) unsigned NOT NULL DEFAULT 0',
                'rejected int(11) unsigned NOT NULL DEFAULT 0',
                'PRIMARY KEY  (id)',
                'KEY feed_started (feed_id,started_at)',
                'KEY started_at (started_at)',
            ]),
            self::SEEN => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'feed_id bigint(20) unsigned NOT NULL',
                'seen_key char(32) NOT NULL',
                'first_seen bigint(20) NOT NULL',
                'last_seen bigint(20) NOT NULL',
                'PRIMARY KEY  (id)',
                'UNIQUE KEY feed_key (feed_id,seen_key)',
                'KEY seen_key (seen_key)',
                'KEY last_seen (last_seen)',
            ]),
            self::DAILY => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'feed_id bigint(20) unsigned NOT NULL',
                'day char(10) NOT NULL',
                'attempts int(11) unsigned NOT NULL DEFAULT 0',
                'failures int(11) unsigned NOT NULL DEFAULT 0',
                'http_errors int(11) unsigned NOT NULL DEFAULT 0',
                'transport_errors int(11) unsigned NOT NULL DEFAULT 0',
                'parse_errors int(11) unsigned NOT NULL DEFAULT 0',
                'other_failures int(11) unsigned NOT NULL DEFAULT 0',
                'items int(11) unsigned NOT NULL DEFAULT 0',
                'fresh int(11) unsigned NOT NULL DEFAULT 0',
                'accepted int(11) unsigned NOT NULL DEFAULT 0',
                'duplicates int(11) unsigned NOT NULL DEFAULT 0',
                'rejected int(11) unsigned NOT NULL DEFAULT 0',
                'PRIMARY KEY  (id)',
                'UNIQUE KEY feed_day (feed_id,day)',
                'KEY day (day)',
            ]),
            self::RUNS => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'run_id varchar(64) NOT NULL',
                'adapter varchar(40) NOT NULL',
                'started_at bigint(20) NOT NULL',
                'ended_at bigint(20) NULL',
                "status varchar(20) NOT NULL DEFAULT 'running'",
                'PRIMARY KEY  (id)',
                'UNIQUE KEY run_id (run_id)',
                'KEY status_started (status,started_at)',
            ]),
            self::EVENTS => implode(",\n", [
                'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                'created_at bigint(20) NOT NULL',
                'kind varchar(20) NOT NULL',
                'feed_id bigint(20) unsigned NULL',
                'user_id bigint(20) unsigned NULL',
                'message text NULL',
                'transition_id varchar(32) NULL',
                'sent tinyint(1) NOT NULL DEFAULT 0',
                'data text NULL',
                'PRIMARY KEY  (id)',
                'KEY created_at (created_at)',
                'KEY feed_created (feed_id,created_at)',
                'KEY kind_sent (kind,sent)',
                'KEY transition_id (transition_id)',
            ]),
        ];
    }
}
