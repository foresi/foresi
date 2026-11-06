<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\InvalidIdokladConnection;
use App\Invoicing\Application\MailSecrets;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;

/**
 * Stores, replaces and deletes the iDoklad API credentials of a firm. The
 * secret is sealed with the keys that seal mail server passwords, held
 * outside the database.
 */
final readonly class ConnectIdoklad
{
    public function __construct(
        private IdokladConnections $connections,
        private MailSecrets $secrets,
        private TransactionalWork $work,
    ) {
    }

    /**
     * @throws InvalidIdokladConnection
     * @throws \App\Invoicing\Application\MailSecretsUnavailable when no key is configured
     */
    public function connect(LegalEntity $firm, string $clientId, #[\SensitiveParameter] string $clientSecret): IdokladConnection
    {
        $clientSecret = trim($clientSecret);
        if ('' === $clientSecret) {
            throw new InvalidIdokladConnection('The iDoklad connection needs a client secret.');
        }
        $sealed = $this->secrets->seal($clientSecret);

        return $this->work->run(function () use ($firm, $clientId, $sealed): IdokladConnection {
            $connection = $this->connections->for($firm);
            if (!$connection instanceof IdokladConnection) {
                $connection = new IdokladConnection($firm, $clientId, $sealed);
                $this->connections->save($connection);
            } else {
                $connection->change($clientId, $sealed);
            }

            return $connection;
        });
    }

    /**
     * Deletes the firm's credentials; false when it had none. The snapshot stays.
     */
    public function disconnect(LegalEntity $firm): bool
    {
        return $this->work->run(function () use ($firm): bool {
            $connection = $this->connections->for($firm);
            if (!$connection instanceof IdokladConnection) {
                return false;
            }
            $this->connections->remove($connection);

            return true;
        });
    }
}
