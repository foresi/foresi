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
