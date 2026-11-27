<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

use Foresi\Inbox\Application\InboxAddressBook;
use Foresi\Party\Contract\ContactKind;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Domain\Id;

/**
 * An address book in memory that answers as Party's ContactsByEmail does:
 * archived contacts are left out of the lookups by address and domain, and
 * flagged among a person's companies.
 */
final class FakeContactsByEmail implements InboxAddressBook
{
    /** @var list<array{PartySummary, list<string>}> */
    private array $contacts = [];

    /** @var array<string, list<string>> person id => company ids */
    private array $links = [];

    /**
     * @param list<string> $emails
     */
    public function add(string $name, ContactKind $kind, array $emails, bool $archived = false): string
    {
        $id = Id::generate();
        $this->contacts[] = [new PartySummary($id, $kind, $name, null, null, [], $emails[0] ?? null, null, [], $archived), array_map(mb_strtolower(...), $emails)];

        return $id;
    }

    public function link(string $companyId, string $personId): void
    {
        $this->links[$personId][] = $companyId;
    }

    public function byAddress(string $email): array
    {
        $email = mb_strtolower(trim($email));

        return $this->where(static fn (PartySummary $contact, array $emails): bool => !$contact->archived && \in_array($email, $emails, true));
    }

    public function byDomain(string $domain): array
    {
        $suffix = '@'.ltrim(mb_strtolower(trim($domain)), '@');

        return $this->where(static fn (PartySummary $contact, array $emails): bool => !$contact->archived
            && ContactKind::Person !== $contact->kind
            && array_any($emails, static fn (string $email): bool => str_ends_with($email, $suffix)));
    }

    public function companiesOfPerson(string $personId): array
    {
        $ids = $this->links[$personId] ?? [];

        return $this->where(static fn (PartySummary $contact): bool => \in_array($contact->id, $ids, true));
    }

    public function create(string $kind, string $displayName, string $street, string $city, string $postalCode, string $countryCode, string $registrationNumber, string $vatId, string $email, string $phone): string
    {
        throw new \LogicException('The fake address book only matches senders.');
    }

    /**
     * @param callable(PartySummary, list<string>): bool $test
     *
     * @return list<PartySummary>
     */
    private function where(callable $test): array
    {
        $found = [];
        foreach ($this->contacts as [$contact, $emails]) {
            if ($test($contact, $emails)) {
                $found[] = $contact;
            }
        }

        return $found;
    }
}
