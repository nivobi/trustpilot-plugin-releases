# Nivobi Trustpilot Reviews

WordPress plugin that syncs Trustpilot reviews to a local database via WP-Cron and serves them through a Preset Manager shortcode system.

## What it does

- **Pulls reviews** from the Trustpilot API on a schedule you choose (hourly / twice-daily / daily / weekly).
- **Stores them locally** in a custom `wp_tp_reviews` table — no live API calls per page view.
- **Filters via Review Sets** — admins define named presets in the admin UI (keyword + minimum-stars + limit), and each preset gets a slug.
- **Renders carousels** anywhere via `[tp_reviews id="preset-slug"]`. Auto-fits N cards per row across breakpoints; arrow nav with smooth scroll; snap-aligned so cards never get cut off.
- **Trustpilot compliance** built into every render: TrustScore, review count, logo, and profile link, plus star-filter disclosure when applicable.
- **JSON-LD AggregateRating** emitted once per page for rich snippets.
- **Self-updates** via Plugin Update Checker — new releases on the public dist repo are detected by WordPress within ~12h and installable from the standard Plugins screen.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- Trustpilot API key + secret (Basic plan or higher — needed for OAuth2 reviews endpoint)

## Installation

1. Download the latest `trustpilot-reviews.zip` from [Releases](https://github.com/nivobi/trustpilot-plugin-releases/releases).
2. WP admin → **Plugins → Add New → Upload Plugin** → upload zip → **Activate**.
3. **Trustpilot → Settings** → enter API key, API secret, and your Trustpilot business domain.
4. Save. The plugin resolves your Business Unit ID, schedules the first sync, and starts pulling reviews.

## Usage

1. **Trustpilot → Dashboard** → create one or more Review Sets (slug, keywords, minimum stars, limit).
2. Drop the shortcode anywhere — Elementor's Shortcode widget, Gutenberg's Shortcode block, classic editor:
   ```
   [tp_reviews id="your-preset-slug"]
   ```
3. Adjust **Sync frequency** and **Run at** in Settings if the default daily 03:00 doesn't suit you.

## Updates

Self-updating. Once installed, future versions appear under **Dashboard → Updates** like any wp.org plugin. No manual zip uploads after the first install.

## Development

Issue tracker, source, and dev workspace are private. Releases (and the source subtree at release time) are mirrored to [trustpilot-plugin-releases](https://github.com/nivobi/trustpilot-plugin-releases).

## License

GPL-2.0-or-later.
