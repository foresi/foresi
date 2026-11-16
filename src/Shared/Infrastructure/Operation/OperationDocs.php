<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

use App\Shared\Infrastructure\Api\Idempotency;
use Nelmio\ApiDocBundle\Describer\DescriberInterface;
use Nelmio\ApiDocBundle\OpenApiPhp\Util;
use OpenApi\Annotations\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Every declared operation in the OpenAPI document (ADR 0032): its summary and
 * description, the path parameters, the optional Idempotency-Key, the body
 * schema derived from the input, the answer and the problems it can answer
 * with. It runs after the route describer, which only knows the generic
 * controller.
 */
#[AutoconfigureTag('nelmio_api_doc.describer.default', ['priority' => -450])]
final readonly class OperationDocs implements DescriberInterface
{
    private const string PROBLEM = '#/components/schemas/Problem';

    public function __construct(private Operations $operations)
    {
    }

    public function describe(OpenApi $api): void
    {
        foreach ($this->operations->all() as $definition) {
            $operation = $definition->operation;
            $parameters = [];
            foreach ($definition->fields as $field) {
                if ($field->inPath) {
                    $parameters[] = ['name' => $field->name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => $field->describe->description ?? ''];
                }
            }
            $parameters[] = ['name' => Idempotency::HEADER, 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 255], 'description' => 'A unique key per change, such as a UUID. A retry with the same key and body answers what the first request answered (Idempotent-Replayed: true), for 24 hours.'];
            $data = null === $operation->response ? ['type' => 'object'] : ['$ref' => '#/components/schemas/'.$operation->response];
            $spec = [
                'tags' => [ucfirst(explode('.', $operation->name)[0])],
                'summary' => $operation->title,
                'description' => $operation->description.\sprintf(' MCP tool: %s. Scope: %s.', $operation->tool, $operation->scope),
                'operationId' => $operation->name,
                'parameters' => $parameters,
                'responses' => [
                    (string) $operation->status => ['description' => 'Done.', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $data]]]]],
                    '400' => $this->problem('A field cannot be used: the problem names it in "parameter" and says why in "reason".'),
                    '401' => $this->problem('No valid token.'),
                    '403' => $this->problem(\sprintf('The token lacks the %s scope, or its person may not do this.', $operation->scope)),
                    '404' => $this->problem('The organisation has no such record.'),
                    '409' => $this->problem('The data does not allow this now, such as a duplicate, or a request with the same Idempotency-Key is still running.'),
                    '422' => $this->problem('The Idempotency-Key was used with another request.'),
                    '429' => $this->problem('The token made too many requests or writes.'),
                ],
            ];
            $body = $definition->bodyFields();
            if ([] !== $body) {
                $spec['requestBody'] = ['required' => array_any($body, static fn (OperationField $field): bool => $field->required), 'content' => ['application/json' => ['schema' => InputSchema::of($body)]]];
            }
            Util::merge(Util::getOperation(Util::getPath($api, $operation->path), strtolower($operation->method)), $spec, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function problem(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => self::PROBLEM]]]];
    }
}
