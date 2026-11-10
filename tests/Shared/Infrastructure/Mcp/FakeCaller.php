<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ToolCaller;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * A caller that is confirmed, refused or fails, as the test sets it.
 */
final class FakeCaller implements ToolCaller
{
    public int $confirmed = 0;
    public ?ToolRefusal $refusal = null;
    public ?\Throwable $failure = null;

    public function confirm(): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        if ($this->refusal instanceof ToolRefusal) {
            throw $this->refusal;
        }
        ++$this->confirmed;
    }
}
