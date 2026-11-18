<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Infrastructure\Api\ContactApiController;
use Foresi\Party\Infrastructure\Api\ContactJson;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every contact write answers: the contact detail, as GET
 * /api/v1/contacts/{id} and foresi_get_contact answer it.
 */
final readonly class ContactAnswer
{
    public function __construct(private ContactDetails $details, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param string $summary with %s for the name of the contact
     */
    public function of(string $id, string $summary, bool $created = false): OperationOutput
    {
        $detail = $this->details->of($id);

        return new OperationOutput(
            ContactJson::detail($detail),
            \sprintf($summary, $detail->contact->name),
            $created ? $this->urls->generate(ContactApiController::SHOW, ['contactId' => $id]) : null,
        );
    }
}
