<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\UserRepository;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * The values a template is previewed with while it is written: a made-up
 * document of the firm, in its currency and the template's language, formatted
 * as a real mail formats them. The firm's own facts (name, numbers, contact
 * e-mail) and the signed-in sender are real, the customer and the amounts are
 * not.
 */
final readonly class MailSamples
{
    private const string NUMBER = '20260042';
    private const string NET = '10000.00';
    private const string VAT = '2100.00';
    private const string TOTAL = '12100.00';
    private const string DUE = '7260.00';
    private const string IBAN = 'CZ6508000000192000145399';
    private const int DUE_IN_DAYS = 14;

    public function __construct(
        private DocumentFormat $format,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private AuditActor $actor,
        private ClockInterface $clock,
        private InstanceFirm $instanceFirm,
    ) {
    }

    /**
     * @param string $customer     a made-up customer, in the language
     * @param string $documentType the title the kind's document carries, in the language
     *
     * @return array<string, string> every variable of the kind except the link
     */
    public function valuesFor(MailTemplateKind $kind, DocumentLanguage $language, Currency $currency, string $customer, string $documentType): array
    {
        $firm = $this->instanceFirm->get();
        $today = \DateTimeImmutable::createFromInterface($this->clock->now());
        $userId = $this->actor->userId();
        $user = null === $userId ? null : $this->users->findById($userId);
        $money = fn (string $amount): string => $this->format->money(Money::of($amount, $currency), $language);
        $values = [
            'number' => self::NUMBER,
            'order_number' => 'PO-1042',
            'variable_symbol' => self::NUMBER,
            'description' => '',
            'issue_date' => $this->format->date($today, $language),
            'due_date' => $this->format->date($today->modify('+'.self::DUE_IN_DAYS.' days'), $language),
            'currency' => $currency->value,
            'document_type' => $documentType,
            'customer_name' => $customer,
            'amount_vat' => $money(self::VAT),
            'amount_total' => $money(self::TOTAL),
            'amount_net' => $money(self::NET),
            'firm_name' => $firm->name(),
            'iban' => self::IBAN,
            'firm_email' => trim($this->settings->get()->contactEmail()),
            'firm_phone' => '',
            'registration_number' => $firm->registrationNumber()->toString(),
            'vat_id' => $firm->vatId()?->toString() ?? '',
            'sender_name' => $user?->displayName() ?? $firm->name(),
        ];

        return match ($kind) {
            MailTemplateKind::Reminder => $values + ['days_overdue' => '9', 'reminder_step' => '1', 'amount_due' => $money(self::DUE)],
            MailTemplateKind::CorrectionDocument => $values + ['original_number' => '20260017'],
            default => $values,
        };
    }
}
