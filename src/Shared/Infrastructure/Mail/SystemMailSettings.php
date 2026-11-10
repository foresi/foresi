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
 * The transport of tests is no constructor argument: autowiring would fill it
 * with the framework's mailer, and the mail would never reach SYSTEM_MAILER_DSN.
 */
final class SystemMailSettings
{
    private ?TransportInterface $transport = null;

    public function __construct(
        #[Autowire(env: 'SYSTEM_MAILER_DSN')]
        private readonly string $dsn,
        #[Autowire(env: 'SYSTEM_MAIL_FROM')]
        private readonly string $from,
    ) {
    }

    /**
     * Settings sending through the given transport, for tests.
     */
    public static function through(TransportInterface $transport, string $from): self
    {
        $settings = new self('through://', $from);
        $settings->transport = $transport;

        return $settings;
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
