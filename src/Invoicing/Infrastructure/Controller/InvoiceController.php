<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\CustomerPicker;
use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownSeries;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\VariableSymbol;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Form\InvoiceFigures;
use App\Invoicing\Infrastructure\Form\InvoiceFormContext;
use App\Invoicing\Infrastructure\Form\InvoiceFormReader;
use App\Invoicing\Infrastructure\Form\InvoiceFormValues;
use App\Invoicing\Infrastructure\Form\InvoiceSubmission;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Countries;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoice form, for staff. Save issues: there is no draft. The number on a new
 * form is a preview, and the flash names the one the document really got. Every
 * form carries a one-time token, so a double click, or Back and Save, lands on the
 * saved document, and an edit carries the version it was opened at, so a save over
 * someone else's asks to reload. Actions other than saving re-render the form with
 * 422, which is how Turbo shows an answer to a submission in place.
 */
final class InvoiceController extends AbstractController
{
    public const string FORM_TOKEN = 'invoice_form';
    private const array SAVING = ['save', 'save_pdf'];
    private const array LINE_CELLS = ['rate' => 'vat', 'treatment' => 'vat'];

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly SeriesCatalog $catalog,
        private readonly NumberCounter $counter,
        private readonly InvoiceStore $store,
        private readonly FirmInvoicingSettings $settings,
        private readonly InvoicingCountries $countries,
        private readonly PartyQuery $parties,
        private readonly CustomerPicker $picker,
        private readonly ContactSettingsForFirm $contacts,
        private readonly NewInvoiceDefaults $defaults,
        private readonly IssueInvoice $issue,
        private readonly EditInvoice $edit,
        private readonly IssueRules $rules,
        private readonly InvoiceFormReader $reader,
        private readonly InvoiceFormValues $values,
        private readonly InvoiceFigures $figures,
        private readonly AmountText $amounts,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices/new', name: 'app_invoice_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        if ($request->isMethod('POST')) {
            return $this->submitNew($request);
        }

        $id = $request->query->getString('firm');
        $firm = '' === $id ? $this->defaults->firmFor($this->actor->userId()) : $this->firm($id);
        if (!$firm instanceof LegalEntity) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'no_firm']);
        }

        try {
            $context = $this->newContext($firm);
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'country']);
        }

        return $this->form($context, $this->values->ofDraft($this->defaults->for($firm)), [], [], $this->tokens->issue(), 0, Response::HTTP_OK);
    }

    #[Route('/invoices/{id}/edit', name: 'app_invoice_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        if ($request->isMethod('POST')) {
            return $this->submitEdit($invoice, $request);
        }

        return $this->form($this->storedContext($invoice), $this->values->ofInvoice($invoice), [], [], $this->tokens->issue(), $invoice->version(), Response::HTTP_OK);
    }

    #[Route('/invoices/recount', name: 'app_invoice_recount', methods: ['POST'])]
    public function recount(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        if (!$this->reader->isComplete($request)) {
            return $this->json(['errors' => ['form']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $invoiceId = $request->request->getString('invoiceId');
        try {
            $context = '' === $invoiceId ? $this->newContext($this->firm($request->request->getString('firmId'))) : $this->storedContext($this->invoice($invoiceId));
        } catch (UnsupportedCountry) {
            return $this->json(['errors' => ['firm']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lines = $this->reader->lines($request, $context->currency);
        $errors = $lines->errors;
        $taxPoint = $this->reader->date(trim($request->request->getString('taxPointDate')), 'taxPointDate', $errors);
        if ([] !== $errors) {
            return $this->json(['errors' => array_keys($errors)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($this->figures->of($lines->lines, $context, $taxPoint ?? $this->today($context)));
        } catch (\OverflowException|\DomainException) {
            return $this->json(['errors' => ['lines']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/invoices/next-number', name: 'app_invoice_next_number', methods: ['GET'])]
    public function nextNumber(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $errors = [];
        $date = $this->reader->date($request->query->getString('date'), 'date', $errors);
        $series = $this->seriesFor($request);
        if (!$date instanceof \DateTimeImmutable) {
            return $this->json(['errors' => ['date']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $number = $this->counter->peek($series, $date);

        return $this->json(['number' => $number, 'variableSymbol' => VariableSymbol::fromNumber($number)]);
    }

    private function submitNew(Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->reader->isComplete($request)) {
            return $this->cutOff($this->generateUrl('app_invoice_new'));
        }
        $token = $request->request->getString('_submission');
        $saved = $this->tokens->isIssued($token) ? $this->tokens->createdId($token) : null;
        if (null !== $saved) {
            return $this->alreadySaved($saved);
        }

        $firm = $this->firm($request->request->getString('firmId'));
        try {
            $context = $this->newContext($firm);
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'country']);
        }

        return $this->submit($request, $context, $token);
    }

    private function submitEdit(Invoice $invoice, Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->reader->isComplete($request)) {
            return $this->cutOff($this->generateUrl('app_invoice_edit', ['id' => $invoice->id()]));
        }
        $token = $request->request->getString('_submission');
        if ($this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            return $this->alreadySaved($invoice->id());
        }
        $request->request->set('firmId', $invoice->firm()->id());

        return $this->submit($request, $this->storedContext($invoice), $token);
    }

    /**
     * The end of the form never arrived, so nothing of it can be trusted to be whole.
     */
    private function cutOff(string $retry): Response
    {
        return $this->render('invoicing/form.html.twig', ['blocked' => 'cut_off', 'retry' => $retry], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function submit(Request $request, InvoiceFormContext $context, string $token): Response
    {
        // Only a button issues a document: a request without one changes nothing.
        $action = $request->request->getString('_action', 'refresh');
        $matches = $this->typedCustomers($request, $context->firm, $action);
        $submission = $this->reader->read($request, $context->currency);
        $values = $this->values->withoutHiddenCodes($this->withFormFields($submission->values, $context), $this->issuerCountry($context));

        if (1 === \count($matches)) {
            // A typed name is a pick, never a save: the defaults of the customer come first.
            $this->addFlash('warning', $this->translator->trans('invoice.customer.picked', ['name' => $matches[0]->name]));

            return $this->reshaped('pick:'.$matches[0]->id, $values, $submission, $context, $token);
        }
        $candidates = array_map(static fn (CustomerOption $option): array => ['id' => $option->id, 'name' => $option->name, 'hint' => $option->registrationNumber ?? ''], $matches);

        if (!\in_array($action, self::SAVING, true)) {
            return $this->reshaped($action, $values, $submission, $context, $token);
        }
        if (!$this->tokens->isIssued($token)) {
            // The token fell out of the session: nothing is lost, the next save goes through.
            $errors = [$this->message('form', 'party.error.expired')];

            return $this->form($context, $values, $errors, $candidates, $this->tokens->issue(), $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ([] !== $submission->errors) {
            $errors = $this->messages($submission->errors, $this->rules->check($submission->input, $context->firm, $context->invoice));

            return $this->form($context, $values, $errors, $candidates, $token, $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invoiceId = $context->invoice?->id();
        $ownNumber = $context->invoice?->number();
        $firmId = $context->firm->id();
        try {
            $saved = null === $invoiceId ? $this->issue->issue($submission->input) : $this->edit->edit($invoiceId, $submission->input, $submission->version);
        } catch (IssueRefused $refused) {
            return $this->refused($refused, $invoiceId, $firmId, $values, $candidates, $token, $submission->version);
        }

        $this->tokens->complete($token, $saved->id());
        $this->flashSaved($saved, $submission, $ownNumber);

        return $this->redirectToSaved($saved->id());
    }

    /**
     * A refused save leaves what was loaded detached, so the form starts from the
     * stored rows again. A stale save has one thing left to do, and says so.
     *
     * @param array<string, mixed>                                $values
     * @param list<array{id: string, name: string, hint: string}> $candidates
     */
    private function refused(IssueRefused $refused, ?string $invoiceId, string $firmId, array $values, array $candidates, string $token, int $version): Response
    {
        $context = null === $invoiceId ? $this->newContext($this->firm($firmId)) : $this->storedContext($this->invoice($invoiceId));
        $stale = array_any($refused->problems, static fn (IssueProblem $problem): bool => 'version' === $problem->field);

        return $this->form($context, $values, $stale ? [] : $this->messages([], $refused->problems), $candidates, $token, $version, $stale ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY, $stale);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function reshaped(string $action, array $values, InvoiceSubmission $submission, InvoiceFormContext $context, string $token): Response
    {
        $errors = [];
        if (str_starts_with($action, 'pick:')) {
            $party = $this->pickable(substr($action, 5));
            if ($party instanceof Party && !$this->contacts->isCustomerOf($party, $context->firm)) {
                $errors = [$this->message('customer', 'invoice.problem.customer_role')];
            } elseif ($party instanceof Party) {
                $values = $this->values->withCustomer($values, $party, $context->firm, $submission->input->issueDate, $context->regime, $this->issuerCountry($context));
            }
        } elseif ('refresh' === $action) {
            if (!$context->invoice instanceof Invoice && $values['firmId'] !== $values['firmShown']) {
                $values = $this->values->forFirm($values, $context->firm, $submission->input->issueDate);
                $this->addFlash('warning', $this->translator->trans('invoice.firm_changed'));
            }
        } else {
            $values = $this->values->withRowAction($values, $action, $context->regime, $this->newLinesAreReverseCharge($context, $values));
        }

        return $this->form($context, $values, $errors, [], $token, $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function pickable(string $id): ?Party
    {
        return Uuid::isValid($id) ? $this->parties->find($id) : null;
    }

    /**
     * The customers a name typed without a pick stands for.
     *
     * @return list<CustomerOption>
     */
    private function typedCustomers(Request $request, LegalEntity $firm, string $action): array
    {
        $query = trim($request->request->getString('customerQuery'));
        if (!\in_array($action, self::SAVING, true) || '' !== $request->request->getString('customerId') || '' === $query) {
            return [];
        }

        return $this->picker->search($query, $firm);
    }

    /**
     * New lines follow the customer on the form, as the lines there did on the pick.
     *
     * @param array<string, mixed> $values
     */
    private function newLinesAreReverseCharge(InvoiceFormContext $context, array $values): bool
    {
        $party = \is_string($values['customerId']) ? $this->pickable($values['customerId']) : null;

        return $context->isPayer() && $party instanceof Party && $this->picker->option($party, $context->firm)->defaults->reverseCharge;
    }

    private function issuerCountry(InvoiceFormContext $context): string
    {
        return $context->invoice?->issuer()->address->countryCode ?? $context->firm->address()->countryCode;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function withFormFields(array $values, InvoiceFormContext $context): array
    {
        $values['lines'] = [] === $values['lines'] ? [$this->values->emptyLine($context->regime, false)] : $values['lines'];
        $party = \is_string($values['customerId']) ? $this->pickable($values['customerId']) : null;
        $customer = $values['customer'];
        if (!$party instanceof Party || !\is_array($customer) || '' !== $customer['name']) {
            return $values;
        }

        return $this->values->withCustomerShown($values, $this->picker->option($party, $context->firm));
    }

    private function flashSaved(Invoice $saved, InvoiceSubmission $submission, ?string $ownNumber): void
    {
        $preview = $submission->values['numberPeek'];
        \assert(\is_string($preview));
        if (null === $submission->input->typedNumber && $saved->number() !== $preview) {
            // A preview another document took is a race. Otherwise it was of something else: the number the document
            // gave up, or a series changed without JavaScript.
            $raced = $preview !== $ownNumber && $this->store->numberTaken($saved->firm(), NumberKey::of($preview), $saved);
            $this->addFlash('warning', $raced
                ? $this->translator->trans('invoice.flash.renumbered', ['number' => $saved->number(), 'preview' => $preview])
                : $this->translator->trans('invoice.flash.number_changed', ['number' => $saved->number()]));

            return;
        }
        $this->addFlash('success', $this->translator->trans('invoice.flash.saved', ['number' => $saved->number()]));
    }

    private function alreadySaved(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->addFlash('warning', $this->translator->trans('invoice.flash.already_saved'));

        return $this->redirectToSaved($id);
    }

    /**
     * The invoice list arrives with its own task; until then the saved invoice is the place to land.
     */
    private function redirectToSaved(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('app_invoice_edit', ['id' => $id]);
    }

    /**
     * @param array<string, string> $formErrors
     * @param list<IssueProblem>    $problems
     *
     * @return list<array{id: string, field: string, message: string}>
     */
    private function messages(array $formErrors, array $problems): array
    {
        $messages = [];
        foreach ($formErrors as $field => $key) {
            $messages[$this->inputId($field)] ??= $this->message($field, $key);
        }
        foreach ($problems as $problem) {
            $messages[$this->inputId($problem->field)] ??= $this->message($problem->field, $problem->key);
        }

        // In the order of the form: the lines come after the document.
        $lines = array_filter($messages, static fn (array $message): bool => str_starts_with($message['id'], 'lines-'));

        return [...array_values(array_diff_key($messages, $lines)), ...array_values($lines)];
    }

    /**
     * @return array{id: string, field: string, message: string}
     */
    private function message(string $field, string $key): array
    {
        return ['id' => $this->inputId($field), 'field' => $this->fieldLabel($field), 'message' => $this->translator->trans($key)];
    }

    private function inputId(string $field): string
    {
        $parts = explode('.', $field);
        if ('lines' === $parts[0] && 3 === \count($parts)) {
            return \sprintf('lines-%s-%s', $parts[1], self::LINE_CELLS[$parts[2]] ?? $parts[2]);
        }

        return implode('-', $parts);
    }

    private function fieldLabel(string $field): string
    {
        $parts = explode('.', $field);
        if ('lines' === $parts[0] && 3 === \count($parts)) {
            $cell = self::LINE_CELLS[$parts[2]] ?? $parts[2];

            return $this->translator->trans('invoice.line.label', ['number' => (int) $parts[1] + 1]).' · '.$this->translator->trans('invoice.line.'.$cell);
        }

        return $this->translator->trans('invoice.field_label.'.$parts[0]);
    }

    /**
     * @param array<string, mixed>                                    $values
     * @param list<array{id: string, field: string, message: string}> $errors
     * @param list<array{id: string, name: string, hint: string}>     $candidates
     */
    private function form(InvoiceFormContext $context, array $values, array $errors, array $candidates, string $token, int $version, int $status, bool $stale = false): Response
    {
        $firm = $context->firm;
        $invoice = $context->invoice;
        $taxPoint = $this->taxPoint($values, $context);
        $errorFields = [];
        foreach ($errors as $error) {
            $errorFields[$error['id']] = $error['message'];
        }

        return $this->render('invoicing/form.html.twig', [
            'blocked' => null,
            'invoice' => $invoice,
            'firm' => $firm,
            'firms' => $this->firmOptions($context),
            'series' => $this->seriesOptions($context),
            'values' => $values,
            'errors' => $errors,
            'errorFields' => $errorFields,
            'candidates' => $candidates,
            'token' => $token,
            'version' => $version,
            'stale' => $stale,
            'delivered' => $invoice?->isDelivered() ?? false,
            'trashed' => $invoice?->isTrashed() ?? false,
            'payer' => $context->isPayer(),
            'issuerCountry' => $this->issuerCountry($context),
            'issuerName' => $invoice?->issuer()->name ?? $firm->name(),
            'currency' => $context->currency->value,
            'dueDays' => $this->dueDays($values),
            'vatOptions' => $this->vatOptions($context, $taxPoint),
            'priceTypes' => $this->priceTypes(),
            'supplyCodes' => $this->supplyCodes($context, $taxPoint),
            'paymentMethods' => $this->paymentMethods(),
            'languages' => $this->languages(),
            'bankAccounts' => $this->bankAccounts($context),
            'countries' => $this->countryOptions(),
            'figures' => $this->shownFigures($context, $values, $taxPoint),
            'currencyNotice' => $this->currencyNotice($context, $values),
            'emptyLine' => $this->values->emptyLine($context->regime, $this->newLinesAreReverseCharge($context, $values)),
            'maxLines' => InvoiceFormReader::MAX_LINES,
        ], new Response(status: $status));
    }

    /**
     * What the rows on the form come to; while a row cannot be counted, zeros.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function shownFigures(InvoiceFormContext $context, array $values, \DateTimeImmutable $taxPoint): array
    {
        $request = new Request(request: ['lines' => $values['lines']]);
        try {
            return $this->figures->of($this->reader->lines($request, $context->currency)->lines, $context, $taxPoint);
        } catch (\OverflowException|\DomainException) {
            return $this->figures->of([], $context, $taxPoint);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function currencyNotice(InvoiceFormContext $context, array $values): ?string
    {
        $party = \is_string($values['customerId']) ? $this->pickable($values['customerId']) : null;
        if ($context->invoice instanceof Invoice || !$party instanceof Party) {
            return null;
        }
        $currency = $this->picker->option($party, $context->firm)->defaults->currency;

        return $currency === $context->currency ? null : $currency->value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function taxPoint(array $values, InvoiceFormContext $context): \DateTimeImmutable
    {
        $errors = [];
        $date = \is_string($values['taxPointDate']) ? $this->reader->date($values['taxPointDate'], 'taxPointDate', $errors) : null;

        return $date ?? $this->today($context);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function dueDays(array $values): int
    {
        $errors = [];
        $issue = \is_string($values['issueDate']) ? $this->reader->date($values['issueDate'], 'issueDate', $errors) : null;
        $due = \is_string($values['dueDate']) ? $this->reader->date($values['dueDate'], 'dueDate', $errors) : null;
        if (!$issue instanceof \DateTimeImmutable || !$due instanceof \DateTimeImmutable) {
            return 14;
        }

        return (int) $issue->diff($due)->format('%r%a');
    }

    /**
     * @return array<string, string>
     */
    private function firmOptions(InvoiceFormContext $context): array
    {
        if ($context->invoice instanceof Invoice) {
            return [];
        }
        $options = [];
        foreach ($this->firms->active() as $firm) {
            $options[$firm->id()] = $firm->name();
        }
        $options[$context->firm->id()] = $context->firm->name();

        return $options;
    }

    /**
     * The active series of the firm; a document keeps showing an archived one of its
     * own. A firm that has none yet offers its default, which the first save stores.
     *
     * @return array<string, string>
     */
    private function seriesOptions(InvoiceFormContext $context): array
    {
        $options = [];
        foreach ($this->catalog->forFirm($context->firm) as $series) {
            $options[$series->id()] = $this->seriesName($series);
        }
        if ($context->invoice instanceof Invoice) {
            $own = $context->invoice->series();
            $options[$own->id()] = $this->seriesName($own);
        }

        return [] === $options ? ['' => $this->translator->trans('series.name.standard')] : $options;
    }

    private function seriesName(Series $series): string
    {
        return Series::STANDARD === $series->name() ? $this->translator->trans('series.name.standard') : $series->name();
    }

    /**
     * @return array<string, string>
     */
    private function vatOptions(InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        return [
            InvoiceFormReader::VAT_BASE => $this->rateLabel($context, VatRateKind::Base, $taxPoint),
            InvoiceFormReader::VAT_REDUCED => $this->rateLabel($context, VatRateKind::Reduced, $taxPoint),
            InvoiceFormReader::VAT_REVERSE_CHARGE => $this->translator->trans('invoice.vat.reverse_charge'),
            InvoiceFormReader::VAT_EXEMPT => $this->translator->trans('invoice.vat.exempt'),
        ];
    }

    private function rateLabel(InvoiceFormContext $context, VatRateKind $kind, \DateTimeImmutable $taxPoint): string
    {
        try {
            return $this->translator->trans('invoice.rate', ['rate' => $this->amounts->trimmed($context->country->rate($kind, $taxPoint)->decimal())]);
        } catch (\DomainException) {
            return $this->translator->trans('invoice.vat.'.$kind->value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function priceTypes(): array
    {
        $options = [];
        foreach (PriceType::cases() as $type) {
            $options[$type->value] = $this->translator->trans('invoice.price_type.'.$type->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function supplyCodes(InvoiceFormContext $context, \DateTimeImmutable $taxPoint): array
    {
        $options = ['' => $this->translator->trans('invoice.line.supplyCode_choose')];
        foreach ($context->country->supplyCodes($taxPoint) as $code) {
            $options[$code->code] = \sprintf('%s – %s', $code->code, $this->translator->trans($code->labelKey));
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function paymentMethods(): array
    {
        $options = [];
        foreach (PaymentMethod::cases() as $method) {
            $options[$method->value] = $this->translator->trans('invoice.payment.'.$method->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function languages(): array
    {
        $options = [];
        foreach (DocumentLanguage::cases() as $language) {
            $options[$language->value] = $this->translator->trans('invoice.language.'.$language->value);
        }

        return $options;
    }

    /**
     * The accounts of the firm, and the one on the document, which the firm may have dropped.
     *
     * @return array<string, string>
     */
    private function bankAccounts(InvoiceFormContext $context): array
    {
        $accounts = $context->firm->bankAccounts();
        $own = $context->invoice?->bankAccount();
        if (null !== $own && !\in_array($own, $accounts, true)) {
            $accounts[] = $own;
        }

        return array_combine($accounts, $accounts);
    }

    /**
     * @return array<string, string>
     */
    private function countryOptions(): array
    {
        $options = [];
        foreach (Countries::CODES as $code) {
            $options[$code] = Countries::name($code, $this->translator->getLocale());
        }

        return $options;
    }

    private function seriesFor(Request $request): Series
    {
        $id = $request->query->getString('series');
        if ('' === $id) {
            return $this->catalog->defaultFor($this->firm($request->query->getString('firm')));
        }

        try {
            return Uuid::isValid($id) ? $this->catalog->get($id) : throw new NotFoundHttpException();
        } catch (UnknownSeries) {
            throw new NotFoundHttpException();
        }
    }

    private function newContext(LegalEntity $firm): InvoiceFormContext
    {
        return InvoiceFormContext::new($firm, $this->countries, $this->settings);
    }

    private function storedContext(Invoice $invoice): InvoiceFormContext
    {
        return InvoiceFormContext::stored($invoice, $this->countries);
    }

    private function today(InvoiceFormContext $context): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($context->country->timezone())->setTime(0, 0);
    }

    private function firm(string $id): LegalEntity
    {
        try {
            return Uuid::isValid($id) ? $this->firms->get($id) : throw new NotFoundHttpException();
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }

    private function invoice(string $id): Invoice
    {
        try {
            return Uuid::isValid($id) ? $this->store->get($id) : throw new NotFoundHttpException();
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with invoices.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
