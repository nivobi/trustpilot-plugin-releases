=== Nivobi Trustpilot Reviews ===
Contributors: nivobi
Tags: trustpilot, reviews, ratings, shortcode, elementor
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync Trustpilot reviews to a local database via WP-Cron and render filtered carousels through a Preset Manager shortcode system.

== Description ==

Pulls reviews from the Trustpilot API on a schedule you choose, stores them locally, and renders filtered carousels via shortcode. Define named "Review Sets" in the admin (keyword + minimum stars + limit) and drop `[tp_reviews id="slug"]` anywhere — including Elementor's Shortcode widget.

= Features =

* Configurable sync schedule — hourly, twice daily, daily (with time-of-day picker), or weekly
* Local database storage — no live API calls per page view
* Preset Manager — admins create named Review Sets without touching code
* Auto-fit carousel — cards reflow per viewport so the last card never gets cut off
* Smooth snap-aligned scrolling, prev/next arrows that disable at edges
* Trustpilot compliance built in — TrustScore, review count, logo, profile link in every render
* JSON-LD AggregateRating for rich snippets (deduped per page)
* Self-updating via GitHub Releases — no more manual zip uploads after first install

= Requirements =

* Trustpilot API key + secret (Basic plan or higher — required for the OAuth2 reviews endpoint)
* WordPress 6.4 or later
* PHP 8.1 or later

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin and activate it.
2. Go to Trustpilot → Settings and enter your API key, API secret, and Trustpilot business domain.
3. Save settings — the plugin resolves your Business Unit ID, schedules the first sync, and starts pulling reviews.
4. Open Trustpilot → Dashboard and create one or more Review Sets.
5. Place `[tp_reviews id="your-preset-slug"]` anywhere on your site.

== Frequently Asked Questions ==

= Why don't I see reviews right after installing? =

The first sync runs on the next WP-Cron tick, which depends on site traffic. Use **Dashboard → Sync Now** to trigger it immediately.

= Can I show different review sets on different pages? =

Yes — that's the whole point of the Preset Manager. Create one preset per filter combination (keyword, min stars, limit) and reference each by its slug.

= How often does the plugin pull new reviews? =

Configurable in Settings. Defaults to daily at 03:00 in your site timezone.

= Will my data survive if I uninstall and reinstall? =

No. Uninstall (Plugins → Delete) drops the reviews table and all settings. Deactivate alone does not. If you want to reinstall without data loss, use the in-place update mechanism (already built in) instead of delete/reinstall.

== Changelog ==

= 1.2.1 =
* Add README.md and readme.txt — readme.txt drives the "View details" modal in WP admin and the Plugin Update Checker info screen.

= 1.2.0 =
* Self-updates via GitHub Releases (Plugin Update Checker bundled)
* Configurable sync schedule UI — frequency + time-of-day
* Carousel snap-aligned scrolling, auto-fit cards across breakpoints
* OAuth token refresh on long full syncs
* Wired up `tp_date_format` option (was unreachable)
* Sync state options demoted to autoload=off
* Uninstall now sweeps all `tp_*` options + preset transients

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Adds in-place updates, configurable sync schedule, and several bug fixes. Recommended for everyone.
