# 26. Projects and tasks

* Status: accepted
* Date: 2026-11-07

## Context and problem statement

The organisation tracks its work in projects: for a client or internal, run by people of any of its firms. In the task history we moved from, seven tasks in ten had a single assignee, and teams used different workflows per project, a short to-do list for one, review and testing steps for another. Reports still need to know which tasks are finished, whatever the team calls the status. The audit log rejects changes to to-many collections of audited entities, so the model cannot keep watchers or labels as collections on a task.

## Decision drivers

* Projects are shared by the firms of an organisation; only invoicing needs a firm.
* One person is accountable for a task; others can follow it.
* Status names are the team's own, their meaning is fixed.
* Every change, including watchers and labels, reaches the audit log.

## Considered options

* Ownership: a project belongs to the tenant (chosen); a project belongs to a firm
* Assignment: one responsible person plus watchers (chosen); several assignees
* Statuses: per project, each with a fixed type (chosen); one global workflow
* Order: one position per task for the list and the board (chosen); a position per view

## Decision outcome

A new module, Work. `Project` belongs to the tenant, with no firm column; it has a name, an optional client (a contact of any kind), a plain-text description, the `WorkflowTemplate` it was created from and an archive date. `ProjectStatus` rows are the project's workflow: an editable name, a `StatusType` (open, active, done, closed) and a position. A template seeds them with translation keys that the application translates once; afterwards they are ordinary text. `Section` groups tasks in the list.

`Task` is its own audit root. It has one responsible membership, an optional due date, a `Priority` on Linear's scale (none to urgent), a section and a status of its own project, and may sit one level under another task of the same project. Its completion date is set when it enters a status whose type is done or closed and cleared when it leaves them. One position orders a task in its section and in its status column: each view shows a subset of one total order, so the two never disagree.

Watchers (`TaskWatcher`) and labels (`TaskLabel`) are rows of their own, audited under the task, and read by query. `Label` belongs to the tenant, coloured with one of the badge tones of the interface, unique by name per tenant (case-insensitively in the application).

### Consequences

* Good, because the firms of an organisation see the same projects and a client project needs no firm until it is invoiced.
* Good, because reports and the completion date rely on status types, not on names a team may change.
* Bad, because moving a task among many may renumber the positions of its neighbours.
* Room for sharing: `Project.visibility`, a private flag on `Section` and `Task`, and a `work_share` table; every read will pass through one `WorkVisibility` port in the application layer. Implemented on 2026-11-09, see below.
* Room for comments, attachments and notifications, which will point at a task by its id.
* Room for time: a `TimeEntry` of a project, optionally a task, a membership, minutes, a date, billable or not and the invoice line that billed it, with rates beside it.

## Sharing (2026-11-09)

Sharing is implemented as planned above.

* **Data.** `Project.visibility` (`organisation`, the default, or `chosen`), `Section.private` and `Task.private`, and `WorkShare`: one membership and one project, section or task, unique per membership and item, audited and rooted to its task or project.
* **The rule.** Owners see everything. A project is open to a team member when it is open to the organisation or shared with them, to a guest only when it is shared with them. In an open project everything not private is seen; a shared section is seen, private or not, with its tasks that are not private; a shared task is seen wherever it is; a subtask follows its task unless it is private itself and not shared. A shared section makes its project seen with only that section; a task shared alone makes the name of its project known, nothing else of it.
* **One rule, two languages.** `WorkScope` holds the rule and the shared ids, in plain PHP for commands (`VisibleWork` loads through it: what is not seen is unknown, 404). `ScopedDql` turns the same scope into joins and conditions for every query of `DoctrineWorkQueries`; a test checks both give the same ids. `SharedWorkVisibility` reads the shares again on every question, so a share given or taken back counts at once.
* **Making an item private keeps a share for whoever did it** (Asana, ClickUp), so a team member does not lock themselves out.
* **A deleted private section leaves its tasks private**, shared with the people the section was shared with, rather than opening them to the project.
* **Guests have one level of rights**: on what they see they create tasks and subtasks, comment, attach files, change the status and the other properties, set existing labels, watch and may be responsible. They never share, change visibility, see internal comments or their files, keep projects, labels, workflows, the trash or settings, or delete a task. Routes a guest may use carry `#[OpenToGuest]` (ADR 0025); every other route stays closed to them, and a test walks all routes to keep it so. The guest's start page `/shared` lists their projects, then single tasks by the name of their project.
* Good, because one rule decides lists, counts, pages, mentions, the responsible picker and file access alike.
* Bad, because every query of the module carries the scope's joins, and the shares are read on every request.

## Notifications (2026-11-09)

* **Data.** `Notification`: one row per recipient membership and task while it is not done, holding the latest event (kind, actor, time), the number of events since it was read, whether a mention or an assignment is among them, `readAt`, `doneAt` and `emailedAt`. A done row stays as history; a later event opens a new one. It is per-person reading state, so it is not audited; the events themselves are in the task's audit. `NotificationPreference`: per membership, e-mail per kind (at once, in the digest, off; at once only for mentions and assignments), the digest hour and a switch for no e-mail.
* **Where it starts.** There is no domain event dispatcher, so the application services (`ManageComments`, `ManageTasks`, `ManageSharing`) call the `Notifier` in the same unit of work as the change. It never tells the actor, tells only active people who can see the task (the sharing rule above), and an internal comment only the team. Commenting and being mentioned make a person a watcher.
* **Reading.** The inbox queries apply the recipient's `WorkScope`, so a task unshared, made private or in the trash drops out of the inbox and comes back if it is seen again.
* **E-mail.** A mention or an assignment queues a delayed Messenger message (2 minutes) that sends only if the row is still unread and the person still wants it and can see the task (Linear, Basecamp). The rest goes into a daily digest sent by an hourly scheduled job at each person's hour in the tenant's time zone (Asana, Freelo). Mail goes through the system mailer with `List-Unsubscribe` and one-click `List-Unsubscribe-Post` (RFC 8058); the link is signed with `APP_SECRET` and asks for one confirmation when opened in a browser.
* Good, because the inbox is always consistent with what a person may see, and e-mail never tells about something already read.
* Bad, because a burst of events on one task by several people is folded into one row, and the earlier events are only in the task's history.

## Time (2026-11-09)

Time tracking is implemented on the room left for it above; rates and invoicing come in a later step.

* **Data.** `TimeEntry`: tenant, project, optional task, the membership of the person who worked, the day worked (`worked_on`), the minutes, an optional start and end, a note, billable or not, who created it and when. A timer is an entry with a start and no end, and no minutes until it stops. `Task.estimateMinutes` and `Project.billableByDefault` sit beside it. Entries are audited, rooted to their task or, without one, to their project.
* **One timer per person in the database.** A running entry holds its membership id in `running_for`, null otherwise, under a plain unique index. A partial index (`WHERE ended_at IS NULL`) would say the same, but Doctrine cannot read one back from SQLite and schema validation fails there. Starting a timer stops and flushes the running one first, in the same unit of work, so the index never sees two. The timer's day is today in the organisation's time zone (ADR 0017).
* **The billable default comes from the client.** A project for a client bills its time by default, an internal one does not (Kimai, Toggl); the migration set existing projects the same way. Each entry may differ; since people rarely change the switch, the default carries the weight.
* **Who logs where.** Owners and team members log time, guests never: they neither see time nor reach its routes (no `#[OpenToGuest]`), as with internal comments. Time goes on a task the person sees, or on a project without a task only when the whole project is open to them, so time without a task never shows to someone who sees only a section or a single task of it. The author or an owner edits and deletes an entry; a running timer is stopped or deleted, not edited.
* **A purged task's time moves to its project.** Purging a task from the trash keeps its time on the project instead of deleting worked hours, which a report or an invoice may still need.
* **Visibility in the database.** `ScopedDql::time` adds the sharing rule to every time query: time on a task follows the task as `ScopedDql` decides for tasks, time without a task needs its project open as a whole. The week and the report filter and sum in SQL, never in PHP after loading.
* **Invoicing reserved.** `invoice_line_id` is a nullable column, null for every entry for now, and the report's invoiced filter reads it. Invoicing time will set it when an invoice is issued and clear it when the invoice goes to the trash; an entry with it set already cannot be changed or deleted. No rate is stored on an entry until it is invoiced.
* **Hourly rates (2026-11-10).** A nullable rate, minor units and currency, on `Project`, in the contact's `InvoicingDefaults` (shared and in a firm's `PartyFirmSettings`) and on `Membership`. The new `Timesheet` module's `RateResolver` picks the project's rate, else the client's for the invoicing firm, else the person's, and says which. The client rate resolves as the other invoicing defaults: a firm with its own defaults has its own rate, none included. Timesheet reads Work, Party and Identity through their application ports; none of them uses Timesheet. Only owners see and set rates. Nothing stores a rate on an entry yet.
* Good, because the rule of one timer, the visibility and the totals all hold in the database, on SQLite and PostgreSQL alike.
* Bad, because a running timer is a row with null minutes that every sum has to leave out.

## Invoicing time (2026-11-10)

Owners turn billable time into the lines of an ordinary invoice; the invoice locks the time it bills, the trash frees it and a restore locks it again.

* **The Timesheet module.** Rates, the dialog and the billing of time live in `src/Timesheet`, not in Work or Invoicing. It builds on Work (projects and time entries), Party (clients and firms), Identity (people) and Invoicing, through their application ports; none of them uses Timesheet, as with Banking. Work changes an entry only through its own methods (`invoice`, `moveToLine`, `release`), and asks Timesheet for the invoice that billed an entry through a Work port, so the task and the week name the invoice.
* **Two ports declared by Invoicing.** `BilledWork` prefills the new invoice form from a reference and returns the `IssueOrigin` that bills the work in the transaction that issues the invoice. `BilledWorkRelease` is told, in the same transaction, when an invoice goes to the trash, when it is restored and when the lines of an issued invoice are edited, as `PaymentSourceRelease` is told of a cancelled payment. Invoicing knows nothing of time; Timesheet implements both.
* **The rate is taken into the entry at issue.** An entry holds no rate until it is invoiced; issuing stores on it the invoice, the line, the rate and the minutes billed after rounding, beside the minutes worked. A later change of a project, client or person rate leaves invoiced time as billed, and the rounding never changes the time logged.
* **Line keys through the form.** The prefilled form carries the reference and every line its source, a key Timesheet gave it. The source is an attribute of the row, so it moves with the row, and the form posts the sources of all rows as one hidden field in the order on screen: a hidden field on each row would make ten fields a line and take an invoice of 100 lines past PHP's 1000 of max_input_vars. The reader pairs the sources with the rows by order, and sources that do not go row for row are ignored: the rows are then read without them, as lines without time and, on edit, by position. Issuing bills the time of each source still on the form, whatever its text, price or quantity became; a line removed leaves its time unbilled, and a copy of a row bills nothing. The edit form carries the id of each stored line the same way.
* **Session-held selection.** What the dialog chose (firm, client, lines, entries with their rounded minutes and a fingerprint of each) waits in the person's session under a random reference, the latest five kept, rather than in a draft table. Issuing checks every entry against its fingerprint under a lock and refuses the whole invoice when one was edited, deleted or invoiced meanwhile, rather than billing time twice or other time than was shown.
* **Home currency only.** Ticked time must have a rate, all in one currency, the home currency of the firm's country, which is what invoices are issued in; otherwise the dialog refuses and says why. Converting rates is left for later.
* **All-or-nothing restore.** The trash releases every entry of the invoice and keeps its line, rate, minutes and fingerprint in `released_time`. Restoring bills each one again exactly so, or, once any was edited, deleted or invoiced elsewhere, refuses and leaves the invoice in the trash, as for an advance another invoice deducts meanwhile (Clockify, Teamwork).
* **Edited-out lines free their time.** An issued invoice keeps its line rows by position, so moving or removing a row in the form rewrites the content of the rows in place. `EditInvoice` reads which stored line each row came from and tells `BilledWorkRelease::revised` where each line went: time follows its row to the line it is on now, and the time of a row removed is released in the same transaction. Input without line ids, from callers other than the form, is read by position.
* Good, because invoicing stays one form with one set of rules, and the time an invoice bills is always the time it shows, through edits, the trash and a restore.
* Bad, because the selection is lost with the session, so a form left open past it has to be prepared again, and every entry billed is locked in the issuing transaction.
