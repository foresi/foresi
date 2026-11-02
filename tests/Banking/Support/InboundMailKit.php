<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\GenerateAddress;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * A fresh schema with one firm whose account has an inbound address, and
 * synthetic mail to it. Nothing is a real message: example.com names and the
 * test IBAN only. The tenant is reset afterwards, as mail arrives without one.
 */
final readonly class InboundMailKit
{
    public const string MESSAGE_ID = 'notice-1@mail.example.com';
    public const string IBAN = 'CZ6508000000192000145399';
    public const string OTHER_TOKEN = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct(public TenantId $tenant, public string $address, public string $feedId, public string $token)
    {
    }

    public static function seed(EntityManagerInterface $em, TenantContext $context, GenerateAddress $generate): self
    {
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $tenant = TenantId::generate();
        $context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        $account = FirmAccounts::add($em, $firm, self::IBAN);
        $em->flush();

        $address = $generate->generate($account->id());
        $feedId = Scalars::text($em->getConnection()->fetchOne('SELECT id FROM bank_feed'));
        $context->reset();
        $em->clear();

        $token = substr($address, \strlen('bank+'), 26);

        return new self($tenant, $address, $feedId, $token);
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
