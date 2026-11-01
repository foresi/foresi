<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Validates a PDF against a PDF/A flavour with veraPDF (https://verapdf.org).
 *
 * The binary comes from VERAPDF_BIN or the PATH. Without it the check is
 * skipped with a message, unless REQUIRE_VERAPDF=1, which makes it a failure
 * (set in CI).
 */
final class VeraPdf
{
    public static function assertConforms(string $pdf, string $flavour): void
    {
        $binary = self::binary();
        if (null === $binary) {
            if ('1' === getenv('REQUIRE_VERAPDF')) {
                Assert::fail('veraPDF is required (REQUIRE_VERAPDF=1) but was not found. Set VERAPDF_BIN or add verapdf to the PATH.');
            }

            throw new SkippedWithMessageException('veraPDF not found; set VERAPDF_BIN or add verapdf to the PATH to run the conformance check.');
        }

        // veraPDF only reads files that end in .pdf.
        $base = tempnam(sys_get_temp_dir(), 'foresi-pdf-');
        \assert(false !== $base);
        $file = $base.'.pdf';
        try {
            file_put_contents($file, $pdf);
            $process = proc_open(
                [$binary, '--flavour', $flavour, '--format', 'text', '--verbose', $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            \assert(\is_resource($process));
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        } finally {
            @unlink($file);
            @unlink($base);
        }

        Assert::assertSame(0, $exit, "veraPDF rejected the document as PDF/A-$flavour:\n".$output);
        Assert::assertStringStartsWith('PASS', $output);
    }

    private static function binary(): ?string
    {
        $configured = getenv('VERAPDF_BIN');
        if (\is_string($configured) && '' !== $configured) {
            return is_executable($configured) ? $configured : null;
        }

        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = $directory.\DIRECTORY_SEPARATOR.'verapdf';
            if ('' !== $directory && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
