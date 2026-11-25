<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * The stored inbound messages, read by their id. The raw file is read anew on
 * every call and checked against the hash stored with it. Everything taken out
 * of a message is the sender's text: show it escaped, never follow it.
 */
interface Messages
{
    public function find(string $messageId): ?MailMessageView;

    /**
     * Several messages at once, as find() reads each, for a list.
     *
     * @param list<string> $messageIds
     *
     * @return array<string, MailMessageView> by message id; an unknown id is left out
     */
    public function findMany(array $messageIds): array;

    /**
     * The bytes as they came.
     *
     * @throws UnknownMailMessage
     * @throws \RuntimeException  when the file is missing, of another message or changed
     */
    public function raw(string $messageId): string;

    /**
     * The message as read from its bytes.
     *
     * @throws UnknownMailMessage
     * @throws \RuntimeException         when the file is missing, of another message or changed
     * @throws \InvalidArgumentException when the bytes are not a mail
     */
    public function mail(string $messageId): InboundMail;

    /**
     * The plain text body, or the HTML body as text; null when the message has
     * neither or cannot be read.
     */
    public function text(string $messageId): ?string;

    /**
     * @return list<MailAttachment> in message order; none when the message cannot be read
     */
    public function attachments(string $messageId): array;

    /**
     * @throws UnknownMailMessage when the message or the attachment is unknown, or the message cannot be read
     */
    public function attachment(string $messageId, int $index): MailAttachmentContent;

    /**
     * Every Authentication-Results header, topmost first; none when the message cannot be read.
     *
     * @return list<AuthenticationResult>
     */
    public function authenticationResults(string $messageId): array;

    /**
     * Who sent the message, judged against these domains, or against its own From
     * domain when the list is empty, by the receiving servers trusted for the
     * channel it came by.
     *
     * @param list<string> $senderDomains
     *
     * @throws UnknownMailMessage
     * @throws \RuntimeException         when the file is missing, of another message or changed
     * @throws \InvalidArgumentException when the bytes are not a mail
     */
    public function verdict(string $messageId, array $senderDomains): SenderCheck;
}
