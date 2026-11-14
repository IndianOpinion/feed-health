# Changelog

All notable changes to this project are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Recording of runs, fetch attempts and items through an adapter
  interface, plain-array actions and a recorder, buffered and flushed at
  run end, at 500 rows and on shutdown.
- Reference adapter for WordPress core `fetch_feed()`: drop-in wrapper
  `feed_health_fetch_feed()` and an optional passive mode.
- Fetch outcomes `ok`, `not_modified`, `http_error`, `transport_error`,
  `parse_error`, `empty`, `cached`, `failure_other`; redirect tracking.
- Canonical URL keys for duplicate detection, with URL filters.
- Statuses `paused`, `failing`, `unchecked`, `quiet`, `redundant`,
  `degraded`, `new`, `healthy` with reasons, and an adaptive quiet
  threshold (90th percentile gap over 14 days).
- Checks on their own cron event, after each import run and daily, with
  a lock, stale-run detection, daily rollups and retention pruning.
- Notifications by email and log: batching, cooldown, reminders, flapping,
  systemic failure, recovery, daily cap (per site, optional network cap),
  retries, and the `feed_health_notify` action.
- Tools > Feed health dashboard, feed detail, event log, settings, CSV
  export and a wp-admin widget.
- Operator actions: test fetch, mute, snooze, reset baseline, forget,
  per-feed thresholds.
- WP-CLI: `status`, `feed`, `check`, `test-fetch`, `mute`, `unmute`,
  `snooze`, `test-alert`, `prune`, `events`.
- URL redaction on every output; uninstall on every site of a network.

[Unreleased]: https://github.com/IndianOpinion/feed-health/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/feed-health/releases/tag/v0.1.0
