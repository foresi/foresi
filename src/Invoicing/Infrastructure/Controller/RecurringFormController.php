<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\CopyInvoice;
use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\CustomerPicker;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Invoicing\Application\PreviewRow;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringPreview;
use App\Invoicing\Application\RecurringRefused;
use App\Invoicing\Application\RecurringRules;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownRecurringInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PeriodText;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Infrastructure\Form\InvoiceErrorTargets;
use App\Invoicing\Infrastructure\Form\InvoiceFigures;
use App\Invoicing\Infrastructure\Form\InvoiceFormContext;
use App\Invoicing\Infrastructure\Form\InvoiceFormOptions;
use App\Invoicing\Infrastructure\Form\InvoiceFormReader;
use App\Invoicing\Infrastructure\Form\InvoiceFormValues;
use App\Invoicing\Infrastructure\Form\RecurringFormReader;
use App\Invoicing\Infrastructure\Form\RecurringFormValues;
use App\Invoicing\Infrastructure\Form\RecurringSubmission;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use App\Tenancy\Application\TenantClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * The form of a recurring template, for staff: the invoice form without a number and
 * dates, with a schedule, a delivery and a preview of the next issues. It can start
 * from an invoice, and nothing is written until Save. As on the invoice form, every
 * form carries a one-time token, an edit carries the version it was opened at, and
 * actions other than saving re-render the form with 422. The preview reads the posted
 * form and never writes.
 */
final class RecurringFormController extends AbstractController
{
    /**
     * The invoice form's token: the lines and totals of this form are recounted by the
     * invoice form's recount, which checks it.
     */
    public const string FORM_TOKEN = InvoiceController::FORM_TOKEN;
    public const string DOCUMENT_NUMBER = '{document_number}';
    private const array MORE_FIELDS = ['orderNumber', 'constantSymbol', 'language', 'textBefore', 'textAfter'];
    /** Where a problem of the rules shows on this form. */
    private const array FIELDS = ['recurrence.firstIssueOn' => 'firstIssueOn', 'recurrence' => 'end'];
    private const array WITHOUT_LINK = ['form', 'version', 'state'];

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly InvoiceStore $invoices,
        private readonly RecurringInvoices $templates,
        private readonly SaveRecurringInvoice $save,
        private readonly RecurringRules $rules,
        private readonly RecurringPreview $preview,
        private readonly RecurringFormReader $reader,
        private readonly RecurringFormValues $recurringValues,
        private readonly InvoiceFormReader $invoiceReader,
        private readonly InvoiceFormValues $values,
        private readonly InvoiceFormOptions $options,
        private readonly InvoiceFigures $figures,
        private readonly FirmInvoicingSettings $settings,
        private readonly InvoicingCountries $countries,
        private readonly NewInvoiceDefaults $defaults,
        private readonly CopyInvoice $copies,
        private readonly CustomerPicker $picker,
        private readonly ContactSettingsForFirm $contacts,
        private readonly PartyQuery $parties,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices/recurring/new', name: 'app_recurring_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        if ($request->isMethod('POST')) {
            return $this->submitNew($request);
        }

        $from = $request->query->getString('from');
        $source = '' === $from ? null : $this->invoice($from);
        $id = $request->query->getString('firm');
        $firm = match (true) {
            $source instanceof Invoice => $source->firm(),
            '' !== $id => $this->firm($id),
            default => $this->defaults->firmFor($this->actor->userId()),
        };
        if (!$firm instanceof LegalEntity) {
            return $this->render('invoicing/recurring/form.html.twig', ['blocked' => 'no_firm']);
        }

        try {
            $context = $this->context($firm);
            $values = $source instanceof Invoice ? $this->recurringValues->ofInvoice($source, $this->today()) : $this->recurringValues->ofNew($firm, $this->today());
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/recurring/form.html.twig', ['blocked' => 'country']);
        }

        return $this->form($context, null, $values, [], [], $this->tokens->issue(), 0, Response::HTTP_OK);
    }

    #[Route('/invoices/recurring/{id}/edit', name: 'app_recurring_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $template = $this->template($id);
        try {
            $context = $this->context($template->firm());
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/recurring/form.html.twig', ['blocked' => 'country']);
        }
        if ($request->isMethod('POST')) {
            return $this->submitEdit($template, $context, $request);
        }

        return $this->form($context, $template, $this->recurringValues->ofTemplate($template, $context->regime), [], [], $this->tokens->issue(), $template->version(), Response::HTTP_OK);
    }

    /**
     * The next issues of the posted form, in its frame. A schedule that cannot be read
     * answers 422 with what is wrong with it.
     */
    #[Route('/invoices/recurring/preview', name: 'app_recurring_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $templateId = $request->request->getString('templateId');
        $template = '' === $templateId ? null : $this->template($templateId);
        $firm = $template?->firm() ?? $this->firm($request->request->getString('firmId'));
        try {
            $context = $this->context($firm);
        } catch (UnsupportedCountry) {
            throw new NotFoundHttpException();
        }
        $preview = $this->previewOf($this->reader->read($request, $context->currency, $this->today()), $template);

        return $this->render('invoicing/recurring/_preview.html.twig', ['preview' => $preview, 'payer' => $context->isPayer()], new Response(status: [] === $preview['problems'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function submitNew(Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->invoiceReader->isComplete($request)) {
            return $this->cutOff($this->generateUrl('app_recurring_new'));
        }
        $token = $request->request->getString('_submission');
        $saved = $this->tokens->isIssued($token) ? $this->tokens->createdId($token) : null;
        if (null !== $saved) {
            return $this->alreadySaved($saved);
        }
        try {
            $context = $this->context($this->firm($request->request->getString('firmId')));
        } catch (UnsupportedCountry) {
            return $this->render('invoicing/recurring/form.html.twig', ['blocked' => 'country']);
        }

        return $this->submit($request, $context, null, $token);
    }

    private function submitEdit(RecurringInvoice $template, InvoiceFormContext $context, Request $request): Response
    {
        $this->checkToken($request);
        if (!$this->invoiceReader->isComplete($request)) {
            return $this->cutOff($this->generateUrl('app_recurring_edit', ['id' => $template->id()]));
        }
        $token = $request->request->getString('_submission');
        $saved = $this->tokens->isIssued($token) ? $this->tokens->createdId($token) : null;
        if (null !== $saved) {
            return $this->alreadySaved($saved);
        }
        $request->request->set('firmId', $template->firm()->id());

        return $this->submit($request, $context, $template, $token);
    }

    private function cutOff(string $retry): Response
    {
        return $this->render('invoicing/recurring/form.html.twig', ['blocked' => 'cut_off', 'retry' => $retry], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function submit(Request $request, InvoiceFormContext $context, ?RecurringInvoice $template, string $token): Response
    {
        // Only the save button saves: a request without one changes nothing.
        $action = $request->request->getString('_action', 'refresh');
        $saving = 'save' === $action;
        $matches = $this->typedCustomers($request, $context->firm, $saving);
        $submission = $this->reader->read($request, $context->currency, $this->today());
        $values = $this->values->withoutHiddenCodes($this->withFormFields($submission->values, $context), $context->firm->address()->countryCode);

        if (1 === \count($matches)) {
            // A typed name is a pick, never a save: the defaults of the customer come first.
            $this->addFlash('warning', $this->translator->trans('invoice.customer.picked', ['name' => $matches[0]->name]));

            return $this->reshaped('pick:'.$matches[0]->id, $values, $submission, $context, $template, $token);
        }
        $candidates = array_map(static fn (CustomerOption $option): array => ['id' => $option->id, 'name' => $option->name, 'hint' => $option->registrationNumber ?? ''], $matches);
        if (!$saving) {
            return $this->reshaped($action, $values, $submission, $context, $template, $token);
        }
        if (!$this->tokens->isIssued($token)) {
            // The token fell out of the session: nothing is lost, the next save goes through.
            return $this->form($context, $template, $values, [$this->message('form', 'party.error.expired')], $candidates, $this->tokens->issue(), $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ([] !== $submission->errors) {
            $problems = $this->rules->check($submission->input, $context->firm, $template, $this->today());

            return $this->form($context, $template, $values, $this->messages($submission->errors, $problems), $candidates, $token, $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = $template instanceof RecurringInvoice
                ? $this->save->edit($template->id(), $submission->version, $submission->input)
                : $this->save->create($submission->input);
        } catch (RecurringRefused $refused) {
            return $this->refused($refused, $template?->id(), $context, $values, $candidates, $token, $submission->version);
        }

        $this->tokens->complete($token, $saved->id());
        // A save always leaves a date to issue on: the rules refuse a schedule without one.
        $this->addFlash('success', $this->translator->trans('recurring.flash.saved', ['date' => $this->date($saved->nextOccurrence() ?? $this->today())]));

        return $this->redirectToRoute('app_recurring_edit', ['id' => $saved->id()]);
    }

    /**
     * A refused save leaves what was loaded detached, so the form starts from the
     * stored rows again. A stale save has one thing left to do, and says so.
     *
     * @param array<string, mixed>                                $values
     * @param list<array{id: string, name: string, hint: string}> $candidates
     */
    private function refused(RecurringRefused $refused, ?string $templateId, InvoiceFormContext $context, array $values, array $candidates, string $token, int $version): Response
    {
        $template = null === $templateId ? null : $this->template($templateId);
        $context = $this->context($template?->firm() ?? $this->firm($context->firm->id()));
        $stale = array_any($refused->problems, static fn (IssueProblem $problem): bool => 'version' === $problem->field);

        return $this->form($context, $template, $values, $stale ? [] : $this->messages([], $refused->problems), $candidates, $token, $version, $stale ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY, $stale);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function reshaped(string $action, array $values, RecurringSubmission $submission, InvoiceFormContext $context, ?RecurringInvoice $template, string $token): Response
    {
        $errors = [];
        if (str_starts_with($action, 'pick:')) {
            $party = $this->pickable(substr($action, 5));
            if ($party instanceof Party && $this->contacts->isArchivedFor($party, $context->firm)) {
                $errors = [$this->message('customer', 'invoice.problem.customer_archived')];
            } elseif ($party instanceof Party) {
                $values = $this->withCustomer($values, $party, $context);
            }
        } elseif ('refresh' === $action) {
            if (!$template instanceof RecurringInvoice && $values['firmId'] !== $values['firmShown']) {
                $values = $this->values->forFirm($values, $context->firm, $this->today());
                $this->addFlash('warning', $this->translator->trans('invoice.firm_changed'));
            }
        } else {
            $values = $this->values->withRowAction($values, $action, $context->regime, $this->newLinesAreReverseCharge($context, $values));
        }

        return $this->form($context, $template, $values, $errors, [], $token, $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A customer was chosen: the defaults the browser applies, the due days among them
     * unless they were typed.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function withCustomer(array $values, Party $party, InvoiceFormContext $context): array
    {
        $values = $this->values->withCustomer($values + ['dueDateSet' => '1', 'dueDate' => ''], $party, $context->firm, $this->today(), $context->regime, $context->firm->address()->countryCode);
        if ('1' !== $values['dueDaysSet']) {
            $values['dueDays'] = (string) $this->picker->option($party, $context->firm)->defaults->dueDays;
        }

        return $values;
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
    private function typedCustomers(Request $request, LegalEntity $firm, bool $saving): array
    {
        $query = trim($request->request->getString('customerQuery'));
        if (!$saving || '' !== $request->request->getString('customerId') || '' === $query) {
            return [];
        }

        return $this->picker->search($query, $firm);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function newLinesAreReverseCharge(InvoiceFormContext $context, array $values): bool
    {
        $party = \is_string($values['customerId']) ? $this->pickable($values['customerId']) : null;

        return $context->isPayer() && $party instanceof Party && $this->picker->option($party, $context->firm)->defaults->reverseCharge;
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

    private function alreadySaved(string $id): RedirectResponse
    {
        $this->addFlash('warning', $this->translator->trans('recurring.flash.already_saved'));

        return $this->redirectToRoute('app_recurring_edit', ['id' => $id]);
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
            $field = self::FIELDS[$problem->field] ?? $problem->field;
            $messages[InvoiceErrorTargets::inputId($field)] ??= $this->message($field, $problem->key, $problem->parameters);
        }

        // In the order of the form: the lines come after the rest.
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
        if (\in_array($parts[0], [...RecurringFormReader::SCHEDULE_FIELDS, 'state'], true)) {
            return $this->translator->trans('recurring.field.'.$parts[0]);
        }

        return $this->translator->trans('invoice.field_label.'.$parts[0]);
    }

    /**
     * @param array<string, mixed>                                                 $values
     * @param list<array{id: string, key: string, field: string, message: string}> $errors
     * @param list<array{id: string, name: string, hint: string}>                  $candidates
     */
    private function form(InvoiceFormContext $context, ?RecurringInvoice $template, array $values, array $errors, array $candidates, string $token, int $version, int $status, bool $stale = false): Response
    {
        $firm = $context->firm;
        $today = $this->today();
        $errorFields = [];
        foreach ($errors as $error) {
            $errorFields[$error['id']] = $error['message'];
        }
        $firmUrl = $this->generateUrl('app_firm_edit', ['id' => $firm->id()]);
        $bankAccounts = $this->options->bankAccounts($firm, $template?->bankAccount());
        $targets = new InvoiceErrorTargets($context->isPayer(), false, [] !== $bankAccounts, '' !== $values['customerId'], $firmUrl);
        $links = array_map(fn (array $error): array => $error + ['link' => \in_array($error['id'], self::WITHOUT_LINK, true) ? null : $targets->of($error['id'], $error['key'])], $errors);
        $previewRequest = new Request(request: $values);
        $previewRequest->request->set('firmId', $firm->id());

        return $this->render('invoicing/recurring/form.html.twig', [
            'blocked' => null,
            'template' => $template,
            'ended' => RecurringState::Ended === $template?->state(),
            'firm' => $firm,
            'firms' => $template instanceof RecurringInvoice ? [] : $this->options->firms($firm),
            'series' => $this->seriesOptions($firm, $template),
            'values' => $values,
            'errors' => $links,
            'errorFields' => $errorFields,
            'firmUrl' => $firmUrl,
            'moreOpen' => $this->moreOpen($values, $firm, $errorFields),
            'noContacts' => 0 === $this->parties->counts()->all,
            'candidates' => $candidates,
            'token' => $token,
            'version' => $version,
            'stale' => $stale,
            'copyNotices' => $this->copyNotices($values, $firm),
            'payer' => $context->isPayer(),
            'issuerCountry' => $firm->address()->countryCode,
            'issuerName' => $firm->name(),
            'currency' => $context->currency->value,
            'vatOptions' => $this->options->vat($context, $today),
            'priceTypes' => $this->options->priceTypes(),
            'supplyCodes' => $this->options->supplyCodes($context, $today),
            'paymentMethods' => $this->options->paymentMethods(),
            'languages' => $this->options->languages(),
            'bankAccounts' => $bankAccounts,
            'figures' => $this->shownFigures($context, $values, $today),
            'emptyLine' => $this->values->emptyLine($context->regime, $this->newLinesAreReverseCharge($context, $values)),
            'maxLines' => InvoiceFormReader::MAX_LINES,
            'units' => $this->choices(RecurrenceUnit::cases(), 'recurring.unit.'),
            'taxPoints' => $this->choices(TaxPointRule::cases(), $context->isPayer() ? 'recurring.tax_point.' : 'recurring.supply_point.'),
            'ends' => $this->choices(RecurrenceEnd::cases(), 'recurring.end.'),
            'deliveries' => array_map(fn (RecurringDelivery $delivery): array => ['value' => $delivery->value, 'label' => $this->translator->trans('recurring.delivery.'.$delivery->value.'.label'), 'text' => $this->translator->trans('recurring.delivery.'.$delivery->value.'.text')], RecurringDelivery::cases()),
            'variables' => $this->variables(),
            'preview' => $this->previewOf($this->reader->read($previewRequest, $context->currency, $today), $template),
        ], new Response(status: $status));
    }

    /**
     * What the preview frame shows: the next issues, or what keeps them from being
     * known; whether the first is issued today; and why the list ends early.
     *
     * @return array{rows: list<array{issue: string, taxPoint: ?string, due: string, description: string}>, problems: list<string>, today: bool, delivery: string, end: ?string}
     */
    private function previewOf(RecurringSubmission $submission, ?RecurringInvoice $template): array
    {
        $problems = [];
        foreach ($submission->scheduleErrors() as $field => $key) {
            $problems[] = $this->translator->trans('recurring.field.'.$field).': '.$this->translator->trans($key);
        }
        $delivery = $submission->input->delivery->value;
        if ([] !== $problems) {
            return ['rows' => [], 'problems' => $problems, 'today' => false, 'delivery' => $delivery, 'end' => null];
        }

        $today = $this->today();
        $rows = $this->preview->next($submission->input, $template?->issuedCount() ?? 0, $today);
        $shown = array_map(fn (PreviewRow $row): array => [
            'issue' => $this->date($row->issue),
            'taxPoint' => $row->taxPoint->format('Y-m-d') === $row->issue->format('Y-m-d') ? null : $this->date($row->taxPoint),
            'due' => $this->date($row->due),
            'description' => str_replace(self::DOCUMENT_NUMBER, $this->translator->trans('recurring.preview.document_number'), $row->description),
        ], $rows);
        $recurrence = $submission->input->recurrence;
        $end = \count($rows) < RecurringPreview::COUNT && RecurrenceEnd::Never !== $recurrence->end() ? $this->translator->trans('recurring.preview.ends') : null;

        return [
            'rows' => $shown,
            'problems' => [],
            'today' => [] !== $rows && $rows[0]->issue->format('Y-m-d') === $today->format('Y-m-d'),
            'delivery' => $delivery,
            'end' => $end,
        ];
    }

    /**
     * The variable chips: the period ones, then the document number, labelled.
     *
     * @return list<array{token: string, label: string}>
     */
    private function variables(): array
    {
        $chips = [];
        foreach ([...PeriodText::TOKENS, self::DOCUMENT_NUMBER] as $token) {
            $chips[] = ['token' => $token, 'label' => $this->translator->trans('recurring.variable.'.str_replace('/', '_', trim($token, '{}')))];
        }

        return $chips;
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function choices(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = $this->translator->trans($prefix.$case->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function seriesOptions(LegalEntity $firm, ?RecurringInvoice $template): array
    {
        return $this->options->series($firm, $template?->series());
    }

    /**
     * "More details" opens for a mistake inside it and for anything that is not what
     * the form starts with.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $errorFields
     */
    private function moreOpen(array $values, LegalEntity $firm, array $errorFields): bool
    {
        if ([] !== array_intersect_key($errorFields, array_flip(self::MORE_FIELDS))) {
            return true;
        }
        $settings = $this->settings->for($firm);

        return '' !== $values['orderNumber']
            || $values['constantSymbol'] !== $settings->constantSymbol()
            || $values['textBefore'] !== $settings->textBefore()
            || $values['textAfter'] !== $settings->textAfter()
            || DocumentLanguage::Czech->value !== $values['language'];
    }

    /**
     * What the invoice a template starts from must say, for as long as it holds.
     *
     * @param array<string, mixed> $values
     *
     * @return list<string> translation keys
     */
    private function copyNotices(array $values, LegalEntity $firm): array
    {
        $id = $values['copyOf'] ?? '';
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return [];
        }
        try {
            $source = $this->invoices->get($id);
            $notices = $this->copies->valuesFrom($source)->notices;
        } catch (UnknownInvoice|UnsupportedCountry) {
            return [];
        }
        if ($source->firm()->id() !== $firm->id()) {
            return [];
        }

        return array_values(array_filter($notices, static fn (string $notice): bool => 'invoice.copy.customer_archived' !== $notice || '' === $values['customerId']));
    }

    /**
     * What the rows on the form come to, at today's rates; while a row cannot be counted, zeros.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function shownFigures(InvoiceFormContext $context, array $values, \DateTimeImmutable $taxPoint): array
    {
        $request = new Request(request: ['lines' => $values['lines']]);
        try {
            return $this->figures->of($this->invoiceReader->lines($request, $context->currency)->lines, $context, $taxPoint);
        } catch (\OverflowException|\DomainException) {
            return $this->figures->of([], $context, $taxPoint);
        }
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }

    /**
     * @throws UnsupportedCountry
     */
    private function context(LegalEntity $firm): InvoiceFormContext
    {
        return InvoiceFormContext::new($firm, $this->countries, $this->settings);
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->today();
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
            return Uuid::isValid($id) ? $this->invoices->get($id) : throw new NotFoundHttpException();
        } catch (UnknownInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function template(string $id): RecurringInvoice
    {
        try {
            return Uuid::isValid($id) ? $this->templates->get($id) : throw new NotFoundHttpException();
        } catch (UnknownRecurringInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with recurring invoices.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
