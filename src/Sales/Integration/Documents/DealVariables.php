<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Documents;

use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\VariableDefinition;
use Foresi\Sales\Application\DealDetail;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The deal a document links to under {{ deal.* }}: the first link of kind
 * "deal". Without one, or when that deal is in the trash or gone, the source
 * does not apply and Documents names the deal variables the template misses.
 * Amounts, quantities and the billing period are written as the deal's page
 * writes them, in the language of the person generating the document:
 * "12 000,00 Kč", "Every month, no end". The totals hold one row per currency,
 * never converted; a monthly total that had to put a weekly or daily line on a
 * month's terms starts with "≈".
 */
final readonly class DealVariables implements DocumentVariableSource
{
    public function __construct(private DealReads $reads, private DealScreen $screen, private TranslatorInterface $translator)
    {
    }

    public function prefix(): string
    {
        return 'deal';
    }

    public function definitions(): array
    {
        return [
            $this->definition('title', 'Roof audit for the warehouse'),
            $this->definition('owner', 'Jana Example'),
            $this->definition('stage', 'Proposal'),
            $this->definition('status', 'Open'),
            $this->definition('lines', [
                ['name' => 'Roof audit', 'unit' => 'piece', 'quantity' => '1', 'unit_price' => '12 000,00 Kč', 'billing_period' => 'One-off'],
                ['name' => 'Maintenance', 'unit' => 'month', 'quantity' => '1', 'unit_price' => '1 500,00 Kč', 'billing_period' => 'Every month, no end'],
            ]),
            $this->definition('one_off_totals', [['currency' => 'CZK', 'amount' => '12 000,00 Kč']]),
            $this->definition('monthly_totals', [['currency' => 'CZK', 'amount' => '1 500,00 Kč']]),
        ];
    }

    public function values(array $links): ?array
    {
        $detail = null;
        foreach ($links as $link) {
            if (SalesRecords::KIND === $link->kind) {
                $detail = $this->detail($link->id);
                break;
            }
        }
        if (!$detail instanceof DealDetail || $detail->deal->isTrashed()) {
            return null;
        }
        $deal = $detail->deal;

        return [
            'title' => $deal->title(),
            'owner' => $this->screen->owner($deal->ownerUserId()),
            'stage' => $this->screen->stage($detail->stage),
            'status' => $this->screen->status($deal)['label'],
            'lines' => array_map(fn (DealLine $line): array => [
                'name' => $line->name(),
                'unit' => $line->unit(),
                'quantity' => null === $line->quantity() ? '' : $this->screen->quantity($line->quantity()),
                'unit_price' => $line->unitPrice() instanceof Money ? $this->screen->money($line->unitPrice()) : '',
                'billing_period' => $this->screen->billing($line),
            ], $detail->lines),
            'one_off_totals' => $this->totals($detail->value->oneOff, ''),
            'monthly_totals' => $this->totals($detail->value->monthly, $detail->value->approximate ? '≈ ' : ''),
        ];
    }

    private function detail(string $id): ?DealDetail
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        try {
            return $this->reads->deal($id);
        } catch (SalesRefused) {
            return null;
        }
    }

    /**
     * @param array<string, Money> $amounts by currency code
     *
     * @return list<array{currency: string, amount: string}>
     */
    private function totals(array $amounts, string $before): array
    {
        $rows = [];
        foreach ($amounts as $code => $amount) {
            $rows[] = ['currency' => $code, 'amount' => $before.$this->screen->money($amount)];
        }

        return $rows;
    }

    /**
     * @param string|list<array<string, string>> $example
     */
    private function definition(string $name, string|array $example): VariableDefinition
    {
        return new VariableDefinition($name, $this->translator->trans('sales.document_variable.deal.'.$name), $example);
    }
}
