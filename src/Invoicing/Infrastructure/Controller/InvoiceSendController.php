<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\OwnerCheck;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStatus;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\MailVariables;
use App\Invoicing\Application\SendDraft;
use App\Invoicing\Application\SendDraftValues;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRecipient;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Application\SentFiles;
use App\Invoicing\Application\SentFileUnreadable;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Party\Domain\PartyEmail;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use App\Tenancy\Application\TenantClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
 * The send dialog of an invoice, for staff: a page of its own, as the trash
 * confirmation is, so it has Back, works at phone width and answers a refusal
 * with 422 and what was typed. Its fields come in iDoklad's order: from, to,
 * subject and text; the method is left out while a link is the only one. The text
 * is shown filled in, with the link as a readable token in the document's
 * language. A one-time token makes a double click queue one send. The PDF a send
 * carried is kept, and staff open it from the history.
 *
 * A reminder by hand is the same dialog with the firm's reminder text: offered on
 * an overdue invoice outside the trash with something left to pay, as the next
 * step after every reminder it has had, so it counts towards the most there can
 * be. The step travels with the form; when another reminder took it meanwhile,
 * the dialog shows the next one to check rather than send a text naming the wrong
 * step.
 *
 * @phpstan-type Values array{to: list<string>, cc: list<string>, others: string, copyToMe: bool, subject: string, body: string}
 * @phpstan-type Problem array{id: string, field: string, message: string}
 */
final class InvoiceSendController extends AbstractController
{
    public const string FORM_TOKEN = 'invoice_send';
    /** iDoklad's limit of other recipients. */
    public const int MAX_OTHERS = 5;
    private const string RECIPIENTS = 'recipients';
    private const string OTHERS = 'others';
    private const string SUBJECT = 'subject';
    private const string BODY = 'body';
    private const string FORM = 'form';

    public function __construct(
        private readonly InvoiceStore $store,
        private readonly SendDraft $drafts,
        private readonly SendInvoice $sending,
        private readonly MailTokenLabels $labels,
        private readonly SubmissionTokens $tokens,
        private readonly StaffCheck $staff,
        private readonly OwnerCheck $owner,
        private readonly InvoicingCountries $countries,
        private readonly InvoiceSends $sends,
        private readonly SentFiles $files,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceBalances $balances,
        private readonly TenantClock $tenantClock,
        private readonly InvoicePayments $payments,
    ) {
    }

    #[Route('/invoices/{id}/send', name: 'app_invoice_send', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function send(string $id, Request $request): Response
    {
        return $this->dialog($id, $request, SendPurpose::Invoice);
    }

    #[Route('/invoices/{id}/remind', name: 'app_invoice_remind', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function remind(string $id, Request $request): Response
    {
        return $this->dialog($id, $request, SendPurpose::Reminder);
    }

    private function dialog(string $id, Request $request, SendPurpose $purpose): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $reminder = SendPurpose::Reminder === $purpose;
        $token = $request->request->getString('_submission');
        if ($post && $this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            $this->addFlash('warning', $reminder
                ? $this->translator->trans('reminder.manual.already_queued', ['number' => $invoice->number(), 'step' => $request->request->getInt('step')])
                : $this->translator->trans('invoice.send.flash.already_queued', ['number' => $invoice->number()]));

            return $this->toList($return);
        }
        if ($invoice->isTrashed()) {
            $this->addFlash('error', $this->translator->trans('invoice.send.trashed', ['number' => $invoice->number()]));

            return $this->toList($return);
        }
        $step = $reminder ? $this->nextStep($invoice) : null;
        $refusal = null === $step ? null : $this->notRemindable($invoice, $step);
        if (null !== $refusal) {
            $this->addFlash('error', $refusal);

            return $this->toList($return);
        }

        $draft = null === $step ? $this->drafts->for($invoice) : $this->drafts->forReminder($invoice, $step, $this->today());
        if (!$post) {
            return $this->page($invoice, $draft, $this->drafted($draft), [], $this->tokens->issue(), $return, Response::HTTP_OK, $step);
        }
        $values = $this->posted($request);
        if (!$draft->hasServer) {
            return $this->page($invoice, $draft, $values, [], $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY, $step);
        }
        if (!$this->tokens->isIssued($token)) {
            // The token fell out of the session: nothing is lost, the next send goes through.
            return $this->page($invoice, $draft, $values, [$this->problem(self::FORM, 'party.error.expired')], $this->tokens->issue(), $return, Response::HTTP_UNPROCESSABLE_ENTITY, $step);
        }
        if (null !== $step && $request->request->getInt('step') !== $step) {
            return $this->taken($invoice, $request->request->getInt('step'), $token, $return);
        }

        $problems = [];
        $others = $this->others($values['others'], $problems);
        $subject = $this->labels->linkFromEditor($values['subject'], $draft->language);
        $body = $this->labels->linkFromEditor($values['body'], $draft->language);
        if (str_contains($subject, '{'.MailVariables::LINK.'}')) {
            $problems[self::SUBJECT] ??= $this->problem(self::SUBJECT, 'firm.mail.error.link_in_subject', ['link' => $this->labels->token(MailVariables::LINK, $draft->language)]);
        }
        if ([] !== $problems) {
            return $this->page($invoice, $draft, $values, array_values($problems), $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY, $step);
        }

        $to = [...$values['to'], ...$others];
        try {
            $send = $this->sending->send(new SendRequest($invoice->id(), $to, $values['cc'], $values['copyToMe'], $subject, $body, $purpose, $step));
        } catch (SendRefused $refused) {
            if (null !== $step && SendRefused::REMINDER_TAKEN === $refused->reason) {
                return $this->taken($invoice, $step, $token, $return);
            }
            $fresh = $this->invoice($id);

            return $this->page($fresh, null === $step ? $this->drafts->for($fresh) : $draft, $values, [$this->refusal($refused, $others)], $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY, $step);
        }
        $this->tokens->complete($token, $send->id());
        $recipients = implode(', ', [...$send->to(), ...$send->cc()]);
        $this->addFlash('success', null === $step
            ? $this->translator->trans('invoice.send.flash.queued', ['number' => $send->documentNumber(), 'recipients' => $recipients])
            : $this->translator->trans('reminder.manual.queued', ['number' => $send->documentNumber(), 'step' => $step, 'recipients' => $recipients]));

        return $this->toList($return);
    }

    /**
     * Another reminder took the step the form was drawn for: the next one, drawn afresh, to check and send.
     */
    private function taken(Invoice $invoice, int $posted, string $token, string $return): Response
    {
        $invoice = $this->invoice($invoice->id());
        $step = $this->nextStep($invoice);
        $refusal = $this->notRemindable($invoice, $step);
        if (null !== $refusal) {
            $this->addFlash('error', $refusal);

            return $this->toList($return);
        }
        $draft = $this->drafts->forReminder($invoice, $step, $this->today());
        $problem = ['id' => self::FORM, 'field' => '', 'message' => $this->translator->trans('reminder.manual.taken', ['taken' => max(1, min($posted, $step - 1)), 'step' => $step])];

        return $this->page($invoice, $draft, $this->drafted($draft), [$problem], $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY, $step);
    }

    /**
     * The step after every reminder the invoice has had, whatever became of them, as the daily run counts.
     */
    private function nextStep(Invoice $invoice): int
    {
        $last = 0;
        foreach ($this->sends->ofInvoice($invoice) as $send) {
            $last = max($last, $send->reminderStep() ?? 0);
        }

        return $last + 1;
    }

    /**
     * Why the invoice gets no reminder now, or null when it may.
     */
    private function notRemindable(Invoice $invoice, int $step): ?string
    {
        $number = ['number' => $invoice->number()];
        if (InvoiceStatus::Overdue !== InvoiceStatus::ofInvoice($invoice, $this->today(), $this->payments->paidOf($invoice))) {
            return $this->translator->trans('reminder.manual.not_overdue', $number);
        }
        if ($step > FirmReminders::MAX_STEPS) {
            return $this->translator->trans('reminder.manual.limit', $number + ['max' => FirmReminders::MAX_STEPS]);
        }
        $open = $this->balances->outstanding([$invoice->id()])[$invoice->id()];
        if ($open->isZero() || $open->isNegative()) {
            return $this->translator->trans('reminder.manual.settled', $number);
        }

        return null;
    }

    /**
     * The tenant's day, at its start, as the reminder run counts it (ADR 0017), so a
     * reminder by hand and the run agree on whether an invoice is overdue and by how much.
     */
    private function today(): \DateTimeImmutable
    {
        return $this->tenantClock->today();
    }

    /**
     * The PDF exactly as a send of the invoice carried it, from the kept copy.
     */
    #[Route('/invoices/{id}/sends/{sendId}/pdf', name: 'app_invoice_sent_pdf', requirements: ['id' => Requirement::UUID_V7, 'sendId' => Requirement::UUID_V7], methods: ['GET'])]
    public function sentPdf(string $id, string $sendId): Response
    {
        $this->denyUnlessStaff();
        $sends = array_filter($this->sends->ofInvoice($this->invoice($id)), static fn (InvoiceSend $send): bool => $send->id() === $sendId);
        $send = array_shift($sends) ?? throw new NotFoundHttpException();
        try {
            $bytes = $this->files->read($send->tenantId()->toString(), $send->pdfKey(), $send->pdfSha256());
        } catch (SentFileUnreadable) {
            throw new NotFoundHttpException('The kept PDF cannot be read.');
        }
        $number = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $send->documentNumber()), '-.');

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, ('' === $number ? 'document' : $number).'.pdf'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return Values
     */
    private function drafted(SendDraftValues $draft): array
    {
        $checked = static fn (string $field): array => array_values(array_map(
            static fn (SendRecipient $recipient): string => $recipient->address,
            array_filter($draft->recipients, static fn (SendRecipient $recipient): bool => $recipient->checked && $field === $recipient->field),
        ));

        return [
            'to' => $checked(SendRecipient::TO),
            'cc' => $checked(SendRecipient::CC),
            'others' => '',
            'copyToMe' => null !== $draft->copyAddress,
            'subject' => $draft->subject,
            'body' => $this->labels->linkToEditor($draft->body, $draft->language),
        ];
    }

    /**
     * @return Values
     */
    private function posted(Request $request): array
    {
        $addresses = static fn (string $name): array => array_values(array_filter($request->request->all($name), \is_string(...)));

        return [
            'to' => $addresses('to'),
            'cc' => $addresses('cc'),
            'others' => $request->request->getString(self::OTHERS),
            'copyToMe' => $request->request->getBoolean('copyToMe'),
            'subject' => $request->request->getString(self::SUBJECT),
            'body' => $request->request->getString(self::BODY),
        ];
    }

    /**
     * The other recipients as typed: split on commas, semicolons and white space.
     *
     * @param array<string, Problem> $problems
     *
     * @return list<string>
     */
    private function others(string $typed, array &$problems): array
    {
        $split = preg_split('/[\s,;]+/u', $typed);
        $others = false === $split ? [] : array_values(array_filter($split, static fn (string $address): bool => '' !== $address));
        if (\count($others) > self::MAX_OTHERS) {
            $problems[self::OTHERS] = $this->problem(self::OTHERS, 'invoice.send.error.too_many_others', ['max' => self::MAX_OTHERS]);

            return $others;
        }
        foreach ($others as $address) {
            if (!PartyEmail::isValid($address)) {
                $problems[self::OTHERS] = $this->problem(self::OTHERS, 'invoice.send.error.bad_address', ['address' => $address]);

                break;
            }
        }

        return $others;
    }

    /**
     * @param list<string> $others
     *
     * @return Problem
     */
    private function refusal(SendRefused $refused, array $others): array
    {
        $detail = $refused->details[0] ?? '';

        return match ($refused->reason) {
            SendRefused::NO_RECIPIENT => $this->problem(self::RECIPIENTS, 'invoice.send.error.no_recipient'),
            SendRefused::TOO_MANY_RECIPIENTS => $this->problem(self::RECIPIENTS, 'invoice.send.error.too_many_recipients', ['max' => InvoiceSend::MAX_RECIPIENTS]),
            SendRefused::BAD_ADDRESS => $this->problem(\in_array($detail, $others, true) ? self::OTHERS : self::RECIPIENTS, 'invoice.send.error.bad_address', ['address' => $detail]),
            SendRefused::BAD_SUBJECT => $this->problem(self::SUBJECT, 'invoice.send.error.bad_subject'),
            SendRefused::BAD_BODY => $this->problem(self::BODY, 'invoice.send.error.bad_body'),
            default => $this->problem(self::FORM, 'invoice.send.error.'.$refused->reason),
        };
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return Problem
     */
    private function problem(string $field, string $key, array $parameters = []): array
    {
        return ['id' => $field, 'field' => self::FORM === $field ? '' : $this->translator->trans('invoice.send.field.'.$field), 'message' => $this->translator->trans($key, $parameters)];
    }

    /**
     * @param Values        $values
     * @param list<Problem> $problems
     */
    private function page(Invoice $invoice, SendDraftValues $draft, array $values, array $problems, string $token, string $return, int $status, ?int $step = null): Response
    {
        $errors = [];
        foreach ($problems as $problem) {
            $errors[$problem['id']] = $problem['message'];
        }
        $reminder = null;
        if (null !== $step) {
            $today = $this->today();
            $days = (int) new \DateTimeImmutable($invoice->dueDate()->format('Y-m-d'))->diff(new \DateTimeImmutable($today->format('Y-m-d')))->format('%a');
            $reminder = ['step' => $step, 'intro' => $this->translator->trans('reminder.manual.intro', ['days' => $days, 'step' => $step, 'max' => FirmReminders::MAX_STEPS])];
        }

        return $this->render('invoicing/send.html.twig', [
            'invoice' => $invoice,
            'draft' => $draft,
            'values' => $values,
            'problems' => $problems,
            'errors' => $errors,
            'reminder' => $reminder,
            'action' => $this->generateUrl(null === $step ? 'app_invoice_send' : 'app_invoice_remind', ['id' => $invoice->id()]),
            'lastSent' => null !== $step ? null : $draft->lastSentAt?->setTimezone($this->timezoneOf($invoice))->format($this->translator->trans('format.datetime')),
            'linkToken' => $this->labels->token(MailVariables::LINK, $draft->language),
            'token' => $token,
            'return' => $return,
            'cancel' => $this->listUrl($return),
            'settingsUrl' => $this->owner->isOwner() ? $this->generateUrl('app_firm_mail', ['id' => $invoice->firm()->id(), 'tab' => 'server']) : null,
            'maxOthers' => self::MAX_OTHERS,
        ], new Response(status: $status));
    }

    private function timezoneOf(Invoice $invoice): \DateTimeZone
    {
        try {
            return $this->countries->for($invoice->issuer()->address->countryCode)->timezone();
        } catch (UnsupportedCountry) {
            return new \DateTimeZone('UTC');
        }
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * Only the known parts of the query come back: the value travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);
        [$route, $parameters] = InvoiceListController::routeOf($query);

        return $this->generateUrl($route, $parameters);
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
            throw new AccessDeniedHttpException('Only staff send invoices.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
