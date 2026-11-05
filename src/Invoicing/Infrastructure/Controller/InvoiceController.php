<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\AdvanceTaxation;
use App\Invoicing\Application\CopyInvoice;
use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\CustomerPicker;
use App\Invoicing\Application\EditInvoice;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoiceHistoryEntry;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\IssueRules;
use App\Invoicing\Application\LineTextDates;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\UndrawableDocument;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownSeries;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\SendStatus;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\VariableSymbol;
use App\Invoicing\Infrastructure\Form\InvoiceErrorTargets;
use App\Invoicing\Infrastructure\Form\InvoiceFigures;
use App\Invoicing\Infrastructure\Form\InvoiceFormContext;
use App\Invoicing\Infrastructure\Form\InvoiceFormOptions;
use App\Invoicing\Infrastructure\Form\InvoiceFormReader;
use App\Invoicing\Infrastructure\Form\InvoiceFormValues;
use App\Invoicing\Infrastructure\Form\InvoiceSubmission;
use App\Invoicing\Infrastructure\Form\PaymentPanel;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Shared\Domain\Countries;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * list and saves nothing twice, and an edit carries the version it was opened at, so a save over
 * someone else's asks to reload. Actions other than saving re-render the form with
 * 422, which is how Turbo shows an answer to a submission in place.
 */
final class InvoiceController extends AbstractController
{
    public const string FORM_TOKEN = 'invoice_form';
    private const array SAVING = ['save', 'save_send', 'save_pdf'];
    /** The title of the form of each kind of document, with its number. */
    private const array TITLES = ['invoice' => 'invoice.title', 'advance' => 'advance.title', 'payment_tax_document' => 'tax_document.title'];
    /** The title of the form of a new document of a kind a person issues. */
    private const array NEW_TITLES = ['invoice' => 'invoice.new', 'advance' => 'advance.new'];
    /** The dialog a saved form opens once over itself: the send dialog of save and send. */
    public const string OPEN_DIALOG_FLASH = 'open_dialog';
    private const array MORE_FIELDS = ['description', 'orderNumber', 'variableSymbol', 'constantSymbol', 'language', 'textBefore', 'textAfter'];

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
        private readonly CopyInvoice $copies,
        private readonly IssueInvoice $issue,
        private readonly EditInvoice $edit,
        private readonly IssueRules $rules,
        private readonly InvoiceFormReader $reader,
        private readonly InvoiceFormValues $values,
        private readonly InvoiceFigures $figures,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly TenantClock $tenantClock,
        private readonly TranslatorInterface $translator,
        private readonly IssuedDocumentFactory $documents,
        private readonly InvoicePdf $pdf,
        private readonly InvoiceSends $sends,
        private readonly InvoiceHistory $history,
        private readonly FirmBankAccounts $accounts,
        private readonly InvoicePayments $payments,
        private readonly PaymentPanel $panel,
        private readonly OwnerCheck $owner,
        private readonly InvoiceFormOptions $options,
        private readonly AdvanceTaxation $taxation,
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

        $kind = InvoiceFormReader::kind($request->query->getString('kind'));
        // No series at all is fine: the first save stores the default. Only archived ones leave nothing to number from.
        if ([] === $this->catalog->forFirm($firm, $kind) && array_any($this->catalog->everyForFirm($firm), static fn (Series $series): bool => $kind === $series->kind())) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'no_series', 'firm' => $firm]);
        }

        try {
            $context = $this->newContext($firm, $kind);
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'country']);
        }

        return $this->form($context, $this->values->ofDraft($this->defaults->for($firm, kind: $kind)), [], [], $this->tokens->issue(), 0, Response::HTTP_OK);
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

    /**
     * The form of a new document, filled from another one. Nothing is written until Save.
     */
    #[Route('/invoices/{id}/copy', name: 'app_invoice_copy', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function copy(string $id): Response
    {
        $this->denyUnlessStaff();
        $source = $this->invoice($id);
        if (!$source->kind()->isIssuedByPerson()) {
            throw new NotFoundHttpException('A tax document on payment comes from its payment only.');
        }
        try {
            $context = $this->newContext($source->firm(), $source->kind());
            $draft = $this->copies->valuesFrom($source);
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/form.html.twig', ['blocked' => 'country']);
        }

        return $this->form($context, $this->values->ofCopy($draft, $source->firm(), $source), [], [], $this->tokens->issue(), 0, Response::HTTP_OK);
    }

    /**
     * The document as a file. It is opened in the browser, which never guesses its type.
     */
    #[Route('/invoices/{id}/pdf', name: 'app_invoice_pdf', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function pdf(string $id): Response
    {
        $this->denyUnlessStaff();
        try {
            $document = $this->documents->of($id);
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
        try {
            $rendered = $this->pdf->render($document);
        } catch (UnsupportedCountry) {
            $this->addFlash('error', $this->translator->trans('invoice.pdf.unsupported_country', ['number' => $document->number]));

            return $this->redirectToRoute('app_invoices');
        } catch (UndrawableDocument) {
            $this->addFlash('error', $this->translator->trans('invoice.pdf.undrawable', ['number' => $document->number]));

            return $this->redirectToRoute('app_invoices');
        }

        return new Response($rendered->bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $rendered->fileName),
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
            $context = '' === $invoiceId ? $this->newContext($this->firm($request->request->getString('firmId')), InvoiceFormReader::kind($request->request->getString('kind'))) : $this->storedContext($this->invoice($invoiceId));
        } catch (UnsupportedCountry) {
            return $this->json(['errors' => ['firm']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lines = $this->reader->lines($request, $context->currency);
        $errors = $lines->errors;
        // An advance invoice takes the rates of its issue date: it has no tax point.
        $rateField = $context->hasTaxPoint() ? 'taxPointDate' : 'issueDate';
        $taxPoint = $this->reader->date(trim($request->request->getString($rateField)), $rateField, $errors);
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
            return $this->alreadySaved();
        }

        $firm = $this->firm($request->request->getString('firmId'));
        try {
            $context = $this->newContext($firm, InvoiceFormReader::kind($request->request->getString('kind')));
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
            return $this->alreadySaved();
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
        // The firm got its first account in another tab while this form was open.
        if (!$context->invoice instanceof Invoice && '' === $request->request->getString('bankAccount')) {
            $request->request->set('bankAccount', $this->accounts->primaryOf($context->firm)?->iban() ?? '');
        }
        $matches = $this->typedCustomers($request, $context->firm, $action);
        $submission = $this->reader->read($request, $context->currency);
        $values = $this->values->withoutHiddenCodes($this->withFormFields($submission->values, $context), $this->issuerCountry($context));
        $values['copyOf'] = $request->request->getString('copyOf');

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

        return match ($action) {
            'save_pdf' => $this->redirectToRoute('app_invoice_pdf', ['id' => $saved->id()]),
            // With JavaScript the send dialog opens over the saved form; without, the send page.
            'save_send' => '1' === $request->request->getString('_dialogs')
                ? $this->openOverForm($saved)
                : $this->redirectToRoute('app_invoice_send', ['id' => $saved->id()]),
            default => $this->redirectToRoute(InvoiceListController::ROUTES[$saved->kind()->value]),
        };
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
        $context = null === $invoiceId ? $this->newContext($this->firm($firmId), InvoiceFormReader::kind(\is_string($values['kind'] ?? null) ? $values['kind'] : '')) : $this->storedContext($this->invoice($invoiceId));
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
            if ($party instanceof Party && $this->contacts->isArchivedFor($party, $context->firm)) {
                $errors = [$this->message('customer', 'invoice.problem.customer_archived')];
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

    private function openOverForm(Invoice $saved): RedirectResponse
    {
        $this->addFlash(self::OPEN_DIALOG_FLASH, $this->generateUrl('app_invoice_send', ['id' => $saved->id()]));

        return $this->redirectToRoute('app_invoice_edit', ['id' => $saved->id()]);
    }

    private function alreadySaved(): RedirectResponse
    {
        $this->addFlash('warning', $this->translator->trans('invoice.flash.already_saved'));

        return $this->redirectToRoute('app_invoices');
    }

    /**
     * @param array<string, string> $formErrors
     * @param list<IssueProblem>    $problems
     *
     * @return list<array{id: string, key: string, field: string, message: string}>
     */
    private function messages(array $formErrors, array $problems): array
    {
        $messages = [];
        foreach ($formErrors as $field => $key) {
            $messages[InvoiceErrorTargets::inputId($field)] ??= $this->message($field, $key);
        }
        foreach ($problems as $problem) {
            $messages[InvoiceErrorTargets::inputId($problem->field)] ??= $this->message($problem->field, $problem->key, $problem->parameters);
        }

        // In the order of the form: the lines come after the document.
        $lines = array_filter($messages, static fn (array $message): bool => str_starts_with($message['id'], 'lines-'));

        return [...array_values(array_diff_key($messages, $lines)), ...array_values($lines)];
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{id: string, key: string, field: string, message: string}
     */
    private function message(string $field, string $key, array $parameters = []): array
    {
        return ['id' => InvoiceErrorTargets::inputId($field), 'key' => $key, 'field' => $this->fieldLabel($field), 'message' => $this->translator->trans($key, $parameters)];
    }

    private function fieldLabel(string $field): string
    {
        $parts = explode('.', $field);
        if ('lines' === $parts[0] && 3 === \count($parts)) {
            $cell = InvoiceErrorTargets::LINE_CELLS[$parts[2]] ?? $parts[2];

            return $this->translator->trans('invoice.line.label', ['number' => (int) $parts[1] + 1]).' · '.$this->translator->trans('invoice.line.'.$cell);
        }

        return $this->translator->trans('invoice.field_label.'.$parts[0]);
    }

    /**
     * @param array<string, mixed>                                                 $values
     * @param list<array{id: string, key: string, field: string, message: string}> $errors
     * @param list<array{id: string, name: string, hint: string}>                  $candidates
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
        $firmUrl = $this->generateUrl('app_firm_edit', ['id' => $firm->id()]);
        // The bank tab is the owner's: staff learn the account is missing, not where to add it.
        $bankUrl = $this->owner->isOwner() ? $this->generateUrl('app_firm_bank', ['id' => $firm->id()]) : null;
        $targets = new InvoiceErrorTargets($context->isPayer(), $invoice?->isDelivered() ?? false, [] !== $this->bankAccounts($context), '' !== $values['customerId'], $firmUrl, $bankUrl);

        return $this->render('invoicing/form.html.twig', [
            'blocked' => null,
            'invoice' => $invoice,
            'firm' => $firm,
            'firms' => $this->firmOptions($context),
            'series' => $this->seriesOptions($context),
            'values' => $values,
            'errors' => array_map(static fn (array $error): array => $error + ['link' => $targets->of($error['id'], $error['key'])], $errors),
            'errorFields' => $errorFields,
            'firmUrl' => $firmUrl,
            'bankUrl' => $bankUrl,
            'statusBadge' => $this->statusBadge($context),
            'payments' => $invoice instanceof Invoice ? $this->panel->of($invoice) : null,
            'moreOpen' => $this->moreOpen($values, $context, $errorFields),
            'noContacts' => 0 === $this->parties->counts()->all,
            'candidates' => $candidates,
            'token' => $token,
            'version' => $version,
            'stale' => $stale,
            'copyNotices' => $this->copyNotices($values, $context),
            'copyOf' => $values['copyOf'] ?? '',
            'datesInLines' => '' !== ($values['copyOf'] ?? '') && $this->hasDatesInLines($values),
            'delivered' => $invoice?->isDelivered() ?? false,
            'trashed' => $invoice?->isTrashed() ?? false,
            // Delivered before its payment was cancelled: it needs a corrective document.
            'needsCorrection' => null !== $invoice?->paymentId() && !$invoice->isTrashed() && $this->payments->get($invoice->paymentId())->isCancelled(),
            'changedAfterSend' => $this->changedAfterSend($context),
            'payer' => $context->isPayer(),
            'kind' => $context->kind->value,
            'listRoute' => InvoiceListController::ROUTES[$context->kind->value],
            'hasTaxPoint' => $context->hasTaxPoint(),
            // Asked on an advance invoice of a VAT payer only, as Fakturoid asks for the follow-up of an advance.
            'taxOnReceiptOptions' => DocumentKind::Advance === $context->kind && $context->isPayer() ? $this->taxOnReceiptOptions() : [],
            'taxOnReceiptNow' => $invoice instanceof Invoice && DocumentKind::Advance === $invoice->kind() ? $this->taxation->of($invoice) : null,
            'titleKey' => self::TITLES[$context->kind->value],
            'newKey' => self::NEW_TITLES[$context->kind->value] ?? 'invoice.new',
            'issuerCountry' => $this->issuerCountry($context),
            'issuerName' => $invoice?->issuer()->name ?? $firm->name(),
            'currency' => $context->currency->value,
            'dueDays' => $this->dueDays($values),
            'vatOptions' => $this->options->vat($context, $taxPoint),
            'priceTypes' => $this->options->priceTypes(),
            'supplyCodes' => $this->options->supplyCodes($context, $taxPoint),
            'paymentMethods' => $this->options->paymentMethods(),
            'languages' => $this->options->languages(),
            'bankAccounts' => $this->bankAccounts($context),
            'countries' => $this->countryOptions(),
            'figures' => $this->shownFigures($context, $values, $taxPoint),
            'currencyNotice' => $this->currencyNotice($context, $values),
            'emptyLine' => $this->values->emptyLine($context->regime, $this->newLinesAreReverseCharge($context, $values)),
            'maxLines' => InvoiceFormReader::MAX_LINES,
        ], new Response(status: $status));
    }

    /**
     * When the invoice was changed after the last send that went out, or is about
     * to: the customer's link shows what was sent, not what is stored. Changed means
     * a newer version than the send carried and a change in its history from the
     * second of the send on, as the log keeps whole seconds; the delivery a send
     * records is no change.
     *
     * @return string|null the moment of that send, as shown
     */
    private function changedAfterSend(InvoiceFormContext $context): ?string
    {
        $invoice = $context->invoice;
        if (!$invoice instanceof Invoice) {
            return null;
        }
        $last = null;
        foreach ($this->sends->ofInvoice($invoice) as $send) {
            if (\in_array($send->status(), [SendStatus::Sent, SendStatus::Queued], true)) {
                $last = $send;
            }
        }
        if (null === $last || $last->invoiceVersion() >= $invoice->version()) {
            return null;
        }
        $after = $last->createdAt();
        if (!array_any($this->history->of($invoice), static fn (InvoiceHistoryEntry $entry): bool => InvoiceHistoryEntry::CHANGED === $entry->event && $entry->occurredAt >= $after)) {
            return null;
        }

        return ($last->sentAt() ?? $last->createdAt())->setTimezone($context->country->timezone())->format($this->translator->trans('format.datetime'));
    }

    /**
     * @return array{label: string, tone: string}|null
     */
    private function statusBadge(InvoiceFormContext $context): ?array
    {
        if (!$context->invoice instanceof Invoice) {
            return null;
        }
        // Overdue by the tenant's day, as the reminders count it (ADR 0017).
        $status = InvoiceStatus::ofInvoice($context->invoice, $this->tenantClock->today(), $this->payments->paidOf($context->invoice));

        return ['label' => $this->translator->trans('invoice.list.status.'.$status->value), 'tone' => $status->tone()];
    }

    /**
     * "More details" opens for a mistake inside it and for anything that is not what
     * the form starts with. An empty description follows the first line.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $errorFields
     */
    private function moreOpen(array $values, InvoiceFormContext $context, array $errorFields): bool
    {
        if ([] !== array_intersect_key($errorFields, array_flip(self::MORE_FIELDS))) {
            return true;
        }
        $settings = $this->settings->for($context->firm);
        /** @var list<array<string, string>> $lines */
        $lines = $values['lines'];
        $number = '' !== $values['number'] ? $values['number'] : $values['numberPeek'];
        \assert(\is_string($number));

        return '' !== $values['orderNumber']
            || !\in_array($values['description'], ['', $lines[0]['text'] ?? ''], true)
            || $values['variableSymbol'] !== VariableSymbol::fromNumber($number)
            || $values['constantSymbol'] !== $settings->constantSymbol()
            || $values['textBefore'] !== $settings->textBefore()
            || $values['textAfter'] !== $settings->textAfter()
            || DocumentLanguage::Czech->value !== $values['language'];
    }

    /**
     * What the copy of a document must say, for as long as it holds: through a refused
     * save, until the form has another firm or customer.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string> translation keys
     */
    private function copyNotices(array $values, InvoiceFormContext $context): array
    {
        $id = $values['copyOf'] ?? '';
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return [];
        }
        try {
            $source = $this->store->get($id);
            $notices = $this->copies->valuesFrom($source)->notices;
        } catch (UnknownInvoice|UnsupportedCountry) {
            return [];
        }
        $sameFirm = $source->firm()->id() === $context->firm->id();

        return array_values(array_filter($notices, static fn (string $notice): bool => match ($notice) {
            'invoice.copy.customer_archived' => $sameFirm && '' === $values['customerId'],
            default => $sameFirm,
        }));
    }

    /**
     * A copy repeats what the lines say, a month that has passed included.
     *
     * @param array<string, mixed> $values
     */
    private function hasDatesInLines(array $values): bool
    {
        /** @var list<array<string, string>> $lines */
        $lines = $values['lines'];
        $language = $values['language'];
        \assert(\is_string($language));

        return array_any($lines, static fn (array $line): bool => LineTextDates::found($line['text'], $language));
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
        // An advance invoice takes the rates of its issue date: it has no tax point.
        $field = $context->hasTaxPoint() ? 'taxPointDate' : 'issueDate';
        $date = \is_string($values[$field]) ? $this->reader->date($values[$field], $field, $errors) : null;

        return $date ?? $this->today($context);
    }

    /**
     * @return array<string, string>
     */
    private function taxOnReceiptOptions(): array
    {
        return [
            '' => $this->translator->trans('advance.tax_on_receipt.rules'),
            TaxOnReceiptChoice::Issue->value => $this->translator->trans('advance.tax_on_receipt.issue'),
            TaxOnReceiptChoice::Skip->value => $this->translator->trans('advance.tax_on_receipt.skip'),
        ];
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
        return $context->invoice instanceof Invoice ? [] : $this->options->firms($context->firm);
    }

    /**
     * The active series of the firm; a document keeps showing an archived one of its
     * own. A firm that has none yet offers its default, which the first save stores.
     *
     * @return array<string, string>
     */
    private function seriesOptions(InvoiceFormContext $context): array
    {
        return $this->options->series($context->firm, $context->invoice?->series(), $context->kind);
    }

    /**
     * The active accounts of the firm, and the one on the document, which the firm may have archived.
     *
     * @return array<string, string>
     */
    private function bankAccounts(InvoiceFormContext $context): array
    {
        return $this->options->bankAccounts($context->firm, $context->invoice?->bankAccount());
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
            return $this->catalog->defaultFor($this->firm($request->query->getString('firm')), InvoiceFormReader::kind($request->query->getString('kind')));
        }

        try {
            return Uuid::isValid($id) ? $this->catalog->get($id) : throw new NotFoundHttpException();
        } catch (UnknownSeries) {
            throw new NotFoundHttpException();
        }
    }

    private function newContext(LegalEntity $firm, DocumentKind $kind = DocumentKind::Invoice): InvoiceFormContext
    {
        return InvoiceFormContext::new($firm, $this->countries, $this->settings, $kind);
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
