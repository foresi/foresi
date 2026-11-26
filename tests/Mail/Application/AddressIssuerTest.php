<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Application\IssueAddresses;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Shared\Application\Sealer;
use Foresi\Shared\Infrastructure\Crypto\SodiumSealer;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Foresi\Tests\Support\Scalars;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mail hands out the addresses of a purpose: a fresh token each, stored only as
 * its hash, and as a sealed copy only for a purpose that shows its addresses
 * again. Rotating keeps the row and kills the old token; revoking kills the
 * address.
 */
final class AddressIssuerTest extends KernelTestCase
{
    private const string ADDRESS = '/^bank\+[a-z2-7]{26}@example\.com$/D';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testIssuingStoresOnlyTheHashOfTheToken(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::KEY, '01937f4e-0000-7000-8000-000000000001', 'Inbox of Client A');
        $this->em->clear();

        self::assertMatchesRegularExpression(self::ADDRESS, $issued->address);
        $token = (string) new InboundAddresses('bank+{token}@example.com')->tokenIn($issued->address);
        $row = $this->connection()->fetchAssociative('SELECT * FROM mail_inbound_address');
        self::assertIsArray($row);
        self::assertSame([$issued->id, 'test', '01937f4e-0000-7000-8000-000000000001', 'Inbox of Client A', InboundToken::hash($token), null, null], [$row['id'], $row['purpose'], $row['owner_user_id'], $row['label'], $row['token_hash'], $row['token_sealed'], $row['revoked_at']]);
        // Audited, but neither the token nor its hash is in the log.
        $audit = (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry WHERE entity_class = ?', [InboundAddress::class]));
        self::assertStringContainsString('Inbox of Client A', $audit);
        self::assertStringNotContainsString($token, $audit);
        self::assertStringNotContainsString(InboundToken::hash($token), $audit);
        self::assertNull($this->issuer()->reveal($issued->id), 'a purpose that shows no address again never does');
    }

    public function testAReshowablePurposeKeepsASealedCopyAndRevealsTheAddress(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
        $this->em->clear();

        $sealed = $this->connection()->fetchOne('SELECT token_sealed FROM mail_inbound_address');
        self::assertIsString($sealed);
        self::assertStringNotContainsString(substr($issued->address, 5, 26), $sealed);
        self::assertSame($issued->address, $this->issuer()->reveal($issued->id));
        $audit = (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry'));
        self::assertStringNotContainsString($sealed, $audit);
    }

    public function testRotatingKeepsTheRowAndKillsTheOldToken(): void
    {
        $old = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
        $this->connection()->executeStatement("UPDATE mail_inbound_address SET last_received_at = '2026-11-20 10:00:00'");
        $this->em->clear();

        $new = $this->issuer()->rotate($old->id);
        $this->em->clear();

        self::assertSame($old->id, $new->id);
        self::assertNotSame($old->address, $new->address);
        self::assertNull($this->store()->routable($this->hashOf($old->address)));
        self::assertSame($old->id, $this->store()->routable($this->hashOf($new->address))?->id());
        self::assertSame($new->address, $this->issuer()->reveal($old->id));
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
        self::assertNull($this->connection()->fetchOne('SELECT last_received_at FROM mail_inbound_address'), 'a new address has received nothing yet');
    }

    public function testRevokingKillsTheAddress(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');

        $this->issuer()->revoke($issued->id);
        $this->em->clear();

        self::assertNull($this->store()->routable($this->hashOf($issued->address)));
        self::assertNull($this->issuer()->reveal($issued->id));
        self::assertNotNull($this->connection()->fetchOne('SELECT revoked_at FROM mail_inbound_address'));
    }

    public function testWithoutATemplateNoAddressIsIssued(): void
    {
        $issuer = $this->issuer('');

        self::assertSame(AddressUnavailable::NotConfigured, $issuer->unavailable(RecordingPurpose::KEY));
        try {
            $issuer->issue(RecordingPurpose::KEY, null, 'Test');
            self::fail('A server without an address template handed out an address.');
        } catch (AddressNotIssued $refused) {
            self::assertSame(AddressUnavailable::NotConfigured, $refused->reason);
            self::assertSame('The inbound address template is not set up.', $refused->getMessage());
        }
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM mail_inbound_address')));
    }

    public function testWithoutAKeyOnlyAddressesShownOnceAreIssued(): void
    {
        $issuer = $this->issuer(sealer: new SodiumSealer(''));

        self::assertNull($issuer->unavailable(RecordingPurpose::KEY));
        self::assertSame(AddressUnavailable::NoKey, $issuer->unavailable(RecordingPurpose::RESHOWABLE_KEY));
        $this->expectExceptionObject(new AddressNotIssued(AddressUnavailable::NoKey));
        $this->expectExceptionMessageIsOrContains('No key seals the token of an inbound address.');
        $issuer->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
    }

    public function testRotatingAnUnknownAddressIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('No inbound address has this id.');

        $this->issuer()->rotate('01937f4e-0000-7000-8000-0000000000ff');
    }

    public function testRotatingWithoutATemplateKeepsTheOldToken(): void
    {
        $old = $this->issuer()->issue(RecordingPurpose::KEY, null, 'Test');
        $hash = $this->connection()->fetchOne('SELECT token_hash FROM mail_inbound_address');
        $this->em->clear();

        try {
            $this->issuer('')->rotate($old->id);
            self::fail('A server without an address template rotated an address.');
        } catch (AddressNotIssued $refused) {
            self::assertSame(AddressUnavailable::NotConfigured, $refused->reason);
        }
        self::assertSame($hash, $this->connection()->fetchOne('SELECT token_hash FROM mail_inbound_address'));
    }

    public function testNothingIsRevealedWithoutASealedCopyOrAPurposeToShowIt(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
        self::assertSame($issued->address, $this->issuer()->reveal($issued->id));

        $this->connection()->executeStatement('UPDATE mail_inbound_address SET token_sealed = NULL');
        $this->em->clear();
        self::assertNull($this->issuer()->reveal($issued->id), 'no sealed copy, nothing to show');

        $other = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Other feed');
        $this->connection()->executeStatement("UPDATE mail_inbound_address SET purpose = 'removed_module' WHERE id = ?", [$other->id]);
        $this->em->clear();
        self::assertNull($this->issuer()->reveal($other->id), 'no module takes mail for the purpose any more');
        self::assertNull($this->issuer()->reveal('01937f4e-0000-7000-8000-0000000000ff'));
    }

    public function testRevealingWithoutAKeyIsRefused(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
        $this->em->clear();

        $this->expectExceptionObject(new AddressNotIssued(AddressUnavailable::NoKey));
        $this->expectExceptionMessageIsOrContains('No key seals the token of an inbound address.');
        $this->issuer(sealer: new SodiumSealer(''))->reveal($issued->id);
    }

    public function testAnUnregisteredPurposeGetsNoAddress(): void
    {
        $this->expectException(\DomainException::class);

        $this->issuer()->issue('removed_module', null, 'Gone');
    }

    public function testMaskingCutsTheTokenOutOfAText(): void
    {
        $issued = $this->issuer()->issue(RecordingPurpose::KEY, null, 'Test');

        self::assertSame("Forward to bank+\u{2026}@example.com.", $this->issuer()->masked('Forward to '.$issued->address.'.'));
    }

    private function issuer(string $template = 'bank+{token}@example.com', ?Sealer $sealer = null): AddressIssuer
    {
        $container = self::getContainer();

        return new IssueAddresses(
            new InboundAddresses($template),
            $this->store(),
            $container->get(InboundPurposes::class),
            $sealer ?? $container->get(Sealer::class),
            $container->get(MailTransaction::class),
            $container->get(ClockInterface::class),
        );
    }

    private function store(): InboundAddressStore
    {
        return self::getContainer()->get(InboundAddressStore::class);
    }

    private function hashOf(string $address): string
    {
        return InboundToken::hash((string) new InboundAddresses('bank+{token}@example.com')->tokenIn($address));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
