<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Controller;

use Foresi\Banking\Application\AcceptRefused;
use Foresi\Banking\Application\AcceptRejectedMessage;
use Foresi\Banking\Application\BankScreen;
use Foresi\Banking\Application\DismissInboundMessage;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\Ownership;
use Foresi\Banking\Application\ProcessMessage;
use Foresi\Banking\Application\RetryInboundMessage;
use Foresi\Banking\Application\UnknownInboundMessage;
use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Banking\Contract\NotificationLines;
use Foresi\Banking\Domain\InboundMessage;
use Foresi\Banking\Domain\InboundStatus;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Party\Application\InstanceFirm;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bank mail of the Messages tab, for staff: a message as it came, in a
 * dialog with its text escaped; trying it again; recording a trust rejection
 * anyway after the payment was checked in the bank, which only the owner may, as
 * the message may be forged; and dismissing what nobody will record. Recording
 * anyway and dismissing ask first, in a dialog that is a page of its own as well.
 * An unknown message is not found.
 */
final class InboundMessageController extends AbstractController
{
    public const string ACTION_TOKEN = 'bank_message';

    public function __construct(
        private readonly InboundMessages $messages,
        private readonly Messages $mail,
        private readonly RetryInboundMessage $retrying,
        private readonly AcceptRejectedMessage $accepting,
        private readonly DismissInboundMessage $dismissing,
        private readonly BankText $text,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly AddressIssuer $addresses,
        private readonly Ownership $ownership,
        private readonly InstanceFirm $firm,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/bank/messages/{id}', name: 'app_bank_message', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyUnlessStaff();
        $message = $this->message($id);
        $mail = $this->mailOf($message);
        $row = $this->messages->row($id, BankScreen::stuckBefore($this->clock)) ?? throw new NotFoundHttpException();

        $body = $this->body($message);

        return $this->render('@Banking/message.html.twig', [
            'message' => $message,
            'subject' => $this->addresses->masked($mail->subject),
            'from' => null === $mail->from ? null : $this->addresses->masked($mail->from),
            'received' => $this->text->time($row->receivedAt),
            'account' => $this->firm->get()->name().' · '.$this->text->account($row->accountLabel, $row->accountIban),
            'status' => $this->text->status($row->status, $row->stuck),
            'reason' => $this->text->reason($row->status, $row->failure, $row->stuck),
            'trust' => $row->trust instanceof MessageTrust ? $this->translator->trans('bank.message.trust.'.$row->trust->value) : null,
            // MessageRow names no parser for the signature of the parser set.
            'parser' => null === $row->parserId ? null : $row->parserId.(null === $row->parserVersion ? '' : ' v'.$row->parserVersion),
            'attempts' => $row->attempts,
            'body' => null === $body ? null : $this->addresses->masked($body),
            'cancel' => $this->listUrl(''),
        ]);
    }

    #[Route('/bank/messages/{id}/retry', name: 'app_bank_message_retry', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function retry(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $message = $this->message($id);
        $this->checkToken($request);
        $return = $request->request->getString('return');
        if (InboundStatus::Received === $message->status() && !$this->stuck($message)) {
            $this->addFlash('warning', $this->translator->trans('bank.message.flash.queued'));

            return $this->toList($return);
        }
        try {
            $status = $this->retrying->retry($id);
        } catch (\Throwable $failure) {
            // Undone and counted by ProcessMessage: the person learns it failed, the log says why.
            $this->logger->error('Trying the inbound message {message} again failed.', ['message' => $id, 'exception' => $failure]);
            $this->addFlash('error', $this->translator->trans('bank.message.flash.retry_failed'));

            return $this->toList($return);
        }
        match ($status) {
            InboundStatus::Processed => $this->addFlash('success', $this->translator->trans('bank.message.flash.processed')),
            InboundStatus::Failed, InboundStatus::Rejected => $this->addFlash('error', $this->translator->trans('bank.message.flash.still', ['reason' => $this->text->reason($status, $this->message($id)->failure(), false)])),
            default => $this->addFlash('warning', $this->translator->trans('bank.message.flash.now', ['status' => $this->text->status($status, false)['text']])),
        };

        return $this->toList($return);
    }

    #[Route('/bank/messages/{id}/accept', name: 'app_bank_message_accept', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function accept(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $message = $this->message($id);
        // A message that failed the sender check may be forged: only the owner records it.
        if (!$this->ownership->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner records a bank e-mail anyway.');
        }
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        if (InboundStatus::Rejected !== $message->status() || ProcessMessage::UNTRUSTED !== $message->failure()) {
            $this->addFlash('error', $this->translator->trans(ProcessMessage::ACCOUNT_MISMATCH === $message->failure() ? 'bank.message.refused.account_mismatch' : 'bank.message.refused.not_rejected'));

            return $this->toList($return);
        }
        if (!$post) {
            return $this->render('@Banking/accept.html.twig', ['message' => $message, 'subject' => $this->addresses->masked($this->mailOf($message)->subject), 'return' => $return, 'cancel' => $this->listUrl($return)]);
        }

        try {
            $status = $this->accepting->accept($id);
        } catch (AcceptRefused $refused) {
            $this->addFlash('error', $this->translator->trans('bank.message.refused.'.$refused->reason));

            return $this->toList($return);
        }
        if (InboundStatus::Processed === $status) {
            $this->addFlash('success', $this->translator->trans('bank.message.flash.accepted'));
        } else {
            $this->addFlash('error', $this->translator->trans('bank.message.flash.still', ['reason' => $this->text->reason($status, $this->message($id)->failure(), false)]));
        }

        return $this->toList($return);
    }

    #[Route('/bank/messages/{id}/dismiss', name: 'app_bank_message_dismiss', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function dismiss(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $message = $this->message($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $dismissable = \in_array($message->status(), DismissInboundMessage::FROM, true) && (InboundStatus::Received !== $message->status() || $this->stuck($message));
        if (!$dismissable) {
            $this->addFlash('warning', $this->translator->trans(InboundStatus::Dismissed === $message->status() ? 'bank.message.flash.dismissed_already' : 'bank.message.refused.not_dismissable'));

            return $this->toList($return);
        }
        if (!$post) {
            return $this->render('@Banking/dismiss.html.twig', [
                'message' => $message,
                'subject' => $this->addresses->masked($this->mailOf($message)->subject),
                'reason' => $this->text->reason($message->status(), $message->failure(), $this->stuck($message)),
                'return' => $return,
                'cancel' => $this->listUrl($return),
            ]);
        }

        if ($this->dismissing->dismiss($id)) {
            $this->addFlash('success', $this->translator->trans('bank.message.flash.dismissed'));
        } else {
            $this->addFlash('warning', $this->translator->trans('bank.message.flash.dismissed_already'));
        }

        return $this->toList($return);
    }

    /**
     * The text of the mail as a person reads it, or null when the stored file is not
     * the one that came, or not a mail. Twig escapes it: a body is never markup here.
     */
    private function body(InboundMessage $message): ?string
    {
        try {
            $mail = $this->mail->mail($message->mailMessageId());
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }
        $text = $mail->text();
        if (null === $text || '' === trim($text)) {
            $html = $mail->html();
            $text = null === $html ? '' : implode("\n", NotificationLines::of($html, true));
        }

        return trim($text);
    }

    private function stuck(InboundMessage $message): bool
    {
        return InboundStatus::Received === $message->status() && $this->mailOf($message)->receivedAt < BankScreen::stuckBefore($this->clock);
    }

    /**
     * What Mail keeps of the message's mail; a message of no mail is not found.
     */
    private function mailOf(InboundMessage $message): MailMessageView
    {
        return $this->mail->find($message->mailMessageId()) ?? throw new NotFoundHttpException();
    }

    private function message(string $id): InboundMessage
    {
        try {
            return $this->messages->find($id) ?? throw new UnknownInboundMessage();
        } catch (UnknownInboundMessage) {
            throw new NotFoundHttpException();
        }
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * The Messages tab, with only the known parts of the query, which travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);
        $query = array_filter(array_intersect_key($query, array_flip(BankMovementController::LIST_QUERY)), \is_string(...));

        return $this->generateUrl('app_bank_movements', ['view' => BankMovementLists::MESSAGES] + array_diff_key($query, ['tab' => true]));
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with bank mail.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
