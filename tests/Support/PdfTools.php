<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;

/**
 * Reads a PDF the way a person's tools do, with Poppler (pdftotext, pdfdetach,
 * pdfinfo, pdftoppm). tc-lib-pdf-parser gives no text of a subset font, so there is
 * no fallback: without Poppler the check is skipped with a message, unless
 * REQUIRE_POPPLER=1, which makes it a failure (set in CI).
 */
final class PdfTools
{
    public static function text(string $pdf): string
    {
        return str_replace("\u{a0}", ' ', self::run($pdf, ['pdftotext', '-enc', 'UTF-8', '-layout', '{pdf}', '-']));
    }

    public static function pages(string $pdf): int
    {
        Assert::assertSame(1, preg_match('/^Pages:\s+(\d+)$/m', self::run($pdf, ['pdfinfo', '{pdf}']), $found));

        return (int) $found[1];
    }

    /**
     * @return array{name: string, content: string} the first attachment
     */
    public static function attachment(string $pdf): array
    {
        Assert::assertSame(1, preg_match('/^1: (.+)$/m', self::run($pdf, ['pdfdetach', '-list', '{pdf}']), $found));
        $target = tempnam(sys_get_temp_dir(), 'foresi-attachment-');
        \assert(false !== $target);
        try {
            self::run($pdf, ['pdfdetach', '-save', '1', '-o', $target, '{pdf}']);

            return ['name' => trim($found[1]), 'content' => (string) file_get_contents($target)];
        } finally {
            @unlink($target);
        }
    }

    /**
     * The first page as a PNG, at 10 pixels per millimetre.
     */
    public static function firstPagePng(string $pdf): string
    {
        $target = tempnam(sys_get_temp_dir(), 'foresi-page-');
        \assert(false !== $target);
        try {
            self::run($pdf, ['pdftoppm', '-r', '254', '-png', '-singlefile', '-f', '1', '-l', '1', '{pdf}', $target]);

            return (string) file_get_contents($target.'.png');
        } finally {
            @unlink($target);
            @unlink($target.'.png');
        }
    }

    /**
     * @param list<string> $command
     */
    private static function run(string $pdf, array $command): string
    {
        $binary = new \Symfony\Component\Process\ExecutableFinder()->find($command[0]);
        if (null === $binary) {
            if ('1' === getenv('REQUIRE_POPPLER')) {
                Assert::fail('Poppler is required (REQUIRE_POPPLER=1) but '.$command[0].' was not found.');
            }

            throw new SkippedWithMessageException($command[0].' not found; install Poppler to run this check.');
        }

        $file = tempnam(sys_get_temp_dir(), 'foresi-read-');
        \assert(false !== $file);
        try {
            file_put_contents($file, $pdf);
            $process = new Process(array_map(static fn (string $part): string => '{pdf}' === $part ? $file : $part, $command));
            $process->mustRun();

            return $process->getOutput();
        } finally {
            @unlink($file);
        }
    }
}
