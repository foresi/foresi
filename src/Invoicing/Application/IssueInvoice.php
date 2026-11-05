<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceChange;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VariableSymbol;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use Psr\Clock\ClockInterface;

/**
 * Saving a new invoice issues it: everything is read and checked first, then one
 * transaction writes the number, the frozen copies, the lines and the totals. The
 * first write is the counter, because a transaction that read first can fail on
 * SQLite when it starts to write. Losing to another save starts again at loading.
 * The same transaction makes the contact a customer of the firm.
 *
 * An origin, such as an occurrence of a recurring template, finishes the texts once
 * the number is known and records itself in the same transaction; when it refuses,
 * nothing is stored. Each retry asks it again.
 */
final readonly class IssueInvoice
{
    public function __construct(
        private LegalEntities $firms,
        private SeriesCatalog $catalog,
        private PartyQuery $parties,
        private IssueRules $rules,
        private CustomerCopies $copies,
        private LinePricing $pricing,
        private InvoicingCountries $countries,
        private FirmInvoicingSettings $settings,
        private InvoiceStore $store,
        private NumberAllocator $numbers,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
        private ContactSettingsForFirm $contacts,
    ) {
    }

    /**
     * @param IssueOrigin|null $origin what issues the invoice, when not a person's form
     *
     * @throws IssueRefused       when a rule refuses; nothing is stored and no number is consumed
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function issue(InvoiceInput $input, ?IssueOrigin $origin = null): Invoice
    {
        return $this->retry->run(function () use ($input, $origin): Invoice {
            $firm = $this->firms->get($input->firmId);
            $problems = $this->rules->check($input, $firm);
            if ([] !== $problems) {
                throw new IssueRefused($problems);
            }
            $party = $this->parties->find($input->customerId) ?? throw new \LogicException('IssueRules refuses an unknown customer.');
            $series = null === $input->seriesId ? $this->catalog->defaultFor($firm, $input->kind) : $this->catalog->get($input->seriesId);
            $customer = $this->copies->for($input, $party, $firm, null) ?? throw new \LogicException('IssueRules refuses a customer without an address.');
            $settings = $this->settings->for($firm);
            $regime = VatRegime::of($firm->vatStatus());
            $issuer = DocumentParty::ofIssuer($firm, $settings);
            $content = $input->change($party, $customer);

            return $this->work->run(function () use ($input, $firm, $series, $regime, $settings, $issuer, $content, $party, $origin): Invoice {
                $invoice = $this->write($input, $firm, $series, $regime, $settings->rounding(), $issuer, $content, $origin);
                $this->contacts->makeCustomer($party, $firm);
                $origin?->recordIn($invoice);

                return $invoice;
            });
        });
    }

    private function write(InvoiceInput $input, LegalEntity $firm, Series $series, VatRegime $regime, TotalRounding $rounding, DocumentParty $issuer, InvoiceChange $content, ?IssueOrigin $origin): Invoice
    {
        // A default series that is not stored yet goes first: the counter refers to it.
        if (null === $input->seriesId) {
            $this->catalog->store($series);
        }
        $number = $this->numbers->allocate($series, $firm, $input->issueDate, $input->typedNumber, null);
        if ($input->variableSymbolFollowsNumber) {
            $content = $content->withVariableSymbol(VariableSymbol::fromNumber($number));
        }
        $text = static fn (string $text): string => $origin?->withNumber($text, $number) ?? $text;
        $content = $content->withTexts($text);
        // The rules saw the order number before it held the number.
        if (mb_strlen($content->orderNumber) > Invoice::MAX_ORDER_NUMBER_LENGTH) {
            throw new IssueRefused([new IssueProblem('orderNumber', 'invoice.problem.too_long')]);
        }
        $country = $this->countries->for($firm->address()->countryCode);
        $home = $country->homeCurrency();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $invoice = new Invoice($firm, $series, $number, $regime, $rounding, $home, $issuer, $content, $this->actor->userId(), $now);
        $this->store->add($invoice);
        $lines = $this->pricing->priced($input->lines, $regime, $country, $content->taxPointDate);
        foreach ($lines as $position => $line) {
            $this->store->addLine(new InvoiceLine($invoice, $position, $text($line->input->text), $line->input->unit, $line->input->rateKind, $line->input->supplyCode, $line->values, $line->amounts));
        }
        $invoice->assignTotals($this->pricing->totals($lines, $regime, $rounding, $country));

        return $invoice;
    }
}
