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

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testRefusesMalformedAndEmptyXml(): void
    {
        foreach (['', '<a>', 'not xml'] as $bytes) {
            try {
                SafeXml::load($bytes);
                self::fail('Expected ReaderFailed for "'.$bytes.'".');
            } catch (ReaderFailed) {
            }
        }
    }

    public function testRefusesAFileOver5Mb(): void
    {
        $this->expectException(ReaderFailed::class);
        SafeXml::load('<a>'.str_repeat('x', 5 * 1024 * 1024).'</a>');
    }

    public function testWhitespaceAloneIsEmpty(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->expectExceptionMessageIsOrContains('The XML file is empty.');
        SafeXml::load(" \n\t ");
    }

    public function testTheSizeLimitIsInclusive(): void
    {
        $limit = SafeXml::MAX_BYTES;
        $atLimit = '<a>'.str_repeat('x', $limit - 7).'</a>';
        self::assertSame($limit, \strlen($atLimit));
        self::assertSame('a', SafeXml::load($atLimit)->documentElement?->localName);

        $this->expectException(ReaderFailed::class);
        $this->expectExceptionMessageIsOrContains('The XML file is over the size limit.');
        SafeXml::load($atLimit.' ');
    }

    public function testADoctypeIsNamedAsSuch(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->expectExceptionMessageIsOrContains('The XML file has a DOCTYPE, which is not allowed.');
        SafeXml::load('<?xml version="1.0"?><!doctype a><a/>');
    }

    public function testADoctypeInUtf16IsFoundThroughItsNulBytes(): void
    {
        $xml = "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE a><a/>', 'UTF-16LE', 'UTF-8');

        $this->expectException(ReaderFailed::class);
        $this->expectExceptionMessageIsOrContains('The XML file has a DOCTYPE, which is not allowed.');
        SafeXml::load($xml);
    }

    public function testKeepsLibxmlsOwnErrorHandlingAsItWas(): void
    {
        $was = libxml_use_internal_errors();
        try {
            libxml_use_internal_errors(false);
            try {
                SafeXml::load('<a>');
            } catch (ReaderFailed) {
            }
            self::assertFalse(libxml_use_internal_errors());

            libxml_use_internal_errors(true);
            libxml_clear_errors();
            try {
                SafeXml::load('<a>');
            } catch (ReaderFailed) {
            }
            self::assertTrue(libxml_use_internal_errors());
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($was);
        }
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
