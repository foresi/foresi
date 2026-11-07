# People

Who works in an organisation, and how they get in. The owner manages it under **Settings > People**.

## Roles

Every person has one role in an organisation (see [ADR 0025](adr/0025-roles-owner-team-member-guest.md)):

- **Owner**: everything a team member can do, plus the firms, the import and the people of the organisation.
- **Team member**: the staff. Sees the modules of the organisation and the list of its people. A team member may be linked to their own contact.
- **Guest**: someone the organisation shares work with, such as a client, a supplier or a subcontractor. What they are comes from the contact they represent, which a guest must always have. They see only what is shared with them, never the people screen or any module.

A person may belong to several organisations, with a different role in each.

## Invitations

The owner clicks **Invite** and enters the e-mail address, the name, the role (team member or guest), the language of the e-mail and, for a guest, the contact they represent. A team member may be linked to their own contact too.

- The invitation is sent by the installation's system mail (`SYSTEM_MAILER_DSN`, `SYSTEM_MAIL_FROM`; see [operations](operations.md)). Without system mail the owner sees the link instead, to pass on themselves.
- The link works **once** and for **seven days**. Only a SHA-256 hash of its token is stored, so the link cannot be read back from the database.
- Inviting an address again replaces its pending invitation; the old link stops working. Someone who already belongs to the organisation (even deactivated) is not invited again: reactivate them instead.
- The **Invitations** tab lists the pending invitations with their expiry. **Send again** issues a new link for another seven days; **Revoke** stops the link at once.
- The link page is rate limited per client address.

Following the link:

- A person without an account chooses a name and a password (at least 12 characters) and is signed in. When `APP_REQUIRE_2FA` is on, the two-factor enrolment follows, as on every sign-in.
- A person with an account signs in first and comes back to the link to accept. Signed in as another account, they are asked to sign out.
- An unknown, expired, revoked or used link shows a page that says so.

## Managing people

The owner sees, for each person, their role, the linked contact, whether two-factor sign-in is on and whether they are active, and can:

- **Edit**: change the role and the linked contact. A guest needs a contact; clearing the contact unlinks it.
- **Deactivate**: the person can no longer sign in to this organisation. The membership and everything they did stay, and **Reactivate** lets them in again. Nobody deactivates themselves.
- **Reset two-factor sign-in**: the person sets it up again on the next sign-in and trusted browsers are forgotten. This is offered only for someone who belongs to this organisation alone; for anyone else the operator runs `bin/console app:user:reset-2fa <email>`, so the owner of one organisation never weakens the sign-in to another.

## The last owner

An organisation always keeps at least one active owner: the last one can be neither changed to another role nor deactivated. Add a second owner first (invite a team member, then make them owner).
