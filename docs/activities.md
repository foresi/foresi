# Activities

The Activities module (`src/Activities`, id `activities`, scope `activities`) keeps what happens with a client and what comes next: a meeting logged with its notes and its recording, a call planned for Thursday, a task to send an offer. Each activity is about one or more records, such as a contact and later a deal, and shows on the timeline of each of them. A planned one also shows in its owner's calendar app.

The module is optional. It requires Attachment, Audit, Identity, Instance, Ui and the shared core; Calendar is optional. Other modules plug into it through `Foresi\Activities\Contract`: they offer the **records** an activity can be about (`ActivityTarget`, such as a contact) and show a record's timeline as a page fragment (`RecordActivities`). Activities never names the modules that use it.

## Staff only

Activities are for the owners and members of the organisation. A guest never sees the screens, the API or the MCP tools, whatever scopes their connection asks for ([ADR 0041](adr/0041-guests-over-mcp.md)).

## Types

A **type** is a kind of activity with a **timing**:

| Timing | Planned | Example |
| --- | --- | --- |
| `timed` | at a time, with a duration (the type's default when left out) | Meeting (60 min), Call (15 min) |
| `date` | on a day | Task |
| `none` | never: it is done when it is written | Note, E-mail |

The migrations seed Meeting, Call, Note, Task and E-mail (keys `meeting`, `call`, `note`, `task`, `email`); an existing instance gets E-mail when it migrates. A seeded type has no name of its own: it shows under its key's name in the person's language until someone renames it. A type's key never changes, so events and integrations can name it. Types are kept under **Settings → Activities**, and archived, never deleted; an archived type is no longer offered, and the activities of that type keep it. The seeded types follow the same rule as the others: any of them, E-mail included, can be renamed and archived.

An **E-mail** activity is an e-mail kept as the source of a record: its subject, its text as the body and the time it was received, linked to the client and the records it led to, such as a deal or a project. Its body opens with the sender line, `From: Client A <client.a@example.com>`, which the timeline shows under the subject with an envelope icon. Like all e-mail text it is shown as plain text, never as HTML. With the Inbox module installed, an inbox item has **Log activity**, which makes an activity linked to the same records as the item's e-mail activity (see [inbox](inbox.md#actions)).

## Activities

An activity has a type, a subject, notes in Markdown, an owner (an owner or member; the person who created it when left out), and either a plan, a done time, or both:

- **Planned:** a day (`planned_on`) for a dated type, or a start (`planned_at`) and a duration for a timed type, never both. A time without an offset is a time of the instance's zone; times are kept and returned in UTC.
- **Logged:** `done: true`, with `done_at` (now when left out) and an `outcome`. A note is always done.

**Done and plan next** (`activities.complete`) marks a planned activity done with its outcome and, when `next_type_id` and `next_subject` are given, plans the next one on the same records for the same owner. Both happen in one transaction: when the next one is refused, the activity is not marked done either, and the refusal names the `next_*` field. An activity that is done already is refused with `already_done`.

**Files**, such as the recording of a meeting, are attachments of kind `activity`. Whoever sees the activity sees, adds and deletes its files; an activity in the trash shows none.

**Trash.** An activity goes to the trash and comes back as it was. In the trash it leaves every timeline and calendar feed, and a change is refused with `trashed`. Trashing or restoring it again changes nothing.

## Who sees an activity

A person sees an activity when they see at least one of its records, through the module that owns that record. When none of its records is visible to them, because it has none or because they are all gone (an archived client, a trashed deal), only its **owner** sees it, on the timelines, in the reads and in the calendar feed alike. The links stay, so a restored record shows its activities again.

## Timeline

A record's timeline lists its activities that are not in the trash: the planned ones on top, the soonest first, then the done ones, the latest first. On screen it is the fragment `RecordActivities::ROUTE` (`/activities/for/{kind}/{id}`), which a module shows inside its own screen, such as the **Activities** tab of a contact. It has **Log activity** and **Plan activity**, whose dialogs show the time fields of the chosen type and take a file, and each planned item has **Done and plan next**. An activity opens in the side sheet with its notes, records and files, and **Edit** and **Delete** (with Undo) in its header.

There is no list of activities in the navigation: "my planned" is in the calendar feed and the API.

## Calendar

When the Calendar module is installed, the source `activities.planned` puts each person's own planned activities, not done and not in the trash, into their calendar feeds. The title is "type: subject", such as `Meeting: Kick-off`; the notes and the records stay in Foresi. A timed activity runs from its start for its duration, a dated one is an all-day entry, and the entry links to the activity in Foresi. A done activity drops out at the next refresh.

## Events

`activities.activity.created`, `.updated`, `.done`, `.trashed` and `.restored`. The payload holds `activity_id`, `type_key`, `links` (each as `kind:id`) and `owner_user_id`; never the subject, the notes or the outcome ([ADR 0030](adr/0030-domain-events.md)).

## API and MCP

The REST endpoints are in [api.md](api.md#activities) and the tools in [mcp.md](mcp.md). Every write is an operation with an idempotency key ([ADR 0032](adr/0032-api-writes.md)); a `complete` retried with the same key acts once.

## For integrators

A module that records an activity itself, such as Inbox logging a mail on the records it is about, uses `Foresi\Activities\Contract\ActivityRecorder`: `record(RecordedActivity)` creates an activity as done at the given time, of the type with the given key (such as `email`), owned by the given owner or member, with the given links, and `link($activityId, $kind, $id)` adds one more record to an activity's links and keeps the others (linking a record twice changes nothing). Both run the rules, audit and events of the screens and the API and act as the person signed in, so a guest or nobody signed in is refused; the refusals are Activities' own (`type_unknown`, `link_kind_unknown`, `link_target_unknown`, `not_found`, `trashed`).

Before it links a record, such a module asks `Foresi\Activities\Contract\LinkableRecords`, for the person signed in: `kinds()` (the kinds the installed modules offer), `canLink($kind, $id)`, `describe($kind, $id)` (the record's name and screen, or null when it is gone or not theirs to see) and `seesActivity($activityId)` (through one of its records, or as its owner; never from the trash). `isLinked($activityId)` asks no one: whether the activity, in the trash too, still has a record, as Inbox asks before it lets Mail remove a mail.

A module that wants activities on its records implements `Foresi\Activities\Contract\ActivityTarget` in `Integration/Activities/` (kinds, `canSee`, `canLink`, `label`, `url`) and links its screen to `RecordActivities::ROUTE` with `kind`, `id` and `return`, the address of its own screen that the dialogs go back to. To log or plan an activity on several records at once, it opens `/activities/new` with `mode` (`log` or `plan`), `link_kind` and `link_id` for its record, `links[]=kind:id`, repeated, for the others (a deal, its client and its contact persons) and `return`; a malformed or repeated link is left out. The address book does this for contacts (`src/Party/Integration/Activities`): an archived contact counts as gone. Sales does it for deals (kind `deal`), where a trashed deal counts as gone, and Work for projects (kind `project`, `src/Work/Integration/Activities`): whoever sees a project sees its activities, and an archived project keeps them but takes no new one.
