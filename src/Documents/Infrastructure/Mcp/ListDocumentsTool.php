<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Mcp;

use Foresi\Documents\Application\DocumentFilter;
use Foresi\Documents\Application\DocumentReads;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The documents, as GET /api/v1/documents writes them.
 */
final readonly class ListDocumentsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DocumentReads $reads, private DocumentsJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_documents';
    }

    public function title(): string
    {
        return 'List documents';
    }

    public function scope(): string
    {
        return 'documents:read';
    }

    public function description(): string
    {
        return 'Returns the documents, newest first: {items} with the id, title, status, type and the days it was created, generated and completed. '
            .'Filter by type_id, status, or linked_kind with linked_id for the documents of a record, such as a contact. Page with limit and offset. '
            .'foresi_get_document gives the links, signers, files and frozen values of one.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type_id' => ToolArguments::idSchema('Only the documents of this type, from foresi_list_document_types.'),
                'status' => ToolArguments::choiceSchema(array_column(DocumentStatus::cases(), 'value'), 'Only the documents in this status.'),
                'linked_kind' => ['type' => 'string', 'description' => 'With linked_id: the kind of the record the documents are linked to, such as contact.'],
                'linked_id' => ToolArguments::idSchema('With linked_kind: the id of the record.'),
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => DocumentFilter::MAX_LIMIT, 'default' => 50, 'description' => 'How many to return at most.'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'How many of the newest to skip.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $documents = $this->reads->documents(
                ToolArguments::text($arguments, 'type_id'),
                ToolArguments::text($arguments, 'status'),
                ToolArguments::text($arguments, 'linked_kind'),
                ToolArguments::text($arguments, 'linked_id'),
                $this->number($arguments, 'limit'),
                $this->number($arguments, 'offset'),
            );
        } catch (DocumentsRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($documents);

        return new ToolOutput(
            ['items' => array_map($this->json->documentRow(...), $documents)],
            1 === $count ? '1 document.' : \sprintf('%d documents, newest first.', $count),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal for a value that is no integer
     */
    private function number(array $arguments, string $name): ?int
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" must be an integer.', $name));
        }

        return $value;
    }
}
