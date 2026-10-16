# Teatatu Events

A WordPress plugin for community events — single, multi-day, all-day and recurring — with venues, neighbourhoods, event tags, feed importers, calendar subscriptions, and a workflow designed for a mix of human editors and AI agents.

The plugin folder ([`teatatu-events/`](teatatu-events/)) is a complete, self-contained WordPress plugin. Drop it into `wp-content/plugins/` on any WordPress 6.0+ / PHP 7.4+ site (single-site or multisite) and activate it. It runs on its own or side by side with Teatatu News.

| Folder | What it is |
|---|---|
| [`teatatu-events/`](teatatu-events/) | **Plugin: the data.** Events, venues, neighbourhoods, tags, series, importers, feeds, REST, agents — and the front-end query API a theme uses. Works with any theme (shortcodes). See [`readme.txt`](teatatu-events/readme.txt). |
| [`teatatu-events-theme/`](teatatu-events-theme/) | **Theme: the display** for events.teatatu.nz. What's-on home page, the events list (by day or as a month calendar) with filters and a venue map, and each event's page. Reads only the plugin's functions. Styled with the Te Atatū Local design system (Events = Pōhutukawa). See [`readme.txt`](teatatu-events-theme/readme.txt). |

**The plugin owns the data; the theme owns the display.** The theme never reads post meta: if it needs a value that isn't in the plugin's normalised event item (`teatatu_events_item_from_post()`), add it to the plugin first.

## What it does

- **Events** (`teatatu_event`, REST `/wp/v2/events`) with start/end, all-day, status (cancelled, postponed, sold out…), tickets, price/free, image (link-only), and either a local page or a link out to the organiser.
- **Venues** with a structured NZ address, aliases and map link; a per-event **Room**; and a fallback **Address**.
- **Neighbourhoods** — every Te Atatū Peninsula address falls into **Matipo**, **Beach** or **Harbourview**, split along Te Atatū Road, Taikata Road and Harbour View Road, worked out automatically from street rules that are loaded from OpenStreetMap data on activation and can then be edited on screen.
- **Event Tags** — a curated list (Show, Garage sale, Community event, Fundraiser…).
- **Recurring series** that keep their next 12 dates as real events.
- **Duplicate** and **Edit as draft** (stage changes to a published event, then merge).
- **Importers** for RSS/Atom, HTML pages, iCal and Schema.org JSON-LD — draft-first, with pending updates, venue matching, a recognised-venue/neighbourhood filter and "removed at source" handling.
- **Twelve shortcodes** in site/network pairs (list, grid, latest, calendar, archive, subscribe) with visitor filters, plus iCal subscribe feeds, add-to-calendar links and Schema.org Event JSON-LD.
- **Multisite**: network-wide listings on any sites you enable, **linked events** (show another site's event without copying it) and **cross-site duplicate removal**.
- A draft-only **Events Agent** role for AI agents — see [`teatatu-events/MCP-GUIDE.md`](teatatu-events/MCP-GUIDE.md) and [`teatatu-events/SKILL.md`](teatatu-events/SKILL.md).
- Only **standard WordPress tables**; images are never downloaded.
- Self-updates from [GitHub Releases](https://github.com/TeAtatu/Teatatu-events/releases), including WordPress's automatic updates (see [Releasing an update](#releasing-an-update)).

## Getting started

1. Copy or symlink [`teatatu-events/`](teatatu-events/) into `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. Optional: copy [`teatatu-events-theme/`](teatatu-events-theme/) into `wp-content/themes/` and activate it on the events site (on multisite, Network Enable it first), then **Settings > Permalinks > Save** so `/events/` and `/event-neighbourhoods/matipo/` resolve.
4. Open **Teatatu Events** in the admin menu: add Venues and Event Tags, then events.
5. Check **Settings > Teatatu Events** (or **Network Admin > Settings > Teatatu Events**).
6. For AI agents: create a user with the **Events Agent** role, give it an Application Password, and follow [`teatatu-events/SKILL.md`](teatatu-events/SKILL.md).

Full documentation — shortcodes, REST, importers, capabilities, scheduled jobs, uninstall — is in [`teatatu-events/readme.txt`](teatatu-events/readme.txt). The design is in [`SPEC.md`](SPEC.md).

## Repo layout

```
teatatu-events/            the plugin — drop this folder into wp-content/plugins/
  teatatu-events.php       plugin header, activation, provisioning
  uninstall.php            retention-aware cleanup (never touches Teatatu News)
  readme.txt               full documentation (WordPress.org format)
  SKILL.md                 short agent workflow
  MCP-GUIDE.md             agent/MCP reference (REST shapes, tools, workflows, errors)
  includes/                one file per concern (see prompt.md's architecture map)
  assets/css/              front-end styles
  assets/data/             bundled street list (seeds the editable street rules) + boundary geometry, from OpenStreetMap
  tools/                   build-neighbourhood-streets.php (regenerates assets/data), build-release.sh (release zips)
teatatu-events-theme/      the theme — drop this folder into wp-content/themes/
  style.css                theme header + layout (design-system tokens only)
  functions.php            setup, assets, <html data-site="events">, network strip sites
  front-page.php           what's on: search, happening next, coming up, neighbourhoods, subscribe
  archive-teatatu_event.php  events list / month calendar (also every event taxonomy page)
  single-teatatu_event.php   an event's page
  template-parts/          event-card.php (design-system EventCard), event-row.php
  inc/                     template tags, Customizer, GitHub updater
  assets/css/              Te Atatū Local tokens + tal-* components (copied unchanged)
SPEC.md                    design spec
prompt.md                  reusable AI prompt for extending the plugin
README.md                  this file
LICENSE                    GPL-3.0
```

## Rebuilding the neighbourhood data

```
cd teatatu-events && php tools/build-neighbourhood-streets.php
```

Fetches Te Atatū Peninsula address points and the boundary roads from OpenStreetMap (Overpass API) and rewrites `assets/data/`. It fails if a street can't be described by clean house-number ranges. Data © OpenStreetMap contributors (ODbL).

## Teatatu Data (optional)

With the [Teatatu Data](https://github.com/TeAtatu/Teatatu-data-management) plugin active, a steward can let it manage this plugin's neighbourhoods, street rules, venues, sources, event tags and categories (its Connections tab). Teatatu Data then keeps those lists up to date on every site, linking existing terms, and they become read-only here. Without it, nothing changes. See `includes/teatatu-data-bridge.php`.

## Extending this plugin with AI

[`prompt.md`](prompt.md) captures the plugin's architecture and invariants. Paste it into a fresh AI coding session along with the change you want, so updates stay consistent with how the plugin was built.

## Releasing an update

Installed sites check this repository's latest GitHub Release (every 12 hours, or straight away with **Check for Updates Now** in the plugin settings) and offer it as a normal WordPress plugin update, which the site's "Enable auto-updates" setting can install automatically.

1. Set the new version in all three places: `Version:` in `teatatu-events/teatatu-events.php`, `TEATATU_EVENTS_VERSION` in the same file, and `Stable tag:` in `teatatu-events/readme.txt`. Add a changelog entry to `readme.txt`. Commit and push.
2. Build the packages: `sh teatatu-events/tools/build-release.sh`. It checks the plugin's three versions match (and the theme's `Version:` in `style.css`, `TTEV_THEME_VERSION` and its readme `Stable tag:`), and that nothing is uncommitted, then writes `teatatu-events-<version>.zip` and `teatatu-events-theme-<theme version>.zip` (each a single folder) to the repository root. Zips are git-ignored.
3. On GitHub: **Releases > Draft a new release**, tag `v<version>` on that commit, paste the changelog as the description (it becomes "View version details"), attach both zips, and **Publish release**.

The theme updates from the same release, but only when its own `Version:` in `teatatu-events-theme/style.css` has gone up — so bump it only when the theme changed.

If no zip is attached, sites fall back to GitHub's source zip of the tag and install only its `teatatu-events/` folder. For a private repository, enter a GitHub token (read access to the repository's contents) in the plugin settings so sites can see and download releases.

## License

GPL-3.0 — see [LICENSE](LICENSE).
