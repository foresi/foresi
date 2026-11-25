<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Mcp;

use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\Operation\OperationField;

/**
 * A refusal of the cash-flow reads as MCP answers it, the way the operations do.
 */
final class CashFlowToolRefusal
{
    public static function of(CashFlowRefused $refused): ToolRefusal
    {
        $field = $refused->field();

        return RefusalKind::Invalid === $refused->kind()
            ? new ToolRefusal(ToolRefusal::INVALID_ARGUMENT, $refused->getMessage(), ['parameter' => null === $field ? null : OperationField::wireName($field), 'reason' => $refused->reason()])
            : new ToolRefusal($refused->reason(), $refused->getMessage());
    }
}
