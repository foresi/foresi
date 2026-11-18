<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * The REST endpoint of every declared operation (routes by OperationRoutes): the
 * body is a JSON object of the fields, the path gives the rest, and the answer
 * is {"data": ...} with the declared status, and Location for a created record.
 * What is refused is answered by OperationErrors.
 */
#[AsController]
final readonly class OperationController
{
    public const string ATTRIBUTE = '_operation';

    public function __construct(private Operations $operations, private OperationRunner $runner)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $definition = $this->operations->get($request->attributes->getString(self::ATTRIBUTE));
        $sent = $this->body($request);
        foreach ($definition->fields as $field) {
            if ($field->inPath) {
                if (\array_key_exists($field->wire, $sent)) {
                    throw new InvalidInput($field->wire, 'is part of the path; leave it out of the body.');
                }
                $sent[$field->wire] = $request->attributes->getString($field->name);
            }
        }
        $output = $this->runner->run($definition, $sent);

        return new JsonResponse(['data' => $output->data], $definition->operation->status, null === $output->location ? [] : ['Location' => $output->location]);
    }

    /**
     * @return array<mixed>
     */
    private function body(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [];
        }
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('Send the body as JSON (Content-Type: application/json).');
        }
        try {
            $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The body is not valid JSON.');
        }
        if (!\is_array($body) || (array_is_list($body) && [] !== $body)) {
            throw new BadRequestHttpException('The body must be a JSON object of the fields.');
        }

        return $body;
    }
}
