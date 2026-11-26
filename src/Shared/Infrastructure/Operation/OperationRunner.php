<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationObserver;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs an operation the same way for REST and MCP (ADR 0032): the scope, then
 * the confirmation flag, then the input, then the observers' before(), the
 * handler and the observers' after(), each given the answer the one before
 * gave. The handler has committed by then, so an observer that fails after it
 * is logged by the class of its failure and the answer it had is sent. The
 * write budget and the idempotency key are the surfaces' own, around this.
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
        private LoggerInterface $logger = new NullLogger(),
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
            try {
                $output = $observer->after($definition->operation, $input, $output);
            } catch (\Throwable $failure) {
                // The class only: a message may carry what was sent.
                $this->logger->error('An observer failed after the operation had succeeded.', [
                    'operation' => $definition->operation->name,
                    'observer' => $observer::class,
                    'failure' => $failure::class,
                ]);
            }
        }

        return $output;
    }
}
