<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Api;

use App\Shared\Infrastructure\Api\ApiErrors;
use App\Shared\Infrastructure\Api\InvalidParameter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ApiErrorsTest extends TestCase
{
    public function testOnlyThePathsOfTheApiAreAnswered(): void
    {
        foreach (['/invoices', '/mcp', '/apis/v1', '/api'] as $path) {
            $event = $this->event($path, new \RuntimeException('Broken.'));

            new ApiErrors()->onException($event);

            self::assertNull($event->getResponse(), $path);
        }
    }

    public function testAnHttpErrorKeepsItsStatusMessageAndHeaders(): void
    {
        $response = $this->answer(new MethodNotAllowedHttpException(['GET', 'HEAD'], 'Only reads.'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405, 'detail' => 'Only reads.', 'code' => 'method_not_allowed'], $this->body($response));
    }

    public function testAForbiddenOneIsForbidden(): void
    {
        $response = $this->answer(new AccessDeniedHttpException('Only the staff read invoices.'));

        self::assertSame(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'Only the staff read invoices.', 'code' => 'forbidden'], $this->body($response));
    }

    public function testWithoutAMessageTheDetailIsTheStatus(): void
    {
        $response = $this->answer(new HttpException(406));

        self::assertSame(['type' => 'about:blank', 'title' => 'Not Acceptable', 'status' => 406, 'detail' => 'Not Acceptable', 'code' => 'not_acceptable'], $this->body($response));
    }

    public function testAStatusWithoutACodeOfItsOwnHasTheGenericOne(): void
    {
        $response = $this->answer(new HttpException(409, 'Conflict here.'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('http_error', $this->body($response)['code']);
    }

    public function testAnInvalidParameterIsNamed(): void
    {
        $response = $this->answer(new InvalidParameter('cursor', 'The cursor is not one this API gave.'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'The cursor is not one this API gave.', 'code' => 'invalid_parameter', 'parameter' => 'cursor'], $this->body($response));
    }

    public function testAQueryStringThatFailsValidationNamesItsFirstParameterAsTheClientSendsIt(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value is not a valid UUID.', null, [], null, 'firmId', 'nope'),
            new ConstraintViolation('This value should be between 1 and 100.', null, [], null, 'limit', 0),
        ]);
        $error = new HttpException(404, 'Ignored.', new ValidationFailedException(null, $violations));

        $response = $this->answer($error);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'This value is not a valid UUID.', 'code' => 'invalid_parameter', 'parameter' => 'firm_id'], $this->body($response));
        self::assertSame('include_cancelled', $this->body($this->answer(new HttpException(422, '', new ValidationFailedException(null, new ConstraintViolationList([new ConstraintViolation('Bad.', null, [], null, 'includeCancelled', 'x')])))))['parameter']);
    }

    public function testAFailedValidationWithoutViolationsIsAnsweredByItsStatus(): void
    {
        $response = $this->answer(new HttpException(422, 'Nothing to say.', new ValidationFailedException(null, new ConstraintViolationList())));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('http_error', $this->body($response)['code']);
    }

    public function testAnythingElseIsAnInternalErrorThatTellsNothing(): void
    {
        foreach ([new \RuntimeException('SQLSTATE secret details'), new HttpException(503, 'Database at db.internal is down.')] as $error) {
            $response = $this->answer($error);

            self::assertSame(500, $response->getStatusCode());
            self::assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500, 'detail' => 'The request failed on the server.', 'code' => 'internal_error'], $this->body($response));
        }
    }

    public function testTheLastClientErrorIsStillTheClients(): void
    {
        self::assertSame(499, $this->answer(new HttpException(499, 'Closed.'))->getStatusCode());
    }

    private function answer(\Throwable $error): Response
    {
        $event = $this->event('/api/v1/invoices', $error);
        new ApiErrors()->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function event(string $path, \Throwable $error): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST, $error);
    }

    /**
     * @return array<mixed>
     */
    private function body(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
