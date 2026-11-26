<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\Invoicing;

use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\Api\CreateDealStageInput;
use Foresi\Deals\Application\Api\LinkBillingEntryInput;
use Foresi\Deals\Application\Api\UnlinkBillingEntryInput;
use Foresi\Deals\Application\Api\UpdateBillingEntryInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Application\BillingEntryView;
use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Deals\Application\DealLineWrites;
use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Application\DealWrites;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Application\StageWrites;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Integration\CashFlow\BillingPlanFlows;
use Foresi\Deals\Integration\Invoicing\UnfulfilOnInvoiceTrashed;
use Foresi\Deals\Integration\Invoicing\UnfulfilOnRecurringInvoiceDeleted;
use Foresi\Deals\Integration\Invoicing\UnfulfilOnRecurringInvoiceEnded;
use Foresi\Events\Infrastructure\Relay\EventListeners;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Event\RecurringInvoiceEnded;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Contract\Event\RecordedEvent;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * The MoneyCoin case: a won deal whose recurring line a recurring invoice
 * already bills. Linked to it, the entry yields no flow, so nothing is counted
 * twice; once the recurring invoice ends, the event unlinks it and the entry
 * counts again.
 */
final class BillingPlanFulfilmentTest extends CashFlowTestCase
{
    private Series $series;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm->update('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->em->persist($this->series);
        $this->em->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em->flush();
    }

    public function testALinkedRecurringEntryYieldsNoFlowUntilItsRecurringInvoiceEnds(): void
    {
        $template = $this->recurring('2026-11-23', 'SEO example.com – Customer');
        $deal = $this->deal('Ongoing SEO, Growth package');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'SEO', unitPrice: '14000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($deal->id()));
        $entry = $this->entry($deal);
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $entry->id(), expectedOn: '2026-11-23'));
        self::assertNotSame([], $this->flows(), 'Unlinked, the entry would count every month next to the recurring invoice.');

        $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', $template->id()));

        self::assertSame([], $this->flows(), 'Linked, only the recurring invoice is counted.');
        $view = self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan->entries[0];
        self::assertSame(BillingEntryView::INVOICED, $view->state);
        self::assertSame('SEO example.com – Customer', $view->document?->label);
        self::assertSame([['entry_id' => $entry->id(), 'deal_id' => $deal->id(), 'kind' => 'recurring', 'document_type' => 'recurring_invoice', 'document_id' => $template->id()]], $this->events('sales.billing_entry.fulfilled'));
        // A document fulfils one entry.
        $other = $this->deal('More SEO');
        $this->lines()->add(new AddDealLineInput($other->id(), name: 'SEO', unitPrice: '1000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($other->id()));
        $this->assertRefused('document_taken', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($other->id(), $this->entry($other)->id(), 'recurring_invoice', $template->id())));

        // Ending it records the event; its listener unlinks the entry, once.
        self::getContainer()->get(RecurringLifecycle::class)->end($template->id());
        self::getContainer()->get(RecurringLifecycle::class)->end($template->id());
        self::assertCount(1, $this->events('invoicing.recurring_invoice.ended'));
        $listener = self::getContainer()->get(UnfulfilOnRecurringInvoiceEnded::class);
        $event = new RecordedEvent(Id::generate(), UnfulfilOnRecurringInvoiceEnded::EVENT, 1, new \DateTimeImmutable(), null, new RecurringInvoiceEnded($template->id()));
        $listener($event);
        $listener($event);

        self::assertNotSame([], $this->flows(), 'The entry counts again.');
        self::assertCount(1, $this->events('sales.billing_entry.unfulfilled'));
        // An ended recurring invoice is linked no more.
        $this->assertRefused('document_inactive', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', $template->id())));
    }

    public function testUnlinkingByHandAndTheListeners(): void
    {
        $template = $this->recurring('2026-12-01', 'Hosting');
        $deal = $this->deal('Hosting');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Hosting', unitPrice: '500.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($deal->id()));
        $entry = $this->entry($deal);
        $this->assertRefused('document_type_mismatch', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'invoice', $template->id())));
        $stranger = new Party(PartyKind::Company, 'Other Example a.s.', new RegistrationNumber('CZ', '25596641'), null, '');
        $this->em->persist($stranger);
        $this->em->flush();
        $this->assertRefused('document_other_client', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', $this->recurring('2026-12-01', 'Other', $stranger)->id())));

        $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', $template->id()));
        $this->plans()->unlink(new UnlinkBillingEntryInput($deal->id(), $entry->id()));
        $this->plans()->unlink(new UnlinkBillingEntryInput($deal->id(), $entry->id()));
        self::assertFalse($this->entry($deal)->isFulfilled());
        self::assertCount(1, $this->events('sales.billing_entry.unfulfilled'), 'Unlinking twice records once.');

        $listeners = self::getContainer()->get(EventListeners::class);
        self::assertContains(UnfulfilOnInvoiceTrashed::class, $listeners->idsFor(UnfulfilOnInvoiceTrashed::EVENT));
        self::assertContains(UnfulfilOnRecurringInvoiceEnded::class, $listeners->idsFor(UnfulfilOnRecurringInvoiceEnded::EVENT));
        self::assertContains(UnfulfilOnRecurringInvoiceDeleted::class, $listeners->idsFor(UnfulfilOnRecurringInvoiceDeleted::EVENT));

        // Deleting a recurring invoice that never issued records its event too.
        $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', $template->id()));
        self::getContainer()->get(RecurringLifecycle::class)->delete($template->id());
        self::assertSame([['recurring_invoice_id' => $template->id()]], $this->events('invoicing.recurring_invoice.deleted'));
    }

    public function testAnInvoiceOfTheClientFulfilsOneOneOffEntry(): void
    {
        $deal = $this->deal('E-shop audit');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Audit', unit: 'piece', quantity: '1', unitPrice: '10000.00', currency: 'CZK'));
        $this->deals()->win(new WinDealInput($deal->id()));
        $entry = $this->entry($deal);
        $invoice = $this->invoice('Audit of the e-shop');

        $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'invoice', $invoice));

        $plan = self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan;
        self::assertSame(BillingEntryView::INVOICED, $plan->entries[0]->state);
        self::assertSame($invoice, $plan->entries[0]->document?->id);
        self::assertSame([], $plan->notInvoiced(), 'What an invoice fulfils is invoiced.');
        self::assertSame([], $this->flows());

        $other = $this->deal('Second audit');
        $this->lines()->add(new AddDealLineInput($other->id(), name: 'Audit', unit: 'piece', quantity: '1', unitPrice: '5000.00', currency: 'CZK'));
        $this->deals()->win(new WinDealInput($other->id()));
        try {
            $this->plans()->link(new LinkBillingEntryInput($other->id(), $this->entry($other)->id(), 'invoice', $invoice));
            self::fail('Expected the invoice to be taken.');
        } catch (SalesRefused $refused) {
            self::assertSame(['document_taken', ['deal_id' => $deal->id(), 'entry_id' => $entry->id()]], [$refused->reason(), $refused->details()]);
        }
    }

    /**
     * The flows and undated flows the billing plan gives the forecast.
     *
     * @return list<ExpectedFlow|UndatedFlow>
     */
    private function flows(): array
    {
        $source = self::getContainer()->get(BillingPlanFlows::class);
        $today = new \DateTimeImmutable('2026-11-17');

        return array_values([...$source->flows($today, $today->modify('+12 months'), $today), ...$source->undatedFlows($today)]);
    }

    private function invoice(string $description): string
    {
        $input = new \ReflectionClass(InvoiceInput::class)->newInstanceArgs([
            'seriesId' => $this->series->id(),
            'typedNumber' => null,
            'customerId' => $this->customer->id(),
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable('2026-11-17'),
            'taxPointDate' => new \DateTimeImmutable('2026-11-17'),
            'dueDate' => new \DateTimeImmutable('2026-12-01'),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '',
            'constantSymbol' => '0308',
            'orderNumber' => '',
            'description' => $description,
            'textBefore' => '',
            'textAfter' => '',
            'language' => DocumentLanguage::Czech,
            'lines' => [new LineInput($description, Quantity::fromString('1'), 'piece', Money::of('10000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
        ]);
        $id = self::getContainer()->get(IssueInvoice::class)->issue($input)->id();
        $this->em->flush();

        return $id;
    }

    private function recurring(string $firstIssueOn, string $name, ?Party $customer = null): RecurringInvoice
    {
        $input = new \ReflectionClass(RecurringInput::class)->newInstanceArgs([
            'seriesId' => $this->series->id(),
            'customerId' => ($customer ?? $this->customer)->id(),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'constantSymbol' => '0308',
            'orderNumber' => '',
            'description' => $name,
            'textBefore' => '',
            'textAfter' => '',
            'language' => DocumentLanguage::Czech,
            'lines' => [new LineInput($name, Quantity::fromString('1'), 'month', Money::of('14000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
            'recurrence' => new Recurrence(new \DateTimeImmutable($firstIssueOn), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null),
            'taxPoint' => TaxPointRule::IssueDate,
            'dueDays' => 14,
            'delivery' => RecurringDelivery::Send,
            'name' => $name,
        ]);
        $template = self::getContainer()->get(SaveRecurringInvoice::class)->create($input);
        $this->em->flush();

        return $template;
    }

    private function entry(Deal $deal): BillingPlanEntry
    {
        return self::getContainer()->get(DealReads::class)->deal($deal->id())->billingPlan->entries[0]->entry;
    }

    private function deal(string $title): Deal
    {
        $stage = self::getContainer()->get(StageWrites::class)->create(new CreateDealStageInput('Inquiry', 'inquiry_'.substr(md5($title), 0, 6)));

        return $this->deals()->create(new CreateDealInput($title, $this->customer->id(), $stage->id()));
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (SalesRefused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());

            return;
        }
        self::fail(\sprintf('Expected the refusal "%s".', $reason));
    }

    private function deals(): DealWrites
    {
        return self::getContainer()->get(DealWrites::class);
    }

    private function lines(): DealLineWrites
    {
        return self::getContainer()->get(DealLineWrites::class);
    }

    private function plans(): BillingPlanWrites
    {
        return self::getContainer()->get(BillingPlanWrites::class);
    }
}
