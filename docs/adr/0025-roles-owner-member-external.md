# 25. Roles: owner, member and external

* Status: accepted
* Date: 2026-11-07

## Context and problem statement

A membership carried one of four roles: owner, member, subcontractor and client. Projects and tasks will be shared with specific people whatever label their contact carries: a client, a supplier, a subcontractor or a contact with no role at all. Two roles for outsiders split one idea by a label the address book already holds. The staff checks of the modules (`StaffCheck`, `OwnerCheck`) were also called by hand in each controller, and the address book had none, so a client or subcontractor could read and edit every contact.

## Decision drivers

* An outsider sees only what is shared with them, never a module of the organisation.
* A new screen is closed to outsiders without anyone remembering to close it.
* What an outsider is comes from one place, the contact they represent.

## Considered options

* Roles: owner, member and external, the meaning of external from the linked contact (chosen); keep client and subcontractor and add more labels as they come
* Access: deny external people by default, with an attribute on the controllers open to them (chosen); a `#[StaffOnly]` attribute on every staff controller; `access_control` by path; a voter called from each controller
* Where the check runs: a `kernel.controller` listener in Identity (chosen); the firewall

## Decision outcome

Three roles, stored as `owner`, `member` and `external`. A migration turns the stored `client` and `subcontractor` into `external`; going down makes every external membership a client one, as the two cannot be told apart again. An external membership must link a contact (`Membership` refuses one without); the owner and a member may link their own person record. `app:user:create` takes `owner`, `member` or `external`, the contact with `--contact=<id>`, required for external.

`ExternalAccessGuard` turns an external person of the active organisation away with 403 from every controller that does not carry `#[OpenToExternal]`: the sign-in and its code step, the account (language, two-factor), the choice of organisation, the public document links, the start page and the "Shared with me" page. The start page sends an external person on to "Shared with me", a placeholder the projects module fills. The health check and the inbound mail webhook run on firewalls without a session, so no person is ever signed in there. The sidebar shows external people "Shared with me" alone; every module's navigation, notices and dashboard sections stay for the staff (`StaffCheck`), the address book included.

A route test walks the whole route collection and expects 403 for an external person on every route outside a short allow-list, so a module added later is checked without a test of its own. The existing per-controller `StaffCheck` and `OwnerCheck` calls stay: they separate the owner from members, which the guard does not.

Paths could not carry the rule: the screens are not under prefixes per audience, and a module that opens a screen to external people would have had to edit the security configuration. Denying by default inverts the cost: forgetting the attribute closes a screen, which a test shows at once, rather than opening one, which nothing shows.

### Consequences

* Good, because a forgotten check now fails closed, and the address book is closed to outsiders.
* Good, because the projects module opens its shared screens with one attribute per controller.
* Bad, because a membership stored as external before this change may have no contact; the domain cannot repair it, an operator links one.
* Bad, because each request of a signed-in user reads their memberships once more, as the staff checks already did.
