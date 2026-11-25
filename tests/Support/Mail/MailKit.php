<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Mail;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Mail\Contract\AddressIssuer;

/**
 * A fresh schema with one inbound address of the test purpose, and synthetic
 * mail to it. Nothing is a real message: example.com names only.
 */
final readonly class MailKit
{
    public const string MESSAGE_ID = SyntheticMail::MESSAGE_ID;
    public const string OTHER_TOKEN = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct(public string $address, public string $addressId, public string $token)
    {
    }

    public static function seed(EntityManagerInterface $em, AddressIssuer $issuer, string $purpose = RecordingPurpose::KEY): self
    {
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        RecordingPurpose::reset();

        $issued = $issuer->issue($purpose, null, 'Test');
        $em->clear();

        return new self($issued->address, $issued->id, substr($issued->address, \strlen('bank+'), 26));
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
