<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Http;

use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Infrastructure\Http\InvoicingErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class InvoicingErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'invoices' => ['app_invoices'];
        yield 'history' => ['app_invoice_history'];
        yield 'advances' => ['app_advances_missing'];
        yield 'tax documents' => ['app_tax_documents_uncorrected'];
        yield 'corrections' => ['app_corrections'];
    }

    #[DataProvider('routes')]
    public function testARefusalIsForbiddenOnTheInvoicingRoutes(string $route): void
    {
        $refusal = new InvoicingNotAllowed();

        $thrown = $this->handle($route, $refusal);

        self::assertInstanceOf(AccessDeniedHttpException::class, $thrown);
        self::assertSame($refusal->getMessage(), $thrown->getMessage());
        self::assertSame($refusal, $thrown->getPrevious());
    }

    #[DataProvider('routes')]
    public function testAnUnknownInvoiceIsNotFoundOnTheInvoicingRoutes(string $route): void
    {
        $unknown = new UnknownInvoice();

        $thrown = $this->handle($route, $unknown);

        self::assertInstanceOf(NotFoundHttpException::class, $thrown);
        self::assertSame('No such invoice.', $thrown->getMessage());
        self::assertSame($unknown, $thrown->getPrevious());
    }

    public function testOtherErrorsAndOtherRoutesStayAsTheyAre(): void
    {
        $refusal = new InvoicingNotAllowed();
        $other = new \RuntimeException('Something else.');

        self::assertSame($refusal, $this->handle('app_purchasing_list', $refusal));
        self::assertSame($refusal, $this->handle(null, $refusal));
        self::assertSame($refusal, $this->handle('app_recurring', $refusal));
        self::assertSame($other, $this->handle('app_invoices', $other));
    }

    private function handle(?string $route, \Throwable $error): \Throwable
    {
        $request = new Request();
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }
        $event = new ExceptionEvent(self::createStub(KernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error);

        new InvoicingErrors()->onException($event);

        return $event->getThrowable();
    }
}
