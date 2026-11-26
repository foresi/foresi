# Projects and tasks

Work of the organisation, kept in projects. This page says what the screens do and what is still to come; the decisions behind the model are in [ADR 0026](adr/0026-projects-and-tasks.md). Owners see every project; team members see the projects open to the organisation and those shared with them; a guest sees only what is shared with them (see [Sharing](#sharing) and [people](people.md)).

## Concepts

- **Project.** A body of work, for a client or internal. It belongs to the organisation, not to one of its firms, so every firm's people see the same projects. It has a name, an optional **client** (any contact of the address book), a plain-text description and an archive date. An archived project leaves the active list, can be restored at any time and accepts no new tasks.
- **Section.** A heading that groups the tasks of a project in the list, such as "Backlog" or "Shared with client". Tasks without a section come first, under no heading. Sections have an order of their own.
- **Status and workflow.** The statuses of a project are its workflow: the steps a task goes through, in order. A new project starts with the **Simple** template (To do, In progress, Done); the **Development** template (Open, In progress, Review, Testing, Blocked, Done, Closed) can be applied later in the project settings. The names are translated once into your language and are plain text afterwards: rename, add, reorder or remove them in the project settings. What cannot change is the **type** of each status: open, active, done or closed. Reports and the completion date rely on the type, never on the name. A project always keeps at least one open and one done status.
- **Task.** A piece of work with a title, a description, a status, a section, a **responsible** person, a due date, a priority (none, low, medium, high, urgent) and labels. A task gets its completion date when it enters a status of type done or closed, and loses it when it leaves.
- **Subtask.** A task one level under another task of the same project. A subtask cannot have subtasks of its own.
- **Responsible.** The one person accountable for a task, picked from the active people of the organisation who can see the task, guests included.
- **Watcher.** A person who follows a task. The person who creates a task and the person responsible for it follow it from the start; anyone can watch or stop watching, and add other people on the task page. Commenting on a task or being mentioned in it makes you a watcher too.
- **Label.** A coloured tag, shared by all projects of the organisation, with a unique name. Colours are the badge tones of the interface: gray, green, amber and red.

## Projects

**Work, Projects** lists the active projects with their client, the number of unfinished tasks and the nearest due date; the **Archived** tab lists the rest. **New project** opens a dialog for the name, the client and the description. Every project starts with the Simple workflow, as a Linear team starts with a default workflow customised later; change it in the project settings.

## The list

A project opens as a list: first the tasks in no section, as the list's own with no heading, then one block per named section under its heading, each task a row with its status, responsible, due date, priority and labels, and its subtasks under it. The filters above narrow the list by responsible person, status (unfinished by default, finished, or all) and due date (overdue, today, the next 7 days, none).

**Changing a task in its row.** The status, the responsible person, the due date and the priority change without opening the task, as in Linear and GitHub Projects: click the value. Status and priority open a menu with a check on the current one, the responsible person opens the list of people with their avatars and **Nobody**, and the due date opens the browser's date picker, where clearing the date removes it. The choice is saved at once and the page stays where it was, with its filters and the focus on the value; a refusal is shown at the top of the list. The same works on the cards of the board (the responsible person, the due date and the priority; a card changes its status by moving) and in **My tasks**. The people offered are those who see the task, as on its page. An archived project offers none of this. Without JavaScript the values are plain text and the task page changes them.

**New task**, in the header of the list and of the board, opens a dialog for the title and the section (no section by default). The task starts in the first open status of the workflow, and you come back to the view you opened it from, with its filters, where a message confirms it. A project without tasks shows the same button in the middle of the page. An archived project has no **New task**. Without JavaScript the dialog is a page of its own.

## The board

The **Board** tab shows one column per status, in workflow order, and the top-level tasks as cards: title, responsible, due date, priority, labels and how many subtasks are done. The filters are the same as in the list, apart from the status. The columns scroll sideways inside the board, never the page.

- **Drag and drop.** Take a card by the handle at its left and drop it in another column or at another place in the same one. It works with a mouse, a finger and a pen. The new place is saved at once and announced to screen readers.
- **The move menu.** Every card has a menu with **Move to** (one item per status), **Move up** and **Move down**. It does everything dragging does, with a keyboard or a screen reader, and works without JavaScript.

The order of a card is the order of the task in its status; the list shows the same tasks in the same relative order.

## Activities

With the Activities module installed, a project's page has an **Activities** tab after List and Board, which stay the default: the project's timeline of meetings, calls, notes and e-mails, with **Log activity** and **Plan activity** (see [activities](activities.md)). An activity is linked to a project with the kind `project`, on the screens, over REST (`activities.create`) and over MCP (`foresi_create_activity` with `links: [{kind: "project", id}]`). Whoever sees the project sees its activities. An archived project keeps them and takes no new one, as it takes no new tasks. Activities is for the owners and team members, so a guest a project is shared with gets no tab.

With the Inbox module installed, an inbox item has **Create task** and **Log time**, filled from the mail with the client's one active project chosen; the mail then shows on the project's Activities tab (see [inbox](inbox.md#actions)).

### For other modules

The project's page has two slots, `Foresi\Work\Contract\ProjectSlots`, the way the deal's page has `SalesSlots`. Both get the context `projectId`, `clientId` (or null) and `return`, the address of the page as shown, with its tab.

- `ProjectSlots::SECTIONS` (`work.project.sections`): tabs after List and Board, by weight. An item's data holds `key` (lowercase letters, digits and dashes), `label` and either `href`, a local address of a fragment loaded into `<turbo-frame id="project-section-<key>">`, or the item's template. `?tab=<key>` shows the section in place of the tasks. Activities is one, at weight 10, and Budget, at weight 20.
- `ProjectSlots::HEADER_FACTS` (`work.project.header_facts`): short facts under the header, next to the client line, such as a time budget. The item's template is included with its data and the context. Sales names there the deal whose hourly line the project is linked to, with the line and its rate, while the deal is open or won and only for owners and members (weight 10); Work shows the time budget after it (weight 20).

## The task page

Opening a task shows its title and description, its subtasks, its **attachments**, and the **discussion and history** (below). The panel beside it (under it on a phone) holds the properties. Each property saves as soon as you choose a value; a **Save** button appears for a browser without JavaScript. **Responsible** is a button with the person's avatar and name that opens the list of people, each with their avatar, and **Nobody** first; choosing one saves it. People appear as round avatars with their initials everywhere in Work: in the list, on the board, among the watchers, beside comments and in the sharing dialog. The title and the description change where you read them: click the title in the header and it becomes a text field (**Enter** saves, **Escape** cancels, and leaving it changed saves); click the description and its editor opens in place with **Save** and **Cancel**, and it stays open when you click elsewhere, so a long text is not lost. The pencil button beside each is the way for the keyboard and screen readers; without JavaScript it opens the same form on the page. The page comes back with the change without reloading. **New subtask**, in the header, opens a dialog for the title; the subtask goes into the section of its task and the page comes back at the subtasks. A subtask has no **New subtask**, and neither does a task of an archived project.

**Labels** are picked in a popup: type to narrow the list, tick as many as you like (Enter or a click), and closing the popup (Escape, a click elsewhere or the button again) saves them in one change. When no label is named as typed, whatever its case, the last row offers to create it: the staff get a new gray label, ticked with the others, and can recolour it on the labels screen. A guest only picks existing labels. Without JavaScript the button opens a page with a checkbox per label, and a field for a new one for the staff.

## Discussion

Under the task, **Discussion and history** is one timeline, oldest first: every change with who made it and when (status, responsible, due date, priority, labels, watchers and edited fields) as a small line, and every comment as a full item. The tabs **All**, **Comments** and **History** narrow it. The comment box is under the timeline.

- **Comments are public by default.** A new comment is read by everyone who sees the task, guests included. Turn on **Internal** to write an internal comment, which only people of the organisation see; it carries an **Internal** label with a lock, in words, never by colour alone. The line under the comment box says in words who will read the comment before it is sent. Until November 2026 the default was internal, so that a slip could not reach a client. It changed because most comments on a shared task are meant for everyone on it, and an internal default hid them from the guests they were written for; Linear, Asana and Zendesk start a comment as public and keep internal as an explicit choice, and the audience line makes the choice visible before sending. Existing comments kept their setting. A guest has no switch: their comments are always public, and they never see an internal comment or its files.
- **Sending.** The button **Add comment**, or Ctrl+Enter (Cmd+Enter on a Mac). The page lands on the new comment.
- **Mentions.** Type `@` and the first letters of a name: the people who can see the task appear in a list; Up and Down move, Enter or a click chooses. The mention is stored as `@[Name](membership:id)` and shown as a chip. It is kept only for an active person who can see the task, whatever the text claims.
- **Markdown.** Comments and the description use a safe subset: paragraphs and line breaks, **bold**, *italic*, ~~strikethrough~~, lists and task lists, links (`http`, `https`, `mailto`), `code`, code blocks, quotes, rules and headings (from the third level down, so a text never outranks the page). Everything else is removed: raw HTML is shown as text, scripts, styles and other tags and attributes are dropped, and so are links with other schemes and images from elsewhere (only the installation's own attachments show as images). The **Preview** tab shows the result before you send; the toolbar (bold, italic, list, link, code, quote) and Ctrl/Cmd+B, I and K help writing.
- **Editing and deleting.** Only the author edits a comment, from its menu; it then says **edited**. The author deletes their own comment, and an owner of the organisation may delete any comment to moderate. Deleting asks nothing: the message says so and **Undo** brings it back. A deleted comment stays in its place in the thread: its author and the owners see "The comment is deleted · Restore", everyone else "This comment was deleted", and nobody reads its text. Restoring brings back the text and the people it mentions, without notifying them again. After 30 days the text and the files of a deleted comment are removed for good and the line stays.

## Attachments

**Attachments** lists the files of a task: an image as a thumbnail, any other file as a row with its kind, name, size, who uploaded it and when.

- **Adding.** Drop files on the area, choose them with **Add file**, or paste an image or drop files into the comment box or the description: the file is uploaded and linked in the text as Markdown (an image shows in place). Each upload shows its progress and, if refused, why. Without JavaScript the area is a plain form with one file.
- **Limit.** A file may be up to 50 MB. An empty file is refused. The type is read from the file itself, never taken from its name or from the browser.
- **Viewing.** Images and PDFs open in a dialog with a download link; videos play in the list. Any other file downloads. Files are served only to people who may see the task, never from a public address.
- **Deleting.** The person who uploaded a file may delete it, and so may an owner of the organisation. Deleting asks nothing: the message says so and **Undo** brings the file back. Whoever may delete a file finds it for 30 days under the collapsed **Deleted files** below the list, with who deleted it and when, and **Restore** puts it back. A deleted file is never served, to anyone, until it is restored. After 30 days the file is removed for good, from the disk too.

## Trash

**Delete task** is in the **⋯** menu of the task page, of each row of the list and of each card on the board. Only the person who created the task and an owner of the organisation see it. There is no question first: the task goes to the trash of its project with its subtasks, you go back to the list or the board (to the list when you deleted it from its own page), and the message that says so has **Undo**.

- **Hidden.** A task in the trash is gone from the list, the board, my tasks, the counts of the projects index and the menu, the subtasks of its task and the people a comment can mention. Its page is not found and its files and those of its comments are not served.
- **Trash of a project.** **Trash (n)** at the end of the list, shown while something is in it, and in the header of the project settings, lists the deleted tasks, the last first, with who deleted them and when. A subtask deleted together with its task is not listed on its own: it comes back with the task. **Restore** is offered to whoever may delete the task.
- **Restoring.** A restored task comes back where it was, with the subtasks deleted together with it. A subtask whose task is in the trash brings the task back too.
- **For good.** Tasks in the trash for more than 30 days are deleted for good every night from 03:00 in the organisation's time zone, with their subtasks, comments, mentions, watchers, labels and files. The audit log keeps what they were, as for invoices.

## My tasks

**Work, My tasks** lists the unfinished tasks you are responsible for, grouped by when they are due: overdue, today, the next 7 days, later and without a date. Each row shows the project, the status, the responsible person, the due date and the priority, and all but the project change in the row as in the list; a task you hand to someone else or finish leaves the tab. A group with nothing in it is left out. The **Watching** tab lists the tasks you follow instead. The client filter narrows both tabs. The count of tasks due by today is shown beside the item in the menu. A guest has **My tasks** too, with the tasks they see; the project of a task shared on its own is named, not linked.

## Project settings

Open **Settings** on a project to:

- change its name, client and description and whether it bills its time, and archive or restore it;
- add, rename, reorder and remove **sections**. **Add a section**, in the header of the sections, names a new one in a dialog. A removed section does not delete its tasks, they move to "No section";
- add, rename, retype, reorder and remove **statuses**. **Add a status**, in the header of the statuses, names a new one and picks its type in a dialog. Removing a status asks where its tasks go.
- **Apply workflow template**, in the header of the statuses, replaces all the statuses with those of a template after one confirmation in a dialog. Custom names and statuses are gone; every task, subtasks and the trash included, moves to the first status of the same type, as Jira moves work between workflows by status category. A type the template lacks goes to the closest one: closed tasks go to done in the Simple template. Finished tasks stay finished with their completion date. The audit log and the history of each task record the change.

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

## Notifications

**Notifications** in the main menu, with the number of unread ones beside it, is the inbox of each person, guests included. It has one row per task, newest first, with the latest event and who did it: a mention, a task given to you, a comment or a change of status on a task you watch, a task shared with you. You are never told about your own actions, and only about tasks you can see; an internal comment tells only the team. Opening a task marks its row read; **Mark all as read** is in the header. **Done** moves a row to the **Done** tab, kept for three months; a later event on the task opens a new row. The filters show only mentions or only tasks given to you.

E-mails go out through the system mailer of the installation, never a firm's server. A mention and a task given to you are e-mailed about two minutes later, unless you have read them in the inbox by then; the rest comes in one daily summary at the hour you choose, in the organisation's time zone. **Notification settings**, in the user menu and in the header of the inbox, sets for each kind of event: at once (mentions and tasks given to you only), in the daily summary, or not at all, the hour of the summary, and a switch to receive no e-mails. Every e-mail has a link that stops that kind of e-mail after one confirmation, and supports one-click unsubscribe in mail clients. Without a system mailer, notifications stay in the inbox.

## Time budget

A project may have a **time budget** in hours: the hours approved for it, such as "8 h of development approved" in a client's e-mail. The budget is the sum of its **changes**, each dated, plus or minus, with a note: "+8 h approved", later "+4 h for the second phase" or "-2 h moved to another project". A project without a change has no budget; approving hours is optional, and a firm that does not approve enters one change. This follows Harvest's project budget in total hours and the recorded budget changes of Oracle Primavera Unifier.

- **On the project.** Under the header: "Budget 8 h · used 2.5 h · left 5.5 h". Going over is shown, with an **Over budget** badge and a negative left, and never blocked: time is still logged, and none of it turns non-billable on its own (Harvest does the same).
- **Budget tab.** Owners and team members have a **Budget** tab after Activities: budget, used, left and the sum of the task estimates, then the changes, the latest first, with the date, hours, note, the source activity and who entered it. **Add change** opens a dialog for the hours (`8`, `-2.5` or `2,5`, at most two decimal places), the date of the change (today), such as the day the hours were approved, and a note; every change counts in the budget whatever its date. A row's menu removes a change entered by mistake; to lower a budget that was right, add a minus change. The budget never goes below zero. Whoever may edit the project may change its budget; an archived project keeps it and takes no change.
- **Counting.** **Counting** in the tab chooses which time uses the budget: **All time** (the default, billable and non-billable) or **Billable time only**, so that work logged non-billable, such as flat-fee operation of a site, leaves the budget alone. Used is the project's time by anyone: not in the trash, not on a task in the trash, and not a timer that still runs.
- **Task estimates** stay on their tasks; the tab shows their sum (of the tasks not in the trash) next to the budget.
- **Guests.** A guest who sees the project sees the budget, used and left under the header and in `foresi_get_project`, never the changes: their notes and sources may hold commercial terms.
- **Source.** A change may name the activity it comes from, such as the e-mail that approved the hours, which must be linked to the project (`source_not_on_project`). For now every `sourceActivityId` is refused with `source_not_on_project`: Activities does not yet say which activities are linked to a project. The inbox action "Add to project budget" brings that check and lifts the refusal.

Over the API and MCP the budget is `GET /api/v1/projects/{id}/budget` and `foresi_get_project_budget` (`projects:read`), and `budget {hours, used_hours, left_hours, counting, estimate_hours}` (or null) in `foresi_get_project`. Changes are written with `work.project_budget.add_change`, `work.project_budget.remove_change` and `work.project_budget.set_counting` (`projects:write`, see [work writes](api.md#work-writes)), and each records the event `work.project_budget.changed` with the ids of the project and the change and what happened (`added`, `removed`, `counting`).

In the APQC Process Classification Framework, setting a project's budget sits under 13.2.3.3 *Develop project plans* (element 16413) and showing budget, used and left under 13.2.3.5 *Review and report project performance* (16417). These ids are of the Cross-Industry PCF 7.4; they are not yet checked against PCF 8.0.

## Time

Owners and team members log the time they spend on the work; guests never see time and cannot open its screens. Time goes on a task the person sees, or on a project without a task when the whole project is open to them, for work that belongs to no task, such as a call with the client.

- **Logging.** **Log time** under the subtasks of a task, in the header of a project's list and board, and on the week page opens a dialog: the day (today), the duration, a note and **Billable**. The duration is read as typed: `1:30`, `1,5` or `1.5`, `1h 30m`, `45m`, or a bare number of hours; at least a minute and at most a day per entry. From the week page the dialog also picks the project and, optionally, one of its tasks.
- **Timer.** **Start timer** in the header of a task, or in the dialog of the week page for a project or a task, starts a timer from now on the organisation's today. Each person runs one timer: starting another stops the running one and logs its time first. While it runs, a bar above every page names its task or project, links to it, counts the time and has **Stop**, which logs the time; the task header says **Stop timer**. The start is stored, so a reload or another device shows the same time.
- **On the task.** The **Time** block shows the total against the estimate, as "3 h 20 min of 5 h", then each entry, newest day first, with its day, person, duration, note and whether it is billable; a running timer shows as **Running**. The author or an owner edits an entry in the same dialog and deletes it from its line, until it is invoiced. A running timer is stopped, not edited.
- **Estimate.** A property of the task in the panel beside it, in hours and minutes typed like a duration, such as `5 h` or `2h 30m`.
- **Billable.** Every entry is billable or not. The dialog starts from the project's default: a project created for a client bills its time, an internal project does not. **Bill time** in the project settings changes the default for new time; existing entries keep theirs. Change it per entry.
- **Hourly rates.** Only owners see and set rates; team members and guests never do. A rate is an amount with its currency (CZK, EUR or USD), empty for none, zero for work that is not charged. It is set in three places: **Hourly rate** in the project settings, next to **Bill time**; **Hourly rate** among the invoicing defaults of a contact; and **Hourly rate** when an owner edits a person in **Settings, People**. Time is billed at the rate of the hourly deal line the project is linked to (see [Sales](sales.md#lines-and-value)), else the project's rate, else the rate of its client, else the person's rate; the invoicing dialog marks time at a deal line's rate as "rate of the deal line". A deal line's rate counts while its deal is open or won, not once it is lost or in the trash. Entries hold no rate until they are invoiced: the rate is taken into each entry when its invoice is issued, so a later change of a rate leaves invoiced time as it was billed.
- **Week.** **Work, Time** opens the person's week, Monday to Sunday: each day with its entries and its total, and the total of the week. The header moves a week back or on, or to this one, and logs time. Each entry links to its task, or its project without one, and is edited and deleted from the week as on the task. An owner switches to the week of anyone of the team.
- **Report.** The **Report** tab reads a period, this month unless last month or other dates are chosen, narrowed to a project, a client, a person, billable or not and invoiced or not. It shows the total, the billable and the non-billable time and the number of entries, the totals by project, person or task, and the entries, newest first. **Export CSV** in the header downloads the entries with the same filter: date, person, client, project, task, minutes, hours, billable and note, separated by semicolons. The report and the week hold only the time of what the person sees: time on a task follows the task, time without a task needs the whole project.
- **Invoicing time.** Only owners invoice time. **Invoice time** in the header of a project's list and board, and of the report, opens a dialog: the client, which of its projects, and the period, last month unless other dates are chosen. It lists the billable time of that period that is finished and not invoiced yet, all ticked, with the total hours and the total without VAT, which change as you untick entries. **Invoice lines** makes one line per project, per task (the default), per person or per entry, and time at different rates goes on separate lines. **Rounding of entries** rounds each entry up to 15 or 30 minutes, or not at all, before its line adds them up; the time logged stays as it is. Ticked time without a rate, or with rates in a currency other than the firm's, is refused: set the rate, or untick the entry. **Continue** opens the ordinary new invoice form of the firm with the client and the lines, in hours (`h`, `hod` in Czech) at the hourly rate; edit it as any invoice, and a line you remove leaves its time unbilled.
- **Invoiced time.** Issuing the invoice bills the time of each line still on it, with the rate and the rounded minutes it was chosen with. Invoiced time names its invoice, linked, where its edit and delete were, on the task and in the week, and cannot be changed or deleted; the report filters it as invoiced. If some of the time was edited, deleted or invoiced by someone else after it was chosen, the invoice is refused and you open the dialog again. Editing the invoice later keeps each entry with its line, wherever the line moves; removing a line frees its time.
- **Trash and restore.** An invoice moved to the trash frees its time: it is unbilled, editable and offered for invoicing again. Restoring the invoice bills the same time again, on the same lines, at the same rates and for the same minutes. Once any of that time was edited, deleted or invoiced on another invoice, the restore is refused and the invoice stays in the trash.

Every entry, its change and its deletion reach the audit log, under its task or under its project. When a task is purged from the trash, its time stays on its project.

## Labels

**Settings, Labels** keeps the labels of the organisation: name and colour. The list shows how many tasks carry each label. Deleting a label asks nothing: it goes to the trash, and the message says so with **Undo**. **Trash (n)** in the header lists the deleted labels with how many tasks they were on, who deleted them and when. A label in the trash shows on no task and in no picker, and its name is free for a new label; **Restore** puts it back on the same tasks, refused while another label has taken the name (rename that one first). After 30 days in the trash a label leaves its tasks for good.

## Over the API and MCP

Projects, tasks, comments, labels and time can be read and changed with a personal API token or an MCP client, with the scopes `projects`, `tasks` and `time_entries` (see [the REST API](api.md#projects-and-tasks) and [MCP](mcp.md#tools)). A token acts as its person: it sees and changes exactly what they do on these screens, so a member's token does not reach a project open only to the people it is shared with, and a private task stays hidden. Guests hold no token. The tasks of a person in one instance are `GET /api/v1/tasks?assignee=me&finished=false`, or `foresi_search_tasks` with `assignee: "me"`; a client that follows several instances holds one token for each.

A task records the events `work.task.created`, `work.task.updated` (with the fields changed), `work.task.completed` (it reached a status of type done or closed) and `work.task.trashed`, and a comment `work.comment.added`; a project's time budget records `work.project_budget.changed`. They carry ids only, and the [event feed](api.md#events) and webhooks show each only to who sees its task, or its project. Sharing, sections, the workflow and notifications stay on the screens.

## Operating it

Notification e-mails need the system mailer (see [operations](operations.md)). The trash is emptied by the scheduled job `task-trash-purge`, the text of comments deleted 30 days ago by `comment-trash-purge`, files deleted 30 days ago by `attachment-trash-purge`, labels deleted 30 days ago by `label-trash-purge`, the daily summaries are sent by `notification-digest` and old done notifications removed by `notification-purge`, all run by the normal worker (see [ADR 0016](adr/0016-scheduled-jobs.md)); without the worker deleted tasks stay in the trash. Projects, tasks, comments, labels and time entries are rows of the application database, and every change, watchers and labels included, reaches the audit log. Attachments are files in the `attachments` directory of the share directory, outside the web root; the ops backup already includes the whole share directory (see [operations](operations.md)). Include the database in your backups as usual. Run `bin/console doctrine:migrations:migrate` on upgrade.

## What comes later

Not in this release:

- **Levels of sharing**, such as view only or comment only for a guest; today every guest has the one level above.
- **Reply by e-mail** to a notification, and quiet hours for e-mails.
- **Notifications for a shared project or section**; today only a task shared on its own tells the person.
- **Invoicing time in other currencies** than the firm's own, and fixed-price or budgeted projects.
- **Import from ClickUp**: folders, lists, tasks, comments, attachments and time entries.
