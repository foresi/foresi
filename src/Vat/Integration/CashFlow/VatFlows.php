<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Integration\CashFlow;

use Foresi\CashFlow\Contract\CategorisedSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Infrastructure\Api\VatApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The VAT of each period in the cash flow: one estimate flow in the category
 * vat for every period not settled that expects money, out on its due date
 * to pay, in on its expected refund date to get back, for the return amount
 * when a person entered it and the estimate otherwise. A flow whose day has
 * passed is awaited, never overdue: only the return knows the final amount.
 * A payment settles the period and its flow leaves the forecast.
 *
 * The owners and members see the flows one by one, a token only with
 * vat:read; anyone else gets their category totals only.
 */
final readonly class VatFlows implements CategorisedSource
{
    public const string KEY = 'vat.periods';

    public const string SUBJECT = 'vat_period';

    public function __construct(
        private VatPeriods $periods,
        private VatAccess $access,
        private ApiTokenContext $tokens,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function categories(): array
    {
        return [FlowCategory::Vat];
    }

    public function mayReadDetails(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (VatRefused) {
            return false;
        }
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('vat', false));
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $zone = $today->getTimezone();
        foreach ($this->periods->open($to) as $view) {
            $on = $view->awaitedOn();
            if (PeriodState::Settled === $view->state || !$on instanceof \DateTimeImmutable) {
                continue;
            }
            $on = new \DateTimeImmutable($on->format('Y-m-d'), $zone);
            if ($on > $to) {
                continue;
            }
            $refund = $view->expected->isNegative();
            yield new ExpectedFlow(
                self::KEY,
                new FlowSubject(self::SUBJECT, $view->period->key, $this->urls->generate(VatApiController::PERIOD, ['key' => $view->period->key], UrlGeneratorInterface::ABSOLUTE_PATH)),
                $refund ? FlowDirection::In : FlowDirection::Out,
                $refund ? Money::ofMinor(-$view->expected->minor(), $view->expected->currency()) : $view->expected,
                $on,
                FlowCategory::Vat,
                FlowCertainty::Estimate,
                $this->label($view, $refund),
                $on < $today ? [FlowFlag::Estimated, FlowFlag::Awaited] : [FlowFlag::Estimated],
            );
        }
    }

    private function label(PeriodView $view, bool $refund): string
    {
        $period = $view->period;
        if (1 === preg_match('/^([0-9]{4})-Q([1-4])\z/', $period->key, $m)) {
            $name = $this->translator->trans('vat.period.quarter', ['quarter' => (int) $m[2], 'year' => $m[1]]);
        } else {
            $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL y');
            $name = (string) $formatter->format(new \DateTimeImmutable($period->key.'-01', new \DateTimeZone('UTC')));
        }

        return $this->translator->trans($refund ? 'vat.flow.refund' : 'vat.flow.payable', ['period' => $name]);
    }
}
