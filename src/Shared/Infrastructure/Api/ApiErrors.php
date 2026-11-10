<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Every error of the REST API as a problem document. The modules turn what their
 * query services refuse into HTTP exceptions on their own routes, and this renders
 * them, an unknown route and a wrong method among them. A query string that does
 * not validate is 400 invalid_parameter with the parameter that is wrong. Anything
 * else is 500 internal_error, and the client learns nothing of what failed.
 *
 * It runs after the listeners of the modules and the security, and before the
 * error page of the framework.
 */
final readonly class ApiErrors
{
    public const string PATH_PREFIX = '/api/';
    public const string INVALID_PARAMETER = 'invalid_parameter';
    public const string INTERNAL_ERROR = 'internal_error';

    /** The stable codes of the statuses the API answers with. */
    public const array CODES = [
        Response::HTTP_BAD_REQUEST => 'bad_request',
        Response::HTTP_UNAUTHORIZED => 'unauthorized',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_NOT_ACCEPTABLE => 'not_acceptable',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
        Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
    ];

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }
        $error = $event->getThrowable();
        if (!$error instanceof HttpExceptionInterface || $error->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_INTERNAL_SERVER_ERROR, self::INTERNAL_ERROR, 'The request failed on the server.'));

            return;
        }
        $headers = $this->headers($error);
        $previous = $error->getPrevious();
        if ($error instanceof InvalidParameter) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_BAD_REQUEST, self::INVALID_PARAMETER, $error->getMessage(), $headers, ['parameter' => $error->parameter]));
        } elseif ($previous instanceof ValidationFailedException && \count($previous->getViolations()) > 0) {
            $violation = $previous->getViolations()->get(0);
            $event->setResponse(ProblemResponse::create(Response::HTTP_BAD_REQUEST, self::INVALID_PARAMETER, (string) $violation->getMessage(), $headers, ['parameter' => $this->parameter($violation->getPropertyPath())]));
        } else {
            $status = $error->getStatusCode();
            $detail = '' === $error->getMessage() ? (Response::$statusTexts[$status] ?? 'Error') : $error->getMessage();
            $event->setResponse(ProblemResponse::create($status, self::CODES[$status] ?? 'http_error', $detail, $headers));
        }
    }

    /**
     * The headers the error asks for, such as Allow or Retry-After.
     *
     * @return array<string, string>
     */
    private function headers(HttpExceptionInterface $error): array
    {
        $headers = [];
        foreach ($error->getHeaders() as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * The name of a parameter as the client sends it: firmId is firm_id.
     */
    private function parameter(string $propertyPath): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyPath));
    }
}
