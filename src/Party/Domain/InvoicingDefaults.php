<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Shared\Domain\Currency;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a new invoice to or from this party starts with.
 */
#[ORM\Embeddable]
final readonly class InvoicingDefaults
{
    public const int DEFAULT_DUE_DAYS = 14;
    private const int MAX_DUE_DAYS = 365;

    public function __construct(
        #[ORM\Column(name: 'due_days', type: 'integer')]
        public int $dueDays,
        #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
        public Currency $currency,
        #[ORM\Column(type: 'string', length: 2, enumType: DocumentLanguage::class, options: ['fixed' => true])]
        public DocumentLanguage $language,
        #[ORM\Column(name: 'payment_method', type: 'string', length: 32, enumType: PaymentMethod::class)]
        public PaymentMethod $paymentMethod,
        #[ORM\Column(name: 'reverse_charge', type: 'boolean')]
        public bool $reverseCharge,
    ) {
        if ($dueDays < 0 || $dueDays > self::MAX_DUE_DAYS) {
            throw new \DomainException(sprintf('The due period must be between 0 and %d days.', self::MAX_DUE_DAYS));
        }
    }

    public static function standard(): self
    {
        return new self(self::DEFAULT_DUE_DAYS, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false);
    }
}
