# 40. Projects and tasks over the API and MCP

* Status: accepted
* Date: 2026-11-19

## Context and problem statement

Projects, tasks, comments, labels and time had screens only. A client that collects a person's tasks from several instances, or an agent that works the tasks of an instance, needs them over the REST API and MCP (ADR 0027). Work decides who sees what in one place, `WorkVisibility`: an owner sees everything, a team member the projects open to the organisation and what is shared with them, and a private task only the people it is shared with. Its refusals had no kind, and it published no events.

## Decision drivers

* The API runs the use cases of the screens (ADR 0027), so a token sees and changes what its person does, and no more.
* A token for one job reaches only what that job needs (ADR 0032).
* What a person may not see is not found, in the lists, the reads, the events and the webhooks alike.
* Lists page by keyset on the id, as every list of the API does.

## Decision outcome

### Resources and scopes

Work declares three resources: `projects`, `tasks` (with comments, labels and who may be responsible) and `time_entries`. Reads are hand-written controllers and read tools over `WorkQueries` and `TimeQueries`, which apply `WorkVisibility` in SQL through `ScopedDql`. `GET /api/v1/tasks` takes `assignee=me|<id>` besides `view` and `filter` of the list `work.my_tasks`, and pages by `id DESC` with the shared cursor; it answers `due_on`, and a client sorts by it. `GET /api/v1/projects/{id}/people` answers who may be responsible, through the same rule as the pickers of the screens.

Writes are operations (ADR 0032) whose handlers call `ManageTasks`, `ManageProjects`, `ManageComments` and `ManageTime`. A task is changed by one partial `PATCH` with `Omitted` fields; the per-field use cases run in one `WorkUnit`, so the change is saved whole or not at all and is one step of the history. Completing a task is setting a status of type done or closed. A timer is stopped by the id of the running entry. Sharing, sections, the workflow and notifications stay on the screens. Guests hold no token; that rule of Identity does not change here.

`WorkRefused` implements `Refused`: `unknown` is `not_found` and `not_allowed` is `forbidden`, the reasons about whose a record is are forbidden, those about its state conflicts, the rest invalid with the field of the input. The screens keep the reason as their translation key.

### Events

Work publishes `work.task.created`, `work.task.updated` (with the fields changed), `work.task.completed`, `work.task.trashed` and `work.comment.added`, version 1, ids only, recorded by the use cases in their unit of work. A change that changes nothing records nothing; a `PATCH` of several fields records one `updated` for each. The subject of every one is the task.

The module-level `EventReadAccess` cannot tell a private task from another, so `Shared` gains the port `EventSubjectAccess` (`sees`, `userSees`), tagged `app.event_subject_access`. The feed leaves out an event whose subject its caller does not see, while its cursor moves past it, and the webhook fan-out does not send it to an owner who does not see it, without disabling the endpoint. A module without one shows every event to whom its `EventReadAccess` lets read them. Work's implementation asks `WorkVisibility`, a task in the trash as it would be out of it.

### Consequences

* A member's token cannot reach a project not shared with them, nor learn the ids of its tasks from the feed.
* A page of the feed can hold fewer events than its limit while more follow; `next_cursor` still leads on.
* The checks of `EventSubjectAccess` load the subject of each event read, one query per event of Work.
