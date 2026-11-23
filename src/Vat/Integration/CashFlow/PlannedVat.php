<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\PlannedVatAmount;
use Foresi\Vat\Contract\VatPlanSource;
use Foresi\Vat\Contract\VatSubject;

/**
 * The VAT of the plans in the cash flow, read through its forecast: the
 * planned flows with their VAT stated, from today on, as the forecast counts
 * them, so an occurrence a bank movement already fulfils counts nothing. It
 * reads without the VAT periods' own source and without the categories that
 * carry no VAT, so neither the VAT nor the tax estimate is asked from inside
 * it. Money planned in gives output VAT and money planned out input VAT, in
 * CZK as the forecast converts it. A reverse charge the firm self-assesses
 * gives the same VAT as output and as input, so it adds nothing to pay, as a
 * received document in a self-assessed treatment does. A flow with no VAT
 * stated counts nothing.
 */
final readonly class PlannedVat implements VatPlanSource
{
    public const string KEY = 'cash-flow.planned_vat';

    /** The categories with no VAT. */
    public const array EXCLUDED_CATEGORIES = [FlowCategory::Vat, FlowCategory::Taxes, FlowCategory::Insurance, FlowCategory::Loans, FlowCategory::OwnerDraws];

    /** Cash flow's own planned items, whose failure leaves the plans unread. */
    public const string PLANNED_ITEMS = 'cash-flow.planned_items';

    /** The forecast reaches this many months from today at most. */
    private const int MAX_MONTHS = 24;

    public function __construct(
        private ForecastReader $reader,
        private InstanceClock $clock,
        private ApiTokenContext $tokens,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('cashflow', false));
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $today = $this->clock->today();
        $from = max(new \DateTimeImmutable($from->format('Y-m-d'), $today->getTimezone()), $today);
        $to = min(new \DateTimeImmutable($to->format('Y-m-d'), $today->getTimezone()), $today->modify(\sprintf('+%d months', self::MAX_MONTHS)));
        if ($to < $from) {
            return [];
        }
        $read = $this->reader->read(new ForecastQuery($from, $to, [VatFlows::KEY], self::EXCLUDED_CATEGORIES, [FlowCertainty::Plan]));
        if (\in_array(self::PLANNED_ITEMS, $read->unavailableSources, true)) {
            throw new \RuntimeException('The planned items of the cash flow could not be read.');
        }
        $details = $this->mayReadDetails();
        $amounts = [];
        foreach ($read->flows as $flow) {
            $selfAssessed = $flow->selfAssessedVatCzk();
            $vat = $selfAssessed ?? $flow->vatCzk();
            if (!$vat instanceof Money) {
                continue;
            }
            $zero = Money::ofMinor(0, $vat->currency());
            $in = FlowDirection::In === $flow->direction;
            $subject = $details && null !== $flow->subject ? new VatSubject($flow->subject->type, $flow->subject->id, $flow->subject->url) : null;
            [$output, $input] = $selfAssessed instanceof Money ? [$vat, $vat] : ($in ? [$vat, $zero] : [$zero, $vat]);
            $amounts[] = new PlannedVatAmount(self::KEY, $subject, $subject instanceof VatSubject ? $flow->label : null, $flow->on, $output, $input);
        }

        return $amounts;
    }
}
