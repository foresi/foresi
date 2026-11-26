<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Shared\Contract\Operation\OperationObserver;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * Writes down what it was told, in order, and refuses before the handler when the test gives it a refusal.
 */
final class RecordingObserver implements OperationObserver
{
    /** @var list<string> */
    public array $seen = [];

    public function __construct(private readonly ?\Throwable $refusal = null)
    {
    }

    public function before(Operation $operation, object $input): void
    {
        $this->seen[] = 'before '.$operation->name.' '.$input::class;
        if ($this->refusal instanceof \Throwable) {
            throw $this->refusal;
        }
    }

    public function after(Operation $operation, object $input, OperationOutput $output): void
    {
        $this->seen[] = 'after '.$operation->name.' '.$output->summary;
    }
}
