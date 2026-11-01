<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Validates an ISDOC file against the published XSD, which bin/fetch-isdoc-schema
 * brings to var/isdoc-schema/. Without it the check is skipped with a message,
 * unless REQUIRE_ISDOC_SCHEMA=1, which makes it a failure (set in CI).
 */
final class IsdocSchema
{
    private const string SCHEMA = __DIR__.'/../../var/isdoc-schema/xsd/isdoc-invoice-6.0.2.xsd';

    public static function assertValid(string $xml): void
    {
        $errors = self::errors($xml);
        Assert::assertSame([], $errors, "The ISDOC file does not match the schema:\n".implode("\n", $errors));
    }

    /**
     * @return list<string>
     */
    public static function errors(string $xml): array
    {
        if (!is_file(self::SCHEMA)) {
            if ('1' === getenv('REQUIRE_ISDOC_SCHEMA')) {
                Assert::fail('The ISDOC schema is required (REQUIRE_ISDOC_SCHEMA=1) but missing. Run composer isdoc:schema.');
            }

            throw new SkippedWithMessageException('The ISDOC schema is missing; run composer isdoc:schema to run this check.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, \LIBXML_NONET)) {
                return [...self::collected(), 'The file is not well formed.'];
            }

            return $document->schemaValidate(self::SCHEMA) ? [] : [...self::collected(), 'The file does not validate.'];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    private static function collected(): array
    {
        return array_map(static fn (\LibXMLError $error): string => \sprintf('line %d: %s', $error->line, trim($error->message)), libxml_get_errors());
    }
}
