<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Api;

use App\Events\Application\Query\EventFeed;
use App\Events\Application\Query\EventSlice;
use App\Events\Infrastructure\Api\EventView;
use App\Events\Infrastructure\Api\ReadableEvents;
use App\Events\Infrastructure\Api\SubjectUrls;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Shared\Application\Event\EventReadAccess;
use App\Shared\Infrastructure\Module\InstalledModules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Which modules' events the feed shows is decided by the access services of the
 * modules: a module that says no, or has no service, is not readable.
 */
final class ReadableEventsTest extends TestCase
{
    public function testNonStaffSees403ForInvoicingTypesAndAnEmptyListWithoutTypes(): void
    {
        $feed = new class implements EventFeed {
            /** @var list<list<string>> */
            public array $asked = [];

            public function slice(?int $afterPosition, array $names, int $limit): EventSlice
            {
                $this->asked[] = $names;

                return new EventSlice([], false);
            }
        };
        $events = $this->events($feed, $this->access('invoicing', false));

        self::assertSame(['items' => [], 'next_cursor' => null], $events->page([], 25, null));
        self::assertSame([[]], $feed->asked);
        self::assertSame([], $events->types());

        $this->expectException(AccessDeniedHttpException::class);
        $events->page(['invoicing.invoice.paid'], 25, null);
    }

    public function testAModuleWithoutAnAccessServiceIsNotReadable(): void
    {
        $events = $this->events($this->empty());

        self::assertSame([], $events->types());
        $this->expectException(AccessDeniedHttpException::class);
        $events->page(['invoicing.*'], 25, null);
    }

    public function testAReadableModuleListsItsTypes(): void
    {
        $events = $this->events($this->empty(), $this->access('invoicing', true));

        self::assertSame(['invoicing.invoice.delivered', 'invoicing.invoice.issued', 'invoicing.invoice.paid', 'invoicing.invoice.restored', 'invoicing.invoice.trashed', 'invoicing.payment.recorded'], array_map(static fn (\App\Events\Infrastructure\Catalogue\EventType $type): string => $type->name, $events->types()));
    }

    private function empty(): EventFeed
    {
        return new class implements EventFeed {
            public function slice(?int $afterPosition, array $names, int $limit): EventSlice
            {
                return new EventSlice([], false);
            }
        };
    }

    private function access(string $module, bool $may): EventReadAccess
    {
        return new readonly class($module, $may) implements EventReadAccess {
            public function __construct(private string $module, private bool $may)
            {
            }

            public function module(): string
            {
                return $this->module;
            }

            public function mayRead(): bool
            {
                return $this->may;
            }
        };
    }

    private function events(EventFeed $feed, EventReadAccess ...$access): ReadableEvents
    {
        $catalogue = new EventCatalogue(new InstalledModules(\dirname(__DIR__, 4)), \dirname(__DIR__, 4));

        return new ReadableEvents($feed, $catalogue, new EventView($catalogue, new EventPayloads(), new SubjectUrls([])), $access);
    }
}
