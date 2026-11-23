<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\CopyInvoice;
use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingAccess;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\IssueAfterTodaysRun;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\NewInvoiceDefaults;
use Foresi\Invoicing\Application\PreviewRow;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInput;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Application\RecurringPreview;
use Foresi\Invoicing\Application\RecurringRefused;
use Foresi\Invoicing\Application\RecurringRules;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnknownRecurringInvoice;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Schedule\InvalidRecurrence;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * Recurring invoices over the API and MCP. It reads the text of the inputs into
 * a RecurringInput, fills what is left out as the recurring form does (its
 * defaults for a new template, CopyInvoice for one made from an invoice, the
 * stored template for a change), and calls the use cases of the form and the
 * list: SaveRecurringInvoice, RecurringPreview and RecurringLifecycle. Every rule
 * stays theirs; what RecurringRules refuses leaves here as RecurringRefused, for
 * the caller to word. A save or a resume with a date due today hands it to
 * IssueAfterTodaysRun, as the screens do.
 */
final readonly class RecurringWrites
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public function __construct(
        private InvoicingAccess $access,
        private SaveRecurringInvoice $save,
        private RecurringLifecycle $lifecycle,
        private RecurringPreview $preview,
        private RecurringRules $rules,
        private RecurringInvoices $templates,
        private InvoiceStore $invoices,
        private CopyInvoice $copies,
        private NewInvoiceDefaults $defaults,
        private CustomerPicker $customers,
        private InstanceFirm $instance,
        private InvoicingCountries $countries,
        private InstanceClock $clock,
        private IssueAfterTodaysRun $afterTodaysRun,
    ) {
    }

    /**
     * @return string the id of the template
     *
     * @throws RecurringRefused when a rule refuses; nothing is stored
     * @throws InvoicingRefused
     */
    public function create(CreateRecurringInvoiceInput $input): string
    {
        $id = $this->save->create($this->inputOf($input))->id();
        $this->afterTodaysRun->afterChange($id);

        return $id;
    }

    /**
     * @throws RecurringRefused when a rule refuses, when it ended or is in the trash, or when it was saved since $version
     * @throws InvoicingRefused
     */
    public function update(UpdateRecurringInvoiceInput $input): string
    {
        $template = $this->template($input->recurringInvoiceId);
        $stored = $this->inputOfTemplate($template);
        $customerId = $this->id($input->customerId, 'customerId') ?? $stored->customerId;
        $lines = null === $input->lines
            ? $stored->lines
            : ApiLines::read($input->lines, $template->currency(), $this->regime(), $this->reverseCharge($customerId));
        $recurrence = $stored->recurrence;
        $end = null === $input->end ? $recurrence->end() : RecurrenceEnd::from($input->end);

        $changed = new RecurringInput(
            null === $input->seriesId ? $stored->seriesId : $this->id($input->seriesId, 'seriesId'),
            $customerId,
            null === $input->paymentMethod ? $stored->paymentMethod : PaymentMethod::from($input->paymentMethod),
            $input->bankAccount ?? $stored->bankAccount,
            $input->constantSymbol ?? $stored->constantSymbol,
            $input->orderNumber ?? $stored->orderNumber,
            $input->description ?? $stored->description,
            $input->textBefore ?? $stored->textBefore,
            $input->textAfter ?? $stored->textAfter,
            null === $input->language ? $stored->language : DocumentLanguage::from($input->language),
            $lines,
            $this->recurrence(
                $this->date($input->firstIssueOn, 'firstIssueOn') ?? $recurrence->firstIssueOn(),
                null === $input->repeat ? $recurrence->unit() : RecurrenceUnit::from($input->repeat),
                $input->every ?? $recurrence->every(),
                $input->lastDayOfMonth ?? $recurrence->onLastDayOfMonth(),
                $end,
                // What the stored end needed goes when another end is chosen.
                RecurrenceEnd::OnDate === $end ? ($this->date($input->endsOn, 'endsOn') ?? $recurrence->endOn()) : $this->date($input->endsOn, 'endsOn'),
                RecurrenceEnd::AfterCount === $end ? ($input->endsAfter ?? $recurrence->endAfter()) : $input->endsAfter,
            ),
            null === $input->taxPoint ? $stored->taxPoint : TaxPointRule::from($input->taxPoint),
            $input->dueDays ?? $stored->dueDays,
            null === $input->delivery ? $stored->delivery : RecurringDelivery::from($input->delivery),
            $input->name ?? $stored->name,
            $input->copyToMe ?? $stored->copyToMe,
            $input->copyToOthers ?? $stored->copyToOthers,
        );
        $this->save->edit($template->id(), $input->version, $changed);
        $this->afterTodaysRun->afterChange($template->id());

        return $template->id();
    }

    /**
     * @throws RecurringRefused once ended, or in the trash
     * @throws InvoicingRefused
     */
    public function pause(PauseRecurringInvoiceInput $input): string
    {
        $id = $this->template($input->recurringInvoiceId)->id();
        $this->lifecycle->pause($id);

        return $id;
    }

    /**
     * @return array{string, list<\DateTimeImmutable>} the id and the dates the pause skipped, oldest first
     *
     * @throws RecurringRefused once ended, or in the trash
     * @throws InvoicingRefused
     */
    public function resume(ResumeRecurringInvoiceInput $input): array
    {
        $id = $this->template($input->recurringInvoiceId)->id();
        $skipped = $this->lifecycle->resume($id, $this->clock->today());
        $this->afterTodaysRun->afterChange($id);

        return [$id, $skipped];
    }

    /**
     * @throws RecurringRefused in the trash
     * @throws InvoicingRefused
     */
    public function end(EndRecurringInvoiceInput $input): string
    {
        $id = $this->template($input->recurringInvoiceId)->id();
        $this->lifecycle->end($id);

        return $id;
    }

    /**
     * What a create with these fields would save and issue, without saving: the
     * template as it would be, its next issues, and what the rules would refuse.
     *
     * @throws InvoicingRefused when a field cannot be read
     */
    public function preview(CreateRecurringInvoiceInput $input, int $count): RecurringDraft
    {
        $draft = $this->inputOf($input);
        $today = $this->clock->today();

        return new RecurringDraft($draft, $this->preview->next($draft, 0, $today, $count), $this->rules->check($draft, null, $today));
    }

    /**
     * The template as stored now, for the staff of the organisation.
     *
     * @throws InvoicingRefused
     */
    public function template(string $id): RecurringInvoice
    {
        $this->allowed();
        try {
            return $this->templates->current($this->id($id, 'recurringInvoiceId') ?? '');
        } catch (UnknownRecurringInvoice $unknown) {
            throw InvoicingRefused::unknownRecurring($unknown);
        }
    }

    /**
     * A page of the templates out of the trash, the soonest next issue first, as the list shows them.
     *
     * @throws InvoicingRefused
     */
    public function page(?RecurringState $state, int $page): RecurringPage
    {
        $this->allowed();

        return $this->templates->page(new RecurringFilter($state, false, max(1, $page)));
    }

    /**
     * @return list<RecurringInvoiceLine> in the order of the template
     */
    public function lines(RecurringInvoice $template): array
    {
        return $this->templates->lines($template);
    }

    /**
     * The next invoices an active template issues, as its form previews them,
     * from its next date on; none while it is paused, ended or in the trash.
     *
     * @return list<PreviewRow>
     */
    public function nextIssues(RecurringInvoice $template, int $count): array
    {
        $next = $template->nextOccurrence();
        if (RecurringState::Active !== $template->state() || $template->isTrashed() || !$next instanceof \DateTimeImmutable) {
            return [];
        }
        $rows = $this->preview->next($this->inputOfTemplate($template), $template->issuedCount(), $this->clock->today(), $count + 1);
        // The preview starts today; an occurrence issued today already is not next.
        $rows = array_values(array_filter($rows, static fn (PreviewRow $row): bool => $row->issue->format('Y-m-d') >= $next->format('Y-m-d')));

        return \array_slice($rows, 0, $count);
    }

    /**
     * @throws InvoicingRefused
     */
    private function inputOf(CreateRecurringInvoiceInput $input): RecurringInput
    {
        $this->allowed();
        $firm = $this->firm();
        $today = $this->clock->today();
        $currency = $this->currency($firm);
        $base = null === $input->fromInvoiceId ? $this->fresh($input, $today) : $this->copied($input->fromInvoiceId);
        $customerId = $this->id($input->customerId, 'customerId') ?? $base->customerId;
        $lines = null === $input->lines ? $base->lines : ApiLines::read($input->lines, $currency, $this->regime(), $this->reverseCharge($customerId));

        return new RecurringInput(
            null === $input->seriesId ? $base->seriesId : $this->id($input->seriesId, 'seriesId'),
            $customerId,
            null === $input->paymentMethod ? $base->paymentMethod : PaymentMethod::from($input->paymentMethod),
            $input->bankAccount ?? $base->bankAccount,
            $input->constantSymbol ?? $base->constantSymbol,
            $input->orderNumber ?? $base->orderNumber,
            $input->description ?? ('' !== $base->description ? $base->description : ($lines[0]->text ?? '')),
            $input->textBefore ?? $base->textBefore,
            $input->textAfter ?? $base->textAfter,
            null === $input->language ? $base->language : DocumentLanguage::from($input->language),
            $lines,
            $this->recurrence(
                $this->date($input->firstIssueOn, 'firstIssueOn') ?? $today,
                RecurrenceUnit::from($input->repeat),
                $input->every,
                $input->lastDayOfMonth,
                RecurrenceEnd::from($input->end),
                $this->date($input->endsOn, 'endsOn'),
                $input->endsAfter,
            ),
            TaxPointRule::from($input->taxPoint),
            $input->dueDays ?? $base->dueDays,
            RecurringDelivery::from($input->delivery),
            $input->name ?? $base->name,
            $input->copyToMe,
            $input->copyToOthers,
        );
    }

    /**
     * What the form opens with for a new template, for the customer sent.
     *
     * @throws InvoicingRefused
     */
    private function fresh(CreateRecurringInvoiceInput $input, \DateTimeImmutable $today): RecurringInput
    {
        $customerId = $this->id($input->customerId, 'customerId')
            ?? throw InvoicingRefused::unreadable('customer_missing', 'customerId', 'A recurring invoice needs customer_id, or from_invoice_id to start from an invoice.');
        if (null === $input->lines) {
            throw InvoicingRefused::unreadable(InvoicingRefused::LINE, 'lines', 'A recurring invoice needs its lines, or from_invoice_id to start from an invoice.');
        }
        try {
            $draft = $this->defaults->for($today);
        } catch (UnsupportedCountry $unsupported) {
            throw InvoicingRefused::conflict('firm_country_unsupported', 'The country of the firm has no invoicing rules in Foresi.', $unsupported);
        }
        $customer = $this->customers->find($customerId);
        if ($customer instanceof \Foresi\Invoicing\Application\CustomerOption) {
            $draft = $this->defaults->withCustomer($draft, $customer);
        }

        return $this->base($customerId, $draft->seriesId, $draft->paymentMethod, $draft->bankAccount, $draft->constantSymbol, '', $draft->description, $draft->textBefore, $draft->textAfter, $draft->language, [], $this->days($draft->issueDate, $draft->dueDate), '');
    }

    /**
     * What "Make recurring" opens with for an issued invoice (RecurringFormValues::ofInvoice).
     *
     * @throws InvoicingRefused
     */
    private function copied(string $invoiceId): RecurringInput
    {
        try {
            $source = $this->invoices->get($this->id($invoiceId, 'fromInvoiceId') ?? '');
            $draft = $this->copies->valuesFrom($source);
        } catch (UnknownInvoice $unknown) {
            throw InvoicingRefused::notFound($unknown);
        } catch (UnsupportedCountry $unsupported) {
            throw InvoicingRefused::conflict('firm_country_unsupported', 'The country of the firm has no invoicing rules in Foresi.', $unsupported);
        }
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $draft->description) ?? ''), 0, RecurringInvoice::MAX_NAME_LENGTH);

        return $this->base(
            $draft->customerId ?? $source->customerParty()->id(),
            $draft->seriesId,
            $draft->paymentMethod,
            $draft->bankAccount,
            $draft->constantSymbol,
            $source->orderNumber(),
            $draft->description,
            $draft->textBefore,
            $draft->textAfter,
            $draft->language,
            $draft->lines,
            $this->days($source->issueDate(), $source->dueDate()),
            $name,
        );
    }

    /**
     * The fields a template takes from where it starts, on a schedule that is replaced.
     *
     * @param list<LineInput> $lines
     */
    private function base(string $customerId, ?string $seriesId, PaymentMethod $paymentMethod, ?string $bankAccount, string $constantSymbol, string $orderNumber, string $description, string $textBefore, string $textAfter, DocumentLanguage $language, array $lines, int $dueDays, string $name): RecurringInput
    {
        $placeholder = new Recurrence($this->clock->today(), RecurrenceUnit::Month, 1, false, RecurrenceEnd::Never, null, null);

        return new RecurringInput($seriesId, $customerId, $paymentMethod, $bankAccount, $constantSymbol, $orderNumber, $description, $textBefore, $textAfter, $language, $lines, $placeholder, TaxPointRule::IssueDate, $dueDays, RecurringDelivery::Review, $name);
    }

    /**
     * The template as its edit form opens with it (RecurringFormValues::ofTemplate).
     */
    private function inputOfTemplate(RecurringInvoice $template): RecurringInput
    {
        $lines = array_map(static fn (RecurringInvoiceLine $line): LineInput => new LineInput($line->text(), $line->quantity(), $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount()), $this->templates->lines($template));

        return new RecurringInput(
            $template->series()?->id(),
            $template->customer()->id(),
            $template->paymentMethod(),
            $template->bankAccount(),
            $template->constantSymbol(),
            $template->orderNumber(),
            $template->description(),
            $template->textBefore(),
            $template->textAfter(),
            $template->language(),
            $lines,
            $template->recurrence(),
            $template->taxPoint(),
            $template->dueDays(),
            $template->delivery(),
            $template->name(),
            $template->copyToMe(),
            $template->copyToOthers(),
        );
    }

    /**
     * The schedule as the form reads it: the dates in the instance's zone, the last
     * day of the month only for months and years.
     *
     * @throws InvoicingRefused
     */
    private function recurrence(\DateTimeImmutable $first, RecurrenceUnit $unit, int $every, bool $lastDay, RecurrenceEnd $end, ?\DateTimeImmutable $endsOn, ?int $endsAfter): Recurrence
    {
        if ($every < 1 || $every > Recurrence::MAX_EVERY) {
            throw InvoicingRefused::unreadable('every_invalid', 'every', \sprintf('every is a whole number from 1 to %d.', Recurrence::MAX_EVERY));
        }
        if (RecurrenceEnd::OnDate !== $end && $endsOn instanceof \DateTimeImmutable) {
            throw InvoicingRefused::unreadable('end_invalid', 'endsOn', 'ends_on is only for end on_date.');
        }
        if (RecurrenceEnd::AfterCount !== $end && null !== $endsAfter) {
            throw InvoicingRefused::unreadable('end_invalid', 'endsAfter', 'ends_after is only for end after_count.');
        }
        if (RecurrenceEnd::OnDate === $end && !$endsOn instanceof \DateTimeImmutable) {
            throw InvoicingRefused::unreadable('end_on_missing', 'endsOn', 'end on_date needs ends_on, YYYY-MM-DD.');
        }
        if (RecurrenceEnd::AfterCount === $end && (null === $endsAfter || $endsAfter < 1 || $endsAfter > Recurrence::MAX_END_AFTER)) {
            throw InvoicingRefused::unreadable('end_after_invalid', 'endsAfter', \sprintf('end after_count needs ends_after, a whole number from 1 to %d.', Recurrence::MAX_END_AFTER));
        }
        $zone = $this->clock->today()->getTimezone();
        $inZone = static fn (\DateTimeImmutable $day): \DateTimeImmutable => new \DateTimeImmutable($day->format('Y-m-d'), $zone);
        $lastDay = $lastDay && \in_array($unit, [RecurrenceUnit::Month, RecurrenceUnit::Year], true);
        try {
            return new Recurrence($inZone($first), $unit, $every, $lastDay, $end, $endsOn instanceof \DateTimeImmutable ? $inZone($endsOn) : null, $endsAfter);
        } catch (InvalidRecurrence $invalid) {
            throw InvoicingRefused::unreadable('end_on_before_first', 'endsOn', $invalid->getMessage());
        }
    }

    private function allowed(): void
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (InvoicingNotAllowed $denied) {
            throw InvoicingRefused::forbidden($denied);
        }
    }

    private function firm(): LegalEntity
    {
        try {
            return $this->instance->get();
        } catch (NoFirmYet $unknown) {
            throw InvoicingRefused::unknownFirm($unknown);
        }
    }

    private function currency(LegalEntity $firm): Currency
    {
        try {
            return $this->countries->for($firm->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry $unsupported) {
            throw InvoicingRefused::conflict('firm_country_unsupported', 'The country of the firm has no invoicing rules in Foresi.', $unsupported);
        }
    }

    private function regime(): VatRegime
    {
        return VatRegime::of($this->firm()->vatStatus());
    }

    /**
     * Whether new lines of the customer default to reverse charge, as on the form.
     */
    private function reverseCharge(string $customerId): bool
    {
        return $this->customers->find($customerId)?->defaults->reverseCharge ?? false;
    }

    private function days(\DateTimeImmutable $issue, \DateTimeImmutable $due): int
    {
        return max(0, min(RecurringInvoice::MAX_DUE_DAYS, (int) $issue->diff($due)->format('%r%a')));
    }

    private function id(?string $id, string $field): ?string
    {
        if (null === $id) {
            return null;
        }
        if (1 !== preg_match(self::UUID, $id)) {
            throw InvoicingRefused::unreadable('id_invalid', $field, 'The id must be a UUID, as the read tools return it.');
        }

        return strtolower($id);
    }

    private function date(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw InvoicingRefused::unreadable(InvoicingRefused::DATE, $field, 'The day must be YYYY-MM-DD.');
        }

        return $day;
    }
}
