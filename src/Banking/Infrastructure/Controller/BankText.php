<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Controller;

use App\Banking\Domain\InboundStatus;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\UnsupportedCountry;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bank movements and messages in words, for the bank screens: amounts in the
 * language of the user from integer minor units, as the invoices show them, an
 * account as its label or its number, and where a movement or a message stands.
 */
final readonly class BankText
{
    public function __construct(
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private InvoicingCountries $countries,
    ) {
    }

    /**
     * "1 210,50 Kč", "1 210,50 EUR"; with a sign, "+1 210,50 Kč" or "−80,00 Kč".
     */
    public function amount(Money $money, ?bool $incoming = null): string
    {
        $sign = null === $incoming ? '' : ($incoming ? '+' : "\u{2212}");

        return $sign.\sprintf("%s\u{a0}%s", $this->grouped($money), $this->unit($money->currency()));
    }

    public function unit(Currency $currency): string
    {
        return Currency::CZK === $currency ? 'Kč' : $currency->value;
    }

    /**
     * For a field, without grouping: "1210,50" in Czech, as TypedNumber reads it back.
     */
    public function plain(Money $money): string
    {
        $symbol = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return \sprintf('%s%d%s%02d', $money->isNegative() ? '-' : '', intdiv(abs($money->minor()), 100), $symbol, abs($money->minor()) % 100);
    }

    /**
     * An amount as typed in a field, in the currency, or null for one that is not an
     * amount above zero.
     */
    public function typed(string $typed, Currency $currency): ?Money
    {
        $decimal = TypedNumber::decimal(trim($typed), $this->locales->getLocale());
        if (1 !== preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $decimal)) {
            return null;
        }
        $money = Money::of($decimal, $currency);

        return $money->isZero() ? null : $money;
    }

    /**
     * The label of an account, or its number when it has none: the domestic form
     * where the firm's country has one, else the IBAN in groups of four.
     */
    public function account(string $label, string $iban, string $countryCode = 'CZ'): string
    {
        if ('' !== $label) {
            return $label;
        }
        try {
            $domestic = $this->countries->for($countryCode)->domesticAccount($iban);
        } catch (UnsupportedCountry) {
            $domestic = null;
        }

        return $domestic ?? trim(chunk_split($iban, 4, ' '));
    }

    /**
     * A moment as the clock of the firm's country shows it.
     */
    public function time(\DateTimeImmutable $at, string $countryCode): string
    {
        try {
            $at = $at->setTimezone($this->countries->for($countryCode)->timezone());
        } catch (UnsupportedCountry) {
        }

        return $at->format($this->translator->trans('format.datetime'));
    }

    /**
     * Where a movement stands, as a badge: its text and tone.
     *
     * @return array{text: string, tone: string}
     */
    public function state(MovementState $state, ?UnmatchedReason $reason): array
    {
        if (MovementState::Unmatched === $state && $reason instanceof UnmatchedReason) {
            return ['text' => $this->translator->trans('bank.movement.reason.'.$reason->value), 'tone' => UnmatchedReason::Rejected === $reason ? 'red' : 'amber'];
        }

        return [
            'text' => $this->translator->trans('bank.movement.state.'.$state->value),
            'tone' => match ($state) {
                MovementState::Paired => 'green',
                MovementState::PossibleDuplicate => 'amber',
                default => 'gray',
            },
        ];
    }

    /**
     * Where a message stands, as a badge.
     *
     * @return array{text: string, tone: string}
     */
    public function status(InboundStatus $status, bool $stuck): array
    {
        if ($stuck) {
            return ['text' => $this->translator->trans('bank.message.status.stuck'), 'tone' => 'amber'];
        }

        return [
            'text' => $this->translator->trans('bank.message.status.'.$status->value),
            'tone' => match ($status) {
                InboundStatus::Processed => 'green',
                InboundStatus::Failed, InboundStatus::Rejected => 'red',
                default => 'gray',
            },
        ];
    }

    /**
     * Why a message stands where it does, in words a person acts on; empty when
     * there is nothing to say.
     */
    public function reason(InboundStatus $status, ?string $failure, bool $stuck): string
    {
        return match (true) {
            $stuck => $this->translator->trans('bank.message.reason.stuck'),
            InboundStatus::Failed === $status, InboundStatus::Rejected === $status => $this->translator->trans('bank.message.reason.'.($failure ?? 'unknown')),
            InboundStatus::NotBankMail === $status => $this->translator->trans('bank.message.reason.not_bank_mail'),
            InboundStatus::Ignored === $status => $this->translator->trans('bank.message.reason.ignored'),
            default => '',
        };
    }

    private function grouped(Money $money): string
    {
        $minor = abs($money->minor());
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $whole = (string) $formatter->format(intdiv($minor, 100), \NumberFormatter::TYPE_INT64);

        return \sprintf('%s%s%s%02d', $money->isNegative() ? '-' : '', $whole, $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $minor % 100);
    }
}
