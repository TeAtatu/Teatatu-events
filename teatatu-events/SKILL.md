# Teatatu Events — Agent Skill

## Purpose

Lets an AI agent draft **events** on a Teatatu Events site (and propose changes to published ones) for a human to review and publish, entirely through the WordPress REST API. This is the short version; the full reference — data shapes, every endpoint, suggested MCP tools, workflows and error handling — is [`MCP-GUIDE.md`](MCP-GUIDE.md).

**You never publish.** Your role (`teatatu_events_agent`) can't publish, can't edit published or other users' events, and can't create Venues or Event Tags. WordPress enforces this; it isn't a temporary restriction.

## Authentication

HTTP Basic Auth with a WordPress **Application Password** for a user with the **Events Agent** role (Users > Profile > Application Passwords): `Authorization: Basic base64(username:application_password)`.

On multisite, your user exists on one site only — always use that site's own `https://<site>/wp-json/` base URL.

## Workflow

1. **Check for duplicates** — `GET /wp-json/wp/v2/events?search=<words>&event_from=<date-1>&event_to=<date+1>&status=publish,draft,pending&context=edit`. Your own draft → update it. A published match → propose changes (step 6) or stop.
2. **Resolve the place** — `GET /wp-json/teatatu-events/v1/venues/match?location=…&address=…`. A match → send its ID in `event-venues`. No match → send the full address in `event.address` (an admin will be asked to assign a venue). Never create venues.
3. **Resolve terms** — list `/wp/v2/event-tags`, `/event-categories`, `/event-sources`. Use existing tags only; create a Source or Category only if nothing fits.
4. **Create the draft** — `POST /wp-json/wp/v2/events` with `"status": "draft"` (or `"pending"` when every fact is confirmed):

   ```json
   {
     "status": "draft",
     "title": "Te Atatū Garage Sale",
     "excerpt": "Community garage sale raising funds for the kindergarten.",
     "event-venues": [31],
     "event-tags": [4],
     "event": {
       "start": "2026-10-03T09:00:00",
       "end": "2026-10-03T13:00:00",
       "room": "Main Hall",
       "price": "Free entry",
       "is_free": true,
       "read_more_url": "https://organiser.example/garage-sale"
     }
   }
   ```

   Times are local site time without an offset. All-day: `"all_day": true` with dates only; `end` is the last day, inclusive. Leave `end` out rather than guessing it.
5. **Verify** — the response's `event.display_when` reads correctly and `event.neighbourhood` is set (for Te Atatū Peninsula addresses).
6. **Changing a published event** (reschedule, cancel, fix) — `POST /wp-json/teatatu-events/v1/events/{id}/draft` to get a draft copy, then `POST /wp-json/wp/v2/events/{draft_id}` with only the changed fields (e.g. `{"event": {"status": "cancelled"}}`). A human merges it.
7. **Report** the ID, title, when, where, status and edit link (`https://<site>/wp-admin/admin.php?page=teatatu-events&tab=events&edit=<id>`), and anything left unresolved.

## Constraints reminder

- `"status": "publish"` → 403. You can only edit your own unpublished events and your own draft copies. You can't merge draft copies or create series.
- Venues, Event Tags and Neighbourhoods are curated — pick, don't create. Neighbourhood is worked out automatically; never send it.
- Images are link-only: send a public image URL; never upload media.
- A near-identical event (same title, date and place) returns `409 teatatu_events_duplicate_suspected` with `data.existing_id`.
- Recurring events are created by humans as a series (up to 12 upcoming dates). Pass them the pattern instead.

## Errors (short)

| Status | Do this |
|---|---|
| 401 | Stop — ask the human to check the Application Password |
| 403 | Don't retry as-is: publish → resend as draft; published/not yours → use a draft copy; venue/tag creation → use existing or `address` |
| 400 | Fix the field named in the message (dates, URLs, status) and retry once |
| 409 | Inspect `data.existing_id`; update that event or report it |

Full table: [`MCP-GUIDE.md` §8](MCP-GUIDE.md).
