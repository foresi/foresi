<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Twig;

use App\Ui\Dialog\DialogRequest;
use App\Ui\Twig\DialogFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class DialogFunctionsTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, bool}>
     */
    public static function headers(): iterable
    {
        yield 'the page' => [null, null, false];
        yield 'a side sheet' => ['sheet', 'sheet', true];
        yield 'a modal dialog' => ['1', 'dialog', true];
    }

    #[DataProvider('headers')]
    public function testOverlayNamesTheLayerAndInDialogIsTrueForBoth(?string $header, ?string $overlay, bool $inDialog): void
    {
        $request = Request::create('/contacts/1');
        if (null !== $header) {
            $request->headers->set(DialogRequest::HEADER, $header);
        }
        $requests = new RequestStack([$request]);
        $functions = new DialogFunctions($requests);

        self::assertSame($overlay, $functions->overlay());
        self::assertSame($inDialog, $functions->inDialog());
    }

    public function testWithoutARequestItIsThePage(): void
    {
        $functions = new DialogFunctions(new RequestStack());

        self::assertNull($functions->overlay());
        self::assertFalse($functions->inDialog());
    }
}
