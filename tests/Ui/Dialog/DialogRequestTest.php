<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Dialog;

use App\Ui\Dialog\DialogRedirects;
use App\Ui\Dialog\DialogRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DialogRequestTest extends TestCase
{
    public function testADialogRequestCarriesTheHeader(): void
    {
        self::assertTrue(DialogRequest::is($this->dialogRequest()));
        self::assertFalse(DialogRequest::is(Request::create('/invoices/1/send')));
    }

    public function testARedirectAnsweringADialogBecomesWhereToGoNext(): void
    {
        $response = $this->respond($this->dialogRequest(), new RedirectResponse('/invoices?tab=unpaid'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(['location' => '/invoices?tab=unpaid'], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString(DialogRequest::HEADER, (string) $response->headers->get('Vary'));
    }

    public function testAPageRequestKeepsItsRedirect(): void
    {
        $response = $this->respond(Request::create('/invoices/1/send', 'POST'), new RedirectResponse('/invoices'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/invoices', $response->headers->get('Location'));
    }

    public function testADialogPageIsNeverCachedAsTheFullPage(): void
    {
        $response = $this->respond($this->dialogRequest(), new Response('<dialog></dialog>'));

        self::assertSame('<dialog></dialog>', $response->getContent());
        self::assertStringContainsString(DialogRequest::HEADER, (string) $response->headers->get('Vary'));
    }

    private function dialogRequest(): Request
    {
        $request = Request::create('/invoices/1/send', 'POST');
        $request->headers->set(DialogRequest::HEADER, '1');

        return $request;
    }

    private function respond(Request $request, Response $response): Response
    {
        $kernel = self::createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        new DialogRedirects()->onResponse($event);

        return $event->getResponse();
    }
}
