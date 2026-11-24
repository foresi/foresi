<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional\Mcp;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Tests\Purchasing\Support\ReceivedDocumentApiTestCase;

/**
 * foresi_read_received_document_again and the reading in what foresi_get_received_document
 * and foresi_search_received_documents answer.
 */
final class ReadReceivedDocumentAgainToolTest extends ReceivedDocumentApiTestCase
{
    public function testTheToolQueuesTheReadingAndAnswersTheDraft(): void
    {
        $id = $this->draft(found: [ReadField::Total->value => [100, 'isdoc']], applied: [ReadField::Total]);

        $call = $this->tool('foresi_read_received_document_again', ['document_id' => $id]);

        self::assertFalse($call['isError'] ?? false, (string) json_encode($call));
        self::assertSame('reading', $this->in($call, 'structuredContent', 'data', 'reading', 'state'));
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($id, $queued[0]->documentId);
    }

    public function testARecordedDocumentIsRefusedWithItsCode(): void
    {
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice', 'supplier_id' => $this->customerId, 'supplier_number' => 'FV-1', 'issue_date' => '2026-11-02',
            'total' => '1210.00', 'vat_recap' => ['21:1000.00:210.00'],
        ]);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $call = $this->tool('foresi_read_received_document_again', ['document_id' => $id]);

        self::assertTrue($call['isError'] ?? false);
        self::assertSame('recorded', $this->in($call, 'structuredContent', 'error', 'code'));
    }

    public function testTheReadAndTheSearchCarryTheReading(): void
    {
        $id = $this->draft(failure: DocumentReading::READER_FAILED);

        $get = $this->tool('foresi_get_received_document', ['id' => $id]);
        self::assertSame('failed', $this->in($get, 'structuredContent', 'data', 'reading', 'state'));
        self::assertSame('upload', $this->in($get, 'structuredContent', 'data', 'source'));

        $search = $this->tool('foresi_search_received_documents', ['reading' => 'failed', 'source' => 'upload']);
        self::assertSame([$id], array_column((array) $this->in($search, 'structuredContent', 'data', 'items'), 'id'));
        $none = $this->tool('foresi_search_received_documents', ['reading' => 'read']);
        self::assertSame([], $this->in($none, 'structuredContent', 'data', 'items'));
    }

    public function testMcpToolListedWithWriteScopeOnly(): void
    {
        self::assertContains('foresi_read_received_document_again', $this->toolNames($this->writer));
        self::assertNotContains('foresi_read_received_document_again', $this->toolNames($this->issued([ApiScope::from('received_documents:read')])));
        self::assertNotContains('foresi_read_received_document_again', $this->toolNames($this->guestSecret()));

        $refused = $this->tool('foresi_read_received_document_again', ['document_id' => $this->draft(failure: DocumentReading::READER_FAILED)], $this->issued([ApiScope::from('received_documents:read')]));
        self::assertSame('insufficient_scope', $this->in($refused, 'structuredContent', 'error', 'code'));
    }
}
