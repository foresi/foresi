<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressReads;
use Foresi\Mail\Application\AddressWrites;
use Foresi\Mail\Application\CreateAddressInput;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\InboundToken;
use Foresi\Mail\Application\MailRefused;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Application\RenameAddressInput;
use Foresi\Mail\Application\ReplaceAddressInput;
use Foresi\Mail\Application\RevokeAddressInput;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Contract\IssuedAddress;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A person creates, renames, revokes and replaces their own inbound addresses,
 * one per purpose; someone else's address and a purpose without owners are not
 * found, and a guest is refused.
 */
final class AddressWritesTest extends KernelTestCase
{
    private IdentityFixtures $fixtures;
    private EntityManagerInterface $em;
    private User $me;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IdentityFixtures($this->em);
        $this->fixtures->rebuildSchema();
        RecordingPurpose::reset();
        $this->me = $this->fixtures->user();
        $this->fixtures->join($this->me, Role::Member);
        $this->signIn($this->me);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        parent::tearDown();
    }

    public function testCreatesAnAddressOfMyOwn(): void
    {
        $issued = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, '  Receipts  '));

        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $issued->address);
        self::assertSame([RecordingPurpose::OWNED_KEY, 'Receipts', null, null], [$issued->view->purpose, $issued->view->label, $issued->view->revokedAt, $issued->view->lastReceivedAt]);
        self::assertSame("bank+\u{2026}@example.com", $issued->view->maskedAddress);
        self::assertStringNotContainsString((string) new InboundAddresses('bank+{token}@example.com')->tokenIn($issued->address), $issued->view->maskedAddress);
        $row = $this->connection()->fetchAssociative('SELECT owner_user_id, token_hash FROM mail_inbound_address');
        self::assertSame($this->me->id(), $row['owner_user_id'] ?? null);
        self::assertSame(InboundToken::hash((string) new InboundAddresses('bank+{token}@example.com')->tokenIn($issued->address)), $row['token_hash'] ?? null);
    }

    public function testRefusesWhatCannotBeCreated(): void
    {
        $this->assertCreateRefused('purpose_unknown', 'nobody_takes_this', 'Label');
        $this->assertCreateRefused('purpose_has_no_owners', 'bank_feed', 'Label');
        $this->assertCreateRefused('purpose_has_no_owners', RecordingPurpose::KEY, 'Label');
        $this->assertCreateRefused('label_required', RecordingPurpose::OWNED_KEY, '   ');
        $this->assertCreateRefused('label_too_long', RecordingPurpose::OWNED_KEY, str_repeat('x', 81));
        self::assertSame([], $this->connection()->fetchAllAssociative('SELECT id FROM mail_inbound_address'));

        // The longest label is taken whole.
        $issued = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, str_repeat('é', 80)));
        self::assertSame(str_repeat('é', 80), $issued->view->label);
    }

    public function testASecondActiveAddressOfAPurposeIsRefusedUntilTheFirstIsRevoked(): void
    {
        $first = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'First'));
        $this->assertCreateRefused('address_exists', RecordingPurpose::OWNED_KEY, 'Second');

        $this->writes()->revoke(new RevokeAddressInput($first->view->id));
        $second = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Second'));

        self::assertNotSame($first->view->id, $second->view->id);
        self::assertCount(2, $this->reads()->mine());
    }

    public function testRenamesAnAddress(): void
    {
        $issued = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'First'));

        $renamed = $this->writes()->rename(new RenameAddressInput($issued->view->id, ' Second '));

        self::assertSame('Second', $renamed->label);
        $this->em->clear();
        self::assertSame('Second', $this->reads()->mine()[0]->label);
        $this->assertRefused('label_required', fn (): \Foresi\Mail\Application\AddressView => $this->writes()->rename(new RenameAddressInput($issued->view->id, ' ')));
        $this->assertRefused('label_too_long', fn (): \Foresi\Mail\Application\AddressView => $this->writes()->rename(new RenameAddressInput($issued->view->id, str_repeat('x', 81))));
    }

    public function testRevokingKeepsTheAddressListedAndStopsItsMail(): void
    {
        $issued = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Receipts'));

        $revoked = $this->writes()->revoke(new RevokeAddressInput($issued->view->id));

        self::assertInstanceOf(\DateTimeImmutable::class, $revoked->revokedAt);
        self::assertSame([$issued->view->id], array_map(static fn (\Foresi\Mail\Application\AddressView $view): string => $view->id, $this->reads()->mine()));
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive($issued->address));
        $this->assertRefused('address_revoked', fn (): \Foresi\Mail\Application\AddressView => $this->writes()->revoke(new RevokeAddressInput($issued->view->id)));
        $this->assertRefused('address_revoked', fn (): \Foresi\Mail\Application\IssuedMailAddress => $this->writes()->replace(new ReplaceAddressInput($issued->view->id)));
    }

    public function testReplacingKeepsPurposeAndLabelAndMovesTheMail(): void
    {
        $old = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Receipts'));

        $new = $this->writes()->replace(new ReplaceAddressInput($old->view->id));

        self::assertNotSame($old->view->id, $new->view->id);
        self::assertNotSame($old->address, $new->address);
        self::assertSame([RecordingPurpose::OWNED_KEY, 'Receipts', null], [$new->view->purpose, $new->view->label, $new->view->revokedAt]);
        $listed = $this->reads()->mine();
        self::assertSame([$old->view->id, $new->view->id], array_map(static fn (\Foresi\Mail\Application\AddressView $view): string => $view->id, $listed));
        self::assertInstanceOf(\DateTimeImmutable::class, $listed[0]->revokedAt);
        self::assertNull($listed[1]->revokedAt);
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive($old->address));
        self::assertSame(ReceiveOutcome::Stored, $this->receive($new->address));
        self::assertSame($new->view->id, RecordingPurpose::accepted()[0][1]);
    }

    public function testAFailedReplacementLeavesTheOldAddressActive(): void
    {
        $old = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Receipts'));
        $container = self::getContainer();
        $failing = new AddressWrites(
            $container->get(InboundAddressStore::class),
            $container->get(InboundPurposes::class),
            $container->get(InboundAddresses::class),
            new class implements AddressIssuer {
                public function issue(string $purpose, ?string $ownerUserId, string $label): IssuedAddress
                {
                    throw new AddressNotIssued(AddressUnavailable::NoKey);
                }

                public function rotate(string $addressId): IssuedAddress
                {
                    throw new \LogicException('Not used.');
                }

                public function reveal(string $addressId): ?string
                {
                    return null;
                }

                public function revoke(string $addressId): void
                {
                }

                public function unavailable(string $purpose): ?AddressUnavailable
                {
                    return null;
                }

                public function masked(string $text): string
                {
                    return $text;
                }
            },
            $container->get(MailTransaction::class),
            $container->get(StaffCheck::class),
            $container->get(AuditActor::class),
            $container->get(ClockInterface::class),
            $container->get(InstanceClock::class),
        );

        $this->assertRefused('inbound_mail_no_key', static fn (): \Foresi\Mail\Application\IssuedMailAddress => $failing->replace(new ReplaceAddressInput($old->view->id)));

        self::assertNull($this->connection()->fetchOne('SELECT revoked_at FROM mail_inbound_address WHERE id = ?', [$old->view->id]));
        self::assertSame(ReceiveOutcome::Stored, $this->receive($old->address));
    }

    public function testTwoCreationsAtOnceMakeOneAddress(): void
    {
        $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'First'));
        $container = self::getContainer();
        $inner = $container->get(InboundAddressStore::class);
        // A store that has not seen the first address yet, as a request at the same moment has not.
        $blind = new readonly class($inner) implements InboundAddressStore {
            public function __construct(private InboundAddressStore $inner)
            {
            }

            public function routable(string $tokenHash): ?InboundAddress
            {
                return $this->inner->routable($tokenHash);
            }

            public function get(string $id): ?InboundAddress
            {
                return $this->inner->get($id);
            }

            public function ownedBy(string $ownerUserId, ?string $purpose = null): array
            {
                return $this->inner->ownedBy($ownerUserId, $purpose);
            }

            public function activeOf(string $ownerUserId, string $purpose): ?InboundAddress
            {
                return null;
            }

            public function add(InboundAddress $address): void
            {
                $this->inner->add($address);
            }

            public function flush(): void
            {
                $this->inner->flush();
            }

            public function touch(string $id, \DateTimeImmutable $at): void
            {
                $this->inner->touch($id, $at);
            }

            public function recordDrop(string $id, \DateTimeImmutable $day): int
            {
                return $this->inner->recordDrop($id, $day);
            }
        };
        $racing = new AddressWrites($blind, $container->get(InboundPurposes::class), $container->get(InboundAddresses::class), $container->get(AddressIssuer::class), $container->get(MailTransaction::class), $container->get(StaffCheck::class), $container->get(AuditActor::class), $container->get(ClockInterface::class), $container->get(InstanceClock::class));

        $this->assertRefused('address_exists', static fn (): \Foresi\Mail\Application\IssuedMailAddress => $racing->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Second')));

        self::assertSame(['First'], $this->connection()->fetchFirstColumn('SELECT label FROM mail_inbound_address'));
    }

    public function testAnotherPersonsAddressAndAPurposeWithoutOwnersAreNotFound(): void
    {
        $someone = $this->fixtures->user('someone@example.com');
        $this->fixtures->join($someone, Role::Member);
        $issuer = self::getContainer()->get(AddressIssuer::class);
        $theirs = $issuer->issue(RecordingPurpose::OWNED_KEY, $someone->id(), 'Theirs');
        $feed = $issuer->issue('bank_feed', null, 'Bank feed');
        $unowned = $issuer->issue(RecordingPurpose::KEY, $this->me->id(), 'Mine, but no owners');

        foreach ([$theirs->id, $feed->id, $unowned->id, '01937f4e-0000-7000-8000-000000000001'] as $id) {
            $this->assertRefused('not_found', fn (): \Foresi\Mail\Application\AddressView => $this->writes()->rename(new RenameAddressInput($id, 'Mine now')));
            $this->assertRefused('not_found', fn (): \Foresi\Mail\Application\AddressView => $this->writes()->revoke(new RevokeAddressInput($id)));
            $this->assertRefused('not_found', fn (): \Foresi\Mail\Application\IssuedMailAddress => $this->writes()->replace(new ReplaceAddressInput($id)));
        }
        // Only the one row that is mine is listed; none of the three was changed.
        self::assertSame([[$unowned->id, 'Mine, but no owners', null]], array_map(static fn (\Foresi\Mail\Application\AddressView $view): array => [$view->id, $view->label, $view->revokedAt], $this->reads()->mine()));
        self::assertSame(['Theirs', 'Bank feed'], $this->connection()->fetchFirstColumn("SELECT label FROM mail_inbound_address WHERE id IN ('".$theirs->id."', '".$feed->id."') ORDER BY created_at, id"));
    }

    public function testTheListFiltersByPurposeAndNeverShowsAToken(): void
    {
        $issued = $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Receipts'));

        self::assertCount(1, $this->reads()->mine(RecordingPurpose::OWNED_KEY));
        self::assertSame([], $this->reads()->mine('other'));
        $token = (string) new InboundAddresses('bank+{token}@example.com')->tokenIn($issued->address);
        self::assertStringNotContainsString($token, (string) json_encode($this->reads()->mine()));
        self::assertStringNotContainsString($token, (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM audit_entry')));
    }

    public function testAGuestAndNoOneSignedInAreRefused(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM mail_inbound_address');
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['user' => $this->me->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $contact = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em->persist($contact);
        $membership->changeRole(Role::Guest, $contact);
        $this->em->flush();

        $this->assertForbidden();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $this->assertForbidden();
        self::assertSame([], $this->connection()->fetchAllAssociative('SELECT id FROM mail_inbound_address'));
    }

    private function assertForbidden(): void
    {
        $id = '01937f4e-0000-7000-8000-000000000001';
        foreach ([
            fn (): array => $this->reads()->mine(),
            fn (): \Foresi\Mail\Application\IssuedMailAddress => $this->writes()->create(new CreateAddressInput(RecordingPurpose::OWNED_KEY, 'Receipts')),
            fn (): \Foresi\Mail\Application\AddressView => $this->writes()->rename(new RenameAddressInput($id, 'Receipts')),
            fn (): \Foresi\Mail\Application\AddressView => $this->writes()->revoke(new RevokeAddressInput($id)),
            fn (): \Foresi\Mail\Application\IssuedMailAddress => $this->writes()->replace(new ReplaceAddressInput($id)),
        ] as $call) {
            $this->assertRefused('forbidden', $call);
        }
    }

    private function assertCreateRefused(string $reason, string $purpose, string $label): void
    {
        $this->assertRefused($reason, fn (): \Foresi\Mail\Application\IssuedMailAddress => $this->writes()->create(new CreateAddressInput($purpose, $label)));
    }

    private function assertRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail('Not refused: '.$reason);
        } catch (MailRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }

    private function receive(string $address): ReceiveOutcome
    {
        return self::getContainer()->get(ReceiveMail::class)->receive(MailKit::mail($address), InboundChannel::Webhook);
    }

    private function writes(): AddressWrites
    {
        return self::getContainer()->get(AddressWrites::class);
    }

    private function reads(): AddressReads
    {
        return self::getContainer()->get(AddressReads::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }
}
