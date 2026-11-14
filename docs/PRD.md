# feed-health: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", an acceptance test in section 12 checks it.
Names of classes, hooks and options in this document are proposals for the
public API of this plugin; the builder may refine them and must record any
change in `docs/ARCHITECTURE.md`.

## 1. Problem

A WordPress site that imports news or posts from many feeds has no view of
the feeds themselves. The importer runs, posts appear, and nobody notices
when one feed out of a hundred stops returning items, starts answering with
an error page, moves to a new address, or keeps returning the same articles
that other feeds already supplied. The site looks fine. The loss shows up
weeks later as a thin section or a missing source.

Three things make this worse in practice:

- Import runs are scheduled jobs. Their log output is often discarded, so an
  error that happens inside a run is seen by nobody.
- One fatal error inside an import run can stop every feed after it in that
  run. From the outside, the feeds just go quiet.
- Output is bursty. A quiet hour is normal for most feeds, so a person who
  looks only now and then cannot tell a slow feed from a dead one.

Measured result in production: at one point 43 of the 50 oldest
unprocessed imported items were duplicates that had been dropped without a
record, so they were picked up again on every pass and nothing reported the
duplicate rate at all.

feed-health records, for every feed, each fetch attempt and what it yielded.
It shows the state of every feed on one admin screen, and it emails or logs
when a feed goes quiet, starts failing, stops being fetched, or recovers.

## 2. Goals and non-goals

Goals:

1. Track, per feed: fetch attempts, fetch failures by kind, the last HTTP
   status, items in the feed, new items (yield), duplicate items, the
   duplicate rate, the age of the newest item, and the time since the last
   new item.
2. Classify each feed into one status (section 4.6) using thresholds that
   adapt to each feed's own cadence, with fixed overrides per feed.
3. Show a dashboard in wp-admin: one row per feed, sortable and filterable by
   status, with a detail view per feed.
4. Notify on status transitions by email, by log, or both, with cooldowns,
   batching, a daily cap and recovery notices. Never notify on every check.
5. Be generic over the importer through its own adapter interface and its
   own WordPress actions. Ship one reference adapter for WordPress core's
   `fetch_feed()`.
6. Detect feeds that are no longer being fetched at all, and import runs
   that started and never finished.
7. Never break an import. Every error inside the plugin is caught, counted and
   logged; the importer carries on.
8. Run as a normal plugin or as a must-use plugin, with WP-CLI commands for
   everything the dashboard does.

Non-goals:

1. Importing content. The plugin never creates, edits or deletes posts.
2. Fetching feeds on its own schedule. It observes fetches that an importer
   makes. The only fetch it makes itself is the operator's "test fetch now"
   button through the reference adapter (case 47).
3. Fixing feeds: it does not rewrite feed URLs, follow up redirects into
   settings, or disable feeds in the importer. It reports; the operator acts.
4. Content quality checks (language, topic, length, images). Those belong
   to other tools.
5. Near-duplicate detection by title or content similarity. Duplicates here
   are the same article URL after canonicalisation (section 4.4).
6. Resolving redirect wrapper links over the network. A separate tool can
   plug in through the URL filter in section 5.
7. Adapters for any third-party importer plugin. Third parties can write
   their own against the interface in section 5.
8. Uptime monitoring of the site itself, or a status page for readers.

## 3. Users and setting

- **Who installs it:** the operator of a WordPress site that pulls content
  from feeds, usually one person who also runs the importer. They check the
  dashboard once a day at most and rely on email for anything urgent.
- **Who reads alerts:** the same operator, or an editor listed as a
  recipient. Alerts must make sense on a phone at 2 a.m. without opening
  wp-admin.
- **Who integrates:** a developer who maintains an importer, a custom import
  script, or a site plugin, and calls this plugin's actions or implements
  its adapter interface.
- **Where it runs:** inside WordPress, on a single site or a multisite
  network (per site), on shared hosting or a dedicated box, with WP-Cron
  driven by page loads or by a system cron calling WP-CLI. Import runs may
  happen in web requests, in WP-Cron, or in WP-CLI.
- **Scale:** designed for 1 to 1,000 feeds and up to 100,000 observed items
  a day. Above that the builder may document limits.

## 4. Behaviour

### 4.1 Definitions

- **Adapter:** an object that connects one importer to this plugin. It has a
  short key (for example `core`), a label, and it reports runs, fetches and
  items.
- **Feed:** one source the importer fetches, identified by the pair
  (adapter key, feed key). The feed key is a stable string chosen by the
  adapter. The reference adapter uses a hash of the canonical feed URL. A feed
  also has a display label and a URL.
- **Run:** one pass of an importer over some or all of its feeds. Runs are
  optional; an importer that does not report runs still works.
- **Fetch attempt:** one try to retrieve one feed. It has a start time, an
  end time, an outcome (section 4.3), an optional HTTP status, a count of
  items in the response, and an optional error code and message.
- **Item observation:** one item seen in one fetch, with its URL, title,
  source name, optional published time, and an outcome: `accepted`,
  `duplicate`, `rejected` or `unknown`.
- **Seen key:** the hash of an item's canonical URL (section 4.4).
- **Fresh item:** an item whose seen key has not been seen from this feed
  before. A **repeat** is an item the same feed has listed before.
- **Duplicate:** a fresh item that the importer reports as a duplicate, or,
  when the importer says nothing, whose seen key was already seen from any
  other feed.
- **Yield:** the number of fresh items accepted, per fetch and per day.
- **Duplicate rate:** duplicates divided by fresh items over a window. Repeats
  are excluded from both sides. Undefined (shown as a dash) when there are no
  fresh items.
- **Last-item age:** two values, both shown. *Newest published age* is now
  minus the newest valid published time in the last successful fetch.
  *Last new item age* is now minus the first-seen time of the most recent
  accepted item. Status decisions use the second (section 4.7).
- **Check:** one evaluation pass that computes every feed's status and sends
  notifications for changes.

### 4.2 Recording runs, fetches and items

1. When an adapter or importer reports a fetch, the plugin stores one fetch
   row with every field it was given and the time it was recorded.
2. A fetch with no feed key, or an adapter key that is not registered, is
   rejected. The rejection is counted under `invalid_input` and logged once
   per request; it never throws into the caller.
3. A fetch for a feed key the plugin has not seen before creates the feed
   record on the fly, with status `new`, label and URL from the report.
4. When a later report for a known feed carries a different label or URL, the
   feed record is updated. The feed key, not the URL, is the identity, so a
   feed that moves address keeps its history when the adapter keeps its key.
5. Item observations are attached to the most recent open fetch of the same
   feed in the same request. If there is none, the plugin opens an implicit
   fetch with outcome `ok` and closes it at the end of the request.
6. For every item observation the plugin computes the seen key and decides
   fresh or repeat for that feed, then decides duplicate (section 4.1), then
   updates the fetch counters: items, fresh, repeats, accepted, duplicates,
   rejected.
7. Item observations with an outcome given by the importer keep that outcome.
   `unknown` items are resolved by the plugin: repeat, duplicate (seen key
   already seen from another feed), or accepted.
8. An item with no URL, or a URL that is not http or https, is counted as
   `rejected` with reason `no_url`. It is not keyed.
9. The same item reported twice in one fetch is counted once.
10. Item bodies are accepted in the payload for interface symmetry with other
    importer-facing tools, and ignored. Bodies are never stored.
11. Titles are stored only for the newest accepted item per feed, truncated
    to 200 characters, so the dashboard can show "last item".
12. A run start creates a run row with status `running`. A run end closes it
    with the reported status. A run still `running` after the stale-run limit
    (default 2 hours) is marked `incomplete` by the next check, and every feed
    that the adapter lists but that has no fetch inside that run's window is
    noted on its feed record as "not reached in run".
13. Fetch reports that arrive with an unknown run id are stored without a run
    link, not rejected.
14. When the kill switch is off (section 7), every recording call returns at
    once without touching the database.

### 4.3 Fetch outcomes

15. `ok`: a valid feed was parsed. Items may be zero (see 20).
16. `not_modified`: HTTP 304. Counts as a success with zero items. It does not
    reset "last new item".
17. `http_error`: the server answered with a status of 400 or above. The
    status is stored. The dashboard names the common ones: 401 and 403
    "blocked", 404 and 410 "gone", 429 "rate limited", 5xx "server error".
18. `transport_error`: no HTTP answer: DNS failure, refused connection, TLS
    failure, timeout. The error code and a message truncated to 300
    characters are stored.
19. `parse_error`: a 2xx answer whose body is not a feed: an HTML page (often
    a bot challenge, a login wall or a parked domain), an empty body, or XML
    that does not parse. This counts as a failure even though the status was
    200, because the feed gave nothing usable.
20. `empty`: a valid feed with zero items. It counts as a success for the
    failure counters but does not count as a new item. Three `empty` results
    in a row are noted on the feed record ("feed returns no items").
21. `cached`: the importer served the feed from its own cache and did not go
    to the network. It is recorded for completeness but counts as neither a
    success nor a failure and does not change "last fetch attempt".
22. Redirects: when a fetch reports a final URL different from the feed URL,
    the final URL is stored. A permanent redirect (301 or 308) is shown on the
    dashboard as "moved: update the feed URL". A redirect to a different
    registrable host is shown as a warning, because parked and hijacked
    domains redirect.
23. An outcome string the plugin does not know is stored as `failure_other`
    and counts as a failure.

### 4.4 Canonical URL and seen keys

24. The seen key is a hash of the canonical form of the item URL. The
    canonical form lower-cases the scheme and host, drops the scheme from the
    key (http and https are the same article), drops a leading `www.`, `m.`,
    `mobile.` or `amp.` host label, drops a default port, drops the fragment,
    drops a trailing slash on the path, drops a trailing `/amp` path segment
    and `amp=1` style parameters, drops known tracking parameters (`utm_*`,
    `fbclid`, `gclid`, `mc_cid`, `mc_eid`, `ref`, `cmpid` and a filterable
    list), sorts the remaining query parameters, and normalises percent
    encoding case.
25. A filter runs on the raw URL before canonicalisation, so another plugin
    can resolve redirect wrapper links or apply site rules. The filter must
    not do network calls in this path; the README must say so.
26. A second filter receives the canonical form and may replace it.
27. If canonicalisation fails (unparseable URL), the raw URL trimmed and
    lower-cased is used, and the failure is counted.
28. Seen keys are stored per feed with first-seen and last-seen times. Seen
    keys older than the retention (default 30 days since last seen) are
    pruned. A feed that relists an item older than that will count it as
    fresh again; the README must say so.

### 4.5 Reference adapter for WordPress core feeds

29. The `core` adapter observes calls to WordPress core's `fetch_feed()`. It
    is on by default and can be turned off in settings.
30. It offers a wrapper function that a site's own import code calls in place
    of `fetch_feed()`, passing the feed URL and an optional feed key and
    label. The wrapper calls `fetch_feed()`, times it, classifies the outcome
    (section 4.3), reports every item with outcome `unknown`, and returns
    exactly what `fetch_feed()` returned. It never alters the return value
    and never throws.
31. Passive mode (setting, default off): the adapter hooks core's public
    feed and HTTP hooks to observe every `fetch_feed()` call on the site,
    including widgets and dashboard news, without code changes. In passive
    mode it records fetches only, with items counted but not keyed, because
    the plugin cannot know which items the site imports. The builder must
    confirm the exact core hooks against current WordPress documentation and
    record them in ARCHITECTURE.md.
32. HTTP status and transport errors are taken from the HTTP response that
    core made for that URL in the same request. When core served the feed
    from its transient cache and made no request, the outcome is `cached`.
33. A `WP_Error` from `fetch_feed()` with no HTTP response maps to
    `transport_error`; with an HTTP response of 400 or above, to
    `http_error`; with a 2xx response, to `parse_error`.
34. Item published time comes from the feed item's date. A missing date is
    stored as null. A date more than 24 hours in the future is treated as
    missing and counted as `bad_date`.
35. The feed key is a hash of the canonical feed URL (section 4.4 rules, but
    query parameters are kept and only sorted, since feed URLs often select
    content by query). A caller may pass its own key; the wrapper uses it
    as given.
36. The adapter lists feeds for the "never fetched" check only from feeds it
    has already seen. A site can add feeds it expects through a filter, so a
    feed that has never been fetched still shows on the dashboard as
    `unchecked`.

### 4.6 Status and precedence

37. Every feed has exactly one status. Statuses, in precedence order:
    1. `paused`: muted by the operator, or reported disabled by its adapter.
    2. `failing`: see 38.
    3. `unchecked`: see 39.
    4. `quiet`: see 40.
    5. `redundant`: see 42.
    6. `degraded`: the last attempt failed, or the failure share in the
       window is above zero, but `failing` is not met.
    7. `new`: fewer than the minimum history (default 5 non-cached attempts
       or 24 hours since first seen, whichever comes later).
    8. `healthy`: none of the above.
    A `new` feed can be `failing` or `unchecked`, but never `quiet` or
    `redundant`, because there is no baseline yet.
38. `failing`: the last N non-cached attempts all failed (default N = 3), or
    at least half of the non-cached attempts in the last 24 hours failed and
    there were at least 4 of them.
39. `unchecked`: no non-cached attempt for longer than the unchecked limit.
    The limit is 3 times the feed's usual interval between attempts (median
    over the last 7 days), with a floor of 6 hours. With fewer than 3
    intervals on record, the floor alone applies.
40. `quiet`: the last new item age is above the feed's quiet threshold, and
    the feed is not failing and not unchecked. The threshold adapts to the
    feed (section 4.7).
41. A feed whose fetches succeed but whose newest published age is above the
    quiet ceiling is `quiet` with the reason "feed content is frozen", even if
    the importer reports new items, because the dates show nothing current
    is arriving. Feeds with no item dates skip this rule.
42. `redundant`: over the last 7 days, at least 10 fresh items and a duplicate
    rate of at least 90 percent. It means the feed adds almost nothing that
    other feeds do not already supply. Alerts for `redundant` are off by
    default; the dashboard still shows it.
43. Each status carries a short reason string built from the numbers, for
    example "3 failures in a row: HTTP 403 blocked" or "no new item for
    31 h; usual gap is 4 h".

### 4.7 Adaptive quiet threshold

44. The usual gap is the 90th percentile of the gaps between consecutive
    accepted items (by first-seen time) over the last 14 days. The 90th
    percentile, not the median, so that a normal overnight lull is inside
    the usual range.
45. The quiet threshold is the usual gap times the quiet multiplier (default
    2), clamped between the quiet floor (default 6 hours) and the quiet
    ceiling (default 7 days).
46. With fewer than 5 gaps on record in 14 days, the threshold is the default
    quiet threshold (48 hours). A per-feed fixed threshold, when set,
    replaces the computed one. A filter can override the threshold for any
    feed.

### 4.8 Operator actions

47. **Test fetch now** (reference adapter feeds only): fetches the feed once
    through the wrapper and records the result as a normal attempt marked
    `manual`. Rate-limited to once per feed per 5 minutes. Feeds from other
    adapters show the button only if that adapter implements test fetch.
48. **Mute** stops all alerts for the feed and sets it `paused` until
    unmuted. Recording continues.
49. **Snooze** suppresses alerts for the feed for a chosen period (1 hour,
    1 day, 1 week). The status is still computed and shown.
50. **Reset baseline** clears the computed gaps and the seen keys for one
    feed, so a feed whose character changed is relearned. History rows stay.
51. **Forget feed** removes a feed and its rows. It needs a confirmation
    step. Used for feeds the importer no longer has.
52. **Set threshold** stores per-feed fixed values for the quiet threshold and
    the failure count.
53. Every operator action is written to the event log with the user id.

### 4.9 Checks

54. A check runs on its own WP-Cron event (default hourly) and on demand from
    WP-CLI and the dashboard. It is independent of import runs, so a long or
    broken import cannot stop health checks.
55. A check holds a lock with a time limit (default 10 minutes). A second
    check that starts while the lock is held exits and records "skipped:
    already running". A lock older than its limit is taken over.
56. A check reads only this plugin's own tables. It never fetches feeds.
57. A check marks stale runs `incomplete` (case 12), computes each feed's
    status and reason, stores them, and collects the transitions (old status
    to new status).
58. A check rolls up the previous days' fetch rows into daily rows per feed
    (attempts, failures by kind, items, fresh, accepted, duplicates) when they
    are not rolled up yet. The dashboard reads the rollups for anything older
    than today.
59. A check prunes rows past their retention (section 6).
60. The check callback catches every error. A failure is logged and recorded
    as an event; the next check runs as normal.
61. If WP-Cron is disabled and no check has run for 3 times the check
    interval, the dashboard shows a banner saying checks are not running and
    how to run them from system cron.

### 4.10 Notifications

62. Notifications are driven by transitions, not by state. A feed that stays
    `failing` does not produce a new alert on every check.
63. Alerting transitions by default: into `failing`, `unchecked` or `quiet`
    from any status other than `paused`. `redundant` is off by default. Each
    can be switched on or off in settings.
64. Recovery: a feed that leaves `failing`, `unchecked` or `quiet` for
    `healthy` or `degraded` produces a recovery notice (setting, default on).
65. Cooldown: a feed sends at most one alert per status per cooldown period
    (default 24 hours). A transition inside the cooldown is recorded but not
    sent.
66. Reminder: a feed still in an alerting status after the reminder interval
    (default 72 hours, 0 turns it off) is included again in the next
    notification, marked "still".
67. Flapping: a feed that changes status more than 4 times in 24 hours is
    shown as "flapping" in the next notification and is not alerted again
    until it has held one status for 6 hours.
68. Batching: all transitions found in one check go out as one email and one
    log line per feed. The email subject names the counts, for example
    "Feed health on Example Site: 2 failing, 1 quiet, 1 recovered".
69. Systemic failure: when at least half of the feeds that were fetched in
    the window go into `failing` in the same check (and there are at least 3
    of them), the plugin sends one alert that says the problem is probably
    the site's own network, DNS, or a block on the site's IP address, and
    lists the feeds in a compact form, instead of one section per feed.
70. A transition from `new` to `quiet` is impossible (case 37). A transition
    from `new` to `failing` alerts, so a new feed that is broken from day one
    is caught.
71. Snoozed and muted feeds never alert. Their transitions are recorded.
72. Daily cap: at most 10 emails a day (setting). Notifications past the cap
    are logged with "email cap reached" and the next email after midnight
    (site time) says how many were held back.
73. Email channel: uses `wp_mail()`. Recipients default to the site admin
    email and can be a list. The body is plain text: for each feed the
    label, the status, the reason, the last HTTP status, the last success
    time, and a link to the feed's dashboard detail. URLs in the body are
    redacted (case 84).
74. If `wp_mail()` returns false, the failure is logged as an event and the
    same transitions are retried at the next check, up to 3 tries, then given
    up with a final log line.
75. Log channel: writes one line per transition to the plugin's event log
    (always) and, when the setting is on, to the PHP error log with a fixed
    prefix. The README must say that a scheduled job's PHP error log often
    goes nowhere, and that the dashboard event log is the reliable place.
76. Every notification also fires a public action carrying the transition
    data, so a site can send it to chat or a pager.
77. A "send test alert" action (dashboard and WP-CLI) sends a sample
    notification through every enabled channel and reports what each
    channel returned.

### 4.11 Dashboard

78. A page under Tools, "Feed health", visible to users with the configured
    capability (default `manage_options`).
79. Summary line: counts per status, time of last check, time of last
    recorded fetch, and banners for: kill switch off, checks not running,
    run incomplete, systemic failure in the last check.
80. Feed table: label, adapter, status with reason, last attempt, last
    success, last HTTP status, attempts and failures in 24 hours, yield
    today and over 7 days, duplicate rate over 7 days, newest published
    age, last new item age, and a 14-day yield sparkline drawn in inline SVG.
    Sortable by any column, filterable by status and adapter, searchable by
    label or URL. Paginated at 50 rows.
81. Feed detail view: the numbers above, the last 50 attempts with outcome,
    status, duration, items, fresh, accepted, duplicates and error message,
    the computed quiet threshold and how it was derived, the per-feed
    overrides, the status history, and the operator actions (section 4.8).
82. CSV export of the feed table with the current filters.
83. A small dashboard widget on the wp-admin home showing the count per
    status and a link, visible to the same capability. It can be turned off.

### 4.12 Redaction

84. Feed URLs often carry credentials in the query string. Before a URL is
    shown on the dashboard, put in an email, written to a log line or
    exported, user info is removed and the values of query parameters whose
    name matches a key, token, secret, password, signature or auth pattern
    (filterable list) are replaced by `***`. The stored URL is not changed,
    because the feed key may depend on it.
85. Error messages from remote servers are stored as plain text, truncated,
    and escaped on output. HTML in them is never rendered.

### 4.13 Failure modes

86. Any error inside a recording call is caught. The call returns normally,
    the error is counted under `internal_error`, and at most one log line per
    request is written. The importer is never interrupted. (Fail open:
    losing a health record is cheaper than losing an import.)
87. If the plugin's tables are missing (deleted by hand, failed install), the
    recording calls do nothing and count the problem in a non-autoloaded
    option; the dashboard shows "tables missing" with a repair button that
    re-runs the installer.
88. If the database rejects a write (for example, a full disk), the call
    fails open as in 86.
89. If a check fails part way, statuses already stored stay, notifications
    for feeds not yet evaluated are not sent, and the next check starts
    over. No notification is sent twice for the same transition (each
    transition has an id, and sent ids are stored).
90. A clock jump backwards (server time moves) never produces negative ages.
    Ages below zero are shown as zero and noted once in the event log.
91. With a persistent object cache present or absent, behaviour is the same;
    the plugin does not depend on transients surviving.
92. Deactivation clears the scheduled check. Reactivation schedules it again
    and keeps all data.

## 5. Public interface

All names below are proposals inside the namespace
`IndianOpinion\FeedHealth`. Hook names use the prefix `feed_health_`.

### 5.1 Adapter interface

`Adapter` (interface):

- `key(): string`. Lower case, 1 to 40 characters from `a-z 0-9 _ -`.
- `label(): string`. Human label for the dashboard.
- `isAvailable(): bool`. False when the importer it serves is not active.
  Unavailable adapters are listed as such and not booted.
- `boot(Recorder $recorder): void`. Hook into the importer and report
  through the recorder.
- `feeds(): iterable` of `FeedDescriptor`. The feeds the importer has
  configured, for the unchecked check and the dashboard. May be empty.
- `supportsTestFetch(): bool` and `testFetch(FeedDescriptor $feed):
  FetchResult`. Optional capability.

Adapters are registered through the filter `feed_health_adapters`, which
receives and returns an array of adapter objects. A duplicate key is
rejected with a logged error; the first registration wins.

### 5.2 Value objects

Immutable, constructed with validation (a typed exception on bad input):

- `FeedDescriptor`: adapter key, feed key (1 to 191 characters), URL,
  label, enabled flag, optional expected interval in minutes.
- `FetchResult`: adapter key, feed key, optional run id, started at,
  finished at (UTC), outcome, optional HTTP status, optional final URL,
  optional error code, optional error message, items in feed, optional bytes,
  manual flag.
- `ItemObservation`: adapter key, feed key, URL, title, source name,
  optional body (ignored), optional published at, outcome, optional reason.
- `Transition`: id, feed, from status, to status, reason, time, kind
  (`alert`, `recovery`, `still`, `systemic`).

### 5.3 Recorder

`Recorder` (one per request, reachable through a public function that
returns it):

- `beginRun(string $adapter, ?string $runId = null): string`. Returns the run
  id (generated when none is given).
- `endRun(string $runId, string $status = 'ok'): void`.
- `recordFetch(FetchResult $result): void`.
- `recordItem(ItemObservation $item): void`.

None of these methods throw to the caller (case 86).

### 5.4 Actions an importer can call (no PHP coupling)

Each takes plain arrays, so an importer can report without loading any class:

- `feed_health_run_start` (adapter key, run id).
- `feed_health_run_end` (adapter key, run id, status).
- `feed_health_fetch` (array with the `FetchResult` fields).
- `feed_health_item` (array with the `ItemObservation` fields: URL, title,
  body, source name, feed key, adapter key, published at, outcome).

Unknown array keys are ignored. Missing required keys reject the call as in
case 2.

### 5.5 Reference adapter function

- A wrapper with the same return contract as `fetch_feed()`, taking the feed
  URL, an optional feed key and an optional label.

### 5.6 Filters

- `feed_health_adapters` (array of adapters).
- `feed_health_settings` (settings array after defaults and stored values).
- `feed_health_item_url` (raw URL, item array): before canonicalisation.
- `feed_health_canonical_url` (canonical string, raw URL).
- `feed_health_tracking_params` (list of parameter names).
- `feed_health_redact_params` (list of name patterns).
- `feed_health_thresholds` (array of thresholds, feed): per-feed override.
- `feed_health_status` (status, reason, feed, metrics): final say on status.
- `feed_health_should_notify` (bool, transition).
- `feed_health_alert_recipients` (array of emails).
- `feed_health_alert_email` (array with subject, body, headers).
- `feed_health_expected_feeds` (array of `FeedDescriptor`): feeds the site
  expects, for the unchecked status.
- `feed_health_capability` (string).

### 5.7 Actions fired by the plugin

- `feed_health_transition` (transition), for every recorded transition.
- `feed_health_notify` (transition list, channel results), once per check
  with something to send.
- `feed_health_check_complete` (summary array).

### 5.8 WP-CLI

- `wp feed-health status [--status=<s>] [--adapter=<a>] [--format=table|json|csv]`
- `wp feed-health feed <adapter> <feed-key> [--format=...]`
- `wp feed-health check [--dry-run]` (dry run computes and prints
  transitions, sends nothing, stores nothing).
- `wp feed-health test-fetch <feed-key>` (reference adapter only).
- `wp feed-health mute|unmute|snooze <adapter> <feed-key> [--for=<duration>]`
- `wp feed-health test-alert`
- `wp feed-health prune`
- `wp feed-health events [--since=<time>] [--format=...]`

Exit code 0 on success, 1 on error. `status` exits 0 even when feeds are
failing, unless `--fail-on=failing,unchecked` is passed, so it can be used in
a monitoring script.

## 6. Data

### 6.1 What is stored

Custom tables with the site prefix (names are proposals):

- `feedhealth_feeds`: adapter key, feed key, label, URL, final URL, status,
  reason, status since, muted, snoozed until, per-feed overrides, usual gap,
  quiet threshold, first seen, last attempt, last success, last HTTP status,
  last new item time and title, newest published time, notes. Unique on
  (adapter key, feed key).
- `feedhealth_fetches`: one row per attempt, with the fields of
  `FetchResult` plus counters for items, fresh, repeats, accepted,
  duplicates, rejected. Indexed on (feed, started at).
- `feedhealth_seen`: (feed id, seen key) unique, first seen, last seen.
  Seen key is a fixed-length binary or hex hash; URLs are not stored here.
- `feedhealth_daily`: one row per feed per day (UTC) with the rollup
  counters.
- `feedhealth_runs`: run id, adapter, started, ended, status.
- `feedhealth_events`: time, kind (`transition`, `notify`, `mail_failed`,
  `operator`, `error`, `check`), feed id (nullable), user id (nullable),
  message, transition id, sent flag.

One option holds settings (autoloaded, small). One non-autoloaded option
holds internal counters and the schema version.

### 6.2 Retention (defaults, all configurable)

- Fetch rows: 30 days.
- Seen keys: 30 days since last seen.
- Daily rollups: 400 days.
- Runs: 30 days.
- Events: 90 days.

### 6.3 Size

At 500 feeds fetched hourly with 30 items each, fetch rows are about 360,000
at 30 days and seen keys depend on fresh items (about 20,000 a day for a
busy site). The builder must state measured row sizes in ARCHITECTURE.md.

### 6.4 Uninstall

`uninstall.php` drops every table, deletes both options, and clears the
scheduled events, on each site of a network. Deactivation keeps data.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Enabled (kill switch) | on | Read at call time. A constant in `wp-config.php` can force it off. |
| Core adapter | on | Wrapper always available when on. |
| Core passive mode | off | Observe every `fetch_feed()` call. |
| Check interval | hourly | WP-Cron schedule. |
| Failures in a row for `failing` | 3 | Per-feed override. |
| Failure share window | 24 h, at least 4 attempts, 50 percent | |
| Unchecked floor | 6 h | Limit is 3 times usual attempt interval. |
| Default quiet threshold | 48 h | Used without enough history. |
| Quiet multiplier | 2 | Times the 90th percentile gap. |
| Quiet floor and ceiling | 6 h and 7 days | |
| Minimum history for baseline | 5 attempts and 24 h | |
| Redundant rule | 10 fresh items and 90 percent over 7 days | Alert off. |
| Stale run limit | 2 h | |
| Channels | log on, email on | Email needs at least one recipient. |
| Recipients | site admin email | List. |
| Alert on | failing, unchecked, quiet | redundant off. |
| Recovery notices | on | |
| Cooldown | 24 h | Per feed per status. |
| Reminder | 72 h | 0 turns it off. |
| Daily email cap | 10 | |
| PHP error log copy | off | Event log always on. |
| Dashboard widget | on | |
| Capability | `manage_options` | Filterable. |
| Retention | as section 6.2 | |

Settings are saved through the Settings API with a nonce, validated on save
(numbers clamped to sane ranges, emails validated, unknown keys dropped), and
filterable at read time.

## 8. Security and privacy

- Every admin page, action and AJAX or REST endpoint checks the capability
  and a nonce. Destructive actions (forget feed, reset baseline) need a POST
  and a confirmation.
- All output is escaped for its context. Remote error messages, feed
  labels, titles and URLs are untrusted.
- URLs are redacted as in case 84 everywhere they leave the database.
- No `eval`, no shell, no `@` error suppression. Database writes use
  prepared statements.
- The test fetch goes through WordPress core's safe HTTP path (no private
  network addresses unless the site allows them), is rate-limited, and is
  available only to the configured capability.
- Personal data: the plugin stores no reader data. It stores feed URLs,
  item URL hashes, the title of the newest item per feed, and operator user
  ids on events. The privacy section of the README says so, and the plugin
  adds a suggested text through WordPress's privacy policy guide.
- The recording actions are callable by any code on the site, by design.
  They are not exposed over HTTP.

## 9. Performance budget

- Recording a fetch: one insert and one update, under 5 ms on a normal host.
- Recording an item: one indexed lookup and one upsert on the seen table.
  Items of one fetch are batched where the API allows, so a 50-item fetch
  adds under 50 ms.
- No queries on front-end page loads when no importer is running. The core
  adapter's passive hooks do nothing until a feed fetch happens.
- A check for 500 feeds completes in under 5 seconds and under 64 MB of
  extra memory. It uses set-based queries, not one query per item.
- The dashboard for 500 feeds renders in under 500 ms using the feeds table
  and daily rollups; the 14-day sparklines come from rollups plus today.
- Autoloaded data stays under 2 KB.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. No 8.2+ syntax.
- WordPress 6.4 or later. Single site and multisite (per site; network
  activation installs tables on each site on first use).
- Works as a must-use plugin through a loader stub; the README documents it.
  In that mode activation hooks do not run, so the installer runs on first
  admin load when the schema version is missing or old.
- WP-Cron on page loads, WP-Cron from system cron, or `wp feed-health check`
  from system cron.
- MySQL 5.7 and later, MariaDB 10.4 and later. Index lengths respect
  utf8mb4 limits.
- Object caches: works with and without.
- Importers that run in WP-CLI: recording works the same in CLI as in web
  requests.

## 11. Observability

- The dashboard is the primary view: statuses, reasons, recent attempts and
  the event log with filters by kind and feed.
- Internal counters (shown on the dashboard and through
  `wp feed-health status --format=json`): fetches recorded, items recorded,
  invalid input, internal errors, canonicalisation failures, bad dates,
  emails sent, emails failed, emails held by the cap, checks run, checks
  skipped by lock. Counted per UTC day, kept 30 days.
- Every check writes one `check` event: duration, feeds evaluated,
  transitions, notifications sent.
- The optional PHP error log copy uses one fixed prefix per line so it can be
  grepped.
- The README has a "Is it working?" section: run `wp feed-health status`,
  run a test fetch, send a test alert, look at the event log.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit 10 with a stub
layer for WordPress (no full install), plus a database fake or SQLite where
the builder prefers. Time is injected through a clock interface so every
age and window test is deterministic.

1. (1) A recorded fetch stores every given field and the record time.
2. (2) A fetch with no feed key or an unknown adapter is rejected, counted
   under `invalid_input`, and nothing is thrown.
3. (3) First fetch for an unknown feed creates a feed with status `new`.
4. (4) A changed URL for the same feed key updates the record and keeps the
   history rows linked.
5. (5) Items reported with no open fetch create an implicit `ok` fetch that
   is closed at the end of the request.
6. (6) Counters on a fetch match a scripted mix of fresh, repeat, accepted,
   duplicate and rejected items.
7. (7) An importer-given outcome is kept; `unknown` resolves to repeat,
   duplicate or accepted by the seen-key rules.
8. (8) An item with no URL or a `ftp:` URL is rejected with `no_url`.
9. (9) The same item twice in one fetch counts once.
10. (10) A body in the payload is not stored anywhere (assert on every
    table and option written).
11. (11) Only the newest accepted title is stored, truncated to 200
    characters.
12. (12) A run left `running` past the limit becomes `incomplete` at the next
    check; listed feeds without a fetch in the run get the note.
13. (13) A fetch with an unknown run id is stored without a run link.
14. (14) With the kill switch off, recording makes zero database calls.
15. (15, 16) `ok` and `not_modified` count as successes; 304 does not move
    last new item.
16. (17) Each listed HTTP status maps to `http_error` and the right label.
17. (18) A transport error stores code and a 300-character message.
18. (19) A 200 with an HTML body, an empty body and broken XML each count as
    `parse_error` and as failures.
19. (20) Three `empty` results in a row add the note; they do not count as
    failures.
20. (21) `cached` changes neither failure counters nor last attempt.
21. (22) A 301 shows "moved"; a redirect to another registrable host shows the
    warning.
22. (23) An unknown outcome string is stored as `failure_other` and counts as
    a failure.
23. (24) A table of URL pairs that must share a key (scheme, `www.`, `m.`,
    `amp.`, port, fragment, trailing slash, `/amp`, tracking parameters,
    parameter order, percent-encoding case) and pairs that must not (different
    path, different meaningful parameter).
24. (25, 26) The raw-URL filter can rewrite a wrapper URL; the canonical
    filter can replace the key.
25. (27) An unparseable URL falls back to the trimmed lower-case form and the
    failure is counted.
26. (28) A seen key past retention is pruned and the item counts as fresh
    again.
27. (29, 30) The wrapper returns exactly what `fetch_feed()` returned (a feed
    object and a `WP_Error` case) and records one fetch with items.
28. (31) Passive mode records a fetch for an unwrapped `fetch_feed()` call
    with items counted and not keyed; with passive mode off, nothing is
    recorded.
29. (32) A cache hit with no HTTP request records `cached`.
30. (33) The three `WP_Error` shapes map to the three outcomes.
31. (34) A missing date stores null; a date 25 hours ahead is treated as
    missing and counted as `bad_date`.
32. (35) Feed key is stable across scheme and `www.` changes, differs when a
    query parameter differs, and a caller key is used as given.
33. (36) A feed added through the expected-feeds filter with no fetch shows
    as `unchecked`.
34. (37) Precedence: a feed that meets both `failing` and `quiet` is
    `failing`; a `new` feed is never `quiet` or `redundant`.
35. (38) Three failures in a row make `failing`; two do not. Half of 4
    attempts in 24 hours make `failing`; half of 3 do not.
36. (39) No attempt for longer than 3 times the median interval, or the
    floor, makes `unchecked`.
37. (40, 44, 45) A feed with a 2-hour 90th percentile gap goes `quiet` after
    6 hours (floor), one with a 10-hour gap after 20 hours, one with a
    10-day gap after 7 days (ceiling).
38. (41) A feed whose newest published item is older than the ceiling is
    `quiet` with the frozen reason; a feed with no dates is not.
39. (42) 10 fresh items with 9 duplicates make `redundant`; 9 fresh items do
    not.
40. (43) Reason strings contain the numbers they are built from.
41. (46) Fewer than 5 gaps uses 48 hours; a per-feed value and the filter
    each override.
42. (47) Test fetch records a `manual` attempt and a second click within 5
    minutes is refused.
43. (48, 49) Mute makes `paused` and stops alerts; snooze stops alerts but
    keeps the status.
44. (50, 51) Reset clears seen keys and gaps but not history; forget removes
    all rows for the feed and needs confirmation.
45. (52, 53) Per-feed thresholds apply; every operator action writes an event
    with the user id.
46. (54, 56) A check reads only plugin tables and makes no HTTP call (the
    HTTP stub fails the test if called).
47. (55) A second check while the lock is held exits with "skipped"; an
    expired lock is taken over.
48. (57) A check stores statuses and returns the expected transitions for a
    scripted history.
49. (58) Rollups match the sum of the fetch rows they replace, and the
    dashboard numbers agree before and after pruning.
50. (59) Rows past each retention are pruned and rows inside are kept.
51. (60, 89) An exception in the middle of a check is caught and logged;
    the next check finishes; no transition is sent twice.
52. (61) With no check for 3 intervals the banner shows.
53. (62) A feed failing over five checks sends one alert.
54. (63) Default alert set sends for `failing`, `unchecked`, `quiet` and not
    for `redundant`; switching `redundant` on makes it send.
55. (64) Leaving `failing` for `healthy` sends a recovery notice; with the
    setting off it does not.
56. (65) A second transition into the same status inside the cooldown is
    recorded and not sent.
57. (66) After 72 hours still failing, the feed appears marked "still"; with
    the reminder at 0 it does not.
58. (67) Five status changes in 24 hours mark the feed flapping and hold its
    alerts until it has held one status for 6 hours.
59. (68) Three transitions in one check produce one email whose subject
    carries the counts.
60. (69) 6 of 10 fetched feeds failing in one check produce one systemic
    alert and no per-feed sections; 2 of 10 do not.
61. (70) `new` to `failing` alerts.
62. (71) Muted and snoozed transitions are recorded and not sent.
63. (72) The 11th email in a day is held and the next day's first email
    reports the held count.
64. (73) The email body holds label, status, reason, HTTP status, last
    success and a detail link, as plain text.
65. (74) A false return from `wp_mail()` is logged and retried at the next
    check, three times at most.
66. (75) The log channel writes one event per transition, and to the PHP
    error log only when that setting is on.
67. (76) The notify action fires with the transitions and channel results.
68. (77) Test alert reports the result of each channel.
69. (78, 79) A user without the capability gets no page; with it, the summary
    and each banner show under their conditions.
70. (80) The table sorts, filters by status and adapter, searches, paginates
    and escapes every cell (a label with markup renders as text).
71. (81) The detail view shows 50 attempts and how the threshold was
    derived.
72. (82) CSV export respects filters and redacts URLs.
73. (83) The widget shows counts and can be turned off.
74. (84) A URL with user info and a `token` parameter is redacted on the
    dashboard, in email, in log lines and in CSV; the stored URL is
    unchanged.
75. (85) A remote error message with HTML is stored as text and rendered
    escaped.
76. (86, 88) A database exception during recording is caught, counted, logged
    once per request, and the importer's call returns normally.
77. (87) With tables missing, recording does nothing, the counter rises, and
    the repair action recreates them.
78. (90) A clock that moves backwards gives zero ages and one event.
79. (91) The same scripted history gives the same statuses with and without
    an object cache stub.
80. (92) Deactivation clears the scheduled check; reactivation restores it and
    data is intact.
81. (Section 5.1) An adapter with a duplicate key is rejected and logged; an
    unavailable adapter is listed but not booted.
82. (Section 5.4) Each action accepts a plain array, ignores unknown keys and
    rejects a missing required key.
83. (Section 5.8) Each WP-CLI command runs against the stubs; `status
    --fail-on=failing` exits 1 when a feed is failing; `check --dry-run`
    stores and sends nothing.
84. (Section 6.4) Uninstall drops every table, both options and the events,
    on each site of a network.
85. (Section 10) In must-use mode with no activation, the first admin load
    installs the schema.

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Which core hooks the passive mode uses, and how it ties an HTTP response
   to the `fetch_feed()` call that caused it when several feeds are fetched
   in one request.
2. Whether item counters are written per item or buffered per fetch and
   written once at the end of the request, and what happens to the buffer
   if the request dies.
3. Whether the duplicate check against "any other feed" should be a global
   seen table or a join on the per-feed table, given the size in 6.3.
4. Whether the 90th percentile gap is the right baseline, or a weekday and
   hour profile is worth the extra storage for feeds with strong daily
   cycles. Start simple; record the reasoning.
5. Whether the dashboard is a plain admin page with server rendering or uses
   the REST API. Either must work without external assets.
6. How the daily email cap counts across a multisite network (per site is
   the proposed answer).
7. Whether a check should also be triggered at the end of each import run,
   in addition to its own schedule, and how that interacts with the lock.

## 14. Lessons from production, written as rules

1. **Identify a feed by a stable key, never by its position in a list.**
   Importers reorder, insert and remove feeds. A history keyed by position
   attaches one feed's record to another without any error.
2. **Store health where the dashboard reads it.** The PHP error log of a
   scheduled job is often discarded or never created. If the only record of
   a failure is a log line, nobody sees it.
3. **Watch for work that never happened.** One fatal error inside an import
   run can stop every feed after it. A feed that is not fetched sends no
   error at all, so "no attempt for too long" and "run started, never ended"
   must be first-class statuses.
4. **Do not read a quiet hour as a broken feed.** Output is bursty and a daily
   average hides one bad hour. Judge each feed against its own cadence, with
   a floor, a ceiling and a minimum history.
5. **One article arrives under many URLs.** Mobile hosts, AMP paths, tracking
   parameters and trailing slashes all make one story look like several.
   Count duplicates on a canonical key or the duplicate rate is understated.
6. **Redirect wrapper links hide the real source.** An aggregator's wrapper
   URL credits every item to the aggregator and defeats de-duplication. Let
   another tool resolve wrappers through the URL filter; never resolve over
   the network inside the record path.
7. **Record what you drop.** An item dropped as a duplicate without a record
   is picked up again on every pass, and the counts never show it.
8. **Count reasons per day; do not log every event.** A backlog turns one log
   line per item into a flood. Counters per reason per day stay small and
   answer the real question.
9. **A 200 is not a success.** Bot challenges, login walls and parked domains
   answer 200 with an HTML page. Only a parsed feed counts.
10. **Run checks in their own scheduled event with their own lock.** An
    import run can last an hour; checks that ride on it stop when it stops.
11. **Catch everything in a scheduled callback.** An uncaught error in a cron
    callback kills that tick and every later tick with the same bug, silently.
12. **Read the kill switch at call time.** A cached switch keeps a broken
    path running after the operator turned it off.
13. **Dates in feeds lie.** They go missing, sit in the future, or reset to
    the fetch time. Use the first-seen time for decisions and clamp future
    dates.
14. **Alert on change, not on state, and batch.** An alert on every check
    trains people to ignore mail. One email per check, cooldowns, a daily
    cap, and one systemic alert when most feeds fail together.
15. **Feed URLs carry secrets.** API keys ride in query strings. Redact
    before a URL leaves the database.
