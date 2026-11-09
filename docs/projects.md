# Projects and tasks

Work of the organisation, kept in projects. This page says what the screens do and what is still to come; the decisions behind the model are in [ADR 0026](adr/0026-projects-and-tasks.md). Owners see every project; team members see the projects open to the organisation and those shared with them; a guest sees only what is shared with them (see [Sharing](#sharing) and [people](people.md)).

## Concepts

- **Project.** A body of work, for a client or internal. It belongs to the organisation, not to one of its firms, so every firm's people see the same projects. It has a name, an optional **client** (any contact of the address book), a plain-text description and an archive date. An archived project leaves the active list, can be restored at any time and accepts no new tasks.
- **Section.** A heading that groups the tasks of a project in the list, such as "Backlog" or "Shared with client". Tasks without a section come first. Sections have an order of their own.
- **Status and workflow.** The statuses of a project are its workflow: the steps a task goes through, in order. A new project starts from a template, **Simple** (To do, In progress, Done) or **Development** (Open, In progress, Review, Testing, Blocked, Done, Closed). The names are translated once into your language and are plain text afterwards: rename, add, reorder or remove them in the project settings. What cannot change is the **type** of each status: open, active, done or closed. Reports and the completion date rely on the type, never on the name. A project always keeps at least one open and one done status.
- **Task.** A piece of work with a title, a description, a status, a section, a **responsible** person, a due date, a priority (none, low, medium, high, urgent) and labels. A task gets its completion date when it enters a status of type done or closed, and loses it when it leaves.
- **Subtask.** A task one level under another task of the same project. A subtask cannot have subtasks of its own.
- **Responsible.** The one person accountable for a task, picked from the active people of the organisation who can see the task, guests included.
- **Watcher.** A person who follows a task. The person who creates a task and the person responsible for it follow it from the start; anyone can watch or stop watching, and add other people on the task page. Notifications to watchers come later.
- **Label.** A coloured tag, shared by all projects of the organisation, with a unique name. Colours are the badge tones of the interface: gray, green, amber and red.

## Projects

**Work, Projects** lists the active projects with their client, the number of unfinished tasks and the nearest due date; the **Archived** tab lists the rest. **New project** opens a dialog for the name, the client, the workflow template and the description. The template is chosen once: it only seeds the statuses, which you can change later.

## The list

A project opens as a list: one block per section, each task a row with its status, responsible, due date, priority and labels, and its subtasks under it. The filters above narrow the list by responsible person, status (unfinished by default, finished, or all) and due date (overdue, today, the next 7 days, none).

**New task**, in the header of the list and of the board, opens a dialog for the title and the section (no section by default). The task starts in the first open status of the workflow, and you come back to the view you opened it from, with its filters, where a message confirms it. A project without tasks shows the same button in the middle of the page. An archived project has no **New task**. Without JavaScript the dialog is a page of its own.

## The board

The **Board** tab shows one column per status, in workflow order, and the top-level tasks as cards: title, responsible, due date, priority, labels and how many subtasks are done. The filters are the same as in the list, apart from the status. The columns scroll sideways inside the board, never the page.

- **Drag and drop.** Take a card by the handle at its left and drop it in another column or at another place in the same one. It works with a mouse, a finger and a pen. The new place is saved at once and announced to screen readers.
- **The move menu.** Every card has a menu with **Move to** (one item per status), **Move up** and **Move down**. It does everything dragging does, with a keyboard or a screen reader, and works without JavaScript.

The order of a card is the order of the task in its status; the list shows the same tasks in the same relative order.

## The task page

Opening a task shows its title and description, its subtasks, its **attachments**, and the **discussion and history** (below). The panel beside it (under it on a phone) holds the properties. Each property saves as soon as you choose a value; a **Save** button appears for a browser without JavaScript. The title and the description open in place with **Edit**. **New subtask**, next to **Edit** in the header, opens a dialog for the title; the subtask goes into the section of its task and the page comes back at the subtasks. A subtask has no **New subtask**, and neither does a task of an archived project.

## Discussion

Under the task, **Discussion and history** is one timeline, oldest first: every change with who made it and when (status, responsible, due date, priority, labels, watchers and edited fields) as a small line, and every comment as a full item. The tabs **All**, **Comments** and **History** narrow it. The comment box is under the timeline.

- **Comments are internal by default.** An internal comment carries an **Internal** label with a lock, in words, never by colour alone. Only people of the organisation see it. Switch **Internal comment** off to write a public comment, which guests who may see the task read too. The default is internal because a slip into a public comment cannot be taken back, while making one public on purpose is a single switch. A guest has no switch: their comments are always public, and they never see an internal comment or its files.
- **Sending.** The button **Add comment**, or Ctrl+Enter (Cmd+Enter on a Mac). The page lands on the new comment.
- **Mentions.** Type `@` and the first letters of a name: the people who can see the task appear in a list; Up and Down move, Enter or a click chooses. The mention is stored as `@[Name](membership:id)` and shown as a chip. It is kept only for an active person who can see the task, whatever the text claims.
- **Markdown.** Comments and the description use a safe subset: paragraphs and line breaks, **bold**, *italic*, ~~strikethrough~~, lists and task lists, links (`http`, `https`, `mailto`), `code`, code blocks, quotes, rules and headings (from the third level down, so a text never outranks the page). Everything else is removed: raw HTML is shown as text, scripts, styles and other tags and attributes are dropped, and so are links with other schemes and images from elsewhere (only the installation's own attachments show as images). The **Preview** tab shows the result before you send; the toolbar (bold, italic, list, link, code, quote) and Ctrl/Cmd+B, I and K help writing.
- **Editing and deleting.** Only the author edits a comment, from its menu; it then says **edited**. The author deletes their own comment, and an owner of the organisation may delete any comment to moderate. A deleted comment leaves the line "Comment was deleted" so the thread still makes sense; its text is gone from view.

## Attachments

**Attachments** lists the files of a task: an image as a thumbnail, any other file as a row with its kind, name, size, who uploaded it and when.

- **Adding.** Drop files on the area, choose them with **Add file**, or paste an image or drop files into the comment box or the description: the file is uploaded and linked in the text as Markdown (an image shows in place). Each upload shows its progress and, if refused, why. Without JavaScript the area is a plain form with one file.
- **Limit.** A file may be up to 50 MB. An empty file is refused. The type is read from the file itself, never taken from its name or from the browser.
- **Viewing.** Images and PDFs open in a dialog with a download link; videos play in the list. Any other file downloads. Files are served only to people who may see the task, never from a public address.
- **Deleting.** The person who uploaded a file may delete it, and so may an owner of the organisation, after a confirmation.

## Trash

**Delete task** is in the **⋯** menu of the task page, of each row of the list and of each card on the board. Only the person who created the task and an owner of the organisation see it. There is no question first: the task goes to the trash of its project with its subtasks, you go back to the list or the board (to the list when you deleted it from its own page), and the message that says so has **Undo**.

- **Hidden.** A task in the trash is gone from the list, the board, my tasks, the counts of the projects index and the menu, the subtasks of its task and the people a comment can mention. Its page is not found and its files and those of its comments are not served.
- **Trash of a project.** **Trash (n)** at the end of the list, shown while something is in it, and in the header of the project settings, lists the deleted tasks, the last first, with who deleted them and when. A subtask deleted together with its task is not listed on its own: it comes back with the task. **Restore** is offered to whoever may delete the task.
- **Restoring.** A restored task comes back where it was, with the subtasks deleted together with it. A subtask whose task is in the trash brings the task back too.
- **For good.** Tasks in the trash for more than 30 days are deleted for good every night from 03:00 in the organisation's time zone, with their subtasks, comments, mentions, watchers, labels and files. The audit log keeps what they were, as for invoices.

## My tasks

**Work, My tasks** lists the unfinished tasks you are responsible for, grouped by when they are due: overdue, today, the next 7 days, later and without a date. A group with nothing in it is left out. The **Watching** tab lists the tasks you follow instead. The client filter narrows both tabs. The count of tasks due by today is shown beside the item in the menu. A guest has **My tasks** too, with the tasks they see; the project of a task shared on its own is named, not linked.

## Project settings

Open **Settings** on a project to:

- change its name, client and description, and archive or restore it;
- add, rename, reorder and remove **sections**. A removed section does not delete its tasks, they move to "No section";
- add, rename, retype, reorder and remove **statuses**. Removing a status asks where its tasks go.

## Sharing

Who sees what follows one rule:

- **Owners** see everything.
- **Team members** see the projects open to **the whole organisation** (the default) and the projects, sections and tasks shared with them.
- **Guests** see only what is shared with them: a project, a section or a single task.
- Inside a project a person sees, everything that is not **private**. A private section or task is seen only by the people it is shared with (and the owners), even by those who see the rest of the project. A subtask follows its task; the tasks of a private section follow the section; a task shared with someone is seen by them wherever it is.
- A guest with a **section** shared sees its project with only that section. A guest with a **single task** shared sees that task, its subtasks and the name of its project for orientation, never its other tasks, its sections or the project page.

**Share**, in the header of the list, the board and the task, and **Sharing** on each section in the project settings, open one dialog:

- **Who sees the project**: the whole organisation or only chosen people; for a section or a task, the **Private** switch.
- **Shared with**: the people it is shared with, each with **Remove**. Removing takes effect at once.
- **Add person**: a picker of the active team members and guests. Owners are left out: they see everything.

Making an item private keeps it shared with whoever made it private, so a team member never locks themselves out. Removing a private section leaves its tasks private, shared with the same people. Every share, removal and change of visibility reaches the audit log; the task history says it, and the dialog of a project or a section shows its sharing history. Only the people of the organisation share, and only what they see. A private item carries a **Private** badge, a project only chosen people see a **Chosen people only** badge.

### Guests

A guest starts on **Shared with me**: the projects they see (the whole project, or the names of the sections shared with them), then the tasks shared with them on their own, under the name of their project. On a project or a task they see, a guest works as the staff do, at one level: they add tasks where they see a section (in no section only when the whole project is shared), add subtasks, change the status, responsible, due date, priority and existing labels, move cards on the board, comment, mention, watch, and attach files. They never see the settings of a project, its trash, the sharing dialog, the labels screen or any other module, and they cannot delete a task. The filter of the responsible person offers them the people of the organisation, never other guests. A page they may not see is not found; a screen of the staff is refused.

## Labels

**Settings, Labels** keeps the labels of the organisation: name and colour. The list shows how many tasks carry each label; deleting a label takes it off its tasks.

## Operating it

There is nothing to configure. The trash is emptied by the scheduled job `task-trash-purge`, which the normal worker runs (see [ADR 0016](adr/0016-scheduled-jobs.md)); without the worker deleted tasks stay in the trash. Projects, tasks, comments and labels are rows of the application database, tenant-isolated like the rest, and every change, watchers and labels included, reaches the audit log. Attachments are files in the `attachments` directory of the share directory, outside the web root; the ops backup already includes the whole share directory (see [operations](operations.md)). Include the database in your backups as usual. Run `bin/console doctrine:migrations:migrate` on upgrade.

## What comes later

Not in this release:

- **Levels of sharing**, such as view only or comment only for a guest; today every guest has the one level above.
- **Notifications** to the responsible person and the watchers.
- **Time tracking** against projects and tasks, and billing it on an invoice.
