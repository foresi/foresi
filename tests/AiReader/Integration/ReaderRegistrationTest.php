<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\AiReader\Integration;

use Foresi\AiReader\Application\AiProvider;
use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\ReceivedDocuments\Application\Reading\ReadDocument;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentFile;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentReader;
use Foresi\ReceivedDocuments\Contract\Reading\ReaderFailed;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReaderRegistrationTest extends KernelTestCase
{
    public function testNotRegisteredWithoutKey(): void
    {
        $pdf = new DocumentFile('invoice.pdf', 'application/pdf', '%PDF-1.4 An example invoice');

        $tagged = array_values(array_filter($this->readers(), static fn (DocumentReader $reader): bool => 'ai' === $reader->source()));

        self::assertNotSame([], $tagged, 'The reader is tagged, so it is there to be switched on by a key.');
        foreach ($tagged as $reader) {
            self::assertFalse($reader->supports($pdf), 'Without a key no AI reader takes a file.');
        }
        self::assertFalse(self::getContainer()->get(AiReaderSettings::class)->isConfigured());
    }

    public function testTheReaderComesAfterTheStructuredOnes(): void
    {
        $sources = array_map(static fn (DocumentReader $reader): string => $reader->source(), $this->readers());

        self::assertGreaterThan(array_search('factur_x', $sources, true), array_search('ai', $sources, true));
    }

    public function testTheProviderWithoutAKeyCannotAnswer(): void
    {
        $provider = self::getContainer()->get(AiProvider::class);

        $this->expectException(ReaderFailed::class);
        $this->expectExceptionMessageIsOrContains('not_configured');

        $provider->extract(new DocumentFile('invoice.pdf', 'application/pdf', '%PDF-1.4'), [], '');
    }

    /**
     * @return list<DocumentReader>
     */
    private function readers(): array
    {
        $readDocument = self::getContainer()->get(ReadDocument::class);
        $readers = new \ReflectionProperty(ReadDocument::class, 'readers')->getValue($readDocument);
        self::assertIsIterable($readers);
        $found = [];
        foreach ($readers as $reader) {
            self::assertInstanceOf(DocumentReader::class, $reader);
            $found[] = $reader;
        }

        return $found;
    }
}
