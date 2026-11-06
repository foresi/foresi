<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\DocumentTotals;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceChange;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Series;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Stores a document another system issued as a document of the firm, marked as
 * imported. It is checked by the rules of issuing, see IssueRules for what an import
 * is spared, and priced by Foresi from its lines: only when that comes to the totals
 * of the source to the minor unit is it stored, so what Foresi shows and reports is
 * what the customer got. It keeps the number, the dates, the issuer copy and the
 * moment of creation it had there, and its delivered and export marks; nothing is
 * sent and no reminder is planned, as reminders follow sends.
 *
 * A source and an id there are imported once: the second time answers that it was,
 * and changes nothing. Only an invoice or an advance invoice stands alone to be
 * imported; tax documents on payment and corrections follow other documents.
 *
 * Each document is its own unit, under an audit correlation of its own, or the one
 * of the import running it.
 */
final readonly class ImportIssuedDocument
{
    public function __construct(
        private LegalEntities $firms,
        private SeriesCatalog $catalog,
        private PartyQuery $parties,
        private IssueRules $rules,
        private CustomerCopies $copies,
        private LinePricing $pricing,
        private InvoicingCountries $countries,
        private InvoiceStore $store,
        private ImportedDocuments $imported,
        private OriginalDocuments $originals,
        private NumberAllocator $numbers,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
        private ContactSettingsForFirm $contacts,
        private TenantContext $tenants,
    ) {
    }

    /**
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function import(ImportedDocumentInput $import): ImportedDocumentResult
    {
        return $this->retry->run(function () use ($import): ImportedDocumentResult {
            $input = $import->document;
            $firm = $this->firms->get($input->firmId);
            $known = $this->imported->find($firm, $import->source, $import->externalId);
            if ($known instanceof Invoice) {
                return ImportedDocumentResult::alreadyImported($known->id());
            }
            if (!$input->kind->standsAlone()) {
                return ImportedDocumentResult::refused([new IssueProblem('document', 'invoice.problem.import_kind_unsupported')]);
            }
            $problems = $this->rules->checkImported($import, $firm);
            if ([] !== $problems) {
                return ImportedDocumentResult::refused($problems);
            }
            $party = $this->parties->find($input->customerId) ?? throw new \LogicException('IssueRules refuses an unknown customer.');
            $series = $this->catalog->get($input->seriesId ?? throw new \LogicException('IssueRules refuses an import without a series.'));
            $customer = $this->copies->for($input, $party, $firm, null) ?? throw new \LogicException('IssueRules refuses a customer without an address.');
            $content = $input->change($party, $customer, $series->kind());
            $country = $this->countries->for($import->issuer->address->countryCode);
            $lines = $this->pricing->priced($input->lines, $import->regime, $country, $content->rateDate());
            $totals = $this->pricing->totals($lines, $import->regime, $import->rounding, $country);
            $computed = ImportedTotals::of($totals);
            if (!$computed->equals($import->expected)) {
                return ImportedDocumentResult::mismatch($import->expected, $computed);
            }

            try {
                return ImportedDocumentResult::imported($this->stored($import, $firm, $series, $content, $lines, $country->homeCurrency(), $totals));
            } catch (IssueRefused $refused) {
                return ImportedDocumentResult::refused($refused->problems);
            }
        });
    }

    /**
     * @param list<PricedLine> $lines
     *
     * @return string the id of the stored document
     *
     * @throws IssueRefused when someone took the number meanwhile
     */
    private function stored(ImportedDocumentInput $import, LegalEntity $firm, Series $series, InvoiceChange $content, array $lines, Currency $home, DocumentTotals $totals): string
    {
        $tenant = $this->tenants->current()->toString();
        $invoice = null;
        try {
            return $this->work->run(function () use ($import, $firm, $series, $content, $lines, $home, $totals, &$invoice, $tenant): string {
                $input = $import->document;
                $number = $this->numbers->allocate($series, $firm, $input->issueDate, $input->typedNumber, null);
                $invoice = new Invoice($firm, $series, $number, $import->regime, $import->rounding, $home, $import->issuer, $content, $this->actor->userId(), $import->createdAt);
                $this->store->add($invoice);
                foreach ($lines as $position => $line) {
                    $this->store->addLine(new InvoiceLine($invoice, $position, $line->input->text, $line->input->unit, $line->input->rateKind, $line->input->supplyCode, $line->values, $line->amounts, $line->input->deducts));
                }
                $invoice->assignTotals($totals);
                $invoice->importedFrom($import->source, $import->externalId, $import->runId, \DateTimeImmutable::createFromInterface($this->clock->now()), $import->deliveredAt, $import->exportedAt);
                if (null !== $import->originalPdf) {
                    $invoice->keepOriginal($this->originals->store($tenant, $invoice->id(), $import->originalPdf), hash('sha256', $import->originalPdf));
                }
                $this->contacts->makeCustomer($content->customerParty, $firm);

                return $invoice->id();
            });
        } catch (\Throwable $failure) {
            // What was written beside the database goes with the transaction that failed.
            if ($invoice instanceof Invoice && null !== $import->originalPdf) {
                $this->originals->remove($tenant, $invoice->id());
            }

            throw $failure;
        }
    }
}
