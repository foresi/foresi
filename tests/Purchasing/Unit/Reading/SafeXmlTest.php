<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\SafeXml;
use PHPUnit\Framework\TestCase;

final class SafeXmlTest extends TestCase
{
    public function testLoadsAWellFormedDocument(): void
    {
        $dom = SafeXml::load('<?xml version="1.0"?><a><b>text</b></a>');

        self::assertSame('a', $dom->documentElement?->localName);
    }

    public function testRefusesADoctypeEvenWithoutAnEntity(): void
    {
        $this->expectException(ReaderFailed::class);
        SafeXml::load('<?xml version="1.0"?><!DOCTYPE a><a/>');
    }

    public function testRefusesAnEntityBomb(): void
    {
        $this->expectException(ReaderFailed::class);
        SafeXml::load('<?xml version="1.0"?><!DOCTYPE a [<!ENTITY x "aaaaaaaaaa"><!ENTITY y "&x;&x;&x;&x;&x;&x;&x;&x;">]><a>&y;</a>');
    }

    public function testRefusesMalformedAndEmptyXml(): void
    {
        foreach (['', '<a>', 'not xml'] as $bytes) {
            try {
                SafeXml::load($bytes);
                self::fail('Expected ReaderFailed for "'.$bytes.'".');
            } catch (ReaderFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefusesAFileOver5Mb(): void
    {
        $this->expectException(ReaderFailed::class);
        SafeXml::load('<a>'.str_repeat('x', 5 * 1024 * 1024).'</a>');
    }

    public function testDoesNotLeaveLibxmlErrorsBehind(): void
    {
        $before = libxml_use_internal_errors();
        try {
            SafeXml::load('<a>');
        } catch (ReaderFailed) {
        }

        self::assertSame($before, libxml_use_internal_errors());
        self::assertSame([], libxml_get_errors());
    }
}
