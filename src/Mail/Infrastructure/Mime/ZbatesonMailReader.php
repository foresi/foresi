<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Mime;

use Foresi\Mail\Application\MailParts;
use Foresi\Mail\Application\MailReader;
use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailAttachmentContent;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Reads mail with zbateson/mail-mime-parser, the only code that touches the
 * library. Everything is taken out of the parsed message at once, so the
 * InboundMail holds plain values and no open stream.
 *
 * The library reads almost any bytes as a message. A message is refused unless
 * its first line is a header field, which is how every RFC 5322 message starts.
 *
 * The library decodes the headers, the text and the attachments lazily, when
 * they are asked for after parsing, so the whole reading is guarded: whatever
 * the library throws, there or later, the mail is unreadable, never an error that
 * stops the worker or the poller.
 */
final class ZbatesonMailReader implements MailReader, MailParts
{
    private const string FIRST_HEADER = '/\A[\x21-\x39\x3B-\x7E]+[ \t]*:/';

    /**
     * @param MailMimeParser|null $parser a parser to use in place of a new one, for a test
     */
    public function __construct(private ?MailMimeParser $parser = null)
    {
    }

    public function read(string $raw): InboundMail
    {
        if (1 !== preg_match(self::FIRST_HEADER, $raw)) {
            throw new \InvalidArgumentException('The bytes do not start with a mail header.');
        }

        try {
            return $this->mail(($this->parser ??= new MailMimeParser())->parse($raw, false), $raw);
        } catch (\InvalidArgumentException $unreadable) {
            throw $unreadable;
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('The mail could not be read.', 0, $error);
        }
    }

    public function attachments(string $raw): array
    {
        $attachments = [];
        foreach ($this->parts($raw) as $index => $part) {
            $attachments[] = new MailAttachment($index, $this->nameOf($part, $index), $this->typeOf($part), \strlen($this->bytesOf($part)));
        }

        return $attachments;
    }

    public function attachment(string $raw, int $index): MailAttachmentContent
    {
        $part = $this->parts($raw)[$index] ?? throw new \OutOfRangeException('The mail has no attachment at this index.');

        return new MailAttachmentContent($this->nameOf($part, $index), $this->typeOf($part), $this->bytesOf($part));
    }

    /**
     * @return list<IMessagePart>
     */
    private function parts(string $raw): array
    {
        if (1 !== preg_match(self::FIRST_HEADER, $raw)) {
            throw new \InvalidArgumentException('The bytes do not start with a mail header.');
        }

        return $this->guarded(fn (): array => array_values(($this->parser ??= new MailMimeParser())->parse($raw, false)->getAllAttachmentParts()));
    }

    private function nameOf(IMessagePart $part, int $index): string
    {
        $name = $this->guarded(static fn (): ?string => $part->getFilename());

        return null === $name || '' === trim($name) ? 'attachment-'.($index + 1) : trim($name);
    }

    /**
     * The bytes with the transfer encoding undone and no charset converted.
     */
    private function bytesOf(IMessagePart $part): string
    {
        return $this->guarded(static fn (): string => (string) ($part->getBinaryContentStream() ?? ''));
    }

    private function typeOf(IMessagePart $part): string
    {
        return strtolower($this->guarded(static fn (): string => $part->getContentType('application/octet-stream')));
    }

    /**
     * Whatever the library throws while decoding lazily makes the mail unreadable.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function guarded(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\InvalidArgumentException $unreadable) {
            throw $unreadable;
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('The mail could not be read.', 0, $error);
        }
    }

    private function mail(IMessage $message, string $raw): InboundMail
    {
        $headers = [];
        foreach ($message->getRawHeaders() as [$name, $value]) {
            // The obsolete syntax allows white space before the colon (RFC 5322 §4.5.1), so "From :" is a From.
            $headers[] = [rtrim($name, " \t"), $this->unfold($value)];
        }
        if ([] === $headers) {
            throw new \InvalidArgumentException('The mail has no header.');
        }

        $from = $message->getHeader('From');
        $date = $message->getHeader('Date');
        $attachmentNames = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $name = $part->getFilename();
            if (null !== $name && '' !== $name) {
                $attachmentNames[] = $name;
            }
        }

        return new InboundMail(
            headers: $headers,
            from: $from instanceof AddressHeader ? $from->getEmail() : null,
            subject: $message->getSubject() ?? '',
            messageId: $this->nonEmpty($message->getMessageId()),
            date: $date instanceof DateHeader ? $date->getDateTimeImmutable() : null,
            text: $message->getTextContent(),
            html: $message->getHtmlContent(),
            attachmentNames: $attachmentNames,
            sha256: hash('sha256', $raw),
            size: \strlen($raw),
        );
    }

    /**
     * RFC 5322 unfolding: a line break followed by white space joins the lines;
     * runs of white space become one space, so a value reads the same however
     * the sending server wrapped it.
     */
    private function unfold(string $value): string
    {
        $unfolded = preg_replace('/\r?\n(?=[ \t])/', '', $value) ?? $value;

        return trim(preg_replace('/[ \t]+/', ' ', $unfolded) ?? $unfolded);
    }

    private function nonEmpty(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
