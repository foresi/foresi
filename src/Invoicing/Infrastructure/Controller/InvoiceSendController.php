<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\OwnerCheck;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\InvoiceSends;
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
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Party\Domain\PartyEmail;
use App\Shared\Infrastructure\Http\SubmissionTokens;
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
    ) {
    }

    #[Route('/invoices/{id}/send', name: 'app_invoice_send', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function send(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $invoice = $this->invoice($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $token = $request->request->getString('_submission');
        if ($post && $this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            $this->addFlash('warning', $this->translator->trans('invoice.send.flash.already_queued', ['number' => $invoice->number()]));

            return $this->toList($return);
        }
        if ($invoice->isTrashed()) {
            $this->addFlash('error', $this->translator->trans('invoice.send.trashed', ['number' => $invoice->number()]));

            return $this->toList($return);
        }

        $draft = $this->drafts->for($invoice);
        if (!$post) {
            return $this->page($invoice, $draft, $this->drafted($draft), [], $this->tokens->issue(), $return, Response::HTTP_OK);
        }
        $values = $this->posted($request);
        if (!$draft->hasServer) {
            return $this->page($invoice, $draft, $values, [], $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->tokens->isIssued($token)) {
            // The token fell out of the session: nothing is lost, the next send goes through.
            return $this->page($invoice, $draft, $values, [$this->problem(self::FORM, 'party.error.expired')], $this->tokens->issue(), $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $problems = [];
        $others = $this->others($values['others'], $problems);
        $subject = $this->fromEditor($values['subject'], $draft, self::SUBJECT, $problems);
        $body = $this->fromEditor($values['body'], $draft, self::BODY, $problems);
        if (str_contains($subject, '{'.MailVariables::LINK.'}')) {
            $problems[self::SUBJECT] ??= $this->problem(self::SUBJECT, 'firm.mail.error.link_in_subject', ['link' => $this->labels->token(MailVariables::LINK, $draft->language)]);
        }
        if ([] !== $problems) {
            return $this->page($invoice, $draft, $values, array_values($problems), $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $to = [...$values['to'], ...$others];
        try {
            $send = $this->sending->send(new SendRequest($invoice->id(), $to, $values['cc'], $values['copyToMe'], $subject, $body));
        } catch (SendRefused $refused) {
            return $this->page($invoice, $this->drafts->for($this->invoice($id)), $values, [$this->refusal($refused, $others)], $token, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->tokens->complete($token, $send->id());
        $this->addFlash('success', $this->translator->trans('invoice.send.flash.queued', ['number' => $send->documentNumber(), 'recipients' => implode(', ', [...$send->to(), ...$send->cc()])]));

        return $this->toList($return);
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
            'subject' => $this->labels->toEditor($draft->subject, $draft->language),
            'body' => $this->labels->toEditor($draft->body, $draft->language),
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
     * @param array<string, Problem> $problems
     */
    private function fromEditor(string $text, SendDraftValues $draft, string $field, array &$problems): string
    {
        $stored = $this->labels->fromEditor($text, $draft->language);
        if ([] !== $stored['unknown']) {
            $problems[$field] = $this->problem($field, 'invoice.send.error.unknown_variables', ['names' => implode(', ', $stored['unknown'])]);
        }

        return $stored['text'];
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
            SendRefused::UNKNOWN_VARIABLE => $this->problem(self::BODY, 'invoice.send.error.unknown_variables', ['names' => implode(', ', $refused->details)]),
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
    private function page(Invoice $invoice, SendDraftValues $draft, array $values, array $problems, string $token, string $return, int $status): Response
    {
        $errors = [];
        foreach ($problems as $problem) {
            $errors[$problem['id']] = $problem['message'];
        }

        return $this->render('invoicing/send.html.twig', [
            'invoice' => $invoice,
            'draft' => $draft,
            'values' => $values,
            'problems' => $problems,
            'errors' => $errors,
            'lastSent' => $draft->lastSentAt?->setTimezone($this->timezoneOf($invoice))->format($this->translator->trans('format.datetime')),
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

        return $this->generateUrl('app_invoices', array_filter(
            array_intersect_key($query, array_flip(InvoiceListController::LIST_QUERY)),
            \is_string(...),
        ));
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
