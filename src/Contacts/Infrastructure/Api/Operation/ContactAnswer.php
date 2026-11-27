<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Api\Operation;

use Foresi\Contacts\Infrastructure\Api\ContactApiController;
use Foresi\Contacts\Infrastructure\Api\ContactJson;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every contact write answers: the contact detail, as GET
 * /api/v1/contacts/{id} and foresi_get_contact answer it.
 */
final readonly class ContactAnswer
{
    public function __construct(private ContactDetails $details, private UrlGeneratorInterface $urls, private CustomFieldPayload $customFields)
    {
    }

    /**
     * @param string $summary with %s for the name of the contact
     */
    public function of(string $id, string $summary, bool $created = false): OperationOutput
    {
        $detail = $this->details->of($id);

        return new OperationOutput(
            $this->customFields->attachOne(ContactExtension::RECORD, ContactJson::detail($detail)),
            \sprintf($summary, $detail->contact->name),
            $created ? $this->urls->generate(ContactApiController::SHOW, ['contactId' => $id]) : null,
        );
    }
}
