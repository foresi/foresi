<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Mail;

use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressOwners;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailAttachmentContent;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\NotAccepted;
use Foresi\Mail\Contract\OwnAddresses;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Purchasing\Application\DocumentWarnings;
use Foresi\Purchasing\Application\Mail\FileMailAttachments;
use Foresi\Purchasing\Application\Mail\MailAttachmentFiling;
use Foresi\Purchasing\Application\Mail\MailFilingQueue;
use Foresi\Purchasing\Application\Mail\MailIntakes;
use Foresi\Purchasing\Application\Mail\ReceivedDocumentsPurpose;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Application\UploadReceivedDocuments;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\MailIntake;
use Foresi\Purchasing\Domain\MailIntakeOutcome;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Infrastructure\Messenger\FileMailAttachmentsHandler;
use Foresi\Purchasing\Infrastructure\Messenger\ReadReceivedDocumentHandler;
use Foresi\Tests\Banking\Support\Scalars;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;
use Foresi\Tests\Support\IsdocFixtures;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A mail to the capture address of a person: each attachment a reader can open
 * becomes a received-document draft of that person, source mail, and the
 * attachments that are not documents are listed and left out. The mails are
 * built here as raw .eml text; senders and names are made up.
 */
final class MailAttachmentFilingTest extends PurchasingTestCase
{
    /** The smallest PNG: one transparent pixel. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const string FAILED = 'Authentication-Results: mx.example.com; dmarc=fail header.from=supplier.example.com';

    private string $address;
    private string $addressId;

    protected function setUp(): void
    {
        parent::setUp();
        $issued = $this->addresses()->create(ReceivedDocumentsPurpose::KEY, 'Documents');
        $this->address = $issued->address;
        $this->addressId = $issued->id;
    }

    public function testEachPdfBecomesADraftWithSourceMail(): void
    {
        $messageId = $this->receive([
            $this->part('first.pdf', 'application/pdf', "%PDF-1.4\nFirst invoice\n%%EOF"),
            $this->part('second.pdf', 'application/pdf', "%PDF-1.4\nSecond invoice\n%%EOF"),
        ]);

        // Nothing is made while Mail stores the message: it is queued, once.
        self::assertSame(0, $this->documentCount());
        self::assertSame([$messageId], array_map(static fn (FileMailAttachments $job): string => $job->messageId, $this->filingJobs()));
        self::assertSame(MailIntakeOutcome::Queued, $this->intake($messageId)->outcome());

        $this->fileMail($messageId);

        $readings = $this->readingJobs();
        self::assertCount(2, $readings, 'One reading is queued for each draft.');
        $names = [];
        foreach ($readings as $job) {
            $document = $this->document($job->documentId);
            self::assertSame(DocumentSource::Mail, $document->source());
            self::assertSame($messageId, $document->sourceMailId());
            self::assertSame($this->owner->user()->id(), $document->createdBy());
            self::assertFalse($document->isRecorded());
            $original = $this->original($document);
            self::assertSame($this->owner->user()->id(), $original->uploadedBy(), 'The owner of the address is the uploader.');
            $names[] = $original->fileName();
        }
        self::assertSame(['first.pdf', 'second.pdf'], $names, 'In mail order.');
        $intake = $this->intake($messageId);
        self::assertSame([MailIntakeOutcome::Filed, 2, []], [$intake->outcome(), $intake->drafts(), $intake->skipped()]);
        self::assertNotNull($intake->finishedAt());
        self::assertSame($this->addressId, $intake->mailAddressId());
        self::assertSame('2026-12-10 09:00:00', $this->purgeAfter($messageId), 'Released to Mail once the files are in Attachment, kept for a while for its sender and subject.');
    }

    public function testInlineLogoIsSkippedInlinePdfIsKept(): void
    {
        $messageId = $this->receive([
            $this->part('logo.png', 'image/png', base64_decode(self::PNG, true), inline: true, contentId: 'logo@mail.example.com'),
            $this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nApple Mail invoice\n%%EOF", inline: true),
            $this->part('scan.png', 'image/png', base64_decode(self::PNG, true)),
        ]);

        $this->fileMail($messageId);

        $names = array_map(fn (ReadReceivedDocument $job): string => $this->original($this->document($job->documentId))->fileName(), $this->readingJobs());
        self::assertSame(['invoice.pdf', 'scan.png'], $names, 'An attached PNG without a Content-ID is a scan; the inline PDF stays.');
        $intake = $this->intake($messageId);
        self::assertSame(2, $intake->drafts());
        self::assertSame([['name' => 'logo.png', 'reason' => 'inline_image']], $intake->skipped());
    }

    public function testMailWithoutAcceptedAttachmentMakesNoDraftAndIsLogged(): void
    {
        $messageId = $this->receive([
            $this->part('notes.txt', 'text/plain', 'Please find the invoice below.'),
            $this->part('invoice.pdf', 'application/pdf', 'This is not a PDF at all, whatever the sender says.'),
            $this->part('logo.png', 'image/png', base64_decode(self::PNG, true), inline: true, contentId: 'logo@mail.example.com'),
        ]);

        $this->fileMail($messageId);

        self::assertSame(0, $this->documentCount());
        self::assertSame([], $this->readingJobs());
        $intake = $this->intake($messageId);
        self::assertSame(MailIntakeOutcome::NothingAccepted, $intake->outcome());
        self::assertSame(0, $intake->drafts());
        self::assertSame([
            ['name' => 'notes.txt', 'reason' => 'unsupported_type'],
            ['name' => 'invoice.pdf', 'reason' => 'unsupported_type'],
            ['name' => 'logo.png', 'reason' => 'inline_image'],
        ], $intake->skipped(), 'The type is told by the bytes, not by what the part says.');
    }

    public function testMailWithoutAnyAttachmentIsLoggedAsNothingAccepted(): void
    {
        $messageId = $this->receive([]);

        $this->fileMail($messageId);

        self::assertSame(MailIntakeOutcome::NothingAccepted, $this->intake($messageId)->outcome());
        self::assertSame(0, $this->documentCount());
        self::assertNotNull($this->purgeAfter($messageId), 'Nothing to keep it for.');
    }

    public function testAnAttachmentOverTheLimitAndThoseBeyondTheDraftLimitAreLeftOut(): void
    {
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nOne\n%%EOF")]);
        $container = self::getContainer();
        $huge = new MailAttachment(0, 'huge.pdf', 'application/pdf', 10 * 1024 * 1024 + 1);
        $many = array_map(static fn (int $index): MailAttachment => new MailAttachment($index + 1, 'part'.$index.'.pdf', 'application/pdf', 30), range(0, MailAttachmentFiling::MAX_DRAFTS));
        $messages = new readonly class([$huge, ...$many]) implements Messages {
            /** @param list<MailAttachment> $parts */
            public function __construct(private array $parts)
            {
            }

            public function find(string $messageId): never
            {
                throw new \LogicException('Not used.');
            }

            public function raw(string $messageId): never
            {
                throw new \LogicException('Not used.');
            }

            public function mail(string $messageId): never
            {
                throw new \LogicException('Not used.');
            }

            public function text(string $messageId): never
            {
                throw new \LogicException('Not used.');
            }

            public function attachments(string $messageId): array
            {
                return $this->parts;
            }

            public function attachment(string $messageId, int $index): MailAttachmentContent
            {
                return new MailAttachmentContent('part.pdf', 'application/pdf', "%PDF-1.4\npart ".$index."\n%%EOF");
            }

            public function authenticationResults(string $messageId): never
            {
                throw new \LogicException('Not used.');
            }

            public function verdict(string $messageId, array $senderDomains): never
            {
                throw new \LogicException('Not used.');
            }
        };

        new MailAttachmentFiling(
            $container->get(MailIntakes::class),
            $messages,
            $container->get(StaffCheck::class),
            $container->get(UploadReceivedDocuments::class),
            $container->get(PurchasingUnit::class),
            $container->get(MessageRetention::class),
            $container->get(ClockInterface::class),
        )->file($messageId);

        $intake = $this->intake($messageId);
        self::assertSame(MailAttachmentFiling::MAX_DRAFTS, $intake->drafts());
        self::assertSame(MailAttachmentFiling::MAX_DRAFTS, $this->documentCount());
        self::assertSame([['name' => 'huge.pdf', 'reason' => 'too_large'], ['name' => 'part'.MailAttachmentFiling::MAX_DRAFTS.'.pdf', 'reason' => 'too_many']], $intake->skipped());
    }

    public function testIsdocAttachmentIsRead(): void
    {
        $messageId = $this->receive([$this->part('invoice.isdoc', 'application/octet-stream', IsdocFixtures::invoice())]);

        $this->fileMail($messageId);

        $jobs = $this->readingJobs();
        self::assertCount(1, $jobs);
        self::assertSame(DocumentSource::Mail, $this->document($jobs[0]->documentId)->source());
        self::getContainer()->get(ReadReceivedDocumentHandler::class)($jobs[0]);

        $this->em->clear();
        $reading = $this->em->find(DocumentReading::class, $jobs[0]->documentId);
        self::assertInstanceOf(DocumentReading::class, $reading);
        self::assertSame(ReadingState::Read, $reading->state());
        self::assertContains('isdoc', array_map(static fn (\Foresi\Purchasing\Contract\Reading\ReadValue $value): string => $value->source, $reading->reading()->values));
        self::assertNotSame([], $reading->applied());
    }

    public function testSameFileTwiceWarnsDuplicateFile(): void
    {
        $pdf = "%PDF-1.4\nThe same invoice twice\n%%EOF";
        $first = $this->receive([$this->part('invoice.pdf', 'application/pdf', $pdf)], 'one@mail.example.com');
        $second = $this->receive([$this->part('copy.pdf', 'application/pdf', $pdf)], 'two@mail.example.com');

        $this->fileMail($first);
        $this->fileMail($second);

        // Both are drafts: a duplicate is never refused, only warned about.
        self::assertSame(2, $this->documentCount());
        $jobs = $this->readingJobs();
        $firstDocument = $this->document($jobs[0]->documentId);
        $secondDocument = $this->document($jobs[1]->documentId);
        $warnings = self::getContainer()->get(DocumentWarnings::class);
        self::assertSame([$firstDocument->id()], $warnings->of($secondDocument)->get(ReceivedDocumentWarning::DUPLICATE_FILE)?->documentIds);
        self::assertSame([$secondDocument->id()], $warnings->of($firstDocument)->get(ReceivedDocumentWarning::DUPLICATE_FILE)?->documentIds);
    }

    public function testFailedVerdictWarnsSenderUnverified(): void
    {
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nSpoofed\n%%EOF")], headers: [self::FAILED]);

        $this->fileMail($messageId);

        self::assertSame('failed', $this->intake($messageId)->verdict());
        $document = $this->document($this->readingJobs()[0]->documentId);
        self::assertTrue($this->warningsOf($document)->has(ReceivedDocumentWarning::SENDER_UNVERIFIED));
    }

    public function testUnknownVerdictDoesNotWarn(): void
    {
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nUnsigned\n%%EOF")]);

        $this->fileMail($messageId);

        self::assertSame('unknown', $this->intake($messageId)->verdict());
        $document = $this->document($this->readingJobs()[0]->documentId);
        self::assertFalse($this->warningsOf($document)->has(ReceivedDocumentWarning::SENDER_UNVERIFIED));
        // A draft from anywhere else has none either.
        $other = $this->uploads()->upload([$this->file('%PDF-1.4 typed')])[0];
        self::assertFalse($other->warnings->has(ReceivedDocumentWarning::SENDER_UNVERIFIED));
    }

    public function testOwnerWhoLeftIsNotAccepted(): void
    {
        $this->signIn($this->member);
        $address = $this->addresses()->create(ReceivedDocumentsPurpose::KEY, 'Marek')->address;
        $this->member->deactivate(new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->em->flush();

        $outcome = self::getContainer()->get(ReceiveMail::class)->receive($this->raw($address, [$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nLate\n%%EOF")], 'left@mail.example.com'), InboundChannel::Webhook);

        self::assertSame(ReceiveOutcome::UnknownRecipient, $outcome, 'Mail keeps nothing.');
        self::assertSame(0, $this->rows('mail_message'));
        self::assertSame(0, $this->rows('purchasing_mail_intake'));
        self::assertSame([], $this->filingJobs());
    }

    public function testOwnerWhoLeftAfterTheMailWasTakenInGetsNoDraft(): void
    {
        $this->signIn($this->member);
        $address = $this->addresses()->create(ReceivedDocumentsPurpose::KEY, 'Marek')->address;
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nLate\n%%EOF")], address: $address);
        $this->member->deactivate(new \DateTimeImmutable('2026-11-10 08:30:00'));
        $this->em->flush();

        $this->fileMail($messageId);

        self::assertSame(0, $this->documentCount());
        $intake = $this->intake($messageId);
        self::assertSame(MailIntakeOutcome::NothingAccepted, $intake->outcome());
        self::assertSame([['name' => '', 'reason' => 'owner_left']], $intake->skipped());
    }

    public function testRevokedAddressIsNotAccepted(): void
    {
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nSent\n%%EOF")]);
        $this->addresses()->revoke($this->addressId);

        // The same mail again to the revoked address is not routed at all.
        $outcome = self::getContainer()->get(ReceiveMail::class)->receive($this->raw($this->address, [], 'revoked@mail.example.com'), InboundChannel::Webhook);
        self::assertSame(ReceiveOutcome::UnknownRecipient, $outcome);
        // And the purpose itself turns away an address that is revoked, unknown or of nobody.
        $feed = self::getContainer()->get(\Foresi\Mail\Contract\AddressIssuer::class)->issue('bank_feed', null, 'Bank feed');
        foreach ([$this->addressId, '11111111-1111-4111-8111-111111111111', $feed->id] as $mailAddressId) {
            try {
                $this->purpose()->accept($messageId, $mailAddressId);
                self::fail('The mail was accepted.');
            } catch (NotAccepted) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1, $this->rows('purchasing_mail_intake'), 'Only the mail taken in before the address was revoked.');
    }

    public function testRunningTwiceFilesOnce(): void
    {
        $messageId = $this->receive([
            $this->part('first.pdf', 'application/pdf', "%PDF-1.4\nFirst\n%%EOF"),
            $this->part('second.pdf', 'application/pdf', "%PDF-1.4\nSecond\n%%EOF"),
        ]);

        $this->fileMail($messageId);
        $this->fileMail($messageId);

        self::assertSame(2, $this->documentCount());
        self::assertCount(2, $this->readingJobs());
        self::assertSame(MailIntakeOutcome::Filed, $this->intake($messageId)->outcome());
    }

    public function testARunThatStartsAgainAfterACrashGoesOnWhereTheOneBeforeStopped(): void
    {
        $messageId = $this->crashAfterTheFirstDraft();

        $this->fileMail($messageId);

        self::assertSame(2, $this->documentCount(), 'The first attachment is not made again.');
        $intake = $this->intake($messageId);
        self::assertSame([MailIntakeOutcome::Filed, 2], [$intake->outcome(), $intake->drafts()]);
    }

    public function testADraftDeletedBeforeTheRetryIsNotFiledAgain(): void
    {
        $messageId = $this->crashAfterTheFirstDraft();
        $this->em->getConnection()->executeStatement('DELETE FROM received_document WHERE source_mail_id = ?', [$messageId]);

        $this->fileMail($messageId);

        self::assertSame(1, $this->documentCount(), 'Only the second attachment is filed; the deleted draft stays deleted.');
        $left = $this->document(Scalars::text($this->em->getConnection()->fetchOne('SELECT id FROM received_document')));
        self::assertSame('second.pdf', $this->original($left)->fileName());
        self::assertSame(MailIntakeOutcome::Filed, $this->intake($messageId)->outcome());
    }

    public function testAMailTheQueueGaveUpOnIsMarkedFailed(): void
    {
        $messageId = $this->receive([$this->part('invoice.pdf', 'application/pdf', "%PDF-1.4\nOne\n%%EOF")]);

        self::getContainer()->get(MailAttachmentFiling::class)->giveUp($messageId);
        $this->fileMail($messageId);

        self::assertSame(MailIntakeOutcome::Failed, $this->intake($messageId)->outcome());
        self::assertSame(0, $this->documentCount(), 'A mail that failed is not filed afterwards.');
        self::assertNull($this->purgeAfter($messageId), 'A mail that failed is kept: it may be the only copy of a document.');
    }

    public function testAJobForAMailThatIsNotThereDoesNothing(): void
    {
        $this->fileMail('22222222-2222-4222-8222-222222222222');

        self::assertSame(0, $this->documentCount());
    }

    public function testASystemUploadForSomeoneWhoLeftIsRefused(): void
    {
        $this->signIn($this->member);
        $this->member->deactivate(new \DateTimeImmutable('2026-11-10 08:00:00'));
        $this->em->flush();

        $this->expectException(PurchasingNotAllowed::class);
        self::getContainer()->get(UploadReceivedDocuments::class)->uploadFor($this->member->user()->id(), [$this->file()], DocumentSource::Mail, '33333333-3333-4333-8333-333333333333');
    }

    /**
     * Mail Messages sends the mail to the address, as the webhook does; the id of the stored message.
     *
     * @param list<string> $parts
     * @param list<string> $headers
     */
    private function receive(array $parts, string $messageId = 'mail-1@mail.example.com', array $headers = [], ?string $address = null): string
    {
        $raw = $this->raw($address ?? $this->address, $parts, $messageId, $headers);
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook));

        return Scalars::text($this->em->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]));
    }

    /**
     * @param list<string> $parts   made by part()
     * @param list<string> $headers further header fields, placed first as a receiving server would
     */
    private function raw(string $to, array $parts, string $messageId, array $headers = []): string
    {
        $boundary = 'b-'.hash('crc32b', $messageId);
        $lines = [...$headers, 'From: Pat Supplier <pat@supplier.example.com>', 'To: '.$to, 'Subject: Invoice for November', 'Date: Fri, 20 Nov 2026 10:00:00 +0100', 'Message-ID: <'.$messageId.'>', 'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="'.$boundary.'"'];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHello, the invoice is attached.\r\n";
        foreach ($parts as $part) {
            $body .= "--$boundary\r\n".$part."\r\n";
        }

        return implode("\r\n", $lines)."\r\n\r\n".$body."--$boundary--\r\n";
    }

    private function part(string $name, string $type, string $bytes, bool $inline = false, ?string $contentId = null): string
    {
        $part = 'Content-Type: '.$type.'; name="'.$name.'"'."\r\n"
            .'Content-Transfer-Encoding: base64'."\r\n"
            .'Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$name.'"'."\r\n";
        if (null !== $contentId) {
            $part .= 'Content-ID: <'.$contentId.'>'."\r\n";
        }

        return $part."\r\n".chunk_split(base64_encode($bytes), 76, "\r\n");
    }

    private function fileMail(string $messageId): void
    {
        // As the Messenger worker runs it: nobody is signed in while the job works.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        try {
            self::getContainer()->get(FileMailAttachmentsHandler::class)(new FileMailAttachments($messageId));
        } finally {
            $tokens->setToken($signedIn);
        }
        $this->em->clear();
    }

    /**
     * A mail of two PDFs whose run made the first draft and died on the second,
     * before it wrote the outcome; the id of the mail.
     */
    private function crashAfterTheFirstDraft(): string
    {
        $messageId = $this->receive([
            $this->part('first.pdf', 'application/pdf', "%PDF-1.4\nFirst\n%%EOF"),
            $this->part('second.pdf', 'application/pdf', "%PDF-1.4\nSecond\n%%EOF"),
        ]);
        $container = self::getContainer();
        $stored = $container->get(Messages::class);
        $second = $stored->attachments($messageId)[1]->index;
        // The second attachment is read once to sort it, then the run dies as it reads it for its draft.
        $dying = new class($stored, $second) implements Messages {
            private int $reads = 0;

            public function __construct(private readonly Messages $stored, private readonly int $dieOn)
            {
            }

            public function find(string $messageId): ?\Foresi\Mail\Contract\MailMessageView
            {
                return $this->stored->find($messageId);
            }

            public function raw(string $messageId): string
            {
                return $this->stored->raw($messageId);
            }

            public function mail(string $messageId): \Foresi\Mail\Contract\InboundMail
            {
                return $this->stored->mail($messageId);
            }

            public function text(string $messageId): ?string
            {
                return $this->stored->text($messageId);
            }

            public function attachments(string $messageId): array
            {
                return $this->stored->attachments($messageId);
            }

            public function attachment(string $messageId, int $index): MailAttachmentContent
            {
                if ($index === $this->dieOn && ++$this->reads > 1) {
                    throw new \RuntimeException('The worker died.');
                }

                return $this->stored->attachment($messageId, $index);
            }

            public function authenticationResults(string $messageId): array
            {
                return $this->stored->authenticationResults($messageId);
            }

            public function verdict(string $messageId, array $senderDomains): \Foresi\Mail\Contract\SenderCheck
            {
                return $this->stored->verdict($messageId, $senderDomains);
            }
        };
        $tokens = $container->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        try {
            new MailAttachmentFiling(
                $container->get(MailIntakes::class),
                $dying,
                $container->get(StaffCheck::class),
                $container->get(UploadReceivedDocuments::class),
                $container->get(PurchasingUnit::class),
                $container->get(MessageRetention::class),
                $container->get(ClockInterface::class),
            )->file($messageId);
            self::fail('The run did not die.');
        } catch (\RuntimeException $died) {
            self::assertSame('The worker died.', $died->getMessage());
        } finally {
            $tokens->setToken($signedIn);
        }
        $this->em->clear();
        self::assertSame(1, $this->documentCount(), 'The run before made the first draft.');
        self::assertSame(MailIntakeOutcome::Queued, $this->intake($messageId)->outcome());

        return $messageId;
    }

    private function addresses(): OwnAddresses
    {
        return self::getContainer()->get(OwnAddresses::class);
    }

    private function purgeAfter(string $messageId): ?string
    {
        $after = $this->em->getConnection()->fetchOne('SELECT purge_after FROM mail_message WHERE id = ?', [$messageId]);

        return \is_string($after) ? substr($after, 0, 19) : null;
    }

    private function purpose(): ReceivedDocumentsPurpose
    {
        $container = self::getContainer();

        return new ReceivedDocumentsPurpose(
            $container->get(AddressOwners::class),
            $container->get(StaffCheck::class),
            $container->get(Messages::class),
            $container->get(MailIntakes::class),
            $container->get(MailFilingQueue::class),
            $container->get(ClockInterface::class),
        );
    }

    private function intake(string $messageId): MailIntake
    {
        $this->em->clear();

        return $this->em->find(MailIntake::class, $messageId) ?? throw new \LogicException('No intake.');
    }

    private function original(ReceivedDocument $document): Attachment
    {
        return self::getContainer()->get(Attachments::class)->get((string) $document->originalAttachmentId()) ?? throw new \LogicException('No original.');
    }

    private function warningsOf(ReceivedDocument $document): \Foresi\Purchasing\Application\ReceivedDocumentWarnings
    {
        return self::getContainer()->get(DocumentWarnings::class)->of($document);
    }

    private function documentCount(): int
    {
        return $this->rows('received_document');
    }

    private function rows(string $table): int
    {
        return Scalars::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
    }

    /**
     * @return list<FileMailAttachments>
     */
    private function filingJobs(): array
    {
        return array_values(array_filter($this->sent(), static fn (object $message): bool => $message instanceof FileMailAttachments));
    }

    /**
     * @return list<ReadReceivedDocument>
     */
    private function readingJobs(): array
    {
        return array_values(array_filter($this->sent(), static fn (object $message): bool => $message instanceof ReadReceivedDocument));
    }

    /**
     * @return list<object>
     *
     * @phpstan-impure
     */
    private function sent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }
}
