<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Controller;

use Foresi\Banking\Application\BankingCountries;
use Foresi\Banking\Domain\InboundStatus;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\MoneyFormat;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
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
        private BankingCountries $countries,
        private InstanceFirm $firm,
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
        return Currency::of('CZK') === $currency ? 'Kč' : $currency->code();
    }

    /**
     * For a field, without grouping: "1210,50" in Czech, as TypedNumber reads it back.
     */
    public function plain(Money $money): string
    {
        return MoneyFormat::field($money, $this->locales->getLocale());
    }

    /**
     * An amount as typed in a field, in the currency, or null for one that is not an
     * amount above zero.
     */
    public function typed(string $typed, Currency $currency): ?Money
    {
        $decimal = TypedNumber::decimal(trim($typed), $this->locales->getLocale());
        $digits = $currency->minorUnits();
        if (1 !== preg_match(0 === $digits ? '/^\d{1,13}$/D' : \sprintf('/^\d{1,13}(?:\.\d{1,%d})?$/D', $digits), $decimal)) {
            return null;
        }
        $money = Money::of($decimal, $currency);

        return $money->isZero() ? null : $money;
    }

    /**
     * The label of an account, or its number when it has none: the domestic form
     * where the firm's country has one, else the IBAN in groups of four.
     */
    public function account(string $label, string $iban): string
    {
        if ('' !== $label) {
            return $label;
        }
        $domestic = $this->countries->for($this->countryCode())?->domesticAccount($iban);

        return $domestic ?? trim(chunk_split($iban, 4, ' '));
    }

    /**
     * A moment as the clock of the firm's country shows it.
     */
    public function time(\DateTimeImmutable $at): string
    {
        $country = $this->countries->for($this->countryCode());
        if ($country instanceof \Foresi\Banking\Contract\BankingCountry) {
            $at = $at->setTimezone($country->timezone());
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
        return MoneyFormat::grouped($money, $this->locales->getLocale());
    }

    private function countryCode(): string
    {
        return $this->firm->find()?->address()->countryCode ?? 'CZ';
    }
}
