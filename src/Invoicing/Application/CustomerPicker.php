<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyRole;

/**
 * The contacts the firm can invoice, found by name, identifier or e-mail: everyone not
 * archived, the customers first, with the contact's own defaults and invoicing e-mail.
 */
final readonly class CustomerPicker
{
    public const int LIMIT = 10;
    private const int MIN_LENGTH = 3;
    /** The rows looked through at most: page size times this, for a search that hits other contacts. */
    private const int MAX_PAGES = 4;

    public function __construct(
        private PartyQuery $parties,
        private PartyDetails $details,
        private PartyInvoicingDefaults $defaults,
    ) {
    }

    /**
     * @return list<CustomerOption> customers, then other contacts, each in name order
     */
    public function search(string $text): array
    {
        $text = trim($text);
        if (!$this->isSearchable($text)) {
            return [];
        }

        $customers = [];
        $others = [];
        $page = 1;
        do {
            $found = $this->parties->search($text, null, false, 'name', $page);
            foreach ($this->partiesOf($found->rows) as $party) {
                if ($party->isArchived()) {
                    continue;
                }
                if ($party->hasRole(PartyRole::Customer)) {
                    $customers[] = $party;
                } else {
                    $others[] = $party;
                }
            }
            ++$page;
        } while (\count($customers) < self::LIMIT && $page <= min($found->pages, self::MAX_PAGES));

        return array_map($this->option(...), \array_slice([...$customers, ...$others], 0, self::LIMIT));
    }

    /**
     * One contact the firm can invoice, for a form that just created it.
     */
    public function find(string $id): ?CustomerOption
    {
        $party = $this->parties->find($id);

        return $party instanceof Party && !$party->isArchived() ? $this->option($party) : null;
    }

    public function option(Party $party): CustomerOption
    {
        $email = $this->details->invoicingEmails($party)[0] ?? $this->details->generalEmail($party);

        return new CustomerOption(
            $party->id(),
            $party->displayName(),
            $party->registrationNumber()?->toString(),
            $party->vatId()?->toString(),
            $this->details->billingAddress($party)?->address() ?? new Address('', '', '', ''),
            $this->defaults->of($party->id()),
            $email?->address(),
        );
    }

    private function isSearchable(string $text): bool
    {
        return mb_strlen($text) >= self::MIN_LENGTH || ctype_digit($text);
    }

    /**
     * @param list<PartyRow> $rows
     *
     * @return list<Party> in the order of the rows
     */
    private function partiesOf(array $rows): array
    {
        $parties = [];
        foreach ($this->parties->findMany(array_map(static fn (PartyRow $row): string => $row->id, $rows)) as $party) {
            $parties[$party->id()] = $party;
        }

        return array_values(array_filter(array_map(static fn (PartyRow $row): ?Party => $parties[$row->id] ?? null, $rows)));
    }
}
