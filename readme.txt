=== Feed Health ===
Contributors: indianopinion
Tags: feeds, rss, monitoring, import, health
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Per-feed yield, failures, HTTP status, last-item age and duplicate rate for sites that import from feeds, with alerts when a feed goes quiet or fails.

== Description ==

A site that imports from many feeds has no view of the feeds themselves. One feed stops returning items, starts answering with an error page or a bot challenge, or keeps sending articles other feeds already supplied, and nobody notices for weeks.

Feed Health records every fetch attempt and what it yielded, gives each feed one status (paused, failing, unchecked, quiet, redundant, degraded, new, healthy) with a reason built from the numbers, shows them on one screen under Tools, and emails or logs when a feed changes status.

Written from scratch; not derived from any third-party plugin.

* Works with WordPress core's fetch_feed() through a drop-in wrapper, or passively with no code change.
* Any importer can report through plain WordPress actions or an adapter interface.
* Quiet thresholds adapt to each feed's own cadence.
* Duplicates are counted on a canonical URL key (scheme, www, m., amp, tracking parameters and trailing slashes do not hide them).
* Alerts on change, batched, with cooldowns, reminders, flapping detection, a daily cap, recovery notices and one systemic alert when most feeds fail together.
* WP-CLI commands for everything the dashboard does.
* Never breaks an import: every error is caught and counted.
* Credentials in feed URLs are redacted everywhere a URL is shown.

== Installation ==

1. Upload the feed-health folder to /wp-content/plugins/ and activate it.
2. Open Tools > Feed health.
3. In your import code, call feed_health_fetch_feed( $url ) in place of fetch_feed( $url ), or turn on Core passive mode in Settings.
4. Send a test alert from the dashboard.

Must-use install: see the README on GitHub.

== Frequently Asked Questions ==

= Does it fetch my feeds? =

No. It observes the fetches your importer makes. The only fetch it makes is the "test fetch now" button, rate-limited.

= What does it store? =

Feed URLs, hashes of article URLs, the newest article title per feed, and the user id of staff who act on the dashboard. No reader data.

= What happens on uninstall? =

All tables, options and scheduled events are removed on every site. Deactivation keeps data.

== Changelog ==

= 0.1.0 =
* First release.
