# feed-health

Tracks per-feed yield, fetch failures, HTTP status, last-item age and
duplicate rate for a WordPress site that imports from feeds, shows them on
one admin screen, and emails or logs when a feed goes quiet, starts
failing, stops being fetched, or recovers. Without it, one feed out of a
hundred can die quietly and nobody notices until a section looks thin
weeks later.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

Measured: a steady-state check over 500 feeds and 167,000 fetch rows takes
about 3 seconds on MySQL 8 (details in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)).

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Is it working?](#is-it-working)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Reporting from your importer](#reporting-from-your-importer)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Limitations](#limitations)
- [Privacy](#privacy)
- [Roadmap](#roadmap)
- [FAQ](#faq)
- [Licence](#licence)

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later, MySQL 5.7+ or
MariaDB 10.4+. No runtime dependencies.

**Without Composer.** Download a release, put the `feed-health` folder in
`wp-content/plugins/`, and activate it. The plugin carries its own
autoloader.

**With Composer.**

```sh
composer require indianopinion/feed-health
```

With `composer/installers` the package lands in `wp-content/plugins/`
(type `wordpress-plugin`). Activate it as usual.

**As a must-use plugin.** Put the folder at
`wp-content/mu-plugins/feed-health/` and copy
[`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to
`wp-content/mu-plugins/feed-health-loader.php`. Activation hooks do not
run for must-use plugins, so the tables are created on the first wp-admin
page load or the first scheduled check.

**Multisite.** Network activation creates the tables on every site. A
site added later gets its tables on its first admin load. Each site has
its own daily email cap (default 10, counted per site per day). A network
administrator can also set a network-wide daily cap on the Settings tab;
it is off (0) by default.

## Quick start

1. Activate. Open **Tools > Feed health**.
2. Report fetches. The simplest way, if your own code uses core's
   `fetch_feed()`, is to swap one function:

   ```php
   $feed = feed_health_fetch_feed( $url );                    // was fetch_feed( $url )
   $feed = feed_health_fetch_feed( $url, 'world', 'World desk' ); // your own key and label
   ```

   It returns exactly what `fetch_feed()` returns and records the attempt
   and every item.
3. Or turn on **Core passive mode** in Settings to observe every
   `fetch_feed()` call on the site with no code change (fetches only; items
   are counted, not checked for duplicates).
4. Importers that are not built on `fetch_feed()` report through actions:
   see [Reporting from your importer](#reporting-from-your-importer).
5. Send a test alert from the dashboard, or `wp feed-health test-alert`.

A runnable demo without WordPress: `composer install && php examples/demo.php`.

## Is it working?

1. `wp feed-health status` lists every feed with its status and reason.
   `--format=json` adds the internal counters (fetches recorded, items,
   invalid input, internal errors, emails sent, held and failed, checks
   run and skipped).
2. Run a test fetch: the button on a feed's detail page, or
   `wp feed-health test-fetch <feed-key>` (core adapter feeds).
3. Send a test alert: `wp feed-health test-alert`. It prints what each
   channel returned.
4. Look at the event log: the **Event log** tab, or
   `wp feed-health events --since="1 day ago"`. Every check writes one
   `check` line with its duration and counts.

The event log is the reliable place to look. A scheduled job's PHP error
log often goes nowhere, so the optional error-log copy is a convenience,
not the record.

If the dashboard says checks are not running, WP-Cron is not firing. Run
due events from system cron:

```cron
*/15 * * * * wp --path=/var/www/site cron event run --due-now
# or, for checks only:
0 * * * * wp --path=/var/www/site feed-health check
```

## Configuration

Everything is on the **Settings** tab, saved through the Settings API,
validated and clamped on save, and filterable with `feed_health_settings`.

| Setting | Default | Notes |
|---|---|---|
| `enabled` | on | Kill switch, read at call time. `define( 'FEED_HEALTH_DISABLED', true );` in `wp-config.php` forces it off. |
| `core_adapter` | on | The `fetch_feed()` wrapper records when on. |
| `core_passive` | off | Observe every `fetch_feed()` call. |
| `check_interval` | hourly | `hourly`, `twicedaily` or `daily`. |
| `failures_in_row` | 3 | `failing` after this many failed attempts in a row. Per-feed override. |
| `failure_window_hours` | 24 | Window for the failure share rule. |
| `failure_window_min_attempts` | 4 | Minimum attempts in the window. |
| `failure_share_percent` | 50 | `failing` when this share of attempts in the window failed. |
| `unchecked_floor_hours` | 6 | `unchecked` after 3 times the usual attempt interval, never less than this. |
| `default_quiet_hours` | 48 | Quiet threshold until a feed has 5 gaps on record. |
| `quiet_multiplier` | 2 | Threshold is the 90th percentile gap times this. |
| `quiet_floor_hours`, `quiet_ceiling_hours` | 6, 168 | Clamp for the computed threshold. The ceiling also marks frozen content. |
| `min_history_attempts`, `min_history_hours` | 5, 24 | A feed is `new` until both are met. |
| `redundant_min_fresh`, `redundant_percent` | 10, 90 | `redundant` over 7 days. |
| `stale_run_hours` | 2 | A run still running after this is `incomplete`. |
| `channel_email`, `channel_log` | on, on | Log channel writes one event per notice. |
| `recipients` | site admin email | Comma-separated list. |
| `alert_failing`, `alert_unchecked`, `alert_quiet` | on | Alert on entering these. |
| `alert_redundant` | off | |
| `recovery` | on | Notice when a feed leaves failing, unchecked or quiet. |
| `cooldown_hours` | 24 | At most one alert per feed per status in this time. |
| `reminder_hours` | 72 | "Still" notice for a feed still alerting. 0 turns it off. |
| `daily_email_cap` | 10 | Per site per day (site time). Held notices are logged and counted in the next email. |
| `php_error_log` | off | Copy log lines to the PHP error log with the prefix `[feed-health] `. |
| `dashboard_widget` | on | wp-admin home widget. |
| `retention_*_days` | fetches 30, seen 30, daily 400, runs 30, events 90 | Fetch rows are kept at least 15 days. |
| Network cap (multisite) | 0 (off) | Daily email cap across all sites, set by a network admin. |
| Capability | `manage_options` | Filter `feed_health_capability`. |

## How it works

- **Feeds** are identified by an adapter key and a feed key, never by
  position or URL. The core adapter's key is a hash of the canonical feed
  URL; pass your own key to keep history when a URL changes.
- **Every fetch attempt** is stored with its outcome: `ok`,
  `not_modified`, `http_error`, `transport_error`, `parse_error` (a 200
  that is not a feed: bot challenge, login wall, parked domain), `empty`,
  `cached` (served from the importer's cache; neither success nor
  failure) or `failure_other`.
- **Every item** is reduced to a key: a hash of its canonical URL (no
  scheme, no `www.`, `m.`, `mobile.` or `amp.`, no default port, fragment,
  trailing slash, `/amp` or `amp=1`, no tracking parameters, sorted
  query). Fresh, repeat and duplicate are decided on that key. Bodies are
  never stored; only the newest accepted title per feed is kept.
- **A check** (hourly, after each import run, and daily) reads only the
  plugin's tables, never fetches, and gives each feed one status:
  `paused`, `failing`, `unchecked`, `quiet`, `redundant`, `degraded`,
  `new`, `healthy`, in that order of precedence, with a reason built from
  the numbers ("3 failures in a row: HTTP 403 blocked"). The quiet
  threshold adapts to each feed: twice its 90th percentile gap between new
  items over 14 days, between 6 hours and 7 days.
- **Notifications** follow changes, not states: one batched email and one
  log line per feed per check, cooldowns, reminders, flapping detection,
  a daily cap, recovery notices, and one systemic alert when most feeds
  fail together (usually the site's own network, DNS or an IP block).
- **Nothing breaks an import.** Every recording call catches its errors,
  counts them and logs once per request.

Design notes and every decision: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
Full behaviour: [docs/PRD.md](docs/PRD.md).

## Reporting from your importer

Three ways, from least to most coupling.

**Actions with plain arrays** (no class of this plugin is loaded). Register
a key once, then report:

```php
add_filter( 'feed_health_adapters', function ( $adapters ) {
    $adapters[] = [ 'key' => 'my-importer', 'label' => 'My importer' ];
    return $adapters;
} );

do_action( 'feed_health_run_start', 'my-importer', $run_id );
do_action( 'feed_health_fetch', [
    'adapter' => 'my-importer', 'feed_key' => 'world', 'feed_url' => $url,
    'outcome' => 'ok', 'http_status' => 200, 'items' => 20, 'run_id' => $run_id,
] );
do_action( 'feed_health_item', [
    'adapter' => 'my-importer', 'feed_key' => 'world', 'url' => $link,
    'title' => $title, 'source' => $source, 'published_at' => $timestamp,
    'outcome' => 'accepted', // or duplicate, rejected, unknown
] );
do_action( 'feed_health_run_end', 'my-importer', $run_id, 'ok' );
```

Fetch fields: `adapter`, `feed_key`, `outcome` (required); `started_at`,
`finished_at`, `http_status`, `final_url`, `redirect_status`,
`error_code`, `error_message`, `items`, `bytes`, `manual`, `run_id`,
`feed_url`, `feed_label`. Item fields: `adapter`, `feed_key`, `url`
(required); `title`, `body` (ignored), `source`, `published_at`,
`outcome`, `reason`. Unknown keys are ignored; a missing required key is
counted as invalid input and the call returns quietly. Full sample:
[`examples/custom-importer-actions.php`](examples/custom-importer-actions.php).

**The wrapper** for `fetch_feed()`: [`examples/wrap-fetch-feed.php`](examples/wrap-fetch-feed.php).

**An adapter class** implementing `IndianOpinion\FeedHealth\Adapter`. It
can list the importer's configured feeds, so a feed that is never fetched
shows as `unchecked`: [`examples/custom-adapter.php`](examples/custom-adapter.php).

## Hooks

Filters:

| Filter | Arguments | Use |
|---|---|---|
| `feed_health_adapters` | adapters | Register adapters (objects or `['key','label']` arrays). |
| `feed_health_settings` | settings | Override settings at read time. |
| `feed_health_item_url` | raw URL, item array | Rewrite an item URL before canonicalisation, for example to unwrap redirect wrapper links. **Must not make network calls**: it runs inside the import. |
| `feed_health_canonical_url` | canonical, raw URL | Replace the canonical form. |
| `feed_health_tracking_params` | names | Query parameters dropped from item URLs (`utm_*` always). |
| `feed_health_redact_params` | patterns | Parameter-name fragments whose values are redacted. |
| `feed_health_thresholds` | thresholds, feed | Per-feed thresholds (`fixedQuiet` in seconds, `failuresInRow`, ...). |
| `feed_health_status` | [status, reason], feed, metrics | Final say on a status. |
| `feed_health_should_notify` | bool, transition | Veto a notice. |
| `feed_health_alert_recipients` | emails | Email recipients. |
| `feed_health_alert_email` | [to, subject, body, headers], entries | Change the email. |
| `feed_health_expected_feeds` | `FeedDescriptor` list | Feeds you expect, for `unchecked`. |
| `feed_health_capability` | capability | Who sees the dashboard. |

Actions fired: `feed_health_transition` (transition), `feed_health_notify`
(entries, channel results; for chat or a pager, see
[`examples/notify-to-chat.php`](examples/notify-to-chat.php)),
`feed_health_check_complete` (summary), `feed_health_run_ended` (run id).

Functions: `feed_health()` (services), `feed_health_recorder()`,
`feed_health_fetch_feed( $url, $feed_key = null, $label = null )`.

## WP-CLI

```text
wp feed-health status [--status=<s>] [--adapter=<a>] [--format=table|json|csv] [--fail-on=failing,unchecked]
wp feed-health feed <adapter> <feed-key> [--format=table|json]
wp feed-health check [--dry-run]
wp feed-health test-fetch <feed-key>
wp feed-health mute|unmute <adapter> <feed-key>
wp feed-health snooze <adapter> <feed-key> [--for=1h|1d|1w|<n>m|<n>h|<n>d]
wp feed-health test-alert
wp feed-health prune
wp feed-health events [--since=<time>] [--kind=<kind>] [--format=...]
```

Exit 0 on success, 1 on error. `status` exits 0 even when feeds fail,
unless `--fail-on` names a status a listed feed has, so it fits a
monitoring script. `check --dry-run` stores and sends nothing.

## Limitations

- Seen keys are kept 30 days after an item was last seen. A feed that
  relists an older item counts it as fresh again.
- Duplicates are the same article URL after canonicalisation. Near
  duplicates (same story, different URL) are not detected.
- Core's `fetch_feed()` caches feeds for 12 hours by default
  (`wp_feed_cache_transient_lifetime`). Cached calls are recorded as
  `cached` and do not count as attempts, so a wrapped feed may be
  attempted only twice a day; its thresholds adapt to that.
- Passive mode counts items but cannot tell which ones the site imports,
  so it does not compute yield or duplicates.
- The cross-host redirect warning uses the last two host labels (three
  under common country second-level names), not a public suffix list.
- The usual gap needs 5 gaps in 14 days; slower feeds use the 48-hour
  default unless you set a per-feed threshold.
- Large prunes run as one `DELETE` per table.

## Privacy

The plugin stores no reader data. It stores feed URLs, one-way hashes of
article URLs, the title of the newest article per feed, and the user ids
of staff who take actions on the dashboard (in the event log). It adds a
suggested paragraph to the privacy policy guide. URLs are redacted (user
info and secret-looking query values) before they appear on the
dashboard, in email, in log lines, in CLI output or in CSV; the stored URL
is unchanged because the feed key may depend on it.

## Roadmap

- Weekday and hour profile for feeds with strong daily cycles (0.1.0 uses
  the 90th percentile gap only).
- REST endpoints for the dashboard data.
- Chunked prunes for very large tables.

## FAQ

**Does it fetch my feeds?** No. It observes fetches your importer makes.
The only fetch it makes is the operator's "test fetch now", through core,
rate-limited to once per feed per 5 minutes.

**Will it slow my imports?** Recording is buffered: one fetch with 50
items costs about 40 ms when flushed. Checks run in their own cron event,
never in the import.

**Can it break an import?** No. Every recording call catches its own
errors. With the kill switch off, recording calls return before touching
the database.

**I get no email.** Check the recipients, send a test alert, and look for
`mail_failed` or "email cap reached" in the event log.

**A feed that publishes weekly keeps going quiet.** Set a per-feed quiet
threshold on its detail page, or use `feed_health_thresholds`.

**What happens on uninstall?** Every table, option and scheduled event is
removed, on every site of a network. Deactivation keeps the data.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).

Contributions are welcome, see [CONTRIBUTING.md](CONTRIBUTING.md).
