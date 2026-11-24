<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use PHPUnit\Framework\TestCase;

final class DocumentFileTest extends TestCase
{
    public function testTheMediaTypeIsTrustedOverTheExtension(): void
    {
        $file = new DocumentFile('invoice.pdf', 'image/png', '');

        self::assertTrue($file->isImage());
        self::assertFalse($file->isPdf());
    }

    public function testTheExtensionDecidesOnlyWithoutAUsefulMediaType(): void
    {
        self::assertTrue((new DocumentFile('a.PDF', '', ''))->isPdf());
        self::assertTrue((new DocumentFile('a.pdf', 'application/octet-stream', ''))->isPdf());
        self::assertTrue((new DocumentFile('a.isdoc', 'application/octet-stream', ''))->isXml());
        self::assertTrue((new DocumentFile('a.jpg', '', ''))->isImage());
    }

    public function testXmlMediaTypes(): void
    {
        self::assertTrue((new DocumentFile('a', 'application/xml; charset=utf-8', ''))->isXml());
        self::assertTrue((new DocumentFile('a', 'application/vnd.example+xml', ''))->isXml());
        self::assertFalse((new DocumentFile('a.xml', 'text/plain', ''))->isXml());
    }
}
