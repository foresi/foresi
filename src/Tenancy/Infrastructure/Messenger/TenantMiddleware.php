<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Messenger;

use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantScopedMessage;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Keeps background work inside its tenant. A dispatched message is stamped
 * with the active tenant; a received one is handled with that tenant active,
 * and the tenant is cleared afterwards so the next message of a long-running
 * worker never sees it. Fails closed: a tenant-scoped message that arrives
 * without a stamp is rejected and never retried.
 */
final readonly class TenantMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->last(ReceivedStamp::class) instanceof ReceivedStamp) {
            return $stack->next()->handle($this->stampOnDispatch($envelope), $stack);
        }

        $stamp = $envelope->last(TenantStamp::class);
        if (!$stamp instanceof TenantStamp) {
            if ($envelope->getMessage() instanceof TenantScopedMessage) {
                throw new UnrecoverableMessageHandlingException(sprintf('%s was received without a tenant.', $envelope->getMessage()::class));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        return $this->handleInTenant(TenantId::fromString($stamp->tenantId), $envelope, $stack);
    }

    private function stampOnDispatch(Envelope $envelope): Envelope
    {
        if (!$this->context->has() || $envelope->last(TenantStamp::class) instanceof TenantStamp) {
            return $envelope;
        }

        return $envelope->with(new TenantStamp($this->context->current()->toString()));
    }

    private function handleInTenant(TenantId $tenant, Envelope $envelope, StackInterface $stack): Envelope
    {
        // A synchronous transport handles inside the dispatching request, whose
        // tenant must survive: only a tenant this middleware activated is reset.
        if ($this->context->has()) {
            if ($this->context->current()->equals($tenant)) {
                return $stack->next()->handle($envelope, $stack);
            }

            $previous = $this->context->current();
            $this->context->switchTo($tenant);
            try {
                return $stack->next()->handle($envelope, $stack);
            } finally {
                $this->context->switchTo($previous);
            }
        }

        $this->context->set($tenant);
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->context->reset();
        }
    }
}
