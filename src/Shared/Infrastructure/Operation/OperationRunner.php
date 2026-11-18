<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\Api\ScopeGuard;

/**
 * Runs an operation the same way for REST and MCP (ADR 0032): the scope, then
 * the confirmation flag, then the input, then the handler. The write budget and
 * the idempotency key are the surfaces' own, around this.
 */
final readonly class OperationRunner
{
    public function __construct(private ScopeGuard $scopes)
    {
    }

    /**
     * @param array<mixed> $sent the fields by wire name
     *
     * @throws \DomainException                   when the token lacks the scope
     * @throws ConfirmationRequired
     * @throws InvalidInput
     * @throws \Foresi\Shared\Application\Refused when the use case refuses
     */
    public function run(OperationDefinition $definition, array $sent): OperationOutput
    {
        $this->scopes->require($definition->operation->scope);
        if ($definition->operation->needsConfirmation) {
            throw new ConfirmationRequired($definition->operation->name);
        }

        return $definition->handler->handle(InputMapper::map($definition, $sent));
    }
}
