<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Mail\Contract\Messages;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Tests\Purchasing\Support\ReceivedDocumentApiTestCase;
use Foresi\Tests\Purchasing\Support\StoredMessages;

/**
 * The reading of a received document over REST: where the draft came from, what the readers
 * found and what the draft took, the lists narrowed by them, and read again.
 */
final class ReceivedDocumentReadingApiTest extends ReceivedDocumentApiTestCase
{
    public function testDetailHasSourceAndReading(): void
    {
        $id = $this->draft(found: [
            ReadField::Total->value => [121_000, 'isdoc'],
            ReadField::IssueDate->value => ['2026-11-02', 'isdoc'],
            ReadField::SupplierName->value => ['Cloud Host s.r.o.', 'ai'],
        ], applied: [ReadField::Total, ReadField::IssueDate]);

        $this->get('/api/v1/received-documents/'.$id, secret: $this->writer);

        self::assertResponseIsSuccessful();
        self::assertSame('upload', $this->at('data', 'source'));
        self::assertSame('read', $this->at('data', 'reading', 'state'));
        self::assertSame(['isdoc', 'ai'], $this->at('data', 'reading', 'sources'));
        self::assertSame(['value' => 121_000, 'source' => 'isdoc', 'applied' => true], $this->at('data', 'reading', 'values', 'total'));
        self::assertSame(['value' => '2026-11-02', 'source' => 'isdoc', 'applied' => true], $this->at('data', 'reading', 'values', 'issue_date'));
        self::assertSame(['value' => 'Cloud Host s.r.o.', 'source' => 'ai', 'applied' => false], $this->at('data', 'reading', 'values', 'supplier_name'));
        self::assertNotNull($this->at('data', 'reading', 'requested_at'));
        self::assertNotNull($this->at('data', 'reading', 'finished_at'));
        self::assertSame([], $this->at('data', 'reading', 'warnings'));
        self::assertNull($this->at('data', 'reading', 'failure'));
    }

    public function testApiDetailHasSourceMail(): void
    {
        $mail = '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11';
        self::getContainer()->set(Messages::class, new StoredMessages($mail, 'Pat Supplier <pat@supplier.example.com>', 'Invoice 11/2026', new \DateTimeImmutable('2026-11-20 09:00:00 UTC'), 'failed'));
        $filed = $this->draft(DocumentSource::Mail, mail: $mail);
        $uploaded = $this->draft();

        $this->get('/api/v1/received-documents/'.$filed, secret: $this->writer);
        self::assertSame('mail', $this->at('data', 'source'));
        self::assertSame(['from' => 'Pat Supplier <pat@supplier.example.com>', 'subject' => 'Invoice 11/2026', 'sent_at' => '2026-11-20T09:00:00+00:00', 'verdict' => 'failed'], $this->at('data', 'source_mail'));

        $this->get('/api/v1/received-documents/'.$uploaded, secret: $this->writer);
        self::assertNull($this->at('data', 'source_mail'));
    }

    public function testListFiltersByMailSource(): void
    {
        $mail = $this->draft(DocumentSource::Mail, mail: '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11');
        $this->draft();

        $this->get('/api/v1/received-documents', ['source' => 'mail'], $this->writer);

        self::assertSame([$mail], $this->ids());
    }

    public function testADocumentNeverReadHasNoReading(): void
    {
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice', 'supplier_id' => $this->customerId, 'supplier_number' => 'FV-1', 'issue_date' => '2026-11-02',
            'total' => '1210.00', 'vat_recap' => ['21:1000.00:210.00'],
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame('api', $this->at('data', 'source'));
        self::assertNull($this->at('data', 'reading'));
    }

    public function testAFailedReadingShowsACodeAndNeverAProvidersText(): void
    {
        $id = $this->draft(failure: DocumentReading::READER_FAILED);

        $this->get('/api/v1/received-documents/'.$id, secret: $this->writer);

        self::assertSame('failed', $this->at('data', 'reading', 'state'));
        self::assertSame('reader_failed', $this->at('data', 'reading', 'failure'));
        self::assertSame([], $this->at('data', 'reading', 'values'));
    }

    public function testTheListIsNarrowedByReadingSourceAndTheToReviewView(): void
    {
        $read = $this->draft(found: [ReadField::Total->value => [100, 'isdoc']], applied: [ReadField::Total]);
        $failed = $this->draft(DocumentSource::Api, failure: DocumentReading::READER_FAILED);
        $running = $this->draft();

        $this->get('/api/v1/received-documents', ['reading' => 'failed'], $this->writer);
        self::assertSame([$failed], $this->ids());
        $this->get('/api/v1/received-documents', ['reading' => 'read'], $this->writer);
        self::assertSame([$read], $this->ids());
        $this->get('/api/v1/received-documents', ['source' => 'api'], $this->writer);
        self::assertSame([$failed], $this->ids());
        $this->get('/api/v1/received-documents', ['source' => 'upload', 'reading' => 'reading'], $this->writer);
        self::assertSame([$running], $this->ids());

        $this->get('/api/v1/received-documents', ['view' => 'purchasing.received.to_review'], $this->issued([ApiScope::from('received_documents:read'), ApiScope::from('views:read')]));
        self::assertEqualsCanonicalizing([$read, $failed], $this->ids());

        $this->get('/api/v1/received-documents', ['reading' => 'bogus'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'reading']);
    }

    public function testReadAgainReturns202AndQueues(): void
    {
        $id = $this->draft(found: [ReadField::Total->value => [100, 'isdoc']], applied: [ReadField::Total]);

        $this->send('POST', '/api/v1/received-documents/'.$id.'/read-again');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('reading', $this->at('data', 'reading', 'state'));
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($id, $queued[0]->documentId);
        self::assertTrue($queued[0]->manual);
    }

    public function testReadAgainOfARecordedDocumentIsRefused(): void
    {
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice', 'supplier_id' => $this->customerId, 'supplier_number' => 'FV-1', 'issue_date' => '2026-11-02',
            'total' => '1210.00', 'vat_recap' => ['21:1000.00:210.00'],
        ]);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->send('POST', '/api/v1/received-documents/'.$id.'/read-again');

        $this->assertProblem(409, 'recorded');
        self::assertSame([], $this->queued());
    }

    public function testReadAgainOfADraftWithoutAFileOrWhileRunningIsRefused(): void
    {
        $this->send('POST', '/api/v1/received-documents/'.$this->draft(DocumentSource::Manual, file: false).'/read-again');
        $this->assertProblem(409, 'no_original_file');

        // A reading just asked for is still running.
        $this->send('POST', '/api/v1/received-documents/'.$this->draft().'/read-again');
        $this->assertProblem(409, 'reading_in_progress');
        self::assertSame([], $this->queued());
    }

    public function testReadAgainOfAnUnknownDocumentIsNotFound(): void
    {
        $this->send('POST', '/api/v1/received-documents/01940000-0000-7000-8000-000000000001/read-again');
        $this->assertProblem(404, 'not_found');
    }

    public function testGuestTokenIsRefused(): void
    {
        $id = $this->draft(failure: DocumentReading::READER_FAILED);

        $this->send('POST', '/api/v1/received-documents/'.$id.'/read-again', secret: $this->guestSecret());

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->queued());
    }

    public function testAReadTokenCannotReadAgain(): void
    {
        $id = $this->draft(failure: DocumentReading::READER_FAILED);

        $this->send('POST', '/api/v1/received-documents/'.$id.'/read-again', secret: $this->issued([ApiScope::from('received_documents:read')]));

        $this->assertProblem(403, 'insufficient_scope');
    }
}
