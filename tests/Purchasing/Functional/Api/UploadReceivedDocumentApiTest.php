<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Tests\Purchasing\Support\ReceivedDocumentApiTestCase;
use Foresi\Tests\Support\ReceivedFileFixtures;

/**
 * A received document uploaded as a file over REST: a draft from the API, its reading queued
 * as for a file dropped on the screen, and what is refused leaves no draft.
 */
final class UploadReceivedDocumentApiTest extends ReceivedDocumentApiTestCase
{
    public function testUploadMakesADraftAndQueuesReading(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'invoice.pdf', 'content_base64' => base64_encode(ReceivedFileFixtures::pdf())]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/received-documents/'.$id);
        self::assertSame('draft', $this->at('data', 'state'));
        self::assertSame('api', $this->at('data', 'source'));
        self::assertTrue($this->at('data', 'has_original_file'));
        self::assertSame('reading', $this->at('data', 'reading', 'state'));
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($id, $queued[0]->documentId);
        self::assertFalse($queued[0]->manual);
        self::assertSame(1, $this->drafts());
    }

    public function testInvalidBase64IsRefused(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'invoice.isdoc', 'content_base64' => '***not base64***']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_invalid']);

        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'invoice.isdoc', 'content_base64' => '']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_invalid']);
        self::assertSame(0, $this->drafts());
        self::assertSame([], $this->queued());
    }

    public function testOver10MbIsRefused(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'big.pdf', 'content_base64' => base64_encode('%PDF-1.7'.str_repeat('0', 10 * 1024 * 1024))]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_too_large']);
        self::assertSame(0, $this->drafts());
    }

    public function testAnEncodedBodyOver10MbIsRefusedBeforeItIsDecoded(): void
    {
        // Not base64 at all: decoding first would answer file_invalid.
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'big.pdf', 'content_base64' => str_repeat('*', 13_981_021)]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_too_large']);
        self::assertSame(0, $this->drafts());
    }

    public function testTheEncodedLimitIsInclusive(): void
    {
        // The longest body that is let through to be decoded; it is not base64, so decoding refuses it.
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'big.pdf', 'content_base64' => str_repeat('*', 13_981_020)]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_invalid']);
        self::assertSame(0, $this->drafts());
    }

    public function testAFileOfExactly10MbIsAccepted(): void
    {
        $pdf = '%PDF-1.7'.str_repeat('0', 10 * 1024 * 1024 - 8);
        self::assertSame(10 * 1024 * 1024, \strlen($pdf));

        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'big.pdf', 'content_base64' => base64_encode($pdf)]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->drafts());
    }

    public function testAGuestIsRefusedBeforeTheBodyIsLookedAt(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'big.pdf', 'content_base64' => str_repeat('*', 13_981_021)], $this->guestSecret());

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->drafts());
    }

    public function testRefusedTypeMakesNoDraft(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'run.exe', 'content_base64' => base64_encode("MZ\x90\x00 example executable")]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'content_base64', 'reason' => 'file_type_unsupported']);
        self::assertSame(0, $this->drafts());
        self::assertSame([], $this->queued());
    }

    public function testAFileNameIsRequired(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['content_base64' => base64_encode(ReceivedFileFixtures::pdf())]);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'file_name']);
        self::assertSame(0, $this->drafts());
    }

    public function testGuestTokenIsRefused(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'invoice.pdf', 'content_base64' => base64_encode(ReceivedFileFixtures::pdf())], $this->guestSecret());

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->drafts());
    }

    public function testAReadTokenCannotUpload(): void
    {
        $this->send('POST', '/api/v1/received-documents/upload', ['file_name' => 'invoice.pdf', 'content_base64' => base64_encode(ReceivedFileFixtures::pdf())], $this->issued([ApiScope::from('received_documents:read')]));

        $this->assertProblem(403, 'insufficient_scope');
    }

    private function drafts(): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(d) FROM '.ReceivedDocument::class.' d')->getSingleScalarResult();
    }
}
