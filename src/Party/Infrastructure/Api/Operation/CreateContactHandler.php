<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\Contact\ContactWrites;
use Foresi\Party\Application\Contact\CreateContactInput;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * contacts.create: the new contact, with its Location.
 */
final readonly class CreateContactHandler implements OperationHandler
{
    public function __construct(private ContactWrites $writes, private ContactAnswer $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return CreateContactInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateContactInput);

        return $this->answer->of($this->writes->create($input, $this->customFields->read(ContactExtension::RECORD, $input->customFields, true)), 'Created the contact %s.', created: true);
    }
}
