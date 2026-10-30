<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Messenger\TenantMiddleware;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class TenantMiddlewareTest extends KernelTestCase
{
    private TenantContext $context;
    private EntityManagerInterface $em;
    private TenantMiddleware $middleware;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->context = $container->get(TenantContext::class);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->middleware = $container->get(TenantMiddleware::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testStampsTheActiveTenantOnDispatch(): void
    {
        $tenant = TenantId::generate();
        $this->context->set($tenant);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ScopedProbe());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        $stamp = $transport->getSent()[0]->last(TenantStamp::class);
        self::assertInstanceOf(TenantStamp::class, $stamp);
        self::assertSame($tenant->toString(), $stamp->tenantId);
    }

    public function testDoesNotStampWithoutTenantAndKeepsAnExistingStamp(): void
    {
        $envelope = $this->middleware->handle(new Envelope(new ScopedProbe()), $this->passThrough());
        self::assertNotInstanceOf(TenantStamp::class, $envelope->last(TenantStamp::class));

        $this->context->set(TenantId::generate());
        $original = new TenantStamp(TenantId::generate()->toString());
        $envelope = $this->middleware->handle(new Envelope(new ScopedProbe(), [$original]), $this->passThrough());
        self::assertSame([$original], $envelope->all(TenantStamp::class));
    }

    public function testSetsAndResetsTheTenantAroundHandling(): void
    {
        $tenant = TenantId::generate();
        $seen = null;

        $this->middleware->handle($this->received($tenant), $this->observing(static function (TenantContext $context) use (&$seen): void {
            $seen = $context->current();
        }));

        self::assertInstanceOf(TenantId::class, $seen);
        self::assertTrue($tenant->equals($seen));
        self::assertFalse($this->context->has());
    }

    public function testResetsTheTenantWhenTheHandlerThrows(): void
    {
        try {
            $this->middleware->handle($this->received(TenantId::generate()), $this->observing(static function (): never {
                throw new \RuntimeException('boom');
            }));
            self::fail('The handler failure must propagate.');
        } catch (\RuntimeException) {
            self::assertFalse($this->context->has());
        }
    }

    public function testRejectsATenantScopedMessageWithoutStamp(): void
    {
        $called = false;

        try {
            $this->middleware->handle(
                new Envelope(new ScopedProbe(), [new ReceivedStamp('async')]),
                $this->observing(static function () use (&$called): void {
                    $called = true;
                }),
            );
            self::fail('A scoped message without a tenant must be rejected.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString(ScopedProbe::class, $e->getMessage());
            self::assertFalse($called);
        }
    }

    public function testHandlesAMessageThatIsNotTenantScopedWithoutStamp(): void
    {
        $called = false;

        $this->middleware->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('async')]),
            $this->observing(static function (TenantContext $context) use (&$called): void {
                $called = !$context->has();
            }),
        );

        self::assertTrue($called);
    }

    public function testKeepsTheTenantOfASynchronousDispatch(): void
    {
        $tenant = TenantId::generate();
        $this->context->set($tenant);

        $this->middleware->handle($this->received($tenant), $this->passThrough());

        self::assertTrue($this->context->has());
        self::assertTrue($tenant->equals($this->context->current()));
    }

    public function testRestoresTheOuterTenantAfterHandlingAnotherOne(): void
    {
        $outer = TenantId::generate();
        $inner = TenantId::generate();
        $this->context->set($outer);
        $seen = null;

        $this->middleware->handle($this->received($inner), $this->observing(static function (TenantContext $context) use (&$seen): void {
            $seen = $context->current();
        }));

        self::assertInstanceOf(TenantId::class, $seen);
        self::assertTrue($inner->equals($seen));
        self::assertTrue($outer->equals($this->context->current()));
    }

    public function testTwoMessagesOfDifferentTenantsDoNotLeak(): void
    {
        $a = TenantId::generate();
        $b = TenantId::generate();

        $this->middleware->handle($this->received($a), $this->observing(function (): void {
            $this->em->persist(new TestNote('a-secret'));
            $this->em->flush();
        }));

        $texts = null;
        $this->middleware->handle($this->received($b), $this->observing(function () use (&$texts): void {
            $this->em->persist(new TestNote('b-note'));
            $this->em->flush();
            $texts = array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll());
        }));

        self::assertSame(['b-note'], $texts);
        self::assertFalse($this->context->has());
    }

    private function received(TenantId $tenant): Envelope
    {
        return new Envelope(new ScopedProbe(), [new ReceivedStamp('async'), new TenantStamp($tenant->toString())]);
    }

    private function passThrough(): StackMiddleware
    {
        return new StackMiddleware();
    }

    /**
     * @param callable(TenantContext): void $handler
     */
    private function observing(callable $handler): StackMiddleware
    {
        $context = $this->context;

        return new StackMiddleware(new CallbackMiddleware(static fn () => $handler($context)));
    }
}
