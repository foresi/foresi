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
use Foresi\Tests\Support\Mail\SyntheticMail;
use Foresi\Tests\Support\Scalars;

/**
 * A fresh schema with one firm whose account has an inbound address, and
 * synthetic mail to it. Nothing is a real message: example.com names and the
 * test IBAN only.
 */
final readonly class InboundMailKit
{
    public const string MESSAGE_ID = SyntheticMail::MESSAGE_ID;
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

        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $account = FirmAccounts::add($em, self::IBAN);
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
        return SyntheticMail::plain($to, $messageId, $headers, $body);
    }

    public function unknownAddress(): string
    {
        return 'bank+'.self::OTHER_TOKEN.'@example.com';
    }
}
