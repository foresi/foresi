<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;

/**
 * What the invoice form opens with before anything is saved. The number is only the
 * preview of the counter. A series that is not stored yet has no id: the default of
 * the firm is then created by the first save.
 */
final readonly class InvoiceDraftValues
{
    /**
     * @param list<LineInput> $lines
     * @param Currency|null   $currencyNotice the other currency the contact prefers; the document stays in $currency
     * @param list<string>    $notices        translation keys of what the form must say when it opens
     */
    public function __construct(
        public string $firmId,
        public VatRegime $regime,
        public Currency $currency,
        public ?string $seriesId,
        public string $number,
        public \DateTimeImmutable $issueDate,
        public \DateTimeImmutable $taxPointDate,
        public \DateTimeImmutable $dueDate,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public string $variableSymbol,
        public string $constantSymbol,
        public string $textBefore,
        public string $textAfter,
        public DocumentLanguage $language,
        public array $lines,
        public ?string $customerId = null,
        public ?Currency $currencyNotice = null,
        public string $description = '',
        public array $notices = [],
    ) {
    }

    /**
     * @param list<LineInput> $lines
     */
    public function withLines(array $lines): self
    {
        return $this->with(['lines' => $lines]);
    }

    /**
     * Another issue date; the rest stays until a customer sets the due date from it.
     */
    public function onIssueDate(\DateTimeImmutable $date): self
    {
        return $this->with(['issueDate' => $date]);
    }

    /**
     * @param array<string, mixed> $changes the fields that differ, by name
     */
    public function with(array $changes): self
    {
        return new \ReflectionClass(self::class)->newInstanceArgs([...get_object_vars($this), ...$changes]);
    }
}
