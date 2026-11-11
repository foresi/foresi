<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Domain\Party;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingNotAllowed;

/**
 * The contacts a received document may name as its supplier, for the combobox
 * of its form: found by name, registration number, VAT ID or e-mail as the
 * address book finds them, archived ones left out.
 */
final readonly class SupplierChoices
{
    public const int LIMIT = 10;
    private const int MIN_LENGTH = 3;

    public function __construct(private PurchasingAccess $access, private PartyQuery $parties)
    {
    }

    /**
     * @return list<SupplierOption> in name order
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function search(string $text): array
    {
        $this->access->denyUnlessStaff();
        $text = trim($text);
        if (mb_strlen($text) < self::MIN_LENGTH && !ctype_digit($text)) {
            return [];
        }
        $rows = \array_slice($this->parties->search($text, null, false, 'name', 1)->rows, 0, self::LIMIT);

        return array_map(static fn (PartyRow $row): SupplierOption => new SupplierOption($row->id, $row->displayName, $row->registrationNumber, $row->vatId), $rows);
    }

    /**
     * The contact with the id, for a form that just created it or shows the one chosen.
     *
     * @throws PurchasingNotAllowed for anyone but the owners and members of the organisation
     */
    public function find(string $id): ?SupplierOption
    {
        $this->access->denyUnlessStaff();
        $party = $this->parties->find($id);

        return $party instanceof Party ? new SupplierOption($party->id(), $party->displayName(), $party->registrationNumber()?->toString(), $party->vatId()?->toString()) : null;
    }
}
