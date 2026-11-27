<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Domain\IdokladConnection;
use Foresi\Idoklad\Domain\InvalidIdokladConnection;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Shared\Application\TransactionalWork;

/**
 * Stores, replaces and deletes the iDoklad API credentials of the iDoklad account. The
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
     * @throws \Foresi\Invoicing\Application\MailSecretsUnavailable when no key is configured
     */
    public function connect(string $clientId, #[\SensitiveParameter] string $clientSecret): IdokladConnection
    {
        $clientSecret = trim($clientSecret);
        if ('' === $clientSecret) {
            throw new InvalidIdokladConnection('The iDoklad connection needs a client secret.');
        }
        $sealed = $this->secrets->seal($clientSecret);

        return $this->work->run(function () use ($clientId, $sealed): IdokladConnection {
            $connection = $this->connections->get();
            if (!$connection instanceof IdokladConnection) {
                $connection = new IdokladConnection($clientId, $sealed);
                $this->connections->save($connection);
            } else {
                $connection->change($clientId, $sealed);
            }

            return $connection;
        });
    }

    /**
     * Deletes the credentials; false when there were none. The snapshot stays.
     */
    public function disconnect(): bool
    {
        return $this->work->run(function (): bool {
            $connection = $this->connections->get();
            if (!$connection instanceof IdokladConnection) {
                return false;
            }
            $this->connections->remove($connection);

            return true;
        });
    }
}
