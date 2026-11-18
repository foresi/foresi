<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * Answers the input it got, or throws what the test gives it.
 */
final class ThingHandler implements OperationHandler
{
    public ?object $input = null;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public static function input(): string
    {
        return ThingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        $this->input = $input;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new OperationOutput(['ok' => true], 'Changed.');
    }
}
