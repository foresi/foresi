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
* Room for sharing: `Project.visibility`, a private flag on `Section` and `Task`, and a `work_share` table; every read will pass through one `WorkVisibility` port in the application layer.
* Room for comments, attachments and notifications, which will point at a task by its id.
* Room for time: a `TimeEntry` of a project, optionally a task, a membership, minutes, a date, billable or not and the invoice line that billed it, with rates beside it.
