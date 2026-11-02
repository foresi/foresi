<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Controller;

use App\Banking\Application\AcceptRefused;
use App\Banking\Application\AcceptRejectedMessage;
use App\Banking\Application\BankScreen;
use App\Banking\Application\DismissInboundMessage;
use App\Banking\Application\InboundAddresses;
use App\Banking\Application\InboundFiles;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\MailReader;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\NotificationLines;
use App\Banking\Application\ProcessMessage;
use App\Banking\Application\RetryInboundMessage;
use App\Banking\Application\UnknownInboundMessage;
use App\Banking\Domain\InboundMessage;
use App\Banking\Domain\InboundStatus;
use App\Banking\Domain\MessageTrust;
use App\Identity\Application\StaffCheck;
use Psr\Clock\ClockInterface;
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
 * anyway after the payment was checked in the bank; and dismissing what nobody
 * will record. Recording anyway and dismissing ask first, in a dialog that is a
 * page of its own as well. A message of another tenant is never found.
 */
final class InboundMessageController extends AbstractController
{
    public const string ACTION_TOKEN = 'bank_message';

    public function __construct(
        private readonly InboundMessages $messages,
        private readonly InboundFiles $files,
        private readonly MailReader $reader,
        private readonly RetryInboundMessage $retrying,
        private readonly AcceptRejectedMessage $accepting,
        private readonly DismissInboundMessage $dismissing,
        private readonly BankText $text,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly InboundAddresses $addresses,
    ) {
    }

    #[Route('/bank/messages/{id}', name: 'app_bank_message', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyUnlessStaff();
        $message = $this->message($id);
        $row = $this->messages->row($id, BankScreen::stuckBefore($this->clock)) ?? throw new NotFoundHttpException();

        $body = $this->body($message);

        return $this->render('banking/message.html.twig', [
            'message' => $message,
            'subject' => $this->addresses->masked($message->subject()),
            'from' => null === $message->fromAddress() ? null : $this->addresses->masked($message->fromAddress()),
            'received' => $this->text->time($row->receivedAt, $row->firmCountry),
            'account' => $row->firmName.' · '.$this->text->account($row->accountLabel, $row->accountIban, $row->firmCountry),
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
        $status = $this->retrying->retry($id);
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
            return $this->render('banking/accept.html.twig', ['message' => $message, 'subject' => $this->addresses->masked($message->subject()), 'return' => $return, 'cancel' => $this->listUrl($return)]);
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
            return $this->render('banking/dismiss.html.twig', [
                'message' => $message,
                'subject' => $this->addresses->masked($message->subject()),
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
            $mail = $this->reader->read($this->files->read($message->tenantId()->toString(), $message->rawKey(), $message->rawSha256()));
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
        return InboundStatus::Received === $message->status() && $message->receivedAt() < BankScreen::stuckBefore($this->clock);
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

        return $this->generateUrl('app_bank_movements', ['tab' => MovementFilter::MESSAGES] + $query);
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
