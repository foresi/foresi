<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api\Operation;

use Foresi\Inbox\Application\Api\CreateContactFromItemInput;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Infrastructure\Api\ItemAnswer;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * An operation has one scope; creating a contact asks the address book's own
 * contacts:write of the token too, refused alike over REST and MCP.
 */
final readonly class CreateContactFromItemHandler implements OperationHandler
{
    public const string CONTACTS_SCOPE = 'contacts:write';

    public function __construct(private ItemWrites $writes, private ItemAnswer $answer, private ScopeGuard $scope)
    {
    }

    public static function input(): string
    {
        return CreateContactFromItemInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateContactFromItemInput);
        if (!$this->scope->allows(self::CONTACTS_SCOPE)) {
            throw InboxRefused::insufficientScope(self::CONTACTS_SCOPE);
        }

        return $this->answer->of($this->writes->createContact($input), 'Created the contact and matched the inbox item to it.');
    }
}
