# Teatatu Events — Design Spec

Status: **implemented in 1.0.0.** This is the design the plugin in `teatatu-events/` was built from, updated where the build refined it (neighbourhood data, iCal identity, series links, complete-fetch rule). Section 12 records the decisions on the open questions.

---

## 1. Goals and decisions

| Decision | Choice |
|---|---|
| Event kinds | Single timed, multi-day, all-day and recurring |
| Location | Reusable **Venue** taxonomy (Location name + Address). Each event also has its own **Room** field and a fallback **Address** |
| Neighbourhood | Every Te Atatū Peninsula address falls into exactly **one of 3 neighbourhoods**, split along Te Atatū Rd, Taikata Rd and Harbour View Rd. Worked out automatically from the address |
| Tags | **Event Tags** taxonomy (e.g. "Show", "Garage sale", "Community event", "Fundraiser"), several per event |
| Event page | Imported events link out to their source. Local events get their own detail page. Editors can override this per event |
| Extra details | Tickets/registration, status badge, add to calendar |
| Recurrence storage | A series with a rule that **generates real occurrence posts**, keeping **up to 12 upcoming dates** at a time |
| Copy / draft editing | **Duplicate** any event as a new draft, or **Edit as draft**: stage changes to a published event and merge them in when published |
| AI agents | Draft-only role, plus `MCP-GUIDE.md`, a reference for agents using an MCP wrapper over the WordPress REST API |
| Linked events | A site can opt in to **show events from other sites on the network in its own listings**, by reference and without making a copy (e.g. a business site featuring a community event) |
| Cross-site duplicates | When several sites import the same external event, **network listings show it once** |
| Front end | Upcoming list/grid, month calendar, past-events archive, visitor filters |
| Imports | RSS/Atom, HTML scraping, **iCal (.ics)**, **Schema.org JSON-LD** |
| "Past" cutoff | An event is past **after its end time** |
| Imported changes | Same as News: new items arrive as drafts, changes to published items wait for human review, and each feed can opt in to auto-publish or auto-apply. **Exception:** an event removed at its source is treated as cancelled straight away and flagged "Removed at source" |
| Past events | Kept and shown in the past-events archive, never auto-trashed |
| Public submission | Planned as a later phase (Phase 7) |
| Time zone | Site time zone only (imports are converted into it) |
| Coexistence | Runs **side by side with Teatatu News or on its own**, with no shared code, data, hooks or dependency |
| Kept from News | Multisite network aggregation, AI agent role + SKILL.md (now paired with MCP-GUIDE.md), GitHub self-updater, link-only images, standard tables only |
| Identity | Prefix `teatatu_events`, version **1.0.0**, repo `TeAtatu/Teatatu-events` |

All News invariants in `prompt.md` still apply after renaming (standard tables only, link-only images, draft-first agent role, multisite patterns, security baseline, shared renderers, GitHub updater). This spec lists only what changes or is new.

---

## 2. Naming (Phase 1: the rename)

Everything is renamed so that no identifier is shared with Teatatu News.

| Thing | News | Events |
|---|---|---|
| Main file | `teatatu-news.php` | `teatatu-events.php` |
| Plugin basename | `teatatu-news/teatatu-news.php` | `teatatu-events/teatatu-events.php` |
| Functions / hooks / options | `teatatu_news_*` | `teatatu_events_*` |
| Constants | `TEATATU_NEWS_*` | `TEATATU_EVENTS_*` |
| Text domain, admin page slug, CSS classes | `teatatu-news` | `teatatu-events` |
| Meta keys | `_teatatu_news_*` | `_teatatu_events_*` |
| Event post type | `teatatu_news` | `teatatu_event` (13 chars) |
| REST base / rewrite slug | `news` | `events` |
| Taxonomies | `teatatu_news_source`, `_category` | `teatatu_events_source`, `teatatu_events_category`, **`teatatu_events_venue`**, **`teatatu_events_tag`** |
| Taxonomy REST bases | `news-sources`, `news-categories` | `event-sources`, `event-categories`, `event-venues`, `event-tags` |
| Custom REST namespace | `teatatu-news/v1` | `teatatu-events/v1` |
| Agent role | `teatatu_news_agent` ("News Agent") | `teatatu_events_agent` ("Events Agent") |
| Capabilities | `*_teatatu_news_items` etc. | `*_teatatu_events_items` etc. |
| Shortcodes | `[teatatu_news_*]` | `[teatatu_events_*]` |
| Cron hooks / interval | `teatatu_news_rss_cron_tick`, `teatatu_news_html_cron_tick`, interval `teatatu_news_five_minutes` | Renamed equivalents, plus new jobs for iCal, Structured Data and series. The full list is in §9a |
| Updater slug / repo | `teatatu-news`, `TeAtatu/Teatatu-news` | `teatatu-events`, `TeAtatu/Teatatu-events` |

**Post type length exception.** WordPress limits `post_type` to 20 characters, and `teatatu_events_` already uses 15. The internal post types therefore use a short `teatatu_evt_` prefix. This is the only departure from the prefix rule, and `prompt.md` must document it:

| Internal CPT | Length | Purpose |
|---|---|---|
| `teatatu_evt_series` | 18 | Recurring series (template + rule) |
| `teatatu_evt_rssfeed` | 19 | RSS/Atom feed config |
| `teatatu_evt_webfeed` | 19 | HTML page feed config |
| `teatatu_evt_icalfeed` | 20 | iCal feed config |
| `teatatu_evt_ldfeed` | 18 | JSON-LD feed config |
| `teatatu_evt_link` | 16 | Linked event: a reference to another site's event or series (§3.6) |

Rename order, to avoid half-renamed identifiers: `teatatu_news_` → `TEATATU_NEWS_` → `teatatu-news` → the bare `teatatu_news` post type. After that, rewrite the user-facing "News"/"article" wording by hand; don't blind-replace it.

**Coexistence tests (must pass):** activate both plugins on one site and on a network, get no fatal errors, and see two separate admin menus. Uninstalling one plugin must leave the other plugin's posts, terms, roles, options and cron jobs untouched. Each plugin's updater must only ever fetch its own repo.

---

## 3. Data model

### 3.1 Event (`teatatu_event`)

- `supports`: `title`, `excerpt`, **`editor`** (new; local events need a full description). Still no `thumbnail`, because images stay link-only.
- `public => true`, `has_archive => true`, `rewrite => events`.
- `post_date` keeps its normal WordPress meaning (when the post was published). It is **never** used as the event date.

Post meta. All keys are registered with `show_in_rest` and sanitize callbacks.

| Key | Type | Notes |
|---|---|---|
| `_teatatu_events_start` | int (UTC unix ts) | Required. Used for sorting and querying |
| `_teatatu_events_end` | int (UTC unix ts) | Always stored. If the editor leaves it blank, it is start + default duration (a setting, default 2 h). All-day events end at 23:59:59 local time on their last day |
| `_teatatu_events_all_day` | bool | All-day events show dates only. Their start is 00:00 local time |
| `_teatatu_events_status` | enum | `scheduled` (default), `cancelled`, `postponed`, `rescheduled`, `sold_out` |
| `_teatatu_events_ticket_url` | url | Booking/registration link |
| `_teatatu_events_price` | string | Free text, e.g. "$20–$35", "Koha" |
| `_teatatu_events_is_free` | bool | Drives the "Free" badge and schema.org `isAccessibleForFree` |
| `_teatatu_events_read_more_url` | url | External page (renamed from News) |
| `_teatatu_events_link_mode` | enum | `auto` (default), `external`, `local`. `auto` means external if a Read More URL exists, otherwise local |
| `_teatatu_events_image_url` | url | Link-only, same resolver chain as News: post → Source default → Venue default → empty |
| `_teatatu_events_room` | string | Room/space within the venue, e.g. "Hall B". Event-only, never stored on the Venue |
| `_teatatu_events_address` | string (multi-line) | Fallback address, used only when no Venue is assigned. Imports that don't match a Venue put their location text here (see §6.6) |
| `_teatatu_events_needs_venue` | bool | Set by an import whose location didn't match a Venue. Cleared when a Venue is assigned or an admin dismisses it |
| `_teatatu_events_removed_at_source` | int (UTC ts) | When the importer found the event missing from its source (see §6.7) |
| `_teatatu_events_status_before_removal` | enum | The status to restore if the removal is undone |
| `_teatatu_events_series_id` | int | Set on generated occurrences |
| `_teatatu_events_detached` | bool | This occurrence was edited individually, so series regeneration no longer overwrites it |
| `_teatatu_events_external_key` | string (hash) | Identity of the external event this was imported from, the same on every site that imports it (§5.5). Empty for local events |
| `_teatatu_events_fuzzy_key` | string (hash) | Normalised title + start date/time + place, computed for every event on save. A fallback for spotting the same event imported by different routes (§5.5) |

Why timestamps? Comparing integers (`meta_type NUMERIC`) gives correct upcoming/past queries without time zone or DST problems. Every value is shown to visitors with `wp_date()` in the site time zone.

Helpers (one place, used by everything): `teatatu_events_get_start( $id )`, `_get_end()`, `_is_past()`, `teatatu_events_format_when( $id )` (renders "Sat 3 Oct, 7–9pm", "3–5 Oct", "Sat 3 Oct (all day)" and so on), and `teatatu_events_query_args( $when, $from, $to )`, the single source of the upcoming/past/range `meta_query`.

### 3.2 Venue (`teatatu_events_venue`, new taxonomy)

A Venue has two parts editors work with: **Location** (the term name, e.g. "Te Atatū Community Centre") and a structured **Address**. Other term meta: `map_url` (optional; defaults to a Google Maps search link built from the address), `website_url`, `aliases` (comma list of other names, used for import matching), `default_image_url` (link-only).

**Venue address fields.** Each is its own term meta key (registered with `show_in_rest`, so they read and write over `/wp/v2/event-venues`), following NZ Post address conventions and mapping one-to-one onto schema.org `PostalAddress`:

| Field | Meta key | Example | schema.org | Required |
|---|---|---|---|---|
| Unit / level | `teatatu_events_addr_unit` | Unit 2, Level 1 | part of `streetAddress` | no |
| Street number | `teatatu_events_addr_number` | 12A | part of `streetAddress` | yes* |
| Street name | `teatatu_events_addr_street` | Te Atatū Road | part of `streetAddress` | yes* |
| Suburb | `teatatu_events_addr_suburb` | Te Atatū Peninsula | (appended to `streetAddress` line 2) | no |
| **Neighbourhood** | `teatatu_events_addr_neighbourhood` (term ID) | Matipo | not exported (no schema.org equivalent) | derived, see §3.2b |
| Town / city | `teatatu_events_addr_city` | Auckland | `addressLocality` | yes |
| Region | `teatatu_events_addr_region` | Auckland | `addressRegion` | no |
| Postcode | `teatatu_events_addr_postcode` | 0610 | `postalCode` | no |
| Country | `teatatu_events_addr_country` | NZ (ISO 3166-1 alpha-2, default = setting) | `addressCountry` | yes (defaulted) |
| Latitude / longitude | `teatatu_events_addr_lat`, `teatatu_events_addr_lng` | -36.8429, 174.6452 | `geo` | no |

\* Street number and name can be left blank for places that don't have one (parks, reserves, marae). Suburb or city is then used for matching.

- The admin form shows these as separate inputs, plus an optional **"Paste address"** box that splits a one-line address into the fields for the editor to check before saving. It uses the same parser as imports (§6.6).
- A derived, read-only `teatatu_events_addr_normalized` meta is rebuilt on every save. It holds the normalised street number + street name + suburb + city + postcode (see §6.6), and it is what imports match against, so matching is one indexed meta query.
- Display uses a single formatter, `teatatu_events_format_address( $term_id, $style )`, with `$style` = `single_line`, `multi_line` or `ics`. Nothing else joins the fields by hand.

How an event's place is shown:

| Event has | Shown as |
|---|---|
| Venue | Venue Location, Room (if set), and the Venue's Address. Any event-level Address is ignored |
| No Venue, Address set | The event's own Address, with Room if set |
| Neither | No location line |

So choosing a Location fills in the address automatically, and the event-level Address only matters when there isn't a Venue.

Capabilities: `manage_/edit_/delete_/assign_teatatu_events_venues`.

### 3.2a Event Tags (`teatatu_events_tag`, new taxonomy)

Non-hierarchical, several per event. Labelled "Event Tags" in the admin, to keep it distinct from the site's normal post tags and from Categories. Example values: Show, Garage sale, Community event, Fundraiser. Editors manage the list. Tags are a controlled vocabulary, so imports only assign tags that already exist (§6.1). Term meta: optional `color` for the badge. Capabilities: `manage_/edit_/delete_/assign_teatatu_events_tags`.

### 3.2b Neighbourhoods (`teatatu_events_neighbourhood`, new taxonomy)

A custom geographic area inside Te Atatū Peninsula. **Every peninsula address falls into exactly one of three neighbourhoods.** The boundaries follow roads so residents can tell at a glance which one they're in.

**The three neighbourhoods:**

| Neighbourhood | Where |
|---|---|
| **Matipo** | North of **Taikata Road** and west of **Te Atatū Road** |
| **Beach** | North of **Harbour View Road** and east of **Te Atatū Road** |
| **Harbourview** | South of Taikata Road and Harbour View Road, down to the North-Western Motorway (SH16) |

Taikata Road runs west and Harbour View Road runs east from the same stretch of Te Atatū Road at the town centre. Together they form one east–west line across the peninsula. Te Atatū Road then splits everything north of that line into west and east.

```
             N
        Matipo | Beach
               | ← Te Atatū Rd
 ── Taikata Rd ┼ Harbour View Rd ──   (town centre)
          Harbourview
               | ← Te Atatū Rd
         ── SH16 ──
```

**Where the names come from.** *Harbourview* is named after Harbourview–Orangihina Park, near SH16 in the south of the peninsula, not after Harbour View Road. Even-numbered houses on Harbour View Road are in Beach. The seeded term descriptions include each name's origin, so the admin screens and any public neighbourhood list make this clear.

**Addresses on boundary roads** are split by side of the road and house number. The rules come from 6,041 OpenStreetMap address points tagged Te Atatū Peninsula (plus blank or "Te Atatu"-tagged points inside the peninsula outline), each placed by where it sits relative to the boundary roads. Odd/even sides matched the address data exactly: no address on Taikata or Harbour View Road contradicted the rule. Five streets cross a boundary:

| Road | Odd numbers | Even numbers |
|---|---|---|
| Te Atatū Road | 375–595 Harbourview · 607+ Beach | 378–568 Harbourview · 570+ Matipo |
| Taikata Road | Matipo (north side) | Harbourview (south side) |
| Harbour View Road | Harbourview (south side) | Beach (north side) |
| Matipo Road | ≤ 81 Matipo · 83+ Harbourview | ≤ 88 Matipo · 90+ Harbourview |
| Wharf Road | ≤ 37 Matipo · 39+ Beach | ≤ 32 Matipo · 46+ Beach |

Te Atatū Road's two sides are numbered out of step, so each side has its own cut-off; no single number works. Te Atatū Road numbers below the peninsula's lowest (Te Atatū South) get no neighbourhood. Numbers with no address data on either side of a cut-off (odd 597–605, even 569) are flagged for an editor rather than guessed. Every other peninsula street (111 of them) lies wholly inside one neighbourhood.

**How a neighbourhood is resolved.** One function, `teatatu_events_resolve_neighbourhood( $address_parts )`, used by venues, event addresses, imports, and REST. The first rule that applies wins:
1. **Manual override** on the venue (or on an address-only event).
2. **Street rules**: rows of street, side (both/odd/even), number range and neighbourhood, stored in the database (network option `teatatu_events_nbhd_street_rules`) and listed in full on the Neighbourhoods tab, where every rule can be edited, added or removed (network admins on multisite). A street's rows are checked in order and the first that matches the house number wins. The rules are **not a table in the code**: like the three neighbourhood terms, they are **loaded once** on activation (or upgrade, or first use) from the bundled `assets/data/neighbourhood-streets.json`, and from then on the database copy is the only one used. An "Add missing streets from the bundled list" button tops up streets that have no rows, without touching existing ones. The bundled file is generated from OpenStreetMap by `tools/build-neighbourhood-streets.php`, which **fails the build** if a street's neighbourhoods interleave along it (can't be described by clean ranges).
3. **Coordinates**: if the address has lat/lng, it is classified against the boundary lines in `assets/data/neighbourhoods.geojson`: the Taikata/Harbour View line, and Te Atatū Road extended due north to the coast. A peninsula outline is used for the on-peninsula test.
4. **Not on the peninsula**: another suburb is named (e.g. Point Chevalier, Te Atatū South), or the point is outside the outline → no neighbourhood. This is normal, not an error.
5. Otherwise (a peninsula address that nothing resolved) → no neighbourhood, and the venue/event is flagged **Needs neighbourhood** under Needs attention.

No external geocoding service is called. The plugin only uses coordinates an editor or source already supplied.

**Storage and behaviour:**
- It's a taxonomy so it can be filtered with `tax_query`, exposed over REST (`event-neighbourhoods`) and renamed. The three terms are created on activation. Editors can rename them and edit descriptions, but there is **no add/delete UI**, and the resolver refuses to use any term that isn't one of the three seeded ones (stored by ID in `teatatu_events_nbhd_term_ids`).
- **Slugs can be renamed** (Neighbourhoods tab; network admins on multisite, since slugs are shared by every site). Each neighbourhood has a fixed internal key (`matipo`, `beach`, `harbourview`) used only by the boundary classifier and the bundled street data; the slug is a network setting (`teatatu_events_nbhd_slugs`). A rename runs on every site and updates every stored use: the neighbourhood term, venue neighbourhoods and overrides, event and series-template overrides, the street rules, feed neighbourhood filters, `neighbourhood="…"` in `[teatatu_events_*]` shortcodes in post content and text/HTML/block widgets, and linked-event snapshots. Events are attached by term ID, so they move with it. The old slug is kept as an alias (`teatatu_events_nbhd_slug_aliases`): shortcodes, visitor-filter links, REST/ICS parameters and agents using it still get the neighbourhood, and old archive URLs 301-redirect. A slug edit made any other way (REST, term screens) is ignored, so nothing is left pointing at a slug that no longer exists. A slug can't be blank, `outside`, or another neighbourhood's.
- **Venues:** the resolved term ID is saved in `teatatu_events_addr_neighbourhood`, plus `teatatu_events_addr_neighbourhood_via` (`override` · `road` · `street` · `coords`), shown next to the field ("Matipo — from the street rules"). It's re-resolved whenever the address changes, unless overridden.
- **Events** get the `teatatu_events_neighbourhood` term assigned automatically on save: the venue's neighbourhood, or else one resolved from the event's own Address (parsed with §6.6's parser), or none. Editors can't set it directly on events that have a venue. On address-only events they can override it.
- **When the rules change** (a street-rule edit), a one-off background job re-resolves all venues and address-only events, on every site.
- Display: an optional badge on cards and the detail page (`show_neighbourhood`).

Capabilities: `manage_teatatu_events_neighbourhoods` (rename and describe; editors/admins). Street rules are network-wide, so on multisite they need `manage_network_options`. Everyone who can edit events can read it.

### 3.3 Source and Category

These are unchanged from News apart from the rename. Source is still where an item came from (an organiser or a feed) and holds the default image.

### 3.4 Recurring series (`teatatu_evt_series`)

- Not public, no REST, and no native admin UI. It is managed from the plugin's maintenance page, like the feed CPTs.
- Holds the template fields (title, excerpt, content, taxonomies including tags, venue, room, address, tickets, price, image, first start/end, all-day) plus the rule.
- `_teatatu_events_rrule`: stored as an RFC 5545 `RRULE` string, so the iCal importer and the editor share one engine. Supported subset: `FREQ` (DAILY/WEEKLY/MONTHLY/YEARLY), `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` (including `2TU` and `-1FR` forms), `BYMONTHDAY`, `BYMONTH`.
- `_teatatu_events_exdates`: excluded dates. `_teatatu_events_rdates`: extra dates, which covers "irregular dates chosen by hand" too.
- **Rolling window of up to 12 upcoming dates.** Each series has a `_teatatu_events_upcoming_count` setting (1–12, default 12; 12 is a hard maximum). The plugin keeps exactly that many *upcoming* (not yet ended) occurrences generated. When one ends, the next date is added, so an ongoing weekly class always shows its next 12 sessions. A rule with `COUNT` or `UNTIL` still stops where it says. The rule itself can run indefinitely; only the generated posts are capped.
- **Generation:** `teatatu_events_series_sync( $series_id )` expands the rule from now until it has the series' upcoming count (exdates excluded, rdates included). It then:
  - creates missing occurrences (`teatatu_event` posts with `post_parent` = series)
  - updates occurrences that aren't detached
  - moves future, non-detached occurrences that no longer match the rule to the **trash**. It never deletes them permanently
  - never touches past occurrences.

  It runs on series save and from an hourly cron that tops each series back up to its upcoming count as occurrences end. Cancelled occurrences **count** towards the 12 (they're still shown with a Cancelled badge). Detached occurrences count too.
- **Status:** occurrences take their status from the series when generated. Publishing a series publishes all of its non-detached occurrences. Because the agent role can't publish, an agent can draft a series but a human has to publish it.
- **Duplicate series** creates a new draft series with the same template and rule and no occurrences until it is saved.
- **Editing one occurrence** marks it detached. The admin shows "Edited separately from series", plus a "Reset to series" action.
- Occurrences are ordinary events, so every query, shortcode, REST route and feed works with no recurrence-specific code.

### 3.5 Duplicate and Edit as draft

Two copy actions are available on any event (row actions in both the maintenance page and the native list, plus buttons on the edit screen):

**Duplicate** creates a brand-new, independent **draft** event:
- Copies title (with "(copy)" appended), excerpt, content, all event fields, Room/Address, image, and all taxonomies (Source, Category, Venue, Event Tags).
- **Doesn't copy** anything that identifies where the original came from: import identity (`_teatatu_events_import_uid`, source links, feed ID), series link and detached flag (the copy is standalone), `removed_at_source`/`needs_venue` flags, and pending updates. Status resets to Scheduled.
- Start/end are copied as they are. The edit screen opens with the date field focused and a notice "Set the date for this copy". Publishing a copy whose start is unchanged from its original asks for confirmation.
- Author is the user who duplicated it.
- `_teatatu_events_copied_from` records the source ID (informational only).

**Edit as draft** stages changes to a **published** event without touching the live version:
- Creates a hidden draft copy (`_teatatu_events_draft_of` = original ID, `_teatatu_events_draft_base` = the original's `post_modified_gmt` at copy time). It has every field of the original, including the import/series identity, because it will be merged back.
- Draft copies are excluded from every front-end query, shortcode, feed, `.ics`, REST collection for the public, and the importers' dedupe. In the admin they show as "Draft changes to: *Original title*".
- Only **one** open draft copy per event. A second "Edit as draft" opens the existing one.
- **Publishing the draft copy** doesn't create a new post. The copy's fields, meta and taxonomies are merged into the original, keeping the original's ID, URL, author, publish date and import identity. The draft copy is then permanently deleted (it's a working copy, not content). Only users who can edit the original published event can do this.
- **Conflict check:** if the original changed after the copy was made (an editor, an applied import update, or a removed-at-source cancellation), publishing shows a field-by-field diff of *original now* vs *your draft*, and the user picks per field. Status changes made by removed-at-source are always shown.
- Editing an occurrence through a draft copy marks the occurrence detached on merge.
- **Discard draft** deletes the copy and leaves the original untouched.
- This is also how the **agent role** proposes changes to published events. It can create and edit a draft copy, which it owns, but can never merge it, because merging needs `edit_published_teatatu_events_items` on the original.

Implementation: both actions go through one function, `teatatu_events_copy_event( $id, $mode )` with `$mode` = `duplicate` or `draft_of`. Merging goes through `teatatu_events_merge_draft( $draft_id )`, hooked on the draft copy's transition to `publish`, so it works the same from the maintenance page, the block editor and REST.

### 3.6 Linked events (showing another site's event without copying it)

A site can list an event that lives on another site in the network as if it were one of its own. It doesn't create a second event. For example, a local business site marks the community garage sale from the community site, so the sale appears in the business site's event list and calendar. There is still one event with one page, and the community site's editors still own it.

**Turning it on (per site).** Site setting **"Show events from other sites"**, off by default. Linked events only exist while it is on. Turning it off hides all of the site's links without deleting them. A network setting, **"Allow linked events"** (default on), can switch the feature off for every site. Linked events need multisite; the setting is hidden on single-site installs.

**Letting events be linked (source side).** Every published event can be linked unless its editor ticks **"Don't allow other sites to show this event"** (`_teatatu_events_no_linking`). Draft copies, drafts and private events can never be linked.

**What a link is.** A post of the internal type `teatatu_evt_link` on the linking site (not public, no REST, no native UI), holding:

| Meta | Purpose |
|---|---|
| `_teatatu_events_link_site_id` | Home site of the event |
| `_teatatu_events_link_event_id` *or* `_teatatu_events_link_series_id` | The event, or a whole series (see below) |
| `_teatatu_events_link_note` | Optional short note shown on this site's card, e.g. "We'll have a stall here" |
| `_teatatu_events_start`, `_teatatu_events_end`, `_teatatu_events_status`, `_teatatu_events_all_day` | **Snapshot** of the source event, under the same keys as real events, so `teatatu_events_query_args()` sorts and filters links and local events in one query |
| `_teatatu_events_link_snapshot` | Title, excerpt, image URL, display place, permalink, Read More URL, site name, and term slugs, used for rendering without switching sites on every page view |

The same taxonomies (Category, Tag, Source, Venue, Neighbourhood) are registered on `teatatu_evt_link`. The snapshot's term slugs are assigned where this site has a term with that slug, so the site's own filters work on linked events. Neighbourhoods always match, because every site has the same three.

**Linking a whole series** creates an anchor link for the series plus one link per current upcoming occurrence, kept in step as the series rolls forward, so each date appears on its own. New occurrences show up automatically as the series rolls forward.

**Keeping snapshots current:**
- A network-wide registry (site option `teatatu_events_link_registry`: `"{site}:{event}" → [linking site IDs]`) records who links to what.
- When a linked event is saved, changes status, is trashed or is deleted, the plugin refreshes every link to it on each linking site, using `switch_to_blog()` once per site.
- A daily reconciliation job (§9a) re-checks every link and repairs snapshots missed by the hooks (e.g. after a direct database edit).
- If the source is **cancelled or postponed**, the link shows that status, just like a local event. If it is **unpublished, trashed or opted out of linking**, the link is hidden and flagged on the linking site ("Source event no longer available"). If it is **permanently deleted**, the link is removed.

**How linked events display** (this site's shortcodes, calendar, archive and subscribe feed):
- They are mixed in with local events, sorted by start, and filtered by the same attributes.
- The card links to the event's page (or Read More URL) **on its home site**. There is no local page, so search engines see no duplicate content, and no JSON-LD is output for it here.
- By default, a small **"From *Site name*"** badge is shown (`show_linked_badge`), plus the link note if one is set.
- The site's `calendar.ics` includes them with the **source's `UID`**. Someone who subscribes to both sites' calendars sees the event once.
- In **network** shortcodes and feeds, links are ignored. The network already includes the source event once, so it never appears twice.

**Managing links (admin).** A **Linked Events** tab, shown when the setting is on, has:
- **Browse network events**: a search of other sites' published, linkable upcoming events (title, date, site, neighbourhood filters), with a "Show on this site" button, and a "Show whole series" button for occurrences.
- A list of this site's links showing their status (live, cancelled, hidden, past), note editing and "Remove".
- On the source site, each event's edit screen lists "Shown on: *Site A, Site B*", read-only, so owners know who is featuring it.

---

## 4. Admin (maintenance page "Teatatu Events")

Tabs: **Events** · **Series** · **Venues** · **Neighbourhoods** · **Event Tags** · **Sources** · **Categories** · **Pending Updates** · **Linked Events** (when enabled) · **RSS Feeds** · **HTML Feeds** · **iCal Feeds** · **Structured Data** · **Shortcodes**

- **Events:** list filtered by Upcoming (default) / Past / Drafts / **Needs attention** / All, with columns When, Title, Venue, Tags, Status, Source, Origin (Local / feed name / series). The add/edit form includes a date/time picker, an all-day toggle, Location (venue picker), Room, Address (shown only when no Location is chosen; picking a Location shows its address read-only instead), tags, status, tickets, price/free, link mode, image URL with preview, and description.
- Row actions: Edit · **Duplicate** · **Edit as draft** (published events only) · Trash. Events with an open draft copy show a "Draft changes pending" badge linking to it.
- **Bulk actions** (native Events list and the plugin's Events tab, with checkboxes): **Publish**, **Apply feed updates**, **Dismiss feed updates**; plus a **"Publish all upcoming drafts (N)"** button (with a confirmation) for drafts that haven't finished. Bulk publishing runs the same checks as publishing by hand: the publish capability, a start date, and never a draft copy (its changes publish onto the original through the merge review) or a duplicate whose date hasn't been confirmed; skipped events are counted with the reason.
- **Feed update pending** is resolved where it's seen: the badge links to the change on Pending Updates (hovering shows what changed), events with one get **Apply feed update** / **Dismiss feed update** row actions and appear under **Needs attention**, and the Pending Updates tab has checkboxes, bulk Apply/Dismiss, and **Apply all** / **Dismiss all**. Apply and Dismiss return to the list they were used from.
- **Quick Edit** on the native Events list (edit.php) has an **Event details** section for correcting metadata without opening the event: start/end date and time, all-day, event status, venue, room, neighbourhood (address-only events; disabled when a venue is chosen, since the venue decides), price, ticket URL, and **Event Tags as checkboxes** (core's free-text tag box is turned off for Event Tags, so Quick Edit can't create stray tags). It saves through the same single writer as the edit screen: choosing a venue clears "Needs venue" and re-resolves the neighbourhood, a series occurrence is detached, and a validation error (e.g. ends before it starts) is shown in the Quick Edit row with nothing saved. Core's "Date" is relabelled "Published" so it isn't mistaken for the event date.
- **Neighbourhoods:** rename the three areas and edit their descriptions, edit street rules, and a "Test an address" box that shows which neighbourhood it resolves to and which rule decided it.
- **Needs attention** also lists venues/events flagged *Needs neighbourhood*.
- **Needs attention** collects the two import flags, and the admin menu shows a count badge for them:
  - *Needs venue:* the imported address is shown with "Assign Location" (existing Venue, or create one from this address) and "Keep address only" (dismiss).
  - *Removed at source:* the event is already Cancelled; actions are "Confirm" (keep cancelled, clear the flag) and "Restore" (put back the previous status).
- **Series:** the same form plus a rule builder (repeat every N days/weeks/months/years; weekdays; monthly by date or by "2nd Tuesday"; ends never / on date / after N). It has an "Upcoming dates to keep" field (1–12, default 12) and shows a live preview of those dates, the exclusions, and a list of generated occurrences. Row actions: Edit · **Duplicate** · Trash.
- **Pending Updates:** moved out of the News tab into its own tab, because date and status changes are time-sensitive. Each row shows a field-by-field diff (old → new) with Apply/Dismiss. Changes to date or status are highlighted.
- The native `edit.php?post_type=teatatu_event` screen keeps an "Event Details" meta box with the same fields, plus sortable When/Venue/Status columns (default sort: start ascending).

---

## 5. Front end

### 5.1 Shortcodes

Shortcodes come in **matching pairs**: every display available for this site's events has a network-wide twin with the same name plus `network_`, the same attributes, and the same output markup.

| Display | This site's events | All sites on the network |
|---|---|---|
| Vertical list | `[teatatu_events_list]` | `[teatatu_events_network_list]` |
| Card grid | `[teatatu_events_grid]` | `[teatatu_events_network_grid]` |
| Compact "next N events" widget | `[teatatu_events_latest]` | `[teatatu_events_network_latest]` |
| Month calendar with prev/next | `[teatatu_events_calendar]` | `[teatatu_events_network_calendar]` |
| Past events, newest first, paginated | `[teatatu_events_archive]` | `[teatatu_events_network_archive]` |
| Calendar subscribe links (webcal/Google/Outlook) | `[teatatu_events_subscribe]` | `[teatatu_events_network_subscribe]` |

That's six of each, twelve in total. Rules that keep the pairs in step:
- **One implementation per display.** Each pair calls one render function with a `scope` of `site` or `network`. The only thing `scope` changes is where the events come from: `teatatu_events_query_items()` for this site, and `teatatu_events_get_network_feed_items()` for the network. Filtering, sorting, grouping, markup and CSS are shared, so a new attribute or display added to one scope is automatically in the other.
- **Same attributes.** Every attribute below works in both scopes. Two are network-only: `exclude_sites` (comma list of site IDs to leave out) and `site_label` (show which site each event is from). The site versions ignore them.
- **Linked events** (§3.6) appear only in the *site* versions, alongside local events. The network versions skip links because they already show the source event. Attribute `linked` = `include` (default when the site setting is on) · `exclude` · `only`.
- **Network shortcodes only render on the Master Site**, as in News. Elsewhere they show an admin-only notice and output nothing for visitors.
- **Links go to the event's home site.** A network card links to the event's page (or Read More URL) on the site it belongs to, never a copy on the Master Site.
- **Cross-site filtering is by slug.** On the network, `category`, `tag`, `source`, `venue` and `neighbourhood` match term slugs on each site. The three neighbourhood terms are seeded with the same slugs on every site, so neighbourhood filters work across the whole network. Visitor filter dropdowns on a network shortcode list the combined terms from all sites, merged by slug.
- **Network archive paging:** page *n* fetches up to *n* × `count` past events from each site, then merges and slices them. It's cached like the other network queries. Deep pages on large networks cost more, so `count` is capped at 50.
- **Network subscribe feed:** `[teatatu_events_network_subscribe]` points at `GET /teatatu-events/v1/network-calendar.ics` (§5.4).
- The in-admin **Shortcodes tab** documents each pair on one row, with the attributes listed once and the network-only ones marked.

Shared attributes (list/grid/latest/archive, both scopes): `count`, `columns`, `source`, `category`, **`venue`**, **`neighbourhood`** (slug or comma list), **`tag`** (slug or comma list), **`when`** (`upcoming` default · `past` · `all`), **`from` / `to`** (`YYYY-MM-DD` or relative, like `+30 days`), `order` (ASC for upcoming, DESC for past), **`group_by`** (`none` · `day` · `month`), **`show_filters`**, `show_image`, `show_excerpt`, `show_source`, `show_category`, **`show_venue`**, **`show_neighbourhood`**, **`show_room`**, **`show_tags`**, **`show_time`**, **`show_status`**, **`show_tickets`**, **`show_calendar_links`**, **`linked`**, **`show_linked_badge`**, **`hide_cancelled`** (default false; cancelled events show with a strike-through badge), `link_target`, `exclude_sites`, `site_label`, `display`.

`orderby` is dropped. Events always sort by start time. The network cache key allow-list becomes `count/source/category/venue/neighbourhood/tag/when/from/to/order/page/month/exclude_sites`. `page` (archive) and `month` (calendar) change which events are fetched, so they must be in the key. Tags and neighbourhoods match across sites by slug.

Calendar attributes (both scopes): `month` (`YYYY-MM`, default current), `source`, `category`, `venue`, `neighbourhood`, `tag`, `show_filters`, `start_of_week` (default: the WP setting). A multi-day event appears on every day it covers. On phones the grid collapses to an agenda list. Navigation works without JavaScript through query params namespaced per instance (`?tte_cal_<id>=2026-11`, where the id is a hash of the shortcode's own attributes), so two calendars on one page don't interfere.

### 5.2 Visitor filters

`show_filters="true"` renders a GET form with Neighbourhood, Category, Event Tag, Venue, Source and date range dropdowns (and a keyword search). It is server-rendered, so it works without JavaScript and filtered views can be bookmarked. Params are namespaced per shortcode instance. Only terms that have at least one matching event are offered.

### 5.3 Event detail page (local events)

`the_content` filter adds a details block above the description: when, place (Location, Room and Address as in §3.2, with map link), event tags, status badge, price/free, a tickets button, and add-to-calendar links. If the event is part of a series it also shows "Other dates in this series". Pages for past events say "This event has finished". **Schema.org `Event` JSON-LD** goes in `wp_head` for SEO, with status mapped to `eventStatus` and tickets to `offers`.

For external events, the card links straight to the Read More URL, and the local single page (if it's ever reached) redirects there.

### 5.4 Add to calendar

- Per event `.ics`: `GET /wp-json/teatatu-events/v1/events/{id}/ics` (only published events). Uses `UID` = `{post_id}@{site host}` and includes `STATUS:CANCELLED` when applicable.
- Google Calendar link (`calendar.google.com/calendar/render?action=TEMPLATE…`).
- Outlook.com link.
- **Subscribe feed:** `GET /wp-json/teatatu-events/v1/calendar.ics` lists all published upcoming events (plus the last 30 days), filterable by `?neighbourhood=&category=&tag=&venue=&source=`, so each neighbourhood can have its own calendar subscription. Linked events (§3.6) are included with their source `UID`, unless `?linked=exclude` is set. People can subscribe to it in Google, Apple or Outlook calendar. A `[teatatu_events_subscribe]` shortcode shows the subscribe/webcal links. Responses are cached and the cache is cleared whenever an event changes.
- **Network subscribe feed:** `GET /wp-json/teatatu-events/v1/network-calendar.ics`, the network twin of the subscribe feed. It's served by the Master Site only, has the same filters plus `exclude_sites`, and each event's `UID` and `URL` point at its home site, so subscribers never see duplicates.
- In all `.ics` output, `LOCATION` = Location, Room, Address.

### 5.5 Removing duplicates across sites

Several sites may import the same event from the same external source (e.g. two sites both subscribe to the council's iCal feed). Each site keeps its own copy, because it owns its content and review workflow, but **network listings show each real-world event once**.

**How copies are recognised.** Two keys are computed for each event:

1. **External key** (`_teatatu_events_external_key`, exact). Every importer writes this, built from the source's own identity so it's the same whichever site imported it:
   - iCal: `ical:` + `UID` + occurrence start (UTC). This matches the existing dedupe key.
   - JSON-LD: `ld:` + the event's `@id`, or else its `url`.
   - RSS / HTML: `url:` + the item's source link.

   URLs are normalised first: lower-case host, `http`→`https`, no `www.`, no fragment, no trailing slash, and tracking parameters (`utm_*`, `fbclid`, `gclid`, …) removed. The key is stored as a SHA-1 hash.
2. **Fuzzy key** (`_teatatu_events_fuzzy_key`, fallback). A hash of the normalised title (lower-case, macrons and punctuation stripped), the start time to the minute (just the date for all-day events), and the place (venue slug, else the neighbourhood plus normalised street address, else nothing). This catches the same event arriving by different routes, e.g. one site uses the organiser's RSS and another scrapes the listing page.

Two events are duplicates if their external keys match, or, when the fuzzy setting is on, their fuzzy keys match **and at least one of them was imported**. Events that editors created by hand on two sites are never merged by default. They may be deliberately separate (see settings).

**Where duplicates are removed:**
- In `teatatu_events_get_network_feed_items()`, after events are gathered from all sites and **before** sorting, counting and paging. So it applies equally to all six network shortcodes, `/teatatu-events/v1/network-feed` and `network-calendar.ics`. Each site is asked for extra events (up to 2 × `count`) so a page is still full after duplicates are removed.
- In **site** listings that include linked events (§3.6), if a linked event duplicates one of this site's own events, the site's own copy is shown. The Linked Events browser also warns "This site already has this event" before a link is added.
- Duplicate removal never changes or deletes anything. It only affects what is displayed. Each site's own (site-scope) listings still show its own copy.

**Which copy is shown (the canonical copy):**
1. The copy on the site highest in the **Duplicate priority** network setting (an ordered list of sites; default: Master Site first, then by site ID).
2. On a tie, the most recently updated copy.

The card links to the canonical copy. If some copies have newer information than the canonical one (e.g. one site has already imported a cancellation), the **newest status and dates** are shown, so a stale copy never hides a cancellation. With `site_label="true"`, the card reads "From *Site A* · also on *Site B*, *Site C*". Network REST items include `also_on: [{site_id, name, url}]`.

In `network-calendar.ics`, the `UID` of an event imported from an external source is derived from its external key, not a post ID. It stays the same even if the canonical copy changes, so subscribers never see the event twice or see it jump.

**Settings (network):** **Merge duplicate events in network listings** (on), **Also use fuzzy matching** (on), **Also merge near-identical events created by hand** (off), **Duplicate priority** (site order). For troubleshooting, `?dedupe=0` on the network REST endpoint returns every copy, each marked with its canonical group.

Keys are backfilled for existing events on upgrade by a one-off background job, and recomputed whenever an event is saved or re-imported.

---

## 6. Importers

All four importers share one field model, one dedupe/pending-update pipeline, and the same draft-first + per-feed auto-publish/auto-apply behaviour (with the same capability gates) as News. The generic DOM helpers in `rss-importer.php` move to `includes/import-common.php` so they're no longer misleadingly `rss_`-prefixed.

### 6.1 Mappable fields

Title, Excerpt, Description, Image, Read More URL, **Start**, **End**, **All-day**, **Location**, **Address**, **Room**, **Event Tags**, **Ticket URL**, **Price**, **Status**.

Address can be mapped as one free-text value (usual for RSS/HTML/iCal) or, where the source provides them, as separate segments (JSON-LD always does). Either way it goes through the address matcher in §6.6.

Event Tags: the mapped text is split on commas, and each value is matched case-insensitively against existing tag names/slugs. Unknown values are ignored and never create terms. Each feed can also have default tags that apply to everything it imports.

`Publish Date` is offered for Start/End only, labelled as such: most feeds' publish dates are not event dates, but some (Eventfinda) put the next session's start there. A publish date with no time zone is read as site time, not UTC.

**Pattern** (optional, per field): a regular expression (no delimiters; case-insensitive) that cuts the value out of what the source found. The first `( )` group is kept, or the whole match if there's none; no match leaves the field empty. It is matched against the element's HTML for text fields, the date text for Start/End, and the URL for links and images. Invalid patterns are dropped on save. Example: the venue from Eventfinda's RSS content "Saturday 31st Oct, 7.00pm, Mr Illingsworth<br/>Auckland" is `,\s*([^,<]+)<br`.

**Linked Page JSON-LD** reads every Schema.org event type (all `…Event` types plus `Festival`, `Hackathon`, `CourseInstance`, `EventSeries`) and resolves `{"@id": …}` references across the page's JSON-LD blocks, so a venue or offer listed as a separate node is still found. When a page lists several events (one per session), the next upcoming one is used. Several offers combine into one price ("Free", "$2", "$10–$25"); the first offer URL is the ticket URL; the event is Sold out only when every offer is. An `addressLocality` that isn't a known town/city (e.g. "Te Atatū Peninsula") is treated as the suburb.

**Date parsing** (`teatatu_events_parse_datetime( $raw, $format_hint )`):
1. A machine-readable value, if present: `datetime="…"`, microdata `content="…"`, or an hCalendar `value-title` `title="…"`, on the selected element or inside it; ISO 8601; or a session value like `2026-11-07, 10:00–15:00`
2. an optional per-field PHP format string (e.g. `D j M Y, g:ia`)
3. a lenient parser that reads NZ day-first dates (`3/10/2026` = 3 Oct), month names, "7pm", and ranges like "3–5 Oct", "7–9pm" or "A and B" (which fill both Start and End: the first date is the start, the second the end).

Text extracted from HTML keeps a space where a line break or block ended, so "A<br>B" reads "A B".

**Sessions** (RSS and HTML feeds): for event pages that list several dates. Read from the linked page's Schema.org events, or from every element matching a selector (e.g. Eventfinda's `time.dt-start`); past sessions are ignored. Modes: *off* (the mapped Start/End), *next upcoming session*, or *each upcoming session as its own event* (up to 12). In "each" mode a session's identity is the item link plus its start, its external key is per session (so cross-site de-duplication matches session to session), and sessions of one source event share an import group, so each event page lists the others under "Other dates" — like a series, but still import-owned (never a local series, as with iCal). A page with no readable sessions keeps the mapped dates.

**Repeated items**: an item whose identity has already been seen in the same fetch is ignored (the first wins), in imports and Preview.

A value without a time zone is read as site time. A date without a year takes the next future occurrence of that date. **If an item's start can't be parsed, it is skipped and the feed's last error records it.** Unlike News, there's no fallback to "now".

### 6.2 RSS/Atom

The same as News with the fields above. Most feeds hold the event date in the description or on the linked page, so dates usually need an element/class selector.

### 6.3 HTML pages

The same as News (Repeating Item selector, and the Read More URL doubles as the dedupe key). Add a **"Follow each item's link"** option so Start/End/Venue/etc. can be mapped from the linked detail page, and an optional **"Only inside"** container selector, so items are taken only from one part of the page (e.g. the main listing, not a "Popular events" block that repeats some of them).

### 6.4 iCal (.ics), new

- Fetched with `wp_safe_remote_get()`, with a size cap. `webcal://` is accepted and rewritten to `https://`.
- Built-in minimal RFC 5545 parser, no bundled library. It handles line unfolding, escaping, `VEVENT`, `DTSTART`/`DTEND`/`DURATION` (with `TZID`, `VALUE=DATE` and `Z`), `SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL`, `UID`, `STATUS`, `RRULE`, `EXDATE`, `RDATE`, `RECURRENCE-ID`, `ATTACH`/`IMAGE` and `CATEGORIES` (matched against Event Tags, see §6.1).
- No field mapping is needed. Fields map directly, and there's an optional default Source/Category/Venue per feed.
- Recurring VEVENTs are expanded with the **same RRULE engine** as series, with the same limit of up to **12 upcoming** occurrences per recurring VEVENT (the window rolls forward on each check), each occurrence becoming its own imported event. They are not imported as local series, so editors never manage a rule that the source owns.
- Dedupe key `_teatatu_events_import_uid` = the `UID` for a one-off event (so a rescheduled event stays the same event), and `UID` + occurrence start (or `RECURRENCE-ID`) for an occurrence of a repeating event. This handles moved single occurrences correctly.
- `STATUS:CANCELLED` maps to a status change, which goes through pending updates like any other change.

### 6.5 Structured Data (Schema.org JSON-LD), new

- The feed URL is a listing or event page. The importer reads all `<script type="application/ld+json">` blocks and finds `Event` (and subtypes like `MusicEvent`) inside `@graph`, `ItemList` and arrays.
- Optional **"Follow event URLs"**: if the listing only links to events, it fetches each linked page (capped per run) and reads that page's JSON-LD.
- Direct mapping: `name`, `description`, `startDate`, `endDate`, `eventStatus`, `location` (Place `name` → Location; `PostalAddress` segments map straight onto the address fields in §3.2, and a plain-text `address` goes through the parser; `geo` → lat/lng; `VirtualLocation` → online, no address), `keywords` → Event Tags, `offers` (url, price, `isAccessibleForFree`), `image`, `url`.
- Dedupe key: the event's `url`, or `@id` if present.
- A "Linked page JSON-LD" mapping source is also offered in the RSS and HTML importers, which is often the most reliable way to get dates from a detail page.

### 6.6 Venue matching on import

**Address parsing and normalisation** (`teatatu_events_parse_address( $text )`, `teatatu_events_normalize_address( $parts )`) do the following:
- Split a free-text address into the §3.2 segments: unit/level, street number (including ranges and letters, e.g. `12-14`, `12A`, `2/15` → unit 2, number 15), street name, suburb, city, postcode (4 digits) and country. For NZ addresses the parser recognises the standard street types and NZ city names. It never guesses a segment it can't identify. Those parts are simply left empty.
- Normalise for comparison: lower-case, strip macrons (Atatū → atatu), punctuation and repeated spaces, expand abbreviations (St → Street, Rd → Road, Ave → Avenue, Dr → Drive, Pl → Place, Cres → Crescent, Tce → Terrace, Hwy → Highway, and so on), and drop the country and unit/level.

**Matching order.** The first match wins:
1. **Location name**: an imported Location equal to a Venue's name or one of its `aliases` (normalised).
2. **Address**: an imported address whose normalised street number + street name, **and** suburb or city or postcode, match a Venue's. For number-less venues, a normalised match on the name within the same suburb/city counts.
3. **Coordinates**: if both sides have lat/lng, within 50 m (a setting).

A Venue matched by address is assigned exactly like one matched by name. The event then uses the Venue's structured address, and the imported address is not stored.

**No match:**
1. No Venue is assigned.
2. Only the **Address** field is filled, using the imported address, or the imported location text if there's no separate address. The event is flagged `_teatatu_events_needs_venue` and appears under Needs attention.
3. An imported Room goes in `_teatatu_events_room` either way.

Venue terms are never auto-created by an import. An admin can create one from the flagged address in one click (§4). The address is pre-split into segments for the admin to check.

### 6.6a Recognised-address filter (per feed)

Every automated feed (RSS, HTML, iCal, JSON-LD) has an **"Only import events at a recognised venue"** setting:

| Setting | Behaviour |
|---|---|
| **Off** (default) | Import everything, and flag unmatched addresses as above |
| **Any recognised venue** | Import only items that match a Venue by §6.6. All other items are skipped |
| **Selected venues only** | As above, but only for the venues ticked on the feed (multi-select of Venue terms) |
| **Selected neighbourhoods** | Import only items whose address (venue or raw address) resolves to one of the ticked neighbourhoods (§3.2b). This includes unmatched-venue addresses, which are still flagged *Needs venue*. Items off the peninsula or unresolvable are skipped |

- An item with no location or address counts as unrecognised when the filter is on.
- Skipped items create no post and no Needs-attention flag. The feed's status line shows a count for the last run ("12 imported, 7 skipped — not a recognised venue"), and the feed's "Preview" lists which items would be skipped and why, so admins can add a missing venue or alias.
- The filter is applied **before** dedupe/create. An already-imported event that later stops matching (e.g. it moved to an unrecognised address) is not cancelled. Its change goes through the normal pending-update review.
- If the filter hides an item on every check, that is **not** "removed at source" (§6.7). Removal is judged only on what the source itself returned, before filtering.
- Changing the filter doesn't touch events already imported.
- Stored as `_teatatu_events_feed_venue_filter` (`off` · `any` · `selected`) and `_teatatu_events_feed_venue_ids` / `_teatatu_events_feed_neighbourhood_ids` on the feed config post (the filter setting gains the value `neighbourhoods`). Needs `manage_teatatu_events_feeds`, like the rest of the feed config.

### 6.7 Import rules specific to events

- Events that have already ended when first seen are skipped (per-feed toggle, default on).
- Pending updates for published events whose **start, end or status** changed are flagged as "date/status change" and sorted to the top of Pending Updates.
- **Removed at source.** This applies to every feed type, for events that are still upcoming, and only when the fetch was **complete**: the source returned its whole list, not one cut off by the per-run item cap. A long RSS feed that only shows its newest items therefore never cancels older events. If a published, not-yet-ended event is missing from a successful fetch, the importer **sets its status to Cancelled immediately**, saving the previous status in `_teatatu_events_status_before_removal`, and flags it `_teatatu_events_removed_at_source`. This bypasses the pending-update queue on purpose, because a vanished event is urgent. Visitors see the normal Cancelled badge. Admins see the flag under Needs attention (§4). Safeguards:
  - Only after a fetch that succeeded and returned at least one event. An empty or failed fetch never cancels anything.
  - Only after the event is missing on **two consecutive** checks, to ride out flaky sources.
  - Drafts removed at source are just flagged. They were never public, so they're not cancelled.
  - If the event reappears at source, the flag clears. The status goes back automatically only if nobody has confirmed the removal. Otherwise it's queued as a pending update.
- Events are never deleted by an importer.
- Every importer writes `_teatatu_events_external_key` (§5.5) on create and re-import. Its per-site dedupe (`_teatatu_events_import_uid`, source links) is unchanged, so matching within a site works as before.

---

## 7. REST API

- `/wp/v2/events` with all meta from §3.1 (raw timestamps), plus the `event-sources`, `event-categories`, `event-venues` and `event-tags` taxonomies.
- An **`event` REST field** on `/wp/v2/events`: one writable object that is the recommended interface for agents and integrations. It holds `start`/`end` (ISO 8601; a value without an offset is read as site time), `all_day`, `status`, `room`, `address`, `ticket_url`, `price`, `is_free`, `link_mode`, `read_more_url` and `image_url`. It also has read-only `display_when`, `display_place`, `neighbourhood` (`{id, name, slug, via}` or null), `is_past`, `needs_venue`, `removed_at_source`, `series_id`, `draft_of` and `has_draft`. The server validates it: `end` ≥ `start`, a valid enum `status`, and well-formed URLs.
- `event-neighbourhoods` is exposed on `/wp/v2/events` (read-only for agents and imports; it's derived) and filterable like any taxonomy.
- Extra query params on `GET /wp/v2/events`: `when` (upcoming/past/all), `event_from`, `event_to`, and `orderby=event_start`. Draft copies are excluded unless `include_draft_copies=1`.
- `GET /teatatu-events/v1/venues/match?location=&address=` runs the §6.6 matcher (auth: `assign_teatatu_events_venues`). Returns the matched venue (ID, name, formatted address, how it matched) or `null`, plus the parsed address segments and the resolved neighbourhood.
- `POST /teatatu-events/v1/events/{id}/duplicate` returns the new draft (§3.5).
- `POST /teatatu-events/v1/events/{id}/draft` creates or returns the draft copy of a published event (§3.5).
- `POST /teatatu-events/v1/events/{id}/merge` merges a draft copy (needs edit-published on the original). `DELETE /teatatu-events/v1/events/{id}/draft` discards it.
- An agent- or import-created event saved with an address but no venue gets `needs_venue` set automatically. Events created by humans don't.
- **Duplicate guard for agents:** when a user with the agent role creates an event, the server checks for an existing event (published, or any draft visible to editors) with the same normalised title and start date, at the same venue or address. If it finds one, it returns `409 teatatu_events_duplicate_suspected` with `data.existing_id`. Humans are never blocked.
- Custom error codes: `teatatu_events_invalid_range`, `teatatu_events_not_published`, `teatatu_events_draft_locked` (the draft copy is owned by someone else) and `teatatu_events_duplicate_suspected`. They're documented in `MCP-GUIDE.md` §8.
- Taxonomy fields on `/wp/v2/events` are named by each taxonomy's **REST base** (`event-venues`, `event-tags`, …), which is how WordPress core names them.
- `GET /teatatu-events/v1/events?from=&to=&when=&venue=&category=&source=&per_page=`, a range query for the calendar, agents and external consumers. Public, published events only. Also takes `tag`, `neighbourhood` and `linked` (`include`·`exclude`·`only`). Linked items are marked `"linked": true` with `home_site` (`id`, `name`, `url`) and the source `event_id`.
- Link management (editors only, `manage_teatatu_events_links`): `GET /teatatu-events/v1/links`, `POST /teatatu-events/v1/links` (`site_id` + `event_id` or `series_id`, optional `note`), `DELETE /teatatu-events/v1/links/{id}`, and `GET /teatatu-events/v1/linkable?search=&from=&to=` (the network browse list). All return 403 if the site setting is off.
- `GET /teatatu-events/v1/events/{id}/ics`, per-event calendar file.
- `GET /teatatu-events/v1/calendar.ics`, subscribe feed (§5.4).
- `GET /teatatu-events/v1/network-feed`, network aggregation (Master Site only). It takes the same filter params as `GET /teatatu-events/v1/events` (`when`, `from`, `to`, `venue`, `neighbourhood`, `tag`, `category`, `source`, `per_page`, `page`) plus `exclude_sites`, and shares `teatatu_events_get_network_feed_items()` with the network shortcodes.
- `GET /teatatu-events/v1/network-calendar.ics`, network subscribe feed (§5.4).
- Series aren't in REST, so agents create single events. See Q4 in §12.
- **`teatatu-events/MCP-GUIDE.md`** ships with the plugin. It is the agent-facing reference for all of the above: auth, field schemas, workflows, suggested MCP tool definitions and error handling. `SKILL.md` stays as the short workflow and points to it. Any REST change must update both (§11).

---

## 8. Capabilities and roles

Editor/Administrator: all Events caps, including `manage_teatatu_events_venues`, `manage_teatatu_events_tags`, `manage_teatatu_events_series` and `manage_teatatu_events_feeds`. That's **one** capability for all four importer types, replacing News's separate RSS/HTML caps. `manage_teatatu_events_links` covers adding, removing and noting linked events on this site. Editors/admins only. Agents never get it; an agent can suggest a link in its report for a human to add. The "Don't allow other sites to show this event" option is part of normal event editing.

`teatatu_events_agent`: `read`, `edit_/delete_teatatu_events_items`, `assign_/manage_/edit_` sources and categories (WordPress needs `edit_terms` to create a hierarchical term over REST), and **`assign_` only for Venues and Event Tags**. Those are curated lists (venues drive the recognised-venue import filter), so agents pick from them but can't grow them. An agent that can't find a venue submits an Address instead, and the event is flagged for an admin. Agents can Duplicate and create draft copies (they own the copy), but never merge. Like News, it never gets publish, edit-others or edit-published, and never feeds or series.

---

## 9. Settings

On top of News's (Master Site, GitHub repo/token, delete-on-uninstall):
- Default event duration (2 h)
- Default upcoming dates kept per series (12, maximum 12)
- Hide cancelled events from lists by default (off)
- Removed-at-source: number of consecutive missing checks before cancelling (2)
- Default country for venue addresses (NZ)
- Coordinate match distance for venue matching (50 m)
- Date and time display formats (default: the WordPress settings)
- **Show events from other sites** (per site, off). Enables linked events (§3.6)
- **Allow linked events** (network setting, on). Master switch for the whole network
- **Cross-site duplicates** (network settings, §5.5): merge duplicates in network listings (on), fuzzy matching (on), merge hand-made near-duplicates (off), duplicate priority (site order)

---

## 9a. Scheduled jobs (WP-Cron)

News has two recurring jobs on a custom 5-minute interval. Events keeps that pattern and adds four more:

| Hook | Interval | Runs | New? |
|---|---|---|---|
| `teatatu_events_rss_cron_tick` | 5 min | RSS/Atom feeds that are due by their own cadence | renamed |
| `teatatu_events_html_cron_tick` | 5 min | HTML page feeds that are due | renamed |
| `teatatu_events_ical_cron_tick` | 5 min | iCal feeds that are due, including rolling each recurring VEVENT forward to 12 upcoming | **new** |
| `teatatu_events_ld_cron_tick` | 5 min | Structured Data (JSON-LD) feeds that are due | **new** |
| `teatatu_events_series_cron_tick` | hourly | Tops each series back up to its upcoming count as dates end (§3.4) | **new** |
| `teatatu_events_links_cron_tick` | daily | Reconciles linked-event snapshots against their sources and prunes deleted ones (§3.6). Only scheduled on sites with the setting on | **new** |

- **Custom interval:** `teatatu_events_five_minutes`, registered once and shared by the four importer ticks. Hourly uses WordPress's built-in `hourly`. The 5-minute tick is only the finest a feed *can* go. Each feed still runs on its own cadence, and a tick with nothing due returns straight away.
- **One tick per importer**, not a shared dispatcher, mirroring News. A slow or failing source type (e.g. a large iCal file) can't use up the run time of the others, and each can be debugged or cleared on its own.
- **Removed-at-source checks** (§6.7) and the recognised-venue filter (§6.6a) run inside each importer's tick. They need no extra job.
- **Cache invalidation** for the subscribe feed and network aggregation happens on save/change hooks, so it needs no scheduled job.
- **One-off jobs** (single events, not recurring): the duplicate-key backfill on upgrade (§5.5) and the neighbourhood re-resolve after a rule change (§3.2b). Both process events in batches and reschedule themselves until done.
- **Self-healing:** as in News, each hook is re-checked on `init` and rescheduled if missing.
- **Clean-up:** deactivation and uninstall clear **all six** hooks on every site (`wp_clear_scheduled_hook`). Nothing touches `teatatu_news_*` hooks, so both plugins' jobs run independently side by side.
- **Low-traffic sites:** WP-Cron only runs on page visits, so a quiet site may check feeds late. `readme.txt` should recommend a real server cron hitting `wp-cron.php` (with `DISABLE_WP_CRON`) for sites that depend on timely imports.

---

## 10. Uninstall

The same retention-aware behaviour as News. It only touches `teatatu_event`, `teatatu_evt_*`, `teatatu_events_*` taxonomies/options/transients/cron hooks and the `teatatu_events_agent` role, **never** anything belonging to Teatatu News.

---

## 11. Delivery phases

1. **Rename + coexistence.** The mechanical rename in §2, version 1.0.0, updater pointed at the new repo, `readme.txt`/`SKILL.md`/`prompt.md`/`README.md` rewritten, `MCP-GUIDE.md` trimmed to what exists at that point, coexistence tests pass. Works as "News, but called Events".
2. **Event data model.** Start/end/all-day/status/tickets/price/link mode, the `event` REST field and query params, venue match endpoint, Duplicate / Edit as draft, Venue and Event Tags taxonomies (structured venue address + formatter), Neighbourhoods (taxonomy, resolver, street-table build script and GeoJSON), Room/Address, editor UI, admin columns, upcoming/past queries, REST meta.
3. **Front end.** List/grid/latest/archive/subscribe shortcodes in **both scopes** (site + network twins), cross-site duplicate removal (§5.5: keys, canonical choice, settings, backfill job), linked events (§3.6: settings, link CPT, registry + snapshot refresh, Linked Events tab, reconciliation cron), visitor filters, detail page block, JSON-LD, add-to-calendar/.ics, both subscribe feeds.
4. **Calendar shortcode**, in both scopes.
5. **Recurring series.** RRULE engine, series CPT and admin, rolling 12-upcoming occurrence sync + cron, Duplicate series.
6. **Importers.** Shared field model and date parser, external keys written by all importers, RSS/HTML updated, iCal (reusing the RRULE engine), JSON-LD, address parser + venue/tag matching, recognised-venue feed filter, removed-at-source handling, Needs attention and Pending Updates tabs.
7. **Public submission (later).** A front-end form where the public can submit events. Submissions land as `pending` for review, with spam protection and no account required. It will be specified separately when this phase starts.

Each phase bumps the version, updates the changelog, the Shortcodes tab, `readme.txt`, `SKILL.md` and `MCP-GUIDE.md` in lockstep, and ships as its own GitHub release.

---

## 12. Decisions on open questions

1. **Removed at source:** the event is treated as Cancelled immediately and flagged "Removed at source" for an admin to confirm or restore (§6.7).
2. **Unmatched venues:** only the Address is loaded and the event is flagged for an admin. Picking a Location loads its Address. Room is event-only (§3.2, §6.6). Venue addresses are stored as separate segments. Each automated feed can be limited to recognised venues (§6.6a).
3. **Subscribe feed:** yes (§5.4).
4. **Agent access to series:** no response, so the default applies. Agents draft single events only.
5. **Past events:** kept and shown in the archive, never auto-trashed.
6. **Public submission:** later phase (Phase 7).
7. **Neighbourhood names:** Matipo, Beach and Harbourview (§3.2b). The cut-offs and odd/even sides are now derived from OpenStreetMap address data (§3.2b). A cut-off setting isn't needed. Still worth a local check: the few Te Atatū Road numbers with no data (odd 597–605, even 569), and that Te Atatū Road extended due north is the right Matipo/Beach line at the northern tip.
8. **Linked events:** added (§3.6). Per-site opt-in, reference only (never a copy), shown only in the site's own listings, with the source event's owners able to opt out.
9. **Cross-site duplicates:** added (§5.5). Network listings show each imported event once. Copies are matched by source identity (and optionally a fuzzy title + time + place key). The copy shown comes from a site priority list, but the newest status and dates are always displayed.
