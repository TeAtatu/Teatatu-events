# Teatatu Events — Agent & MCP Guide

Reference for AI agents that work with Teatatu Events through an **MCP server wrapped around the WordPress REST API**. It covers what the agent may do, how to connect, the exact data shapes, a suggested MCP tool set, step-by-step workflows, and how to handle errors.

> Matches Teatatu Events 1.0.0. If an endpoint below returns `404 rest_no_route`, the site runs an older version or the plugin is inactive: tell the human instead of working around it. `SKILL.md` is the short version of this guide.

---

## 1. Ground rules (read first)

1. **You draft; humans publish.** Your role (`teatatu_events_agent`) cannot publish, cannot edit published events, and cannot edit other users' events. WordPress enforces this, and it is not a temporary restriction. Never ask a human to give you more permissions.
2. **Changes to a published event go through a draft copy** (§6.3). You never edit the live event directly.
3. **Pick from curated lists; don't grow them.** Venues and Event Tags are curated by editors. You can assign existing ones but not create them. You *may* create Sources and Categories, but only after checking none match.
4. **Always check for duplicates before creating** (§6.1).
5. **Images are links.** Supply a public image URL. The plugin never downloads or uploads images, so don't try to use `/wp/v2/media`.
6. **Stay on one site.** On multisite, your user exists on one site only. Every request must use that site's own base URL.
7. **Report what you did.** End every task with the IDs, titles, statuses and admin edit links of everything you created or changed.

---

## 2. Connecting

| Setting | Value |
|---|---|
| Base URL | `https://<site>/wp-json` |
| Auth | HTTP Basic with a WordPress **Application Password**: `Authorization: Basic base64(username:app_password)` |
| Content type | `application/json` |
| Time zone | The site's time zone (normally `Pacific/Auckland`) |

To set it up, a human creates a WordPress user with the **Events Agent** role, then generates an Application Password under **Users > Profile > Application Passwords**.

The MCP wrapper should read these from its own configuration or environment (e.g. `WP_BASE_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`). It must never put them in tool inputs or outputs, logs, or URLs.

**Health check:** `GET /wp/v2/users/me?context=edit` should return your user with `roles: ["teatatu_events_agent"]`. If it shows another role, stop and tell the human, because the permission assumptions in this guide won't hold.

---

## 3. Data model

### 3.1 Event (`/wp/v2/events`)

The fields you'll use. Send the event details in the single **`event`** object. The raw `meta` keys also exist, but don't write them directly.

```jsonc
{
  "id": 123,                          // read-only
  "status": "draft",                  // you may send "draft" or "pending" only
  "title": "Te Atatū Garage Sale",
  "excerpt": "Plain-text summary, 1–2 sentences, shown on cards.",
  "content": "<p>Full description (HTML). Shown on the event's own page.</p>",
  "link": "https://site/events/te-atatu-garage-sale/",   // read-only

  // Taxonomies: the field name is the taxonomy's REST base; the value is an array of term IDs
  "event-sources":    [12],
  "event-categories": [7],
  "event-venues":     [31],           // at most one venue
  "event-tags":       [4, 9],

  "event": {
    "start": "2026-10-03T09:00:00",   // local site time, no offset (recommended)
    "end":   "2026-10-03T13:00:00",   // optional; defaults to start + site default duration
    "all_day": false,
    "status": "scheduled",            // scheduled | cancelled | postponed | rescheduled | sold_out
    "room": "Hall B",                 // optional, room within the venue
    "address": "",                    // only when no venue fits (see §6.2)
    "ticket_url": "https://tickets.example/garage-sale",
    "price": "Free entry",            // free text: "$20", "$15–$25", "Koha"
    "is_free": true,
    "link_mode": "auto",              // auto | external | local
    "read_more_url": "https://organiser.example/events/garage-sale",
    "image_url": "https://organiser.example/img/garage-sale.jpg",
    "no_linking": false,              // multisite: true stops other sites showing this event

    // read-only:
    "start_ts": 1790996400,           // UTC Unix timestamps
    "end_ts": 1791010800,
    "display_when": "Sat 3 Oct, 9am–1pm",
    "display_place": "Te Atatū Community Centre, Hall B, 595 Te Atatū Road, Te Atatū Peninsula, Auckland 0610",
    "neighbourhood": { "id": 3, "name": "Matipo", "slug": "matipo", "via": "street" },
    "is_past": false,
    "needs_venue": false,
    "needs_neighbourhood": false,     // peninsula address no rule could place
    "removed_at_source": null,        // ISO date when the feed dropped it (then Cancelled)
    "pending_update": false,          // a feed change is waiting for human review
    "series_id": null,
    "draft_of": null,                 // set on a draft copy: the published event's ID
    "has_draft": false                // true if a draft copy of this event exists
  }
}
```

**Dates and times**
- Send local wall-clock time **without an offset** (`2026-10-03T19:00:00`). The server reads it in the site time zone and handles daylight saving. If you do include an offset, it must be correct for that date (NZ is `+13:00` from late September to early April, otherwise `+12:00`).
- **All-day events:** set `all_day: true` and send dates only, e.g. `"start": "2026-10-03", "end": "2026-10-05"`. `end` is the **last day, inclusive**, so that example runs 3–5 Oct.
- If you don't know the end time, leave `end` out. **Don't invent one.**
- If you don't know the start time but know the date, use an all-day event and say "time TBC" in the excerpt. Never guess a time.
- Dates without a year: confirm the year from the source. If you can't, ask.

**`link_mode`**
- `auto` (default): cards link to `read_more_url` if set, otherwise to the event's own page.
- `external`: always link out to `read_more_url`.
- `local`: always use the event's own page, which then needs a good `content`.

**Status vs publish status.** `event.status` is the *event's* state (e.g. cancelled). The top-level `status` is the *post's* publish state. They are independent.

### 3.2 Taxonomies

| REST route | Event field | Agent may | Notes |
|---|---|---|---|
| `/wp/v2/event-venues` | `event-venues` | read, assign | Name = **Location**. Address is split into fields (below) |
| `/wp/v2/event-neighbourhoods` | `event-neighbourhoods` | read only | One of 3 peninsula areas. **Set automatically** from the venue or address; never send it |
| `/wp/v2/event-tags` | `event-tags` | read, assign | e.g. Show, Garage sale, Community event, Fundraiser |
| `/wp/v2/event-categories` | `event-categories` | read, assign, create | |
| `/wp/v2/event-sources` | `event-sources` | read, assign, create | Who the event came from (organiser/publisher) |

Venue term `meta` (read-only for you): `teatatu_events_addr_unit`, `_number`, `_street`, `_suburb`, `_neighbourhood`, `_city`, `_region`, `_postcode`, `_country`, `_lat`, `_lng`.

List terms with `?per_page=100&_fields=id,name,slug,count`, and page through using the `X-WP-TotalPages` response header.

---

## 4. Endpoints

| Method & path | Purpose |
|---|---|
| `GET /wp/v2/events` | List/search. Params: `search`, `status` (e.g. `draft,pending,publish`), `when` (`upcoming`·`past`·`all`), `event_from`, `event_to` (`YYYY-MM-DD`), `orderby=event_start`, `order`, `include_draft_copies=1` (draft copies are hidden otherwise), `event-venues`, `event-neighbourhoods`, `event-tags`, `event-categories`, `event-sources`, `per_page`, `page`, `context=edit` |
| `GET /wp/v2/events/{id}?context=edit` | Read one event, including drafts you own |
| `POST /wp/v2/events` | Create (status `draft` or `pending`) |
| `POST /wp/v2/events/{id}` | Update **your own unpublished** event or draft copy |
| `DELETE /wp/v2/events/{id}` | Trash your own unpublished event |
| `POST /teatatu-events/v1/events/{id}/duplicate` | Copy any event to a new independent draft (you own it) |
| `POST /teatatu-events/v1/events/{id}/draft` | Create or return the draft copy of a **published** event, for proposing changes |
| `DELETE /teatatu-events/v1/events/{id}/draft` | Discard the draft copy (only if you own it) |
| `GET /teatatu-events/v1/venues/match?location=&address=` | Match a place to an existing venue, and parse the address |
| `GET /wp/v2/event-venues`, `/event-tags`, `/event-categories`, `/event-sources` | List terms |
| `POST /wp/v2/event-categories`, `/event-sources` | Create a term (`{"name": "..."}`) |
| `GET /teatatu-events/v1/events?from=&to=&...` | Public published events (no auth). Handy for a quick calendar view. Items with `"linked": true` belong to another site on the network (`home_site`). They are shown here by reference: don't treat them as duplicates of your new event, and never try to edit them on this site |

`POST /teatatu-events/v1/events/{id}/merge` (applying a draft copy) exists, but **it's for humans**. You'll get a 403, so don't call it.

As a list user you only see **published events plus your own drafts**. Other people's drafts are invisible to you. Keep that in mind when checking for duplicates.

---

## 5. Suggested MCP tools

If the MCP wrapper exposes a generic "call REST endpoint" tool, you can use §4 directly. If you're building dedicated tools, this set covers every agent workflow. Each is a thin mapping onto one or two REST calls. Keep tool outputs small (use `_fields`).

| Tool | Input | Calls | Returns |
|---|---|---|---|
| `events_search` | `query?`, `from?`, `to?`, `when?`, `venue_id?`, `neighbourhood_ids?`, `tag_ids?`, `statuses?` (default `publish,draft,pending`), `page?` | `GET /wp/v2/events?context=edit&orderby=event_start&order=asc&_fields=id,status,title,event,link,event-venues,event-tags` | Compact list + `total_pages` |
| `event_get` | `id` | `GET /wp/v2/events/{id}?context=edit` | Full event |
| `event_create_draft` | Event fields per §3.1 + `submit_for_review?` (bool) | `POST /wp/v2/events` with `status` = `pending` if `submit_for_review`, else `draft` | `id`, `status`, `edit_url` |
| `event_update_draft` | `id` + changed fields | `POST /wp/v2/events/{id}` | Updated event |
| `event_duplicate` | `id`, then optional changed fields | `POST …/{id}/duplicate`, then `POST /wp/v2/events/{new_id}` with the changes | New draft |
| `event_propose_changes` | `id` of a published event + changed fields + `note` | `POST …/{id}/draft`, then `POST /wp/v2/events/{draft_id}` | Draft copy `id`, `edit_url` |
| `event_discard_draft_copy` | `id` of the published event | `DELETE …/{id}/draft` | ok |
| `venue_match` | `location?`, `address?` | `GET …/venues/match` | `venue` or `null`, parsed address |
| `terms_list` | `taxonomy` (`venues`·`neighbourhoods`·`tags`·`categories`·`sources`), `search?` | `GET /wp/v2/event-{taxonomy}?search=&per_page=100` | `[{id,name,slug}]` |
| `term_create` | `taxonomy` (`categories`·`sources` only), `name` | `POST /wp/v2/event-{taxonomy}` | `{id,name}` |

The wrapper should build `edit_url` as `https://<site>/wp-admin/post.php?post={id}&action=edit` for humans. It should refuse `status: "publish"` itself rather than sending it to the server.

---

## 6. Workflows

### 6.1 Create a new event

1. **Gather facts** from the source: title, date(s), times, place, price, tickets, organiser, description, image, source URL. Mark anything uncertain.
2. **Check for duplicates:** `events_search` with a few distinctive title words and `from`/`to` = event date ± 1 day, `statuses=publish,draft,pending`. If you find a likely match:
   - Your own draft: update it instead (`event_update_draft`).
   - Published: propose changes if something differs (§6.3); otherwise stop and report "already listed".
3. **Resolve the place:** `venue_match` with the location name and/or address.
   - Match found: put its ID in `event-venues`, leave `address` empty, and put any room/space in `room`.
   - No match: leave `event-venues` empty and put the full address in `address` (one line is fine). The event is automatically flagged for an admin to assign a venue. Don't create venues.
   - Online-only: no venue; put the join/info link in `read_more_url` and say "Online" in the excerpt.
4. **Resolve terms:** `terms_list` for tags, categories and sources. Pick existing ones that fit. Only use tags that exist. Create a Source or Category only if nothing reasonably matches.
5. **Create:** `event_create_draft`. Use `submit_for_review: true` only when every required fact is confirmed; otherwise leave it as a draft and list the gaps.
6. **Verify** the response: `status` is `draft`/`pending`, and `event.display_when` reads correctly (this catches time zone and all-day mistakes).
7. **Report** the ID, title, when, where, status and edit link, plus anything left unresolved.

### 6.2 Place rules summary

| Situation | `event-venues` | `address` | `room` |
|---|---|---|---|
| Known venue | `[id]` | empty | if given |
| Known venue, specific room | `[id]` | empty | e.g. "Studio 2" |
| Unknown place | empty | full address | if given |
| No physical place | empty | empty | empty |

The **neighbourhood** is worked out by the server from whichever of these you send. After creating the event, check `event.neighbourhood` in the response. If it's `null` for a Te Atatū Peninsula address, make sure the street name and number are spelled correctly in `address` (e.g. "12 Taikata Road, Te Atatū Peninsula"). If they are correct, mention in your report that the event needs a neighbourhood.

### 6.3 Change a published event (reschedule, cancel, fix details)

1. `event_propose_changes` with the event ID and **only** the fields that change.
   - Cancelled: `event.status = "cancelled"` (keep the dates).
   - Postponed with no new date: `"postponed"`. With a new date: update `start`/`end` and set `"rescheduled"`.
2. Put the reason and evidence (source URL) in your report. The human reviews the diff and merges it.
3. If `has_draft` was already `true` and the draft copy isn't yours, you'll get `403`. Report that someone else already has changes pending.

### 6.4 Copy an event (e.g. this year's version of last year's event)

1. `event_duplicate` with the old event's ID.
2. Update the copy: **always** new `start`/`end`, and check price, tickets, image and description. The copy starts with "(copy)" in the title, so set the real title.
3. Leave it as a draft for review.

### 6.5 Recurring events

You can't create recurring series. Humans do that in wp-admin, and each series keeps up to 12 upcoming dates. If asked for a recurring event:
- Ask the human to set it up as a series, giving them the full details and the pattern (e.g. "every Tuesday 6–8pm from 6 Oct").
- Only create individual draft events for each date if the human explicitly asks you to, and for no more than 12 dates.
- Don't edit single occurrences of a series except through §6.3. Series occurrences have `event.series_id` set.

### 6.6 Clean up your own drafts

Search `statuses=draft` for your items. Update or trash (`DELETE`) your own drafts that are duplicates or obsolete. Never trash anything you didn't create.

---

## 7. Writing guidance

- **Title:** the event's own name, in plain text without dates or venue ("Te Atatū Garage Sale", not "Garage Sale – Sat 3 Oct @ Community Centre").
- **Excerpt:** 1–2 plain-text sentences (≤ 300 characters) saying what it is and who it's for. Don't repeat the date and place; the cards show them.
- **Content:** fuller description in simple HTML (`<p>`, `<ul>`, `<strong>`, `<a>`), written from the source and not copied wholesale. Needed when `link_mode` is `local`, or when there's no `read_more_url`.
- **Price:** as the organiser states it. `is_free: true` only if it's explicitly free.
- **Tags:** 1–3 that clearly apply.
- Keep macrons and te reo Māori spelling exactly as the source uses them.

---

## 8. Errors

| HTTP | Code | Meaning | What to do |
|---|---|---|---|
| 401 | `rest_not_logged_in`, `invalid_username`, `incorrect_password` | Bad or expired Application Password | Stop. Ask the human to check or regenerate it. Don't retry |
| 403 | `rest_cannot_publish` | You sent `status: publish` | Resend as `draft`/`pending` |
| 403 | `rest_cannot_edit` | Published, or not your event | Use §6.3 (draft copy) instead |
| 403 | `rest_cannot_create` / `rest_cannot_assign_term` | Tried to create a venue or tag, or wrong site | Use existing terms or `address`. Check the base URL |
| 403 | `teatatu_events_draft_locked` | Someone else owns the draft copy | Report it; don't retry |
| 404 | `rest_post_invalid_id` | Wrong ID, or another user's draft | Re-search |
| 404 | `rest_no_route` | Feature not on this site yet | Report it; don't improvise |
| 400 | `rest_invalid_param` | Bad field value (URL, enum, term ID) | Fix it and retry **once** |
| 400 | `teatatu_events_missing_start` | Created an event without `event.start` | Add the start date and retry once |
| 400 | `teatatu_events_invalid_date` | `start`/`end` couldn't be read | Use `2026-10-03T19:00:00` (site time) or `2026-10-03` for all-day; retry once |
| 400 | `teatatu_events_invalid_range` | `end` is before `start`, or all-day with times | Fix the dates and retry once |
| 400 | `teatatu_events_not_published` | Asked for a draft copy of an unpublished event | Update it directly if it's yours; otherwise report it |
| 409 | `teatatu_events_duplicate_suspected` | Server found a near-identical event | Inspect `data.existing_id`, then update that event or report it |
| 5xx | — | Server problem | Retry once after a short pause, then report |

Never loop on failures. After two failed attempts at the same call, stop and report.

---

## 9. Example: create a draft

```http
POST /wp-json/wp/v2/events
Authorization: Basic <base64 username:app_password>
Content-Type: application/json

{
  "status": "draft",
  "title": "Te Atatū Garage Sale",
  "excerpt": "Community garage sale raising funds for the kindergarten. Bargains, baking and a sausage sizzle.",
  "event-venues": [31],
  "event-tags": [4, 9],
  "event-sources": [12],
  "event": {
    "start": "2026-10-03T09:00:00",
    "end": "2026-10-03T13:00:00",
    "room": "Main Hall",
    "price": "Free entry",
    "is_free": true,
    "read_more_url": "https://organiser.example/events/garage-sale",
    "image_url": "https://organiser.example/img/garage-sale.jpg"
  }
}
```

## 10. Example: propose a cancellation

```http
POST /wp-json/teatatu-events/v1/events/123/draft
→ 201 { "id": 456, "event": { "draft_of": 123, ... } }

POST /wp-json/wp/v2/events/456
{ "event": { "status": "cancelled" } }
→ 200
```

Report: "Proposed cancelling *Te Atatū Garage Sale* (#123), per the organiser's post <url>. Draft copy #456 is awaiting review: <edit link>."
