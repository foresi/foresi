<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationObserver;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs an operation the same way for REST and MCP (ADR 0032): the scope, then
 * the confirmation flag, then the input, then the observers' before(), the
 * handler and the observers' after(). The write budget and the idempotency key
 * are the surfaces' own, around this.
 */
final readonly class OperationRunner
{
    /**
     * @param iterable<OperationObserver> $observers
     */
    public function __construct(
        private ScopeGuard $scopes,
        #[AutowireIterator(OperationObserver::TAG)]
        private iterable $observers = [],
    ) {
    }

    /**
     * @param array<mixed> $sent the fields by wire name
     *
     * @throws \DomainException                when the token lacks the scope
     * @throws ConfirmationRequired
     * @throws InvalidInput
     * @throws \Foresi\Shared\Contract\Refused when the use case or an observer refuses
     */
    public function run(OperationDefinition $definition, array $sent): OperationOutput
    {
        $this->scopes->require($definition->operation->scope);
        if ($definition->operation->needsConfirmation) {
            throw new ConfirmationRequired($definition->operation->name);
        }

        $input = InputMapper::map($definition, $sent);
        foreach ($this->observers as $observer) {
            $observer->before($definition->operation, $input);
        }
        $output = $definition->handler->handle($input);
        foreach ($this->observers as $observer) {
            $observer->after($definition->operation, $input, $output);
        }

        return $output;
    }
}
