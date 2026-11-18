<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Party\Support\FirmAccounts;

/**
 * A fresh schema with one firm whose account has an inbound address, and
 * synthetic mail to it. Nothing is a real message: example.com names and the
 * test IBAN only.
 */
final readonly class InboundMailKit
{
    public const string MESSAGE_ID = 'notice-1@mail.example.com';
    public const string IBAN = 'CZ6508000000192000145399';
    public const string OTHER_TOKEN = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct(public string $address, public string $feedId, public string $token)
    {
    }

    public static function seed(EntityManagerInterface $em, GenerateAddress $generate): self
    {
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        $account = FirmAccounts::add($em, $firm, self::IBAN);
        $em->flush();

        $address = $generate->generate($account->id());
        $feedId = Scalars::text($em->getConnection()->fetchOne('SELECT id FROM bank_feed'));
        $em->clear();

        $token = substr($address, \strlen('bank+'), 26);

        return new self($address, $feedId, $token);
    }

    /**
     * A plain notification e-mail.
     *
     * @param array<string, string> $headers more header fields, placed first as a receiving server would
     */
    public static function mail(string $to, ?string $messageId = self::MESSAGE_ID, array $headers = [], string $body = "A payment arrived on your account.\r\n"): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $lines[] = 'From: Example Bank <info@bank.example.com>';
        $lines[] = 'To: '.$to;
        $lines[] = 'Subject: Payment notification';
        $lines[] = 'Date: Fri, 20 Nov 2026 10:00:00 +0100';
        if (null !== $messageId) {
            $lines[] = 'Message-ID: <'.$messageId.'>';
        }
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=utf-8';

        return implode("\r\n", $lines)."\r\n\r\n".$body;
    }

    public function unknownAddress(): string
    {
        return 'bank+'.self::OTHER_TOKEN.'@example.com';
    }
}
