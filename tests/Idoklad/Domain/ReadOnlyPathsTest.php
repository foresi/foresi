<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Domain;

use App\Idoklad\Domain\IdokladPathRefused;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ReadOnlyPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadOnlyPathsTest extends TestCase
{
    public function testEveryAllowedPathIsAGetEndpointOfTheApiDocumentation(): void
    {
        $gets = array_map(
            static fn (array $endpoint): string => preg_replace('/\{[A-Za-z]+\}/', '{id}', $endpoint[1]) ?? '',
            array_filter($this->endpoints(), static fn (array $endpoint): bool => 'GET' === $endpoint[0]),
        );

        foreach (ReadOnlyPaths::TEMPLATES as $template) {
            self::assertContains($template, $gets, $template.' is not a documented GET endpoint.');
        }
    }

    public function testNoDocumentedWriteEndpointIsAllowedWithAnyMethod(): void
    {
        $refused = 0;
        foreach ($this->endpoints() as [$method, $template]) {
            $path = preg_replace('/\{[A-Za-z]+\}/', '7', $template) ?? '';
            if ('GET' !== $method) {
                foreach ([$method, 'GET'] as $tried) {
                    try {
                        ReadOnlyPaths::check($tried, $path);
                        // A GET of a path that also takes a write is allowed only when it is a documented read.
                        self::assertSame('GET', $tried);
                        self::assertContains(['GET', $template], $this->endpoints());
                    } catch (IdokladPathRefused) {
                        ++$refused;
                    }
                }
            }
        }
        self::assertGreaterThan(40, $refused);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'get'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('writes')]
    public function testOnlyGetIsEverAllowed(string $method): void
    {
        $this->expectException(IdokladPathRefused::class);
        $this->expectExceptionMessageIsOrContains('The import only reads from iDoklad; '.$method.' is refused.');

        ReadOnlyPaths::check($method, '/IssuedInvoices');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'mark exported' => ['/Batch/Exported'];
        yield 'send an invoice' => ['/Mails/IssuedInvoice/Send'];
        yield 'send a payment confirmation' => ['/Mails/IssuedDocumentPayments/SendConfirmation/7'];
        yield 'reminders' => ['/Mails/Reminders/Send'];
        yield 'a copy for a new invoice' => ['/IssuedInvoices/7/Copy'];
        yield 'a default model' => ['/IssuedInvoices/Default'];
        yield 'account a proforma' => ['/ProformaInvoices/7/Account'];
        yield 'a query in the path' => ['/IssuedInvoices?filter=x'];
        yield 'a parent step' => ['/IssuedInvoices/../Mails/IssuedInvoice/Send'];
        yield 'an id of zero' => ['/IssuedInvoices/0'];
        yield 'an id with a sign' => ['/IssuedInvoices/-7'];
        yield 'a trailing slash' => ['/IssuedInvoices/'];
        yield 'a trailing line break' => ["/IssuedInvoices\n"];
        yield 'without the slash' => ['IssuedInvoices'];
        yield 'another letter case' => ['/issuedinvoices'];
        yield 'an image instead of the PDF' => ['/Reports/IssuedInvoice/7/Image'];
        yield 'the PDF of another report' => ['/Reports/BankStatement/7/Pdf'];
        yield 'the agenda' => ['/Account/CurrentAgenda/Signature'];
    }

    #[DataProvider('refusedPaths')]
    public function testOtherPathsAreRefused(string $path): void
    {
        $this->expectException(IdokladPathRefused::class);
        $this->expectExceptionMessageIsOrContains('The import does not read this iDoklad path.');

        ReadOnlyPaths::check('GET', $path);
    }

    public function testTheListsTheirDetailsAndThePdfsAreAllowed(): void
    {
        $paths = ['/Logs/12/3', '/IssuedDocumentPayments/PaymentsForIssuedTaxDocument', '/Contacts/1234567890123456789'];
        foreach (IdokladResource::cases() as $resource) {
            $paths[] = $resource->path();
            $paths[] = $resource->path().'/42';
            if (null !== $resource->pdfReport()) {
                $paths[] = '/Reports/'.$resource->pdfReport().'/42/Pdf';
            }
        }

        foreach (array_diff($paths, ['/Logs/42']) as $path) {
            ReadOnlyPaths::check('GET', $path);
        }
        $this->expectException(IdokladPathRefused::class);
        ReadOnlyPaths::check('GET', '/Contacts/12345678901234567890');
    }

    /**
     * @return list<array{string, string}>
     */
    private function endpoints(): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/api-endpoints.json'), true, 8, \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixture) && \is_array($fixture['endpoints']));
        $endpoints = [];
        foreach ($fixture['endpoints'] as $endpoint) {
            \assert(\is_array($endpoint) && \is_string($endpoint[0]) && \is_string($endpoint[1]));
            $endpoints[] = [$endpoint[0], $endpoint[1]];
        }

        return $endpoints;
    }
}
