# Meetings

The Meetings module (`src/Meetings`, id `meetings`, scope `meetings`) keeps the firm's meetings and their minutes: recurring meetings grouped in series, such as a weekly production meeting or a board meeting, and one-off meetings. A meeting has a day, an optional time, its attendees, the records it is about, and its minutes in Markdown.

The module is optional. It requires Work, Party, Identity, Audit, Instance, Shared and Ui; Calendar and Sales are optional and not used yet. It is for the owners and members of the organisation only; a guest sees none of it, on the screens, the API or MCP.

Everything is available through the [REST API](api.md#meetings) and the [MCP server](mcp.md#tools).

## Series

A series groups the meetings that recur, with their people and agenda.

- A **name**, up to 120 characters, unique in the firm whatever its case.
- **Who sees it**: `members`, its members and the attendees of each meeting, or `organisation`, everyone in the firm. A new series is for its members, the person creating it among them.
- Its **members**, people of the firm, and among them the **default attendees**, who start as attendees of each new meeting of the series.
- An **agenda template** in Markdown, up to 20,000 characters, copied into the minutes of each new meeting. A later change of the template does not touch the meetings that exist.
- A **default project**, the project offered first for a task from the minutes.
- **Archived**: an archived series keeps its meetings and takes no new ones.

The owners and the members of a series change it and archive it.

## Meetings

- A **series**, or none for a one-off meeting.
- A **title**, up to 200 characters. Left empty, a meeting of a series is named after the series and the day, such as "Production weekly – 2026-11-30"; a one-off meeting needs one.
- The **day** it is held on and an optional **time**, HH:MM in the instance's time zone.
- Its **attendees**, people of the firm. A new meeting of a series starts with the default attendees of the series. Someone who leaves the firm stays listed on the meetings they attended.
- **Linked records**: the projects and contacts it is about. A link never widens who sees the meeting, and a link to a record that goes away shows nothing.
- Its **minutes** in Markdown, up to 200,000 characters. A new meeting of a series starts with the agenda template of the series, unless minutes are given.
- **Custom fields**, added by modules through `MeetingExtension` (see [Writing a module](modules.md#add-fields-to-a-record)).

### Minutes that two people write

The minutes carry a version. Saving them sends the version they were read at; if someone else saved them since, the save is refused (`stale_minutes`, with the current version), and nothing is overwritten. The page says so: reload to see their version. Saving the same text again changes nothing and keeps the version.

### Who sees a meeting

In this order:

1. The owners see every series and every meeting.
2. Everyone in the firm sees an `organisation` series and its meetings.
3. The members of a `members` series see it and its meetings; an attendee of one of its meetings sees that meeting, not the series or its other meetings.
4. A one-off meeting is seen by its attendees and by the person who created it.

Whoever does not see a meeting or a series gets "not found", on the screens, the API and MCP, and the lists leave it out.

### The trash

The person who created a meeting and the owners move it to the trash and restore it. A meeting in the trash leaves the lists and is removed for good after 30 days, with its attendees, links and custom fields, by a job that runs every day at 03:00; the audit log keeps what it was.

## Screens

**Projects → Meetings** (`/meetings`) lists the meetings on saved views, as the other lists: All, Upcoming, Past and My meetings (those you attend), the person's own views, the search in the title and the minutes, the quick filters by series, by My meetings and by when, and the Settings sheet of the columns. The navigation shows how many meetings are today.

- **New meeting** in the page header opens a dialog with the series, the title, the day, the time, the attendees and the linked project and contact. Picking a series ticks its default attendees.
- The meeting page shows the attendees and the linked records under the header, and the minutes read as Markdown. **Edit the minutes** opens them in the Markdown editor in place, with a preview. **Edit** changes the rest; **⋯ → Move to trash** is in the header menu, and the page shows the history of the meeting.
- **Series** (`/meetings/series`) lists the active and the archived series, with who sees each, its members, how many meetings it has and the day of the last one. **New series** in the page header opens a dialog; **Edit** and **Archive** are in the menu of a row.
- **Trash** (`/meetings/trash`) lists the meetings in the trash, each with **Restore**.

## Events

Changes record these events, version 1, with the id of the series or the meeting and the id of its series:

| Event | When |
| --- | --- |
| `meetings.series.created`, `updated`, `archived` | A series is created, changed or archived. |
| `meetings.meeting.created`, `updated` | A meeting is created, or its title, day, time, attendees, links or custom fields change (`customFields` lists the keys of the custom fields that changed). |
| `meetings.meeting.minutes_changed` | Its minutes are saved with a change, with the new version. |
| `meetings.meeting.trashed`, `restored` | It is moved to the trash or restored. |

## For other modules

- `Foresi\Meetings\Contract\MeetingTarget` (tag `foresi.meetings.target`): the records of a kind a meeting can be about, the same shape as `ActivityTarget`. Meetings answers for `project` and `contact` itself; another module answers for its own records in its `Integration/Meetings`.
- `Foresi\Meetings\Contract\Field\MeetingExtension` (record `meeting`): custom fields of a meeting.
