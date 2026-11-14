# feed-health: architecture

This document describes how the plugin is built and why. It answers the
open questions in section 13 of [PRD.md](PRD.md) and records every place
where the build refines a name or behaviour the PRD proposed.

## Modules

| Module | Role |
|---|---|
| `feed-health.php`, `autoload.php` | Plugin header, autoloader without Composer, one call to `Plugin::register()`. |
| `Plugin` | Builds the services and wires hooks, cron events, activation, deactivation, must-use install, privacy text and WP-CLI. |
| `Settings` | One small autoloaded option. Defaults, validation and clamping on save, `feed_health_settings` filter on read. Never cached, so the kill switch is read at call time. |
| `State` | One non-autoloaded option: schema version, per-day counters (30 days), check bookkeeping, email tally, held-back count. Counter increments are buffered and written once per flush. |
| `Adapter`, `SimpleAdapter`, `AdapterRegistry` | The importer interface, an adapter for action-only importers, and the registry fed by `feed_health_adapters` (duplicate keys rejected, first wins; unavailable adapters listed, not booted). |
| `Core\CoreAdapter` | Reference adapter for `fetch_feed()`: the wrapper, passive mode, outcome classification. |
| `Recorder` | Receives runs, fetches and items; buffers; resolves fresh, repeat and duplicate; writes rows; never throws. |
| `UrlCanonicalizer`, `Redactor` | Canonical article and feed URLs and their keys; credential redaction on every way out. |
| `Repository`, `Storage\Db`, `Storage\WpdbDb`, `Storage\Schema` | All table access through a narrow gateway. `WpdbDb` builds prepared SQL over `$wpdb`. Tests use an in-memory `Db` with the same semantics, and an integration suite runs the real SQL on MySQL. |
| `Health\StatusEvaluator`, `Metrics`, `Thresholds`, `Baseline`, `Duration` | Pure status rules: no I/O, so every rule is tested with plain numbers. |
| `Checker` | One check: lock, stale runs, expected feeds, metrics, statuses, transitions, notifications, daily rollup and prune. |
| `Notify\Notifier`, `EmailFormatter` | Classification, cooldown, reminders, flapping, systemic failure, batching, caps, retries, channels. |
| `Operator` | Mute, snooze, reset baseline, forget, thresholds, test fetch. Shared by the dashboard and WP-CLI. |
| `Admin\AdminPage`, `Dashboard`, `Widget` | Server-rendered Tools page, CSV export, wp-admin widget. |
| `Cli\Command` | `wp feed-health ...` |
| `Uninstaller` | Drops tables and options on every site. |

## Data flow

```
importer ──(wrapper | actions | adapter)──> Recorder ──buffer──> flush ──> feeds, fetches, seen, runs
                                                                    │
WP-Cron hourly / after a run / daily ──> Checker ──reads──> feeds, fetches, events
                                            │
                                            ├─> StatusEvaluator (pure) ─> feed status + reason
                                            ├─> Notifier ─> events (transition, notify), wp_mail, error_log, actions
                                            └─> rollup (daily) + prune
Dashboard / CSV / WP-CLI ──reads──> feeds, daily rollups, today's fetches, events
```

All times are Unix seconds (UTC) in `BIGINT` columns, so nothing depends
on the database's time zone. Days for rollups are UTC days. The email cap
and "held back since midnight" use the site's time zone (`wp_date()`).

## Rulings on the open questions

Each ruling came with the build task. The reason is ours.

1. **Passive mode hooks only core `fetch_feed()` plus the plugin's own
   adapter interface; no importer-specific hooks.** Confirmed against the
   WordPress developer reference: `fetch_feed()` fires
   `do_action_ref_array( 'wp_feed_options', array( &$feed, $url ) )` before
   `$feed->init()`, fires nothing after `init()`, and returns
   `WP_Error( 'simplepie-error', ... )` on failure. `WP_Http::request()`
   fires `do_action( 'http_api_debug', $response, 'response',
   'WpOrg\Requests\Requests', $parsed_args, $url )`, where `$response` is
   the response array or a `WP_Error`. Passive mode listens to
   `wp_feed_options`, keeps the SimplePie object it receives, and starts
   listening to `http_api_debug`. Because core has no "after" hook, the
   call is finalised when the next `fetch_feed()` starts or at `shutdown`
   (priority 1, before the recorder flushes at 1000), by reading the
   object's `error()` and item count. *Tying a response to its call:*
   `fetch_feed()` is synchronous, so every HTTP response between one
   `wp_feed_options` and the next belongs to that call. Among them, the
   response whose URL equals the feed URL wins; otherwise the first one
   (autodiscovery). The wrapper uses the same listener but scopes it
   exactly to its own call, and passive mode ignores calls made through
   the wrapper, so nothing is recorded twice. *Reason:* importer-specific
   hooks would tie the plugin to code we do not own; the two core hooks
   are public and stable.
2. **Writes: buffer per run and flush on shutdown, capped at 500 rows per
   flush.** Fetches and items are buffered in memory. The buffer is
   written when a run ends, when it reaches 500 rows (fetches plus items),
   and on `shutdown`. Items that arrive after a cap flush attach to the
   already written fetch row through counter increments. Run starts are
   written at once, one insert, so a run whose request dies still shows
   as `running` and later `incomplete`. *If the request dies:* PHP runs
   shutdown functions after a fatal error, so the buffer is still
   written; a killed process (out-of-memory kill, `SIGKILL`) loses at most
   500 rows. *Reason:* one write per item is the slow path the
   performance budget forbids, and an unbounded buffer is a memory risk on
   a long run.
3. **Dedup key store: own table of hashed keys with 30-day retention and a
   daily prune.** `feedhealth_seen` holds `(feed_id, seen_key)` unique,
   with first and last seen. "Seen from any other feed" is one query on a
   secondary index on `seen_key` for all keys of a fetch (`IN (...)`, in
   chunks of 500), not a separate global table. Pruning runs once a day
   (the daily event, and the first check of each UTC day). *Reason:* one
   table answers both "repeat for this feed" and "duplicate of another
   feed"; at 20,000 fresh items a day it holds about 600,000 rows.
4. **Baseline: p90 over the last 14 days; no weekday profile in 0.1.0.**
   The usual gap is the nearest-rank 90th percentile of the gaps between
   new-item arrivals in the last 14 days (or since a baseline reset). A
   weekday and hour profile is in the roadmap (README). *Reason:* p90
   already absorbs the nightly lull; a profile needs weeks of history per
   feed and more storage before it helps.
5. **Admin page: server-rendered; no REST route in 0.1.0.** One Tools page
   with tabs, forms posting to `admin-post.php`, inline CSS, inline SVG
   sparklines, no scripts, no external assets. *Reason:* fewer surfaces to
   secure, and it works without JavaScript.
6. **Multisite: a per-site daily email cap plus an off-by-default network
   cap.** This is the accepted reading of the ruling. Each site counts its
   own emails per day in its own time zone, against its own cap (setting
   `daily_email_cap`, default 10). A network-wide daily cap (site option,
   default 0 meaning off, set by a network administrator on the Settings
   tab) can also bound the total across all sites. Mail held by either cap
   is logged with "email cap reached" and counted in that site's next
   email. A site that wants at most one email a day sets its cap to 1.
7. **End-of-import check: yes, on the adapter's end-of-run action, with a
   daily cron fallback.** `endRun()` (and the `feed_health_run_end`
   action) fires `feed_health_run_ended`; the plugin then schedules one
   single event a minute later. WordPress refuses a duplicate single
   event within ten minutes, and the check's own lock stops two checks
   from overlapping: a second one exits with "skipped: already running".
   The daily event rolls up, prunes and runs a check, so checks continue
   even if the hourly event is lost. The check never runs inline in the
   importer's request. *Reason:* the import must not wait for a check, and
   a check must not depend on an import finishing (lesson 10).

## Decisions made in the build

- **Arrivals.** All items accepted in one fetch share one first-seen time,
  so gaps are measured between fetches that brought new items (zero gaps
  are not counted). Otherwise a 20-item fetch would add 19 zero gaps and
  drag the 90th percentile down.
- **A 10-day cadence cannot build a 14-day baseline.** With fewer than 5
  gaps in 14 days the default threshold (48 h) applies, as case 46 says.
  The ceiling (7 days) therefore bites for feeds with a usual gap of 3.5
  to 7 days. Test 37's 10-day case is checked on the threshold function
  with 10-day gaps given directly.
- **Repeats and importer outcomes.** A repeat (seen before from the same
  feed) counts only as a repeat, whatever the importer says, so the
  duplicate rate keeps repeats out of both sides. For fresh items the
  importer's outcome is kept; `unknown` resolves to duplicate when another
  feed has the key, otherwise accepted.
- **Never-fetched feeds.** A feed listed by an adapter or the
  `feed_health_expected_feeds` filter that has no record is created with
  status `unchecked` and reason "never fetched". It is created in that
  status, so there is no transition and no alert at creation; it shows on
  the dashboard and in `status --fail-on=unchecked`.
- **Flapping.** More than 4 status changes in 24 hours marks the feed
  flapping. The change that crosses the line is sent (when it would be
  sent anyway) with a "FLAPPING" marker; later changes are recorded and
  held. The mark clears once the feed has held one status for 6 hours; if
  that status alerts, a "settled after flapping" notice goes out.
- **Send states.** Each transition is an event with a send state: pending,
  sent, suppressed (muted, snoozed, cooldown, not alerting, filtered,
  flapping), held by the cap, or given up after 3 failed tries. Only
  pending ones are sent, and they are marked sent right after the channel
  accepts them. A check that dies leaves its transitions pending, so the
  next check sends them once.
- **Systemic alert.** When at least 3 feeds, and at least half of the
  feeds with an attempt in the failure window, go `failing` in one check,
  their sections are replaced by one systemic section listing them.
- **Healthy reason.** The healthy reason is a fixed string. A reason with
  ages in it would change at every check and force one write per feed;
  the ages are in their own columns.
- **Feed records only change when a value changes.** A steady check
  writes almost nothing.
- **Lock.** A third, short-lived option `feed_health_lock`, created with
  `add_option()` (atomic on the unique option name). The PRD named two
  options; uninstall removes all three, plus the network cap site option.
- **Value objects.** `FetchResult` carries two optional extras the PRD did
  not list, `feed_url` and `feed_label`, so that case 3 can create the
  feed record "with label and URL from the report", and `redirect_status`
  for case 22. `ItemObservation` accepts `source` or `source_name`.
- **Action-only importers.** The `feed_health_adapters` filter also takes
  a plain array `['key' => ..., 'label' => ...]`, which becomes a
  `SimpleAdapter`, so an importer can register and report without loading
  any class of this plugin.
- **Retention floors.** Fetch rows are kept at least 15 days, because the
  baseline reads 14 days of them; daily rollups at least 8 days.
- **Registrable host.** The cross-host redirect warning compares the last
  two host labels, or three under common two-letter country second-level
  names (`co.uk`, `co.in`, ...). There is no public suffix list; rare
  suffixes may warn when they should not.
- **Redaction patterns** match parameter names containing `key`, `token`,
  `secret`, `password`, `passwd`, `pass`, `signature`, `sig`, `auth`,
  `credential` or `session`. Over-redaction is the safe direction.
- **CSV cells** that start with `=`, `+`, `-` or `@` get a leading quote,
  so a hostile feed label cannot become a spreadsheet formula.
- **PHPStan level 8** with `treatPhpDocTypesAsCertain: false`: adapters
  are third-party code, so runtime type checks on what they return are
  deliberate.

## Measured sizes and timings

Measured on MySQL 8.0 (InnoDB, utf8mb4) on a shared, busy machine (load
average about 8), with 500 feeds, 14 days of hourly fetches (167,000 fetch
rows) and 100,000 seen keys:

| Table | Data per row | Index per row |
|---|---|---|
| `feedhealth_fetches` | 97 B | 75 B |
| `feedhealth_seen` | 100 B | 206 B |
| `feedhealth_feeds` | 229 B | 65 B |

So 30 days of fetch rows for 500 hourly feeds (360,000 rows) take about
62 MB, and 600,000 seen keys about 184 MB.

| Operation | Time |
|---|---|
| Steady-state check, 500 feeds | 3.1 s |
| First check after install (writes every feed once) | 8.3 s |
| Dashboard rows, 500 feeds | 0.2 s |
| One fetch with 50 items, recorded and flushed | 36 to 50 ms |
| Catch-up rollup of 14 days for 500 feeds | 3 to 6 s (one-off; a normal day is 1/14 of that) |

A check uses one indexed query per feed (14 days of its fetch rows, five
columns) plus two set-based window queries; extra memory stayed under
6 MB.

## Testing

- `tests/stubs/` stands in for WordPress, `wpdb`, SimplePie objects and
  WP-CLI. `tests/Support/MemoryDb` implements `Db` with the same semantics
  as `WpdbDb` (unique keys, NULL comparisons, ordering, grouped
  aggregates), counts calls and can be told to fail.
- A frozen clock makes every age and window deterministic.
- `tests/Integration/MysqlTest` runs the real SQL when
  `FEED_HEALTH_TEST_DSN` is set; CI runs it against a MySQL service.
- Each PRD acceptance test is named in a test docblock ("Test 23 (case
  24)").
