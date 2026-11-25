<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\AuthenticationResult;
use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Contract\MailAttachmentContent;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Mail\Contract\UnknownMailMessage;
use Foresi\Mail\Domain\MailMessage;

/**
 * The stored messages as other modules read them: the row, and the raw file
 * read anew, checked against its hash, for everything else.
 */
final readonly class StoredMessages implements Messages
{
    public function __construct(
        private MailMessages $messages,
        private InboundFiles $files,
        private MailReader $reader,
        private MailParts $parts,
        private AuthenticationResultsParser $parser,
        private TrustSettings $settings,
        private SenderVerdict $verdict,
    ) {
    }

    public function find(string $messageId): ?MailMessageView
    {
        $message = $this->messages->find($messageId);

        return $message instanceof MailMessage ? $this->view($message) : null;
    }

    public function findMany(array $messageIds): array
    {
        $views = [];
        foreach ([] === $messageIds ? [] : $this->messages->findMany(array_values(array_unique($messageIds))) as $message) {
            $views[$message->id()] = $this->view($message);
        }

        return $views;
    }

    private function view(MailMessage $message): MailMessageView
    {
        $address = $message->address();

        return new MailMessageView(
            $message->id(),
            $address->id(),
            $address->purpose(),
            $address->ownerUserId(),
            $message->channel()->value,
            $message->messageIdHeader(),
            $message->inReplyTo(),
            $message->references(),
            $message->fromAddress(),
            $message->subject(),
            $message->sentAt(),
            $message->receivedAt(),
            $message->verdict(),
            $message->rawSha256(),
            $message->size(),
        );
    }

    public function raw(string $messageId): string
    {
        return $this->rawOf($this->stored($messageId));
    }

    public function mail(string $messageId): InboundMail
    {
        return $this->reader->read($this->raw($messageId));
    }

    public function text(string $messageId): ?string
    {
        try {
            $mail = $this->mail($messageId);
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }
        $text = $mail->text();
        if (null !== $text && '' !== trim($text)) {
            return $text;
        }
        $html = $mail->html();

        return null === $html ? null : HtmlText::of($html);
    }

    public function attachments(string $messageId): array
    {
        try {
            return $this->parts->attachments($this->raw($messageId));
        } catch (\RuntimeException|\InvalidArgumentException) {
            return [];
        }
    }

    public function attachment(string $messageId, int $index): MailAttachmentContent
    {
        try {
            return $this->parts->attachment($this->raw($messageId), $index);
        } catch (\OutOfRangeException) {
            throw new UnknownMailMessage('The stored mail message has no such attachment.');
        } catch (UnknownMailMessage $unknown) {
            throw $unknown;
        } catch (\RuntimeException|\InvalidArgumentException) {
            throw new UnknownMailMessage('The stored mail message cannot be read.');
        }
    }

    public function authenticationResults(string $messageId): array
    {
        try {
            $message = $this->stored($messageId);
            $mail = $this->reader->read($this->rawOf($message));
        } catch (\RuntimeException|\InvalidArgumentException) {
            return [];
        }
        $results = [];
        foreach ($mail->headers('Authentication-Results') as $header) {
            $parsed = $this->parser->parse($header);
            $authservId = TrustSettings::name($parsed['authserv']);
            $results[] = new AuthenticationResult(
                $authservId,
                '' !== $authservId && $this->settings->trustsAuthserv($authservId, $message->channel()),
                $parsed['results'],
            );
        }

        return $results;
    }

    public function verdict(string $messageId, array $senderDomains): SenderCheck
    {
        $message = $this->stored($messageId);
        $verdict = $this->verdict->judge($this->reader->read($this->rawOf($message)), $message->channel(), $senderDomains);

        return new SenderCheck($verdict->result, $verdict->basis);
    }

    private function stored(string $messageId): MailMessage
    {
        return $this->messages->find($messageId) ?? throw new UnknownMailMessage();
    }

    private function rawOf(MailMessage $message): string
    {
        return $this->files->read($message->id(), $message->rawKey(), $message->rawSha256());
    }
}
