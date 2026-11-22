<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Application;

use Foresi\Documents\Application\Api\AttachSignedCopyInput;
use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\Api\MarkDocumentSentInput;
use Foresi\Documents\Application\Api\MarkSignerDeclinedInput;
use Foresi\Documents\Application\Api\MarkSignerSignedInput;
use Foresi\Documents\Application\Api\RemoveSignerInput;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\SignerStatus;
use Foresi\Tests\Documents\Support\DocumentsTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ManualSigningTest extends DocumentsTestCase
{
    #[Test]
    public function parallelAndSequentialOrdersAreKeptAsGiven(): void
    {
        $draft = $this->draft();
        $this->signer($draft, 'Second Signer', 2);
        $this->signer($draft, 'First Signer', 1, 'ours');
        $this->signer($draft, 'Parallel Signer', 1);

        $signers = $this->store()->signers($this->reload($draft));

        self::assertSame([['First Signer', 1], ['Parallel Signer', 1], ['Second Signer', 2]], array_map(static fn (\Foresi\Documents\Domain\Signer $signer): array => [$signer->name(), $signer->order()], $signers));
    }

    #[Test]
    public function theOrderIsShownNotEnforced(): void
    {
        $document = $this->ready();
        $first = $this->signer($document, 'First Signer', 1);
        $second = $this->signer($document, 'Second Signer', 2);

        $this->documents()->markSigned(new MarkSignerSignedInput($document->id(), $second->id()));

        self::assertSame(SignerStatus::Signed, $this->store()->signer($this->reload($document), $second->id())?->status());
        self::assertSame(SignerStatus::Pending, $this->store()->signer($this->reload($document), $first->id())?->status());
    }

    #[Test]
    public function uploadFromReadyImpliesSent(): void
    {
        $document = $this->ready();
        $this->signer($document, 'Jana Example', 1);

        $this->documents()->attachSignedCopy(new AttachSignedCopyInput($document->id(), 'signed.pdf', base64_encode(self::PDF)));

        $fresh = $this->reload($document);
        self::assertSame(DocumentStatus::OutForSignature, $fresh->status());
        self::assertSame(hash('sha256', self::PDF), $fresh->signedSha256());
        self::assertNotNull($fresh->sentAt());
        self::assertCount(1, $this->eventData('documents.document.sent_for_signature'));
    }

    #[Test]
    public function completesOnceInEitherOrder(): void
    {
        // The signed copy first, then the last signature.
        $first = $this->ready();
        $a = $this->signer($first, 'Jana Example', 1);
        $b = $this->signer($first, 'Petr Example', 2);
        $this->documents()->markSigned(new MarkSignerSignedInput($first->id(), $a->id()));
        $this->documents()->attachSignedCopy(new AttachSignedCopyInput($first->id(), 'signed.pdf', base64_encode(self::PDF)));
        self::assertSame(DocumentStatus::OutForSignature, $this->reload($first)->status());
        $this->documents()->markSigned(new MarkSignerSignedInput($first->id(), $b->id()));
        self::assertSame(DocumentStatus::Completed, $this->reload($first)->status());

        // Every signature first, then the signed copy.
        $second = $this->ready();
        $c = $this->signer($second, 'Jana Example', 1);
        $this->documents()->markSigned(new MarkSignerSignedInput($second->id(), $c->id()));
        self::assertSame(DocumentStatus::OutForSignature, $this->reload($second)->status());
        $this->documents()->attachSignedCopy(new AttachSignedCopyInput($second->id(), 'signed.pdf', base64_encode(self::PDF)));
        self::assertSame(DocumentStatus::Completed, $this->reload($second)->status());

        // Doing either again changes nothing and completes nothing again.
        foreach ([
            fn (): \Foresi\Documents\Domain\Document => $this->documents()->markSigned(new MarkSignerSignedInput($first->id(), $b->id())),
            fn (): \Foresi\Documents\Domain\Document => $this->documents()->attachSignedCopy(new AttachSignedCopyInput($second->id(), 'signed.pdf', base64_encode(self::PDF))),
        ] as $again) {
            try {
                $again();
                self::fail('A completed document took another signature or copy.');
            } catch (DocumentsRefused $refused) {
                self::assertSame('not_signable', $refused->reason());
            }
        }

        $completed = array_map(static fn (string $data): string => (string) json_decode($data, true, flags: \JSON_THROW_ON_ERROR)['document_id'], $this->eventData('documents.document.completed'));
        self::assertSame([$first->id(), $second->id()], $completed);
        self::assertCount(3, $this->eventData('documents.signer.signed'));
    }

    #[Test]
    public function declinedSignerDeclinesDocument(): void
    {
        $document = $this->ready();
        $signer = $this->signer($document, 'Jana Example', 1);
        $this->documents()->markSent(new MarkDocumentSentInput($document->id()));

        $this->documents()->markDeclined(new MarkSignerDeclinedInput($document->id(), $signer->id()));

        $fresh = $this->reload($document);
        self::assertSame(DocumentStatus::Declined, $fresh->status());
        self::assertSame(SignerStatus::Declined, $this->store()->signer($fresh, $signer->id())?->status());
        self::assertCount(1, $this->eventData('documents.document.declined'));
        $this->expectException(DocumentsRefused::class);
        $this->documents()->removeSigner(new RemoveSignerInput($document->id(), $signer->id()));
    }

    #[Test]
    public function signaturesNeedAGeneratedDocument(): void
    {
        $draft = $this->draft();
        $signer = $this->signer($draft, 'Jana Example', 1);

        try {
            $this->documents()->markSigned(new MarkSignerSignedInput($draft->id(), $signer->id()));
            self::fail('A draft was signed.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('not_signable', $refused->reason());
        }
        $this->expectException(DocumentsRefused::class);
        $this->documents()->attachSignedCopy(new AttachSignedCopyInput($draft->id(), 'signed.pdf', base64_encode(self::PDF)));
    }

    #[Test]
    public function aSignedCopyMustBeAPdf(): void
    {
        $document = $this->ready();

        try {
            $this->documents()->attachSignedCopy(new AttachSignedCopyInput($document->id(), 'signed.pdf', base64_encode('not a pdf')));
            self::fail('A file that is no PDF was attached.');
        } catch (DocumentsRefused $refused) {
            self::assertSame('file_not_pdf', $refused->reason());
        }
        self::assertNull($this->reload($document)->signedAttachmentId());
    }

    private function ready(): Document
    {
        $draft = $this->draft();

        return $this->documents()->generate(new GenerateDocumentInput($draft->id()));
    }
}
