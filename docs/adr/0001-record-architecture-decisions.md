# 1. Record architecture decisions

* Status: accepted
* Date: 2026-10-29

## Context and problem statement

Architecture decisions are easy to forget and hard to reconstruct later. New contributors need to understand why the code base looks the way it does.

## Decision drivers

* Decisions must be discoverable and reviewable together with the code.
* The format must be lightweight so that it is actually used.

## Considered options

* Markdown Architectural Decision Records (MADR) in the repository
* A wiki or external documentation tool
* No formal records

## Decision outcome

Chosen option: "MADR in the repository", because records are versioned and reviewed with the code they describe.

Records live in `docs/adr`, are numbered sequentially (`NNNN-short-title.md`) and follow the MADR structure: context and problem statement, decision drivers, considered options, decision outcome and consequences. A record is never rewritten once accepted. A later record supersedes it and the old status becomes "superseded by NNNN".

### Consequences

* Good: the reasoning behind decisions stays next to the code.
* Bad: a small amount of writing for every significant decision.
