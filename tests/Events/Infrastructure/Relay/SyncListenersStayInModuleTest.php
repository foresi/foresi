<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Relay;

use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Catalogue\EventType;
use App\Events\Infrastructure\Relay\EventListeners;
use App\Shared\Domain\Module\ModuleId;
use App\Tests\Events\Fixtures\Stranger\ForeignSyncListener;
use App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued;
use App\Tests\Events\Fixtures\Valid\OwnSyncListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A synchronous listener runs in the publisher's transaction, so it may only
 * listen to the events of its own module; another module listens after the
 * commit. The module of a class is the second segment of its namespace.
 */
final class SyncListenersStayInModuleTest extends KernelTestCase
{
    public function testEverySyncListenerIsInTheModuleOfItsEvent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        self::assertSame([], $this->foreignListeners($container->get('event_dispatcher'), $container->get(EventCatalogue::class)->all(), 'App'));
    }

    public function testAForeignSyncListenerIsFound(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(InvoiceIssued::class, new OwnSyncListener());
        $dispatcher->addListener(InvoiceIssued::class, [new ForeignSyncListener(), 'onIssued']);
        $catalogue = EventCatalogue::scan([[ModuleId::of('invoicing'), 'App\Tests\Events\Fixtures\Valid\\', \dirname(__DIR__, 2).'/Fixtures/Valid']]);

        self::assertSame(
            [ForeignSyncListener::class.' listens to '.InvoiceIssued::class],
            $this->foreignListeners($dispatcher, $catalogue->all(), 'App\Tests\Events\Fixtures'),
        );
    }

    public function testEveryAsyncListenerListensToACatalogueName(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $catalogue = $container->get(EventCatalogue::class);

        foreach ($container->get(EventListeners::class)->all() as $id => $listener) {
            self::assertTrue($catalogue->has($listener->listensTo()), \sprintf('The listener %s listens to "%s", which no installed module publishes.', $id, $listener->listensTo()));
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<EventType> $types
     * @param string          $root  the namespace the module segment follows
     *
     * @return list<string>
     */
    private function foreignListeners(EventDispatcherInterface $dispatcher, array $types, string $root): array
    {
        $foreign = [];
        foreach ($types as $type) {
            foreach ($dispatcher->getListeners($type->class) as $listener) {
                $class = $this->classOf($listener);
                if ($this->moduleOf($class, $root) !== $this->moduleOf($type->class, $root)) {
                    $foreign[] = $class.' listens to '.$type->class;
                }
            }
        }

        return $foreign;
    }

    private function classOf(mixed $listener): string
    {
        if ($listener instanceof WrappedListener) {
            $listener = $listener->getWrappedListener();
        }
        if (\is_array($listener) && \is_object($listener[0] ?? null)) {
            return $listener[0]::class;
        }
        if ($listener instanceof \Closure) {
            $scope = new \ReflectionFunction($listener)->getClosureScopeClass();

            return $scope?->getName() ?? 'Closure';
        }
        if (\is_object($listener)) {
            return $listener::class;
        }

        return get_debug_type($listener);
    }

    private function moduleOf(string $class, string $root): string
    {
        if (!str_starts_with($class, $root.'\\')) {
            return '';
        }

        return explode('\\', substr($class, \strlen($root) + 1))[0];
    }
}
