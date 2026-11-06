<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Invoicing\Application\MailSecrets;
use App\Party\Domain\LegalEntity;

/**
 * Tries the stored credentials of a firm with one small read: a token alone
 * does not tell whether the tariff has API access, an answer does.
 */
final readonly class VerifyIdokladConnection
{
    public function __construct(
        private IdokladConnections $connections,
        private MailSecrets $secrets,
        private IdokladClient $client,
    ) {
    }

    /**
     * @throws IdokladFailure IdokladAccessDenied for wrong credentials, IdokladTariffWithoutApi for a tariff without the API
     */
    public function verify(LegalEntity $firm): void
    {
        $connection = $this->connections->for($firm) ?? throw new IdokladNotConnected('The firm has no iDoklad connection.');
        $this->client->session($connection->clientId(), $this->secrets->open($connection->sealedSecret()))->get('/Contacts', ['page' => 1, 'pagesize' => 1]);
    }
}
