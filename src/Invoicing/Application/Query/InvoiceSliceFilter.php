<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Query;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Id;

/**
 * Which documents a slice holds, for the API and MCP: of one kind or of every kind, of one firm
 * or all, of one customer or all, in one state or any, issued within a period, both days
 * included. The trash stays out unless asked for, and then holds only what is in it. The text
 * is looked for as the list of the screens looks for it: in the number, the customer name, the
 * variable symbol and the description. A view, a saved one or a filter sent with the request
 * (spec D13), narrows it further: its conditions are those of the kind's list (InvoiceLists), or
 * of the invoices' when no kind is given.
 */
final readonly class InvoiceSliceFilter
{
    /** The payment states a slice may be narrowed to. */
    public const array STATES = [InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::PAID];

    /**
     * @param string|null $state one of STATES; null takes any. Ignored for the trash.
     *
     * @throws \InvalidArgumentException for a state that is none of STATES, or an id that is none
     */
    public function __construct(
        public ?DocumentKind $kind = null,
        public ?string $firmId = null,
        public ?string $customerId = null,
        public ?string $state = null,
        public bool $trashed = false,
        public ?\DateTimeImmutable $issuedFrom = null,
        public ?\DateTimeImmutable $issuedTo = null,
        public string $text = '',
        public ?ViewFilter $view = null,
    ) {
        if (null !== $state && !\in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown state "%s".', $state));
        }
        foreach ([$firmId, $customerId] as $id) {
            if (null !== $id && !Id::isValid($id)) {
                throw new \InvalidArgumentException('An id is not valid.');
            }
        }
    }

    /**
     * The tab of the list of the screens this filter shows.
     */
    public function tab(): string
    {
        if ($this->trashed) {
            return InvoiceFilter::TRASH;
        }

        return $this->state ?? InvoiceFilter::ALL;
    }
}
