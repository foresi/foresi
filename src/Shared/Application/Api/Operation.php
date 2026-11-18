<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * Declares a write once, on the input class of its use case (ADR 0032): the REST
 * endpoint and the MCP tool are derived from it, with the same scope check, the
 * same idempotency and the same refusals. Each constructor parameter of the
 * input is a field, sent in snake case; a parameter named in the path, such as
 * {contactId}, comes from the path over REST and as contact_id over MCP.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Operation
{
    /**
     * @param string      $name              unique, such as "contacts.create"
     * @param string      $method            the HTTP method: POST, PATCH or DELETE
     * @param string      $path              below /api/v1, such as "/api/v1/contacts/{contactId}"
     * @param string      $tool              the MCP tool, such as "foresi_create_contact"
     * @param string      $scope             the scope a token needs, such as "contacts:write"
     * @param string      $title             a short name for people, such as "Create a contact"
     * @param string      $description       what it changes and when to use it, written for agents
     * @param int         $status            the status of a success over REST
     * @param bool        $destructive       it destroys something or cannot be taken back (destructiveHint)
     * @param bool        $needsConfirmation a person must confirm it in Foresi; until that exists it is refused over the API
     * @param string|null $response          the schema of the answer's data in the OpenAPI document, such as "ContactDetail"
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public string $tool,
        public string $scope,
        public string $title,
        public string $description,
        public int $status = 200,
        public bool $destructive = false,
        public bool $needsConfirmation = false,
        public ?string $response = null,
    ) {
    }
}
