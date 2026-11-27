<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Screen;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Deals\Application\DealLineRules;
use Foresi\Deals\Application\DealValue;
use Foresi\Deals\Application\HourlyValue;
use Foresi\Deals\Application\LostReasonNames;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Application\StageNames;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Domain\DealStatus;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Domain\Stage;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the deal screens say a deal: its stage and status, its owner and
 * client, the price, period and quantity of a line, and its value one-off
 * and monthly, apart per currency and never added across currencies.
 */
final class DealScreen implements ResetInterface
{
    /** @var array<string, string>|null the names of the people of the organisation, by user id */
    private ?array $names = null;

    /** @var array<string, string> the names of the contacts read, by id */
    private array $clients = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MoneyText $money,
        private readonly StageNames $stageNames,
        private readonly LostReasonNames $reasonNames,
        private readonly People $people,
        private readonly PartyDirectory $parties,
        private readonly LocaleSwitcher $locales,
    ) {
    }

    public function reset(): void
    {
        $this->names = null;
        $this->clients = [];
    }

    public function stage(?Stage $stage): string
    {
        return $stage instanceof Stage ? $this->stageNames->of($stage) : $this->trans('deals.deal.stage_unknown');
    }

    public function reason(LostReason $reason): string
    {
        return $this->reasonNames->of($reason);
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function status(Deal $deal): array
    {
        return ['label' => $this->trans('deals.status.'.$deal->status()->value), 'tone' => match ($deal->status()) {
            DealStatus::Open => 'gray',
            DealStatus::Won => 'green',
            DealStatus::Lost => 'red',
        }];
    }

    public function owner(string $userId): string
    {
        return $this->names()[$userId] ?? $this->trans('deals.deal.owner_unknown');
    }

    public function client(string $partyId): string
    {
        return $this->clients[$partyId] ??= $this->parties->find($partyId)->displayName ?? $this->trans('deals.deal.client_unknown');
    }

    /**
     * The owners and members who may own a deal, by user id; the deal's own
     * owner too when that person is no longer one.
     *
     * @return array<string, string>
     */
    public function owners(?string $current = null): array
    {
        $owners = [];
        foreach ($this->people->all() as $person) {
            if ($person->active && $person->role->isStaff()) {
                $owners[$person->userId] = $person->name;
            }
        }
        if (null !== $current && !isset($owners[$current])) {
            $owners[$current] = $this->owner($current);
        }

        return $owners;
    }

    /**
     * The owners and members whose name holds the text, for the owner picker of the filter sheet.
     *
     * @return list<array{id: string, label: string, hint: string}>
     */
    public function ownerResults(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $results = [];
        foreach ($this->people->all() as $person) {
            if ($person->role->isStaff() && ('' === $text || str_contains(mb_strtolower($person->name.' '.$person->email), $text))) {
                $results[] = ['id' => $person->userId, 'label' => $person->name, 'hint' => $person->email];
            }
        }

        return $results;
    }

    public function money(Money $money): string
    {
        return $this->money->shown($money);
    }

    /**
     * "12 000,00 Kč · 300,00 EUR", one amount per currency; empty for none.
     *
     * @param array<string, Money> $amounts
     */
    public function amounts(array $amounts): string
    {
        return implode(' · ', array_map($this->money(...), array_values($amounts)));
    }

    /**
     * The value as a list row says it: "12 000,00 Kč + 1 500,00 Kč a month",
     * "≈" before a monthly value that is approximate; empty for no value.
     */
    public function value(DealValue $value): string
    {
        $parts = [];
        if ([] !== $value->oneOff) {
            $parts[] = $this->amounts($value->oneOff);
        }
        if ([] !== $value->monthly) {
            $parts[] = $this->trans('deals.value.per_month', ['amount' => ($value->approximate ? '≈ ' : '').$this->amounts($value->monthly)]);
        }
        if ([] !== $value->hourly) {
            $parts[] = $this->trans('deals.value.hourly_mark');
        }

        return implode(' + ', $parts);
    }

    /**
     * The hourly lines of a value, as the footer of the lines table says them:
     * "1 200,00 Kč/h (≈ 40 h = 48 000,00 Kč)", or the rate alone without expected hours.
     *
     * @param array<string, list<HourlyValue>> $hourly
     */
    public function hourly(array $hourly): string
    {
        $texts = [];
        foreach ($hourly as $lines) {
            foreach ($lines as $line) {
                $texts[] = $line->expectedAmount instanceof Money && null !== $line->expectedHours
                    ? $this->trans('deals.value.hourly_expected', ['rate' => $this->money($line->rate), 'hours' => $this->quantity($line->expectedHours), 'amount' => $this->money($line->expectedAmount)])
                    : $this->trans('deals.value.hourly_rate', ['rate' => $this->money($line->rate)]);
            }
        }

        return implode(' · ', $texts);
    }

    /**
     * "One-off", "Hourly", or "Every 3 months, 12 times" for a recurring line.
     */
    public function billing(DealLine $line): string
    {
        if (BillingKind::TimeBased === $line->billingKind()) {
            return $this->trans('deals.billing_kind.time_based');
        }
        $unit = $line->recurrenceUnit();
        if (BillingKind::OneOff === $line->billingKind() || !$unit instanceof \Foresi\Shared\Domain\Schedule\RecurrenceUnit) {
            return $this->trans('deals.billing_kind.one_off');
        }
        $every = $this->trans('deals.line.every.'.$unit->value, ['count' => $line->interval() ?? 1]);
        $cycles = $line->cycles();

        return null === $cycles ? $this->trans('deals.line.billing_open', ['every' => $every]) : $this->trans('deals.line.billing_cycles', ['every' => $every, 'count' => $cycles]);
    }

    /**
     * The quantity as the locale writes it, without the zeros after the point: "1,5".
     */
    public function quantity(string $quantity): string
    {
        $formatter = new \NumberFormatter($this->locales->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, DealLine::QUANTITY_SCALE);

        return (string) $formatter->format((float) $quantity);
    }

    /**
     * "2 hours × 1 500,00 Kč", or the quantity alone for a line with no price;
     * "Not estimated" for an hourly line with no expected hours.
     */
    public function lineAmount(DealLine $line): string
    {
        $price = $line->unitPrice();
        if (null === $line->quantity()) {
            return $this->trans('deals.line.not_estimated');
        }
        $quantity = trim($this->quantity($line->quantity()).' '.$line->unit());

        return $price instanceof Money ? $quantity.' × '.$this->money($price) : $quantity;
    }

    /**
     * The amount of a line: its price times its quantity, or for an hourly line
     * its rate per hour (hours are worked, not sold, so no total is made of them);
     * empty for a line with no price.
     */
    public function lineTotal(DealLine $line): string
    {
        $price = $line->unitPrice();
        if (!$price instanceof Money) {
            return '';
        }
        if (BillingKind::TimeBased === $line->billingKind()) {
            return $this->trans('deals.line.per_hour', ['rate' => $this->money($price)]);
        }

        return $this->money($price->multipliedBy($line->quantity() ?? '1'));
    }

    /**
     * The values of a line's form: those of the line, or of a new one-off line of one piece.
     *
     * @return array<string, string>
     */
    public function lineValues(?DealLine $line): array
    {
        $price = $line?->unitPrice();

        return [
            'productId' => '',
            'name' => $line?->name() ?? '',
            'unit' => $line?->unit() ?? '',
            'quantity' => $line instanceof DealLine ? $this->plainQuantity($line->quantity() ?? '') : '1',
            'unitPrice' => $price instanceof Money ? $this->decimal($price) : '',
            'currency' => $price?->currency()->value ?? 'CZK',
            'billingKind' => $line?->billingKind()->value ?? BillingKind::OneOff->value,
            'recurrenceUnit' => $line?->recurrenceUnit()->value ?? 'month',
            'interval' => (string) ($line?->interval() ?? 1),
            'cycles' => null === $line?->cycles() ? '' : (string) $line->cycles(),
            'projectId' => $line?->projectId() ?? '',
        ];
    }

    /**
     * @return array{billingKinds: array<string, string>, units: array<string, string>, currencies: array<string, string>}
     */
    public function lineOptions(): array
    {
        return [
            'billingKinds' => array_combine(DealLineRules::BILLING_KINDS, array_map(fn (string $kind): string => $this->trans('deals.billing_kind.'.$kind), DealLineRules::BILLING_KINDS)),
            'units' => array_combine(DealLineRules::UNITS, array_map(fn (string $unit): string => $this->trans('deals.line.unit_of_time.'.$unit), DealLineRules::UNITS)),
            'currencies' => array_combine(DealLineRules::CURRENCIES, DealLineRules::CURRENCIES),
        ];
    }

    /**
     * The message of a refusal, as the screens show it under the field or in an alert.
     */
    public function refusal(SalesRefused $refused): string
    {
        return $this->trans('deals.error.'.$refused->reason());
    }

    /**
     * A local address to come back to, or null for one that leads elsewhere.
     */
    public static function localPath(string $path): ?string
    {
        if ('' === $path || '/' !== $path[0] || \strlen($path) > 2048 || str_starts_with($path, '//') || str_starts_with($path, '/\\') || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        return $this->names ??= array_column(array_map(static fn (Person $person): array => ['id' => $person->userId, 'name' => $person->name], $this->people->all()), 'name', 'id');
    }

    private function plainQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    /**
     * A day as the deal page writes it: "15 Dec 2026", "15. 12. 2026" in Czech.
     */
    public function day(\DateTimeImmutable $day): string
    {
        $formatter = new \IntlDateFormatter($this->locales->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
        $text = $formatter->format(new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC')));

        return false === $text ? $day->format('Y-m-d') : $text;
    }

    /** An amount as a form's field holds it: "5000.00". */
    public function plainAmount(Money $money): string
    {
        return $this->decimal($money);
    }

    private function decimal(Money $money): string
    {
        $minor = $money->minor();

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', \STR_PAD_LEFT);
    }
}
