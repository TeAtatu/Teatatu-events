=== Teatatu Events Theme ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0

Display theme for a Te Atatū Peninsula events site. Requires the Teatatu Events plugin (version with the front-end API, includes/front-end.php).

== Description ==

This theme only displays. Every event value comes from the Teatatu Events plugin:

* Events are read with teatatu_events_item_from_post() and teatatu_events_search() — when, place and room, neighbourhood, tags and category, price, tickets, status, picture, linked events from other sites.
* The events list is a plain GET form using the plugin's filter names (when, from, to, q, neighbourhood, category, tag, venue, free, view, month). The plugin applies them to the main query on /events/ and on every event taxonomy page (neighbourhood, category, tag, venue, source).
* "Add to calendar" and "Subscribe" buttons use the plugin's teatatu_events_calendar_urls(), teatatu_events_feed_url() and teatatu_events_subscribe_urls().
* The plugin's own details box on event pages is turned off (filter teatatu_events_content_details); this theme lays the details out itself.

= Templates =

* front-page.php — "What's on" hero with search (when, keyword, neighbourhood, category) and "Happening next", the next 7 days and this weekend as event cards, neighbourhoods with upcoming counts, kinds of event, calendar subscription, and the "Add your event" call to action.
* archive-teatatu_event.php — the events list: filters, removable filter chips, events grouped by day (or a month calendar with ?view=calendar), a venue map, and "Add to your calendar" for the current filters. Also used for every event taxonomy archive.
* single-teatatu_event.php — date block, title, when and where, status notices (cancelled, postponed, finished…), picture, description, tags, other dates in the series, where (venue, room, neighbourhood, directions, map), the details panel (tickets, add to calendar, organiser link) and more events at the same venue or neighbourhood.
* page.php, single.php, index.php, 404.php — plain content.

= Customizer (Appearance > Customize > Te Atatū Events) =

Home page photo URL and alt text (without a photo, the home page shows "Happening next"), "Add an event" link, footer line.

= Menus =

Header and Footer menu locations. With no header menu assigned, the section links are All upcoming / Today / This weekend / Next 7 days / Free / Calendar.

= Maps =

Leaflet 1.9.4 (unpkg) with OpenStreetMap tiles, loaded only on pages that have at least one event at a venue with coordinates. Events at the same venue share a pin.

= Design system =

Styled with the Te Atatū Local design system, the shared look of the five Te Atatū sites:

* assets/css/te-atatu-local-tokens.css — colour (light and dark), type, spacing, radius and shadow tokens, generated from the design system's tokens.json. Do not edit by hand.
* assets/css/te-atatu-local.css — the design system's tal-* components, copied unchanged from its bundle.css. Events use its EventCard (.tal-event), date block (.tal-date) and compact event row (.tal-event-row).
* <html data-site="events"> makes every component use the Events accent, Pōhutukawa.
* The header is the shared NetworkNav: the black strip linking Te Atatū and Events, plus News, Homes and Market when their plugins (teatatu-news.php, teatatu-homes.php, teatatu-market.php) are active anywhere on the network — linking to the Master Site set in that plugin's network settings (else the site they run on; filter `ttev_network_sites` to change addresses), the "Te Atatū Events" wordmark, search, "Add an event", and the section links.
* style.css lays out the pages using only design-system tokens. When the design system changes, re-copy both files rather than editing them here.
* Font: Plus Jakarta Sans (400, 600, 700, 800) from Google Fonts.

= Updates =

The theme updates from the same GitHub release as the Teatatu Events plugin (repository and token are set in the plugin's settings; default TeAtatu/Teatatu-events). The plugin runs the theme's update checks (teatatu-events/includes/theme-updater.php), because it is loaded on every site of a network while theme code only loads where the theme is active; inc/updater.php is the theme's own copy, used only when the plugin isn't active.

* Auto-updates: switched on once, when the plugin first finds the theme installed. Turn them off or on again with the theme's "Disable auto-updates" / "Enable auto-updates" link (Network Admin > Themes on multisite, Appearance > Themes on a single site); that choice is always respected. Settings > Teatatu Events shows the current state.

* It compares its own Version in teatatu-events-theme/style.css at the latest release, so a plugin-only release doesn't offer a theme update.
* The package is the release's attached teatatu-events-theme-<version>.zip (built by teatatu-events/tools/build-release.sh), else GitHub's source zip, from which only the teatatu-events-theme/ folder is installed.
* With auto-updates off, the "update available" notice still shows; click Update now.

== Changelog ==

= 1.0.0 =
* First release: home page, events list with filters and month calendar, event page, Te Atatū Local design system (Pōhutukawa), GitHub updater shared with the plugin's releases.
