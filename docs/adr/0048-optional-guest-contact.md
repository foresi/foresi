# 48. A guest's contact is optional

* Status: accepted
* Date: 2026-11-27
* Supersedes in part: [ADR 0025](0025-roles-owner-team-member-guest.md) (the rule that a guest membership links a contact)

## Context and problem statement

ADR 0025 made a guest membership, and an invitation of a guest, refuse to exist without the contact (party) the guest represents, so that what an outsider is would come from one place. `app:user:create` required `--contact` for a guest.

The address book has since become a module of its own, `contacts`, split off the core's `party`. Identity is core and keeps the party record, but an instance may now run without the address book: it then has no screen to create a contact, and so no way to invite a guest at all.

Nothing reads a guest's contact to decide anything. What a guest may see comes from their role and from what is shared with them; the contact shows only as a label on the people screens. The columns `membership.party_id` and `invitation.party_id` were nullable from the start.

## Decision drivers

* An instance without the address book can still invite and share with guests.
* The core does not depend on a module that requires it.
* A link stored before stays as it is, whatever is installed.
* No migration.

## Considered options

* The contact is optional, and offered only with the address book (chosen)
* Every guest keeps a contact, created on the fly by the invitation when the address book is missing
* The contact stays required whenever the address book is installed
* Identity forgets contacts; the address book keeps the link of a person to a contact

## Decision outcome

A membership and an invitation of any role may link a contact or none. `Membership`, `Invitation`, `InvitePeople`, `ManagePeople` and `app:user:create` no longer refuse a guest without one; an unknown contact is still refused. The unused `Membership::linkContact` is removed. The columns and their foreign keys stay.

The people screens offer the contact only when the address book is installed. Identity publishes the port `Foresi\Identity\Contract\PersonContactPicker` with the address of the contact search. The contacts module serves it, with the search itself at the unchanged route `app_people_contacts` (`/settings/people/contacts`), open to the owner only. Identity injects the port nullable: with it, the invitation and the edit form show the contact field and the people and invitation lists the contact column, linked through `Party\Contract\ContactPages`; without it, there is no field and no column.

A change of a person sets the contact only when the submission carries the contact field. A form without the field, as on an instance without the address book, keeps the contact a person has; only an empty field unlinks it.

### Consequences

* Good, because a guest can be invited on an instance of the core and the work modules alone.
* Good, because Identity names the address book only through a port it owns, and the address book fills it from its own code, as it requires Identity.
* Good, because no data is created behind the owner's back and no link is lost when the address book is removed.
* Bad, because with the address book installed a guest may still have no contact, so who an outsider is rests on their name and e-mail; a feature that needs the contact, such as showing a client their invoices, has to require it itself.
* Bad, because a contact linked on an instance that later drops the address book is kept but no longer shown until the module comes back.
