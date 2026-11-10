<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Http;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\UnknownParty;
use App\Party\Infrastructure\Http\PartyErrors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PartyErrorsTest extends TestCase
{
    public function testNotAllowedIsForbiddenAndUnknownIsNotFoundOnTheContactRoutes(): void
    {
        foreach (['app_contacts', 'app_contact_show', 'app_contact_archive', 'api_v1_contacts', 'api_v1_contact'] as $route) {
            $notAllowed = new PartyNotAllowed();
            $event = $this->event($route, $notAllowed);
            new PartyErrors()->onException($event);
            self::assertInstanceOf(AccessDeniedHttpException::class, $event->getThrowable());
            self::assertSame($notAllowed->getMessage(), $event->getThrowable()->getMessage());
            self::assertSame($notAllowed, $event->getThrowable()->getPrevious());

            $unknown = new UnknownParty();
            $event = $this->event($route, $unknown);
            new PartyErrors()->onException($event);
            self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
            self::assertSame($unknown, $event->getThrowable()->getPrevious());
        }
    }

    public function testOtherRoutesAndOtherErrorsAreLeftAlone(): void
    {
        $error = new PartyNotAllowed();
        foreach (['app_invoices', 'app_dashboard', 'api_v1_invoices', 'api_v1_me', null] as $route) {
            $event = $this->event($route, $error);
            new PartyErrors()->onException($event);
            self::assertSame($error, $event->getThrowable());
        }
        $other = new \RuntimeException('Other');
        $event = $this->event('app_contact_show', $other);
        new PartyErrors()->onException($event);
        self::assertSame($other, $event->getThrowable());
    }

    private function event(?string $route, \Throwable $error): ExceptionEvent
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = new Request(attributes: null === $route ? [] : ['_route' => $route]);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $error);
    }
}
