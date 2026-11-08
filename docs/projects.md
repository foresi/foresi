# Projects and tasks

Work of the organisation, kept in projects. This page says what the screens do and what is still to come; the decisions behind the model are in [ADR 0026](adr/0026-projects-and-tasks.md). Only the owner and the team members of an organisation see its projects. A guest sees none of them yet (see [people](people.md)).

## Concepts

- **Project.** A body of work, for a client or internal. It belongs to the organisation, not to one of its firms, so every firm's people see the same projects. It has a name, an optional **client** (any contact of the address book), a plain-text description and an archive date. An archived project leaves the active list, can be restored at any time and accepts no new tasks.
- **Section.** A heading that groups the tasks of a project in the list, such as "Backlog" or "Shared with client". Tasks without a section come first. Sections have an order of their own.
- **Status and workflow.** The statuses of a project are its workflow: the steps a task goes through, in order. A new project starts from a template, **Simple** (To do, In progress, Done) or **Development** (Open, In progress, Review, Testing, Blocked, Done, Closed). The names are translated once into your language and are plain text afterwards: rename, add, reorder or remove them in the project settings. What cannot change is the **type** of each status: open, active, done or closed. Reports and the completion date rely on the type, never on the name. A project always keeps at least one open and one done status.
- **Task.** A piece of work with a title, a description, a status, a section, a **responsible** person, a due date, a priority (none, low, medium, high, urgent) and labels. A task gets its completion date when it enters a status of type done or closed, and loses it when it leaves.
- **Subtask.** A task one level under another task of the same project. A subtask cannot have subtasks of its own.
- **Responsible.** The one person accountable for a task, picked from the active people of the organisation.
- **Watcher.** A person who follows a task. The person who creates a task and the person responsible for it follow it from the start; anyone can watch or stop watching, and add other people on the task page. Notifications to watchers come later.
- **Label.** A coloured tag, shared by all projects of the organisation, with a unique name. Colours are the badge tones of the interface: gray, green, amber and red.

## Projects

**Work, Projects** lists the active projects with their client, the number of unfinished tasks and the nearest due date; the **Archived** tab lists the rest. **New project** opens a dialog for the name, the client, the workflow template and the description. The template is chosen once: it only seeds the statuses, which you can change later.

## The list

A project opens as a list: one block per section, each task a row with its status, responsible, due date, priority and labels, and its subtasks under it. The filters above narrow the list by responsible person, status (unfinished by default, finished, or all) and due date (overdue, today, the next 7 days, none).

Each section ends with a line to add a task: type the title and press Enter. The task is saved, the line stays focused and empty, and you can type the next one at once.

## The board

The **Board** tab shows one column per status, in workflow order, and the top-level tasks as cards: title, responsible, due date, priority, labels and how many subtasks are done. The filters are the same as in the list, apart from the status. The columns scroll sideways inside the board, never the page.

- **Drag and drop.** Take a card by the handle at its left and drop it in another column or at another place in the same one. It works with a mouse, a finger and a pen. The new place is saved at once and announced to screen readers.
- **The move menu.** Every card has a menu with **Move to** (one item per status), **Move up** and **Move down**. It does everything dragging does, with a keyboard or a screen reader, and works without JavaScript.

The order of a card is the order of the task in its status; the list shows the same tasks in the same relative order.

## The task page

Opening a task shows its title and description, its subtasks with a line to add one, and its **activity**: every change with who made it and when (status, responsible, due date, priority, labels, watchers and edited fields). The panel beside it (under it on a phone) holds the properties. Each property saves as soon as you choose a value; a **Save** button appears for a browser without JavaScript. The title and the description open in place with **Edit**.

## My tasks

**Work, My tasks** lists the unfinished tasks you are responsible for, grouped by when they are due: overdue, today, the next 7 days, later and without a date. A group with nothing in it is left out. The **Watching** tab lists the tasks you follow instead. The client filter narrows both tabs. The count of tasks due by today is shown beside the item in the menu.

## Project settings

Open **Settings** on a project to:

- change its name, client and description, and archive or restore it;
- add, rename, reorder and remove **sections**. A removed section does not delete its tasks, they move to "No section";
- add, rename, retype, reorder and remove **statuses**. Removing a status asks where its tasks go.

## Labels

**Settings, Labels** keeps the labels of the organisation: name and colour. The list shows how many tasks carry each label; deleting a label takes it off its tasks.

## Operating it

There is nothing to configure. Projects, tasks and labels are rows of the application database, tenant-isolated like the rest, and every change, watchers and labels included, reaches the audit log. Include the database in your backups as usual. Run `bin/console doctrine:migrations:migrate` on upgrade.

## What comes later

Not in this release:

- **Sharing with guests.** Sharing a project, a section or a task with a client or a subcontractor, who then sees only that.
- **Comments** on a task.
- **Attachments** on a task.
- **Notifications** to the responsible person and the watchers.
- **Time tracking** against projects and tasks, and billing it on an invoice.
