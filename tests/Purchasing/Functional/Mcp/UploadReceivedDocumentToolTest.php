<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional\Mcp;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Purchasing\Support\ReceivedDocumentApiTestCase;
use Foresi\Tests\Support\ReceivedFileFixtures;

/**
 * foresi_upload_received_document: the file as base64, a draft from the API with its reading queued.
 */
final class UploadReceivedDocumentToolTest extends ReceivedDocumentApiTestCase
{
    public function testTheToolMakesADraftAndQueuesTheReading(): void
    {
        $call = $this->tool('foresi_upload_received_document', ['file_name' => 'invoice.pdf', 'content_base64' => base64_encode(ReceivedFileFixtures::pdf())]);

        self::assertFalse($call['isError'] ?? false, (string) json_encode($call));
        self::assertSame('api', $this->in($call, 'structuredContent', 'data', 'source'));
        self::assertSame('reading', $this->in($call, 'structuredContent', 'data', 'reading', 'state'));
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($this->in($call, 'structuredContent', 'data', 'id'), $queued[0]->documentId);
    }

    public function testARefusedFileIsAnErrorWithItsReason(): void
    {
        $call = $this->tool('foresi_upload_received_document', ['file_name' => 'run.exe', 'content_base64' => base64_encode("MZ\x90\x00 example executable")]);

        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('file_type_unsupported', $this->in($call, 'structuredContent', 'error', 'details', 'reason'));
        self::assertSame([], $this->queued());
    }

    public function testMcpToolListedWithWriteScopeOnly(): void
    {
        self::assertContains('foresi_upload_received_document', $this->toolNames($this->writer));
        self::assertNotContains('foresi_upload_received_document', $this->toolNames($this->issued([ApiScope::from('received_documents:read')])));
        self::assertNotContains('foresi_upload_received_document', $this->toolNames($this->guestSecret()));

        $refused = $this->tool('foresi_upload_received_document', ['file_name' => 'invoice.pdf', 'content_base64' => base64_encode(ReceivedFileFixtures::pdf())], $this->issued([ApiScope::from('received_documents:read')]));
        self::assertSame('insufficient_scope', $this->in($refused, 'structuredContent', 'error', 'code'));
    }
}
