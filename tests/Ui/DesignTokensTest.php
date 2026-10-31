<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The templates, the component classes and the Stimulus controllers use only the
 * closed scales of assets/styles/app.css: three radii, the control and row
 * heights, the named type steps, three border roles and three elevations. Any
 * arbitrary value or default Tailwind step fails with its file and line.
 */
final class DesignTokensTest extends TestCase
{
    /**
     * Deliberate exceptions, as "relative path" => [the exact utilities allowed there].
     * Keep this list short; every entry is explained in the design consistency report.
     */
    private const array ALLOWED = [
        // The step markers are circles: a number or a check in a ring.
        'templates/components/Ui/Stepper.html.twig' => ['rounded-full'],
        // The sticky sidebar is as tall as the viewport minus the frame padding.
        'templates/layout/app.html.twig' => ['h-[calc(100vh-20px)]'],
    ];

    private const array RADII = ['sm', 'control', 'panel'];

    private const array SHADOWS = ['raised', 'floating', 'overlay'];

    private const array INSET_SHADOWS = ['rail', 'selected'];

    /** Border colours: the three roles, plus hover, selected and error states. */
    private const array BORDER_COLOURS = ['divider', 'container', 'control-line', 'muted', 'green', 'badge-red-text', 'transparent', 'current'];

    /** Border utilities that are no colour: widths, sides and table layout. */
    private const string BORDER_SHAPE = '/^(?:[xytrblse]-?)?(?:0|2|4|8)?$|^(?:collapse|separate|solid|dashed|dotted|none)$/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function rules(): iterable
    {
        yield 'radius' => ['radius'];
        yield 'height' => ['height'];
        yield 'font size' => ['type'];
        yield 'shadow' => ['shadow'];
        yield 'border colour' => ['border'];
        yield 'raw colour' => ['colour'];
    }

    #[DataProvider('rules')]
    public function testOnlyTokensAreUsed(string $rule): void
    {
        $violations = [];
        foreach ($this->files() as $path => $lines) {
            foreach ($lines as $index => $line) {
                foreach ($this->utilities($line) as $utility) {
                    if ($this->violates($rule, $utility) && !in_array($this->base($utility), self::ALLOWED[$path] ?? [], true)) {
                        $violations[] = sprintf('%s:%d %s', $path, $index + 1, $utility);
                    }
                }
                if ('colour' === $rule && 1 === preg_match('/\b(?:rgba?|hsla?|oklch)\(/', $line)) {
                    $violations[] = sprintf('%s:%d raw colour function', $path, $index + 1);
                }
            }
        }

        self::assertSame([], $violations, "Use the design tokens of assets/styles/app.css:\n".implode("\n", $violations));
    }

    public function testTheScanCatchesArbitraryValues(): void
    {
        self::assertTrue($this->violates('radius', 'rounded-full'));
        self::assertTrue($this->violates('radius', 'rounded-[4px]'));
        self::assertTrue($this->violates('radius', 'rounded-md'));
        self::assertTrue($this->violates('radius', 'file:rounded-lg'));
        self::assertFalse($this->violates('radius', 'rounded-control'));
        self::assertFalse($this->violates('radius', 'has-[:checked]:rounded-panel'));
        self::assertTrue($this->violates('height', 'h-[30px]'));
        self::assertTrue($this->violates('height', 'min-h-[2rem]'));
        self::assertTrue($this->violates('height', 'size-[52px]'));
        self::assertFalse($this->violates('height', 'h-control-sm'));
        self::assertFalse($this->violates('height', 'max-h-[50vh]'));
        self::assertTrue($this->violates('type', 'text-[13px]'));
        self::assertTrue($this->violates('type', 'text-xs'));
        self::assertTrue($this->violates('type', 'file:text-sm'));
        self::assertFalse($this->violates('type', 'text-small'));
        self::assertFalse($this->violates('type', 'text-muted'));
        self::assertTrue($this->violates('shadow', 'shadow-[0_1px_2px_rgba(0,0,0,.1)]'));
        self::assertTrue($this->violates('shadow', 'shadow-md'));
        self::assertTrue($this->violates('shadow', 'inset-shadow-[inset_0_1px_0_red]'));
        self::assertFalse($this->violates('shadow', 'data-[stuck]:shadow-floating'));
        self::assertFalse($this->violates('shadow', 'group-data-[selected=true]:inset-shadow-selected'));
        self::assertTrue($this->violates('border', 'border-line-strong'));
        self::assertTrue($this->violates('border', 'divide-[#eee]'));
        self::assertFalse($this->violates('border', 'border-b-2'));
        self::assertFalse($this->violates('border', 'hover:border-muted'));
        self::assertFalse($this->violates('border', 'border-collapse'));
        self::assertTrue($this->violates('colour', 'bg-[#fff]'));
        self::assertFalse($this->violates('colour', 'bg-green-tint'));
    }

    private function violates(string $rule, string $utility): bool
    {
        $base = $this->base($utility);

        return match ($rule) {
            'radius' => 1 === preg_match('/^rounded(?:-(?:[trblse]|tl|tr|bl|br|ss|se|es|ee))?-(.+)$/', $base, $m)
                && !in_array($m[1], self::RADII, true),
            'height' => 1 === preg_match('/^(?:min-h|h|size)-\[/', $base),
            'type' => 1 === preg_match('/^text-(?:\[|(?:xs|sm|base|lg|\d?xl)$)/', $base)
                || (1 === preg_match('/^text-(.+)$/', $base, $m) && 1 === preg_match('/^\d|px$|rem$/', $m[1])),
            'shadow' => (1 === preg_match('/^shadow-(.+)$/', $base, $m) && !in_array($m[1], self::SHADOWS, true))
                || (1 === preg_match('/^inset-shadow-(.+)$/', $base, $m) && !in_array($m[1], self::INSET_SHADOWS, true)),
            'border' => 1 === preg_match('/^(?:border|divide)(?:-(.+))?$/', $base, $m)
                && 1 !== preg_match(self::BORDER_SHAPE, $m[1] ?? '')
                && !in_array(preg_replace('/^[xytrblse]-/', '', $m[1] ?? ''), self::BORDER_COLOURS, true)
                && 'y' !== ($m[1] ?? '') && 'x' !== ($m[1] ?? ''),
            'colour' => 1 === preg_match('/-\[#[0-9a-f]{3,8}\]/i', $base),
            default => throw new \LogicException($rule),
        };
    }

    /**
     * The utility without its variants (hover:, max-sm:, has-[:checked]:, [&_a]: …).
     */
    private function base(string $utility): string
    {
        $base = preg_replace('/^(?:[^:\[\]]+:|[^:\[\]]*\[[^\]]*\][^:\[\]]*:)+/', '', $utility) ?? $utility;

        return ltrim($base, '!-');
    }

    /**
     * Class-like tokens of a line: runs without spaces or quotes.
     *
     * @return list<string>
     */
    private function utilities(string $line): array
    {
        preg_match_all('/[^\s"\'`{}]+/', $line, $matches);

        return array_values(array_filter(
            $matches[0],
            static fn (string $token): bool => 1 === preg_match('/(?:^|:|!|-)(?:rounded|h|min-h|size|text|shadow|inset-shadow|border|divide|bg|from|to)(?:-|$)/', $token),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['templates', 'src/Ui', 'assets/controllers'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);
                if (!in_array($file->getExtension(), ['twig', 'php', 'js'], true)) {
                    continue;
                }
                $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES);
                self::assertIsArray($lines);
                $files[substr($file->getPathname(), strlen($root) + 1)] = $lines;
            }
        }
        ksort($files);
        self::assertNotSame([], $files);

        return $files;
    }
}
