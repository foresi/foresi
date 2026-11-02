<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;

/**
 * The contacts a firm can invoice, found by name, identifier or e-mail: everyone not
 * archived for it, the customers of the firm first. What a contact is to the firm
 * decides, through ContactSettingsForFirm, so the roles, the archive state, the
 * defaults and the e-mails are those of that firm.
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
        private ContactSettingsForFirm $contacts,
    ) {
    }

    /**
     * @return list<CustomerOption> customers, then other contacts, each in name order
     */
    public function search(string $text, LegalEntity $firm): array
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
                if ($this->contacts->isArchivedFor($party, $firm)) {
                    continue;
                }
                if ($this->contacts->isCustomer($party, $firm)) {
                    $customers[] = $party;
                } else {
                    $others[] = $party;
                }
            }
            ++$page;
        } while (\count($customers) < self::LIMIT && $page <= min($found->pages, self::MAX_PAGES));

        return array_map(fn (Party $party): CustomerOption => $this->option($party, $firm), \array_slice([...$customers, ...$others], 0, self::LIMIT));
    }

    /**
     * One contact the firm can invoice, for a form that just created it.
     */
    public function find(string $id, LegalEntity $firm): ?CustomerOption
    {
        $party = $this->parties->find($id);

        return $party instanceof Party && !$this->contacts->isArchivedFor($party, $firm) ? $this->option($party, $firm) : null;
    }

    public function option(Party $party, LegalEntity $firm): CustomerOption
    {
        $email = $this->contacts->emailsFor($party, $firm)[0] ?? $this->details->generalEmail($party);

        return new CustomerOption(
            $party->id(),
            $party->displayName(),
            $party->registrationNumber()?->toString(),
            $party->vatId()?->toString(),
            $this->details->billingAddress($party)?->address() ?? new Address('', '', '', ''),
            $this->contacts->defaultsFor($party, $firm),
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
