# Calendar

The Calendar module (`src/Calendar`, id `calendar`, scope `calendar`) gives each person secret iCal addresses, **calendar feeds**, that they subscribe to in their own calendar app: Apple Calendar, Google Calendar, Outlook or any app that reads iCalendar (RFC 5545). Any module can put what it plans for a person into their feeds. A feed with nothing in it is a valid, empty calendar.

The module is optional. It requires Identity, Instance, Ui and the core modules, and knows no module that feeds it: they plug in through its contract (ADR 0033).

## Feeds

A person manages their own feeds in their account, under **Calendar feeds** in the account menu (`/account/calendar`), or over the [API](api.md#calendar) and [MCP](mcp.md). Only the owners and members of the organisation have feeds; a guest has none.

- **New feed address** asks for a name, such as "Phone", which is also the calendar's name in the app. The answer shows the address once, with a button to copy it and a `webcal:` link that opens the calendar app to subscribe. Foresi keeps only the SHA-256 of the address's token, so the address cannot be shown again. Give each app or device its own feed.
- **Rename** changes the name; the address stays.
- **Revoke** stops the address at once, for good. The calendar app keeps what it last fetched until the calendar is removed there. A revoked feed stays listed.

The list shows when each feed was created and when a calendar app last fetched it, recorded at most once an hour. Someone who leaves the staff, or becomes a guest, loses their feeds: their addresses answer 404.

## The address

`GET /calendar/feed/{token}.ics` answers `text/calendar; charset=utf-8`. The token is 32 random bytes (256 bits) in base64url. The address needs no sign-in and sets no cookie, like the links to sent documents.

- An unknown, malformed or revoked token gets `404`, so the answer says nothing about what exists.
- A token may be fetched 30 times an hour (a sliding window, the rate limiter `calendar_feed`); more is answered `429` with `Retry-After`. Calendar apps fetch every 3 to 24 hours on their own schedule, so a change in Foresi can take that long to show; the feed asks for every 3 hours (`REFRESH-INTERVAL` and `X-PUBLISHED-TTL`).
- The feed holds the entries from 30 days ago to 365 days ahead, in the instance's days.

Each entry is a `VEVENT` with only `UID`, `DTSTAMP` (when the thing last changed), `DTSTART`, `DTEND`, `SUMMARY` and `URL`: no description, place or attendees. Times are in UTC, so an entry keeps its wall-clock time across a change of summer time. A date-only entry is all day (`VALUE=DATE`), ending the day after its last day. Text is escaped and lines are folded at 75 octets as RFC 5545 asks.

## Feeding the calendar

A module implements `Foresi\Calendar\Contract\CalendarEntrySource` in its `Integration/Calendar/` and is tagged `foresi.calendar.entry_source` by autoconfiguration:

```php
public function key(): string; // "activities.planned"
/** @return iterable<CalendarEntry> */
public function entriesFor(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
```

`entriesFor()` returns the person's entries that start before `$to` and end on or after `$from`. A `CalendarEntry` has a `uid`, a `title`, `start`, `end` (null for a timed entry without a duration, or one day), `allDay`, `lastModified` and an absolute `url`. For an all-day entry `start` and `end` are dates and `end` is the last day, included. Build the uid with `Foresi\Calendar\Contract\CalendarUids::of($key, $id)`, which gives `<key>-<id>@<host of DEFAULT_URI>`, the same on every fetch. A source that throws is logged and left out; the feed answers with the others.

Feeds are personal settings, not business records: they emit no events.
