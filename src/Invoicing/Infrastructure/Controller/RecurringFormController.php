<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\CopyInvoice;
use Foresi\Invoicing\Application\CustomerOption;
use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\IssueAfterTodaysRun;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\PreviewRow;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOccurrence;
use Foresi\Invoicing\Application\RecurringPreview;
use Foresi\Invoicing\Application\RecurringRefused;
use Foresi\Invoicing\Application\RecurringRules;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Application\UnknownRecurringInvoice;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Domain\TaxPointRule;
use Foresi\Invoicing\Infrastructure\Form\InvoiceErrorTargets;
use Foresi\Invoicing\Infrastructure\Form\InvoiceFigures;
use Foresi\Invoicing\Infrastructure\Form\InvoiceFormContext;
use Foresi\Invoicing\Infrastructure\Form\InvoiceFormOptions;
use Foresi\Invoicing\Infrastructure\Form\InvoiceFormReader;
use Foresi\Invoicing\Infrastructure\Form\InvoiceFormValues;
use Foresi\Invoicing\Infrastructure\Form\RecurringFormReader;
use Foresi\Invoicing\Infrastructure\Form\RecurringFormValues;
use Foresi\Invoicing\Infrastructure\Form\RecurringSubmission;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Schedule\PeriodText;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Shared\Infrastructure\Http\SubmissionTokens;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
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
    public const string DOCUMENT_NUMBER = RecurringOccurrence::DOCUMENT_NUMBER;
    private const array MORE_FIELDS = ['constantSymbol', 'language', 'textBefore', 'textAfter'];
    /** Where a problem of the rules shows on this form. */
    private const array FIELDS = ['recurrence.firstIssueOn' => 'firstIssueOn', 'recurrence' => 'end'];
    private const array WITHOUT_LINK = ['form', 'version', 'state'];

    public function __construct(
        private readonly InstanceFirm $instanceFirm,
        private readonly InvoiceStore $invoices,
        private readonly RecurringInvoices $templates,
        private readonly SaveRecurringInvoice $save,
        private readonly RecurringRules $rules,
        private readonly RecurringPreview $preview,
        private readonly IssueAfterTodaysRun $afterTodaysRun,
        private readonly RecurringFormReader $reader,
        private readonly RecurringFormValues $recurringValues,
        private readonly InvoiceFormReader $invoiceReader,
        private readonly InvoiceFormValues $values,
        private readonly InvoiceFormOptions $options,
        private readonly InvoiceFigures $figures,
        private readonly FirmInvoicingSettings $settings,
        private readonly InvoicingCountries $countries,
        private readonly CopyInvoice $copies,
        private readonly CustomerPicker $picker,
        private readonly PartyDetails $partyDetails,
        private readonly PartyQuery $parties,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly InstanceClock $clock,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
        private readonly OwnerCheck $owner,
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
        if (!$this->instanceFirm->find() instanceof LegalEntity) {
            return $this->render('@Invoicing/recurring/form.html.twig', ['blocked' => 'no_firm']);
        }

        try {
            $context = $this->context();
            $values = $source instanceof Invoice ? $this->recurringValues->ofInvoice($source, $this->today()) : $this->recurringValues->ofNew($this->today());
        } catch (UnsupportedCountry) {
            return $this->render('@Invoicing/recurring/form.html.twig', ['blocked' => 'country']);
        }

        return $this->form($context, null, $values, [], [], $this->tokens->issue(), 0, Response::HTTP_OK);
    }

    #[Route('/invoices/recurring/{id}/edit', name: 'app_recurring_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $template = $this->template($id);
        try {
            $context = $this->context();
        } catch (UnsupportedCountry) {
            return $this->render('@Invoicing/recurring/form.html.twig', ['blocked' => 'country']);
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
        $this->firm();
        try {
            $context = $this->context();
        } catch (UnsupportedCountry) {
            throw new NotFoundHttpException();
        }
        $recipients = $this->recipients($request->request->getString('customerId'), $template);
        $this->withRecipients($request, $recipients);
        $preview = $this->previewOf($this->reader->read($request, $context->currency, $this->today()), $template);

        return $this->render('@Invoicing/recurring/_live.html.twig', [
            'preview' => $preview,
            'recipients' => $recipients,
        ], new Response(status: [] === $preview['problems'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
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
            return $this->alreadySaved();
        }
        try {
            $this->firm();
            $context = $this->context();
        } catch (UnsupportedCountry) {
            return $this->render('@Invoicing/recurring/form.html.twig', ['blocked' => 'country']);
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
            return $this->alreadySaved();
        }

        return $this->submit($request, $context, $template, $token);
    }

    private function cutOff(string $retry): Response
    {
        return $this->render('@Invoicing/recurring/form.html.twig', ['blocked' => 'cut_off', 'retry' => $retry], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function submit(Request $request, InvoiceFormContext $context, ?RecurringInvoice $template, string $token): Response
    {
        // Only the save button saves: a request without one changes nothing.
        $action = $request->request->getString('_action', 'refresh');
        $saving = 'save' === $action;
        $matches = $this->typedCustomers($request, $saving);
        $wanted = $this->withRecipients($request, $this->recipients($request->request->getString('customerId'), $template));
        $submission = $this->reader->read($request, $context->currency, $this->today());
        // The form shows the boxes as they were chosen, so a box that comes back with an address is as it was.
        $values = $this->values->withoutHiddenCodes($this->withFormFields([...$submission->values, ...$wanted], $context), $this->firm()->address()->countryCode);

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
            $problems = $this->rules->check($submission->input, $template, $this->today());

            return $this->form($context, $template, $values, $this->messages($submission->errors, $problems), $candidates, $token, $submission->version, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = $template instanceof RecurringInvoice
                ? $this->save->edit($template->id(), $submission->version, $submission->input)
                : $this->save->create($submission->input);
        } catch (RecurringRefused $refused) {
            return $this->refused($refused, $template?->id(), $values, $candidates, $token, $submission->version);
        }

        $this->tokens->complete($token, $saved->id());
        // A save always leaves a date to issue on: the rules refuse a schedule without one.
        $this->addFlash('success', $this->translator->trans('recurring.flash.saved', ['date' => $this->date($saved->nextOccurrence() ?? $this->today())]));
        // A date due today after the day's run is issued now, as the preview said.
        $this->afterTodaysRun->afterChange($saved->id());

        // Edited in the sheet: the detail again, in place (docs/design/overlays.md).
        return DialogRequest::SHEET === DialogRequest::layer($request) && $template instanceof RecurringInvoice
            ? $this->redirectToRoute('app_recurring_show', ['id' => $saved->id()])
            : $this->redirectToRoute('app_recurring');
    }

    /**
     * A refused save leaves what was loaded detached, so the form starts from the
     * stored rows again. A stale save has one thing left to do, and says so.
     *
     * @param array<string, mixed>                                $values
     * @param list<array{id: string, name: string, hint: string}> $candidates
     */
    private function refused(RecurringRefused $refused, ?string $templateId, array $values, array $candidates, string $token, int $version): Response
    {
        $template = null === $templateId ? null : $this->template($templateId);
        $context = $this->context();
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
            if ($party instanceof Party && $party->isArchived()) {
                $errors = [$this->message('customer', 'invoice.problem.customer_archived')];
            } elseif ($party instanceof Party) {
                $values = $this->withCustomer($values, $party, $context);
            }
        } elseif ('refresh' !== $action) {
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
        $values = $this->values->withCustomer($values + ['dueDateSet' => '1', 'dueDate' => ''], $party, $this->today(), $context->regime, $this->firm()->address()->countryCode);
        if ('1' !== $values['dueDaysSet']) {
            $values['dueDays'] = (string) $this->picker->option($party)->defaults->dueDays;
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
    private function typedCustomers(Request $request, bool $saving): array
    {
        $query = trim($request->request->getString('customerQuery'));
        if (!$saving || '' !== $request->request->getString('customerId') || '' === $query) {
            return [];
        }

        return $this->picker->search($query);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function newLinesAreReverseCharge(InvoiceFormContext $context, array $values): bool
    {
        $party = \is_string($values['customerId']) ? $this->pickable($values['customerId']) : null;

        return $context->isPayer() && $party instanceof Party && $this->picker->option($party)->defaults->reverseCharge;
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

        return $this->values->withCustomerShown($values, $this->picker->option($party));
    }

    private function alreadySaved(): RedirectResponse
    {
        $this->addFlash('warning', $this->translator->trans('recurring.flash.already_saved'));

        return $this->redirectToRoute('app_recurring');
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
        if (\in_array($parts[0], [...RecurringFormReader::OWN_FIELDS, 'state'], true)) {
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
        $firm = $this->firm();
        $today = $this->today();
        $errorFields = [];
        foreach ($errors as $error) {
            $errorFields[$error['id']] = $error['message'];
        }
        $firmUrl = $this->generateUrl('app_firm');
        // Accounts are kept on the firm's bank tab, which only an owner may open.
        $bankUrl = $this->owner->isOwner() ? $this->bankUrl() : null;
        $bankAccounts = $this->options->bankAccounts($template?->bankAccount());
        $targets = new InvoiceErrorTargets($context->isPayer(), false, [] !== $bankAccounts, '' !== $values['customerId'], $firmUrl, $bankUrl);
        $links = array_map(fn (array $error): array => $error + ['link' => \in_array($error['id'], self::WITHOUT_LINK, true) ? null : $targets->of($error['id'])], $errors);
        $previewRequest = new Request(request: $values);
        $recipients = $this->recipients($previewRequest->request->getString('customerId'), $template);
        $this->withRecipients($previewRequest, $recipients);

        return $this->render('@Invoicing/recurring/form.html.twig', [
            'blocked' => null,
            'template' => $template,
            'ended' => RecurringState::Ended === $template?->state(),
            'firm' => $firm,
            'series' => $this->seriesOptions($template),
            'values' => $values,
            'errors' => $links,
            'errorFields' => $errorFields,
            'firmUrl' => $firmUrl,
            'bankUrl' => $bankUrl,
            'moreOpen' => $this->moreOpen($values, $errorFields),
            'noContacts' => 0 === $this->parties->counts()->all,
            'candidates' => $candidates,
            'token' => $token,
            'version' => $version,
            'stale' => $stale,
            'copyNotices' => $this->copyNotices($values),
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
            'bankDetails' => $this->options->bankDetails($context->country, array_keys($bankAccounts)),
            'figures' => $this->shownFigures($context, $values, $today),
            'emptyLine' => $this->values->emptyLine($context->regime, $this->newLinesAreReverseCharge($context, $values)),
            'maxLines' => InvoiceFormReader::MAX_LINES,
            'units' => $this->choices(RecurrenceUnit::cases(), 'recurring.unit.'),
            'taxPoints' => $this->choices(TaxPointRule::cases(), $context->isPayer() ? 'recurring.tax_point.' : 'recurring.supply_point.'),
            'ends' => $this->choices(RecurrenceEnd::cases(), 'recurring.end.'),
            'recipients' => $recipients,
            'variables' => $this->variables(),
            'preview' => $this->previewOf($this->reader->read($previewRequest, $context->currency, $today), $template),
        ], new Response(status: $status));
    }

    /**
     * What the preview frame shows, as iDoklad does: the next issue in one line,
     * and the dates after it on request. When the next date is due by today, the
     * line says when it is issued instead (IssueAfterTodaysRun): right after the
     * save, or by the run at its hour. Each date carries its description only when
     * the descriptions differ, so the list never repeats the description field.
     *
     * @return array{rows: list<array{issue: string, description: ?string}>, problems: list<string>, when: ?string, delivery: string, end: ?string}
     */
    private function previewOf(RecurringSubmission $submission, ?RecurringInvoice $template): array
    {
        $problems = [];
        foreach ($submission->scheduleErrors() as $field => $key) {
            $problems[] = $this->translator->trans('recurring.field.'.$field).': '.$this->translator->trans($key);
        }
        $delivery = $submission->input->delivery->value;
        if ([] !== $problems) {
            return ['rows' => [], 'problems' => $problems, 'when' => null, 'delivery' => $delivery, 'end' => null];
        }

        $today = $this->today();
        $rows = $this->preview->next($submission->input, $template?->issuedCount() ?? 0, $today);
        $descriptions = array_map(fn (PreviewRow $row): string => str_replace(self::DOCUMENT_NUMBER, $this->translator->trans('recurring.preview.document_number'), $row->description), $rows);
        $varies = \count(array_unique($descriptions)) > 1;
        $shown = array_map(fn (PreviewRow $row, string $description): array => [
            'issue' => $this->date($row->issue),
            'description' => $varies ? $description : null,
        ], $rows, $descriptions);
        $recurrence = $submission->input->recurrence;
        $end = \count($rows) < RecurringPreview::COUNT && RecurrenceEnd::Never !== $recurrence->end() ? $this->translator->trans('recurring.preview.ends') : null;

        return [
            'rows' => $shown,
            'problems' => [],
            'when' => [] === $rows ? null : $this->when($rows[0]->issue, $today),
            'delivery' => $delivery,
            'end' => $end,
        ];
    }

    /**
     * "now" when a save issues the date at once, "morning" when the run issues it
     * today at its hour, "date" for a later day.
     */
    private function when(\DateTimeImmutable $next, \DateTimeImmutable $today): string
    {
        return match (true) {
            $this->afterTodaysRun->issuesOnSave($next) => 'now',
            $next->format('Y-m-d') <= $today->format('Y-m-d') => 'morning',
            default => 'date',
        };
    }

    /**
     * Whom each box of "Odesílání faktury" sends to, as DeliverRecurringInvoice
     * picks them through SendDraft: the contact's addresses for invoices of the
     * firm; the copy to the firm's contact address, else to the template's owner
     * (whoever creates it, for a new one), as SendInvoice makes it; and the
     * contact's addresses for a copy. Shown, never posted: the run reads them on
     * its day. Keyed by the box.
     *
     * @return array{sendToCustomer: list<string>, copyToMe: list<string>, copyToOthers: list<string>}
     */
    private function recipients(string $customerId, ?RecurringInvoice $template): array
    {
        $customer = [];
        $others = [];
        $party = $this->pickable($customerId);
        if ($party instanceof Party) {
            foreach ($this->partyDetails->invoicingEmails($party) as $email) {
                if (EmailPurpose::Invoices === $email->purpose()) {
                    $customer[] = $email->address();
                } elseif (EmailPurpose::Copy === $email->purpose()) {
                    $others[] = $email->address();
                }
            }
        }
        $contact = trim($this->settings->get()->contactEmail());
        $owner = $template instanceof RecurringInvoice ? $template->owner() : $this->actor->userId();
        $me = '' !== $contact ? $contact : (null === $owner ? null : $this->users->findById($owner)?->email());

        return [
            'sendToCustomer' => array_values(array_unique($customer)),
            'copyToMe' => null === $me || '' === $me ? [] : [$me],
            'copyToOthers' => array_values(array_unique($others)),
        ];
    }

    /**
     * A box with no address to send to is off, whatever was posted: the form shows it
     * unticked and disabled. What was chosen for it is kept in {box}Wanted while it is
     * disabled, so the box is as it was once the customer has an address again. The
     * request is left with what counts; the boxes as they were chosen are returned.
     *
     * @param array<string, list<string>> $recipients
     *
     * @return array<string, string>
     */
    private function withRecipients(Request $request, array $recipients): array
    {
        $wanted = [];
        foreach ($recipients as $box => $addresses) {
            $wanted[$box] = '1' === $request->request->getString($box) || '1' === $request->request->getString($box.'Wanted') ? '1' : '';
            $request->request->set($box, [] === $addresses ? '' : $wanted[$box]);
            $request->request->remove($box.'Wanted');
        }

        return $wanted;
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
    private function seriesOptions(?RecurringInvoice $template): array
    {
        return $this->options->recurringSeries($template?->series());
    }

    /**
     * "More details" opens for a mistake inside it and for anything that is not what
     * the form starts with.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $errorFields
     */
    private function moreOpen(array $values, array $errorFields): bool
    {
        if ([] !== array_intersect_key($errorFields, array_flip(self::MORE_FIELDS))) {
            return true;
        }
        $settings = $this->settings->get();

        return $values['constantSymbol'] !== $settings->constantSymbol()
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
    private function copyNotices(array $values): array
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
    private function context(): InvoiceFormContext
    {
        return InvoiceFormContext::new($this->instanceFirm, $this->countries, $this->settings);
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->today();
    }

    /**
     * The bank tab of the firm, or null without Banking: Invoicing knows that
     * screen by its route only.
     */
    private function bankUrl(): ?string
    {
        try {
            return $this->generateUrl('app_firm_bank');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * The firm of the instance, which a new template invoices from (ADR 0039).
     */
    private function firm(): LegalEntity
    {
        return $this->instanceFirm->find() ?? throw new NotFoundHttpException();
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
