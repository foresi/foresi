<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The payments of the estimate in the cash flow (Goal 5, old T7): every
 * advance and settlement not yet paid, and an overpayment coming back, as
 * estimate flows on their due day. The income tax is in the category taxes,
 * social, health and sickness insurance in insurance. They are computed from
 * the profile and its chosen expense method each time, never stored. A
 * payment the bank has paid is no flow any more (B4).
 *
 * Only the owner with taxes:read may see them one by one; anyone else gets
 * their category totals only, as they come from the owner's personal profile
 * (B7). The estimate reads the forecast without this source, so the forecast
 * never asks it again from inside it.
 */
final readonly class EstimateFlows implements CashFlowSource
{
    public const string KEY = ForecastAmounts::OWN_SOURCE;

    public const string SUBJECT = 'tax_payment';

    public function __construct(
        private Estimates $estimates,
        private TaxesAccess $access,
        private ApiTokenContext $tokens,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return $this->access->isOwner() && (!$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('taxes', false)));
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        foreach ($this->estimates->openPayments($to) as $payment) {
            yield new ExpectedFlow(
                self::KEY,
                new FlowSubject(self::SUBJECT, $payment->key(), $this->urls->generate(TaxesApiController::ESTIMATE, ['year' => $payment->taxYear()], UrlGeneratorInterface::ABSOLUTE_PATH)),
                $payment->comesIn() ? FlowDirection::In : FlowDirection::Out,
                $payment->amount,
                new \DateTimeImmutable($payment->dueOn->format('Y-m-d'), $today->getTimezone()),
                Authority::IncomeTax === $payment->authority ? FlowCategory::Taxes : FlowCategory::Insurance,
                FlowCertainty::Estimate,
                $this->label($payment),
                [FlowFlag::Estimated],
            );
        }
    }

    private function label(DuePayment $payment): string
    {
        return $this->translator->trans('taxes.flow.'.$payment->kind, [
            'authority' => $this->translator->trans('taxes.authority.'.$payment->authority->value),
            'period' => $payment->periodLabel(),
        ]);
    }
}
