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

- **Edit**: change the role, the linked contact and the hourly rate. A guest needs a contact; clearing the contact unlinks it. The rate, an amount and its currency, is what the person's time is billed at when neither the project nor its client has one; only owners see it (see [projects](projects.md)).
- **Deactivate**: the person can no longer sign in to this organisation. The membership and everything they did stay, and **Reactivate** lets them in again. Nobody deactivates themselves.
- **Reset two-factor sign-in**: the person sets it up again on the next sign-in and trusted browsers are forgotten. This is offered only for someone who belongs to this organisation alone; for anyone else the operator runs `bin/console app:user:reset-2fa <email>`, so the owner of one organisation never weakens the sign-in to another.

## The last owner

An organisation always keeps at least one active owner: the last one can be neither changed to another role nor deactivated. Add a second owner first (invite a team member, then make them owner).

## Contacts for other modules

The people and firms an organisation works with are its contacts, kept in the address book (the Party module). Other modules reach them only through `Foresi\Party\Contract`, so they run against any address book that serves the contract.

### Reading a contact

`Foresi\Party\Contract\PartyDirectory` reads contacts for modules such as Sales (the customer of a deal or an offer) and Outreach (the recipients of a campaign):

```php
interface PartyDirectory
{
    public function find(string $id): ?PartySummary; // archived contacts too, flagged; null when unknown

    /** @throws UnknownParty */
    public function get(string $id): PartySummary;

    /** @return list<PartyPersonSummary> the persons linked to the company, in link order */
    public function personsOf(string $companyId): array;
}
```

- `PartySummary` has the `id`, the `kind` (`Foresi\Party\Contract\Event\ContactKind`: person, sole trader or company), the `displayName`, the `registrationNumber` and the `vatId` (null when the contact has none), the `addressLines` of the billing address (else the first address; street, postal code and city, country code), the general `email`, the `phone` (else the mobile), the `roles` (`customer`, `supplier`, `partner`, `authority`) and `archived`.
- `PartyPersonSummary` has the person's `id`, `displayName`, `email` and `phone`, and the `position` the link to the company gives (null when it gives none).
- `personsOf()` of a person, or of an unknown id, is an empty list.
- It reads as the person signed in: only the owners and members of the organisation read contacts, and a guest is refused, as on the address book's screens, API and MCP tools.

### Adding a contact person

`Foresi\Party\Contract\ContactPersonWrites` adds a contact person to a company or sole trader for modules such as Sales (a person added to the client from the deal's dialog), the same two ways as the contact's own dialog:

```php
interface ContactPersonWrites
{
    /** @return list<PartyPersonSummary> persons of the address book, not archived, the company does not list yet */
    public function candidatesOf(string $companyId): array;

    /** @throws UnknownParty @throws ContactPersonRefused */
    public function link(string $companyId, string $personId, string $position): PartyPersonSummary;

    /** @throws UnknownParty @throws ContactPersonRefused */
    public function addNew(string $companyId, string $name, string $email, string $position): PartyPersonSummary;
}
```

- `link()` takes one of the candidates only; `addNew()` writes a person with no address of its own and links it. The person becomes the company's primary contact person when it has none yet.
- `ContactPersonRefused` names each field that is wrong (`name`, `email`, `position`, `person`) with its reason (`required`, `too_long`, `email_invalid`, `person_invalid`); nothing is written.
- `candidatesOf()` of a person, or of an unknown id, is an empty list. A write joins the unit of work it is called in, and acts as the person signed in, with the address book's own access rules.

### Adding a tab to a contact

A module that keeps something of a contact, such as its deals, adds a tab after the contact's own five (Overview, Contact persons, Addresses, Bank accounts, History). It implements `Foresi\Ui\Contract\Slot\SlotProvider` and answers the slot `Foresi\Party\Contract\ContactTabs::SLOT` (`party.contact_tabs`), whose context holds `contactId`, with a `SlotItem` whose data holds:

- `key`: the tab's key; the tab opens at `/contacts/<id>?tab=<key>`. A key the address book uses itself is ignored.
- `label`: the tab's name, already translated.
- `href`: the module's own page fragment for the contact.

Lower weights come first. The contact's own tabs take the weights 0 to 50, so a module's tab starts at 100. An unknown `?tab=`, such as the key of a module that is not installed, opens the overview.

The tab's body loads inside the contact detail from `href`, in a lazy Turbo frame with the id `ContactTabs::FRAME` (`contact-tab`). The response holds `<turbo-frame id="contact-tab">` with the body. Links in it open as whole pages unless they name the frame with `data-turbo-frame="contact-tab"`.
