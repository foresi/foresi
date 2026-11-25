<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Integration\Banking;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\GenerateAddress;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\SyntheticMail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * With Banking, a bank notice a person also forwards to their inbox address is
 * flagged on the item as a mail the bank feed took too. Synthetic mail only.
 */
final class CrossPurposeDuplicateTest extends KernelTestCase
{
    public function testACrossPurposeDuplicateIsFlagged(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $owner = new User('owner@example.com', 'hash', 'Example Person');
        $em->persist($owner);
        $em->persist(new Membership($owner, Role::Owner));
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $account = FirmAccounts::add($em, 'CZ6508000000192000145399');
        $em->flush();
        $inboxAddress = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $owner->id(), 'My inbox')->address;
        $bankAddress = self::getContainer()->get(GenerateAddress::class)->generate($account->id());
        $notice = SyntheticMail::plain($bankAddress);
        $receive = self::getContainer()->get(ReceiveMail::class);

        self::assertSame(ReceiveOutcome::Stored, $receive->receive($notice, InboundChannel::Webhook, $bankAddress));
        self::assertSame(ReceiveOutcome::Stored, $receive->receive($notice, InboundChannel::Webhook, $inboxAddress));
        $messageId = $em->getConnection()->fetchOne('SELECT mail_message_id FROM inbox_item');
        self::assertIsString($messageId);
        $em->clear();
        $item = new DoctrineItemStore($em)->itemOfMessage($messageId);

        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame('bank_feed', $item->duplicateOfPurpose());
    }
}
