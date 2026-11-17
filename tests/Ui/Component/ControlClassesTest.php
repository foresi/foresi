<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\Button;
use App\Ui\Component\ErrorFocus;
use App\Ui\Component\IconButton;
use App\Ui\Component\MappingRow;
use App\Ui\Component\Style;
use App\Ui\Component\TextInput;
use PHPUnit\Framework\TestCase;

/**
 * The classes the controls take from Style, without a kernel.
 */
final class ControlClassesTest extends TestCase
{
    public function testButtonIconsFollowTheButtonSize(): void
    {
        $button = new Button();
        $iconButton = new IconButton();
        self::assertSame('size-5 shrink-0', $button->iconClasses());
        self::assertSame('size-5 shrink-0', $iconButton->iconClasses());

        $button->size = 'sm';
        $iconButton->size = 'sm';
        self::assertSame('size-4 shrink-0', $button->iconClasses());
        self::assertSame('size-4 shrink-0', $iconButton->iconClasses());
    }

    public function testAControlWearsTheFieldLook(): void
    {
        $input = new TextInput(new ErrorFocus());
        self::assertSame(Style::field(false), $input->classes());

        $input->error = 'Required.';
        self::assertSame(Style::field(true), $input->classes());
    }

    public function testAMappingSelectWearsTheFieldLookWithRoomForItsArrow(): void
    {
        $row = new MappingRow(new ErrorFocus());
        self::assertSame('h-control cursor-pointer appearance-none pr-10 hover:border-green '.Style::field(false), $row->selectClasses());

        $row->errors = ['Pick a field.'];
        self::assertSame('h-control cursor-pointer appearance-none pr-10 hover:border-green '.Style::field(true), $row->selectClasses());
    }
}
