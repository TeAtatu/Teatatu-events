# Teatatu Events

A WordPress plugin for community events — single, multi-day, all-day and recurring — with venues, neighbourhoods, event tags, feed importers, calendar subscriptions, and a workflow designed for a mix of human editors and AI agents.

The plugin folder ([`teatatu-events/`](teatatu-events/)) is a complete, self-contained WordPress plugin. Drop it into `wp-content/plugins/` on any WordPress 6.0+ / PHP 7.4+ site (single-site or multisite) and activate it. It runs on its own or side by side with Teatatu News.

## What it does

- **Events** (`teatatu_event`, REST `/wp/v2/events`) with start/end, all-day, status (cancelled, postponed, sold out…), tickets, price/free, image (link-only), and either a local page or a link out to the organiser.
- **Venues** with a structured NZ address, aliases and map link; a per-event **Room**; and a fallback **Address**.
- **Neighbourhoods** — every Te Atatū Peninsula address falls into **Matipo**, **Beach** or **Harbourview**, split along Te Atatū Road, Taikata Road and Harbour View Road, worked out automatically from street rules that are loaded from OpenStreetMap data on activation and can then be edited on screen.
- **Event Tags** — a curated list (Show, Garage sale, Community event, Fundraiser…).
- **Recurring series** that keep their next 12 dates as real events.
- **Duplicate** and **Edit as draft** (stage changes to a published event, then merge).
- **Importers** for RSS/Atom, HTML pages, iCal and Schema.org JSON-LD — draft-first, with pending updates, venue matching, a recognised-venue/neighbourhood filter and "removed at source" handling.
- **Twelve shortcodes** in site/network pairs (list, grid, latest, calendar, archive, subscribe) with visitor filters, plus iCal subscribe feeds, add-to-calendar links and Schema.org Event JSON-LD.
- **Multisite**: network-wide listings on a Master Site, **linked events** (show another site's event without copying it) and **cross-site duplicate removal**.
- A draft-only **Events Agent** role for AI agents — see [`teatatu-events/MCP-GUIDE.md`](teatatu-events/MCP-GUIDE.md) and [`teatatu-events/SKILL.md`](teatatu-events/SKILL.md).
- Only **standard WordPress tables**; images are never downloaded.
- Self-updates from [GitHub Releases](https://github.com/TeAtatu/Teatatu-events/releases).

## Getting started

1. Copy or symlink [`teatatu-events/`](teatatu-events/) into `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. Open **Teatatu Events** in the admin menu: add Venues and Event Tags, then events.
4. Check **Settings > Teatatu Events** (or **Network Admin > Settings > Teatatu Events**).
5. For AI agents: create a user with the **Events Agent** role, give it an Application Password, and follow [`teatatu-events/SKILL.md`](teatatu-events/SKILL.md).

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
  tools/                   build-neighbourhood-streets.php (regenerates assets/data)
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

## Extending this plugin with AI

[`prompt.md`](prompt.md) captures the plugin's architecture and invariants. Paste it into a fresh AI coding session along with the change you want, so updates stay consistent with how the plugin was built.

## License

GPL-3.0 — see [LICENSE](LICENSE).
