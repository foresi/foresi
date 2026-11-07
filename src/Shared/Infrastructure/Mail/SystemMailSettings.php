<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMailUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;

/**
 * The system mailer of the installation: its DSN (SYSTEM_MAILER_DSN) and its
 * sender (SYSTEM_MAIL_FROM, such as "Foresi <noreply@example.com>"). Either one
 * empty, or a sender that is no address, and the installation sends no mail.
 */
final readonly class SystemMailSettings
{
    public function __construct(
        #[Autowire(env: 'SYSTEM_MAILER_DSN')]
        private string $dsn,
        #[Autowire(env: 'SYSTEM_MAIL_FROM')]
        private string $from,
        private ?TransportInterface $transport = null,
    ) {
    }

    /**
     * Settings sending through the given transport, for tests.
     */
    public static function through(TransportInterface $transport, string $from): self
    {
        return new self('through://', $from, $transport);
    }

    public function isConfigured(): bool
    {
        try {
            $this->from();

            return true;
        } catch (SystemMailUnavailable) {
            return false;
        }
    }

    /**
     * @throws SystemMailUnavailable
     */
    public function from(): Address
    {
        if ('' === $this->dsn || '' === $this->from) {
            throw new SystemMailUnavailable();
        }
        try {
            return Address::create($this->from);
        } catch (\InvalidArgumentException) {
            throw new SystemMailUnavailable();
        }
    }

    public function transport(): TransportInterface
    {
        return $this->transport ?? Transport::fromDsn($this->dsn);
    }
}
