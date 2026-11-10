<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a new invoice to or from this party starts with, whether overdue
 * invoices to it are reminded automatically, and the hourly rate its time is
 * billed at when its project has none. The rate is two nullable columns, as
 * an embeddable cannot be null; only owners see it.
 */
#[ORM\Embeddable]
final readonly class InvoicingDefaults
{
    public const int DEFAULT_DUE_DAYS = 14;
    private const int MAX_DUE_DAYS = 365;

    #[ORM\Column(name: 'hourly_rate_minor', type: 'bigint', nullable: true)]
    private ?int $hourlyRateMinor;

    #[ORM\Column(name: 'hourly_rate_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $hourlyRateCurrency;

    /**
     * @param ?Money $hourlyRate null for none; zero is a rate, for work that is not charged
     */
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
        #[ORM\Column(name: 'send_reminders', type: 'boolean', options: ['default' => true])]
        public bool $sendReminders = true,
        ?Money $hourlyRate = null,
    ) {
        if ($dueDays < 0 || $dueDays > self::MAX_DUE_DAYS) {
            throw new \DomainException(sprintf('The due period must be between 0 and %d days.', self::MAX_DUE_DAYS));
        }
        if (true === $hourlyRate?->isNegative()) {
            throw new \DomainException('An hourly rate is not negative.');
        }
        $this->hourlyRateMinor = $hourlyRate?->minor();
        $this->hourlyRateCurrency = $hourlyRate?->currency();
    }

    public static function standard(): self
    {
        return new self(self::DEFAULT_DUE_DAYS, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false);
    }

    public function hourlyRate(): ?Money
    {
        return null === $this->hourlyRateMinor || !$this->hourlyRateCurrency instanceof Currency ? null : Money::ofMinor($this->hourlyRateMinor, $this->hourlyRateCurrency);
    }

    public function equals(self $other): bool
    {
        return $this->dueDays === $other->dueDays
            && $this->currency === $other->currency
            && $this->language === $other->language
            && $this->paymentMethod === $other->paymentMethod
            && $this->reverseCharge === $other->reverseCharge
            && $this->sendReminders === $other->sendReminders
            && $this->hourlyRateMinor === $other->hourlyRateMinor
            && $this->hourlyRateCurrency === $other->hourlyRateCurrency;
    }
}
