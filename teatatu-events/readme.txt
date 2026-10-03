=== Teatatu Events ===
Contributors: teatatu
Tags: events, calendar, ical, community, multisite
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Community events for WordPress: venues, neighbourhoods, recurring series, feed importers, calendar subscriptions, linked events across a network, and a draft-only workflow for AI agents.

== Description ==

Teatatu Events adds an **Event** content type built for a community site (or a network of them), with a workflow for human editors and AI agents.

* **Events** — single, multi-day, all-day and recurring. Status (Scheduled, Cancelled, Postponed, Rescheduled, Sold out), tickets/registration link, price or Free, Read More link, image (link-only), and a local page or a link out to the organiser.
* **Venues** — a reusable Location with a structured NZ address (unit, number, street, suburb, city, region, postcode, country, coordinates), aliases and map link. Each event also has its own **Room**, and a fallback **Address** when there's no venue.
* **Neighbourhoods** — every Te Atatū Peninsula address falls into exactly one of three areas — **Matipo**, **Beach** and **Harbourview** — split along Te Atatū Road, Taikata Road and Harbour View Road. Worked out automatically from the venue or address; filterable everywhere.
* **Event Tags** — a curated list (Show, Garage sale, Community event, Fundraiser…), several per event.
* **Recurring series** — a rule (every N days/weeks/months/years; weekdays; "2nd Tuesday"; ends never/on a date/after N), extra and skipped dates. The series keeps its next **up to 12** upcoming dates as real events; editing one date on its own detaches it.
* **Duplicate** any event as a new draft, or **Edit as draft**: stage changes to a published event and merge them in when published (with a field-by-field review if the live event changed meanwhile).
* **Importers** — RSS/Atom, HTML pages, **iCal (.ics)** and **Schema.org JSON-LD**, with draft-first review, pending updates, venue/tag matching, a recognised-venue or neighbourhood filter, and "removed at source" handling.
* **Front end** — list, grid, latest, month calendar, past-events archive and calendar-subscribe shortcodes, with visitor filters, add-to-calendar links, a details block and Schema.org Event JSON-LD on event pages, and iCal subscribe feeds.
* **Multisite** — every shortcode has a network twin (Master Site), **linked events** (show another site's event without copying it), and **cross-site duplicate removal** when several sites import the same event.
* **AI agents** — a draft-only **Events Agent** role and a REST interface documented in `MCP-GUIDE.md`.
* Uses **only standard WordPress tables**. Images are never downloaded or stored.

Teatatu Events runs on its own or side by side with Teatatu News — they share no code, data, hooks, roles or update channel.

== Installation ==

1. Copy the `teatatu-events` folder to `wp-content/plugins/`.
2. Activate it (or **Network Activate** on multisite).
3. Open **Teatatu Events** in the admin menu: add Venues and Event Tags, then events.
4. Settings: **Settings > Teatatu Events** (single site) or **Network Admin > Settings > Teatatu Events** (multisite) — default event duration, series length, removed-at-source threshold, default country, date formats, Master Site, linked events, duplicate handling, GitHub updates.
5. Sites that rely on timely imports should run a real server cron against `wp-cron.php` (and set `DISABLE_WP_CRON`), because WP-Cron only runs on page visits.

== The Teatatu Events screen ==

Tabs: **Events** (Upcoming / Past / Drafts / **Needs attention** / All) · **Series** · **Venues** · **Neighbourhoods** · **Event Tags** · **Sources** · **Categories** · **Pending Updates** · **Linked Events** (multisite) · **RSS Feeds** · **HTML Feeds** · **iCal Feeds** · **Structured Data** · **Shortcodes**.

Bulk actions on the Events lists (native and plugin): **Publish**, **Apply feed updates** and **Dismiss feed updates** for the checked events, plus **Publish all upcoming drafts**. A "Feed update pending" badge links to the change; Apply / Dismiss are also row actions, and Pending Updates has Apply all / Dismiss all.

**Needs attention** collects: imported addresses that matched no venue (Assign Location / Create venue from this address / Keep address only), peninsula addresses no rule could place in a neighbourhood, events **removed at source** (Confirm = stay cancelled / Restore), and published events with a **feed update pending** (Apply / Dismiss). The admin menu shows a count.

== Neighbourhoods ==

Resolution order: (1) an editor override on the venue or address-only event; (2) the **street rules** listed on the Neighbourhoods tab (network-wide, stored in the database); (3) coordinates against `assets/data/neighbourhoods.geojson`; (4) off the peninsula → none; (5) otherwise flagged "Needs neighbourhood".

Neighbourhood **slugs** can be renamed on the Neighbourhoods tab (network admins on multisite). A rename updates every site and every stored use — venues, events, series, street rules, feed filters, linked events and `[teatatu_events_*]` shortcodes in pages, posts and widgets; the old slug keeps working as an alias and old archive URLs redirect.

The street rules are loaded once, on activation, from the bundled `assets/data/neighbourhood-streets.json` (the same way the three neighbourhood terms are created), and are then edited on screen. "Add missing streets from the bundled list" tops up any bundled street that has no rules. The bundled list is generated from OpenStreetMap address points (`addr:suburb = Te Atatū Peninsula`) by `php tools/build-neighbourhood-streets.php`. 111 streets lie wholly in one neighbourhood; five cross a boundary and are split by house number and side of the road:

* Te Atatū Road — odd 375–595 Harbourview, odd 607+ Beach; even 378–568 Harbourview, even 570+ Matipo; lower numbers are Te Atatū South (no neighbourhood); odd 597–605 and even 569 have no data and are flagged.
* Taikata Road — odd Matipo, even Harbourview.
* Harbour View Road — even Beach, odd Harbourview.
* Matipo Road — odd ≤81 / even ≤88 Matipo; higher numbers Harbourview.
* Wharf Road — odd ≤37 / even ≤32 Matipo; odd 39+ / even 46+ Beach.

The build fails if a street's neighbourhoods interleave along it. Rebuild after big subdivision changes; editors can patch individual streets on the Neighbourhoods tab without a rebuild. No external geocoding service is ever called.

== Shortcodes ==

Matching pairs — the site version shows this site's events (plus linked events); the network version shows every site's events and only renders on the Master Site:

* `[teatatu_events_list]` / `[teatatu_events_network_list]` — vertical list
* `[teatatu_events_grid]` / `[teatatu_events_network_grid]` — card grid
* `[teatatu_events_latest]` / `[teatatu_events_network_latest]` — next few events (default 3)
* `[teatatu_events_calendar]` / `[teatatu_events_network_calendar]` — month calendar (agenda on phones)
* `[teatatu_events_archive]` / `[teatatu_events_network_archive]` — past events, newest first, paged
* `[teatatu_events_subscribe]` / `[teatatu_events_network_subscribe]` — calendar subscribe links

Attributes (all optional, both versions): `count` (10; latest 3; archive 20), `columns` (3), `when` (upcoming · past · all), `from`/`to` (YYYY-MM-DD or "+30 days"), `neighbourhood`, `tag`, `category`, `venue`, `source` (slug or comma list), `order` (ASC; past DESC), `group_by` (none · day · month), `show_filters` (false), `show_image`, `show_excerpt`, `show_tags`, `show_venue`, `show_room`, `show_time`, `show_status`, `show_tickets` (true), `show_neighbourhood`, `show_source`, `show_category`, `show_calendar_links` (false), `hide_cancelled` (setting), `linked` (include · exclude · only — site versions), `show_linked_badge` (true), `link_target` (_self), `display` (full · summary), calendar `month` (YYYY-MM) and `start_of_week`. Network only: `exclude_sites`, `site_label` ("From A · also on B").

Visitor filters and paging use per-shortcode query parameters, so several shortcodes on one page don't interfere and filtered views can be bookmarked.

== Calendar feeds ==

* One event: `/wp-json/teatatu-events/v1/events/{id}/ics`
* This site: `/wp-json/teatatu-events/v1/calendar.ics?neighbourhood=&tag=&category=&venue=&source=&linked=` — upcoming events plus the last 30 days, including linked events (with their source UID).
* Network (Master Site): `/wp-json/teatatu-events/v1/network-calendar.ics` — same filters plus `exclude_sites`; duplicates appear once.

An imported event's UID comes from its source identity, so the same real-world event has the same UID on every site and subscribers never see it twice.

== Importers ==

All four importers share one pipeline: draft-first creation; unreviewed drafts kept in sync; changes to published events held in **Pending Updates** (date/status changes first) unless the feed has **Auto-apply**; **Auto-publish** optional per feed. Both toggles can only be switched on by a user who already holds the matching publish/edit-published capability.

* **RSS/Atom** — per-field Content Mapping (RSS fields, a scraped linked page, or the linked page's JSON-LD — the default for dates and places), with an optional regex **Pattern** per field to cut a value out of a larger one.
* **Sessions** (RSS and HTML) — for event pages listing several dates: import the next upcoming session, or each upcoming session (up to 12) as its own event, grouped as "Other dates". Sessions come from the page's JSON-LD or a selector such as Eventfinda's `time.dt-start`.
* **HTML pages** — a Repeating Item selector (optionally only inside one container, e.g. the main listing), the same mapping, and optional "follow each item's link". An event linked twice on the page is imported once.
* **iCal** — built-in RFC 5545 parser (TZID, all-day, DURATION, RRULE/EXDATE/RDATE/RECURRENCE-ID, CATEGORIES, GEO, image attachments). Repeating events expand to their next 12 occurrences.
* **Structured Data** — Schema.org Event JSON-LD (incl. @graph and ItemList), optionally following event URLs.

Dates: ISO 8601 / `datetime=""` / microdata `content=""` / hCalendar `value-title`, an optional PHP format, then a lenient NZ day-first parser ("Sat 3 Oct 7–9pm", "3–5 Oct", "11-1pm", "A and B" = from A to B). An item whose start can't be read is skipped and reported. Linked Page JSON-LD understands every Schema.org event type (incl. Festival), follows `@id` references to venues and offers, and uses the next upcoming of several dates.

Places: a Location name or alias, then the normalised street address, then coordinates (within 50 m) are matched to a Venue. Unmatched → the address is stored and the event flagged. Imports never create venues or tags (unknown tags are ignored). Per feed: default Source/Category/Venue/Tags, skip already-ended events, and **Only import events at**: anywhere · any recognised venue · selected venues · selected neighbourhoods.

**Removed at source** — a published, upcoming event missing from a successful, complete fetch on two consecutive checks (setting) is set to Cancelled immediately and flagged; drafts are only flagged; nothing is ever deleted. If it comes back, its previous status is restored unless an editor confirmed the removal.

== Linked events and cross-site duplicates (multisite) ==

A site with **Show events from other sites** on (Linked Events tab; the network can disable the feature) can show another site's event — or a whole series — by reference. Linked events appear only in the site's own listings, with a "From Site" badge and an optional note; cards link to the event on its home site. Snapshots refresh when the source changes and nightly. Sources can opt out ("Don't allow other sites to show this event"). If the site already has its own copy of the event, the link is hidden.

Network listings collapse copies of the same event (same source identity, or optionally the same title + start + place when at least one copy was imported). The copy shown comes from the **Duplicate priority** site order; the newest status and dates in the group are always shown. Add `?dedupe=0` to the network feed to see every copy.

== REST API ==

* `/wp/v2/events` — standard routes, plus the writable `event` object (start/end in site time, all-day, status, room, address, tickets, price, links, image) and read-only derived values (display_when, display_place, neighbourhood, flags, series, draft copy). Extra query params: `when`, `event_from`, `event_to`, `orderby=event_start`, `include_draft_copies`.
* Taxonomies: `/wp/v2/event-sources`, `event-categories`, `event-venues`, `event-tags`, `event-neighbourhoods`.
* `teatatu-events/v1`: `GET events` (public range query), `GET events/{id}/ics`, `GET calendar.ics`, `GET network-calendar.ics`, `GET network-feed`, `POST events/{id}/duplicate`, `POST|DELETE events/{id}/draft`, `POST events/{id}/merge`, `GET venues/match`, `GET|POST links`, `DELETE links/{id}`, `GET linkable`.

Agent rules: a start date is required on create; a near-identical event returns `409 teatatu_events_duplicate_suspected`; an address without a venue flags the event for an admin. See `MCP-GUIDE.md`.

== Capabilities ==

Administrator/Editor: all event capabilities plus `manage_teatatu_events_{sources,categories,venues,tags,neighbourhoods,series,feeds,links}`.

**Events Agent** (`teatatu_events_agent`): `read`, `edit_/delete_teatatu_events_items`, create/assign Sources and Categories, assign Venues and Event Tags. Never publish, edit others' or published events, feeds, series, linked events, or create venues/tags.

== Scheduled jobs ==

`teatatu_events_rss_cron_tick`, `_html_`, `_ical_`, `_ld_cron_tick` (every 5 minutes; each feed still runs on its own cadence), `teatatu_events_series_cron_tick` (hourly), `teatatu_events_links_cron_tick` (daily, only where linked events are on), plus one-off batch jobs for duplicate-key backfill and neighbourhood re-resolution. Every hook is re-checked on `init`.

== Uninstall ==

Always removes the agent role, capabilities, scheduled jobs and settings. With "Delete all Teatatu Events data when this plugin is uninstalled" ticked, also deletes every event (including trashed), series, link, feed configuration and Events term on every site. Teatatu News data is never touched.

== Frequently Asked Questions ==

= Can it run alongside Teatatu News? =
Yes. Every identifier is different (functions, post types, taxonomies, roles, options, cron hooks, REST namespace, update repo), and uninstalling either leaves the other's data alone.

= Why are internal post types called teatatu_evt_*? =
WordPress limits post type names to 20 characters; `teatatu_events_` already uses 15.

== Changelog ==

= 1.0.0 =
* First release of Teatatu Events (built from Teatatu News 1.7.0): event data model, Venues, Neighbourhoods, Event Tags, recurring series, Duplicate / Edit as draft, RSS/HTML/iCal/JSON-LD importers, removed-at-source handling, twelve paired shortcodes with visitor filters and a month calendar, iCal feeds, Schema.org output, linked events, cross-site duplicate removal, REST `event` field and agent guide.
