<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressOwners;
use Foresi\Mail\Contract\OwnAddress;
use Foresi\Mail\Contract\OwnAddresses;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Contract\Refused;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The contract other modules show a person's own address through: the same
 * rules as the REST and MCP calls, the address shown again only to its owner,
 * and the owner of an address for a purpose's accept().
 */
final class OwnAddressesTest extends KernelTestCase
{
    private IdentityFixtures $fixtures;
    private User $me;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixtures = new IdentityFixtures(self::getContainer()->get(EntityManagerInterface::class));
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

    public function testCreateRenameAndMine(): void
    {
        self::assertNull($this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY));

        $issued = $this->own()->create(RecordingPurpose::OWNED_RESHOWABLE_KEY, 'Receipts');
        $renamed = $this->own()->rename($issued->id, 'Invoices');
        $mine = $this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY);

        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $issued->address);
        self::assertInstanceOf(OwnAddress::class, $mine);
        self::assertSame([$issued->id, 'Invoices', "bank+\u{2026}@example.com", null, false], [$mine->id, $mine->label, $mine->maskedAddress, $mine->lastReceivedAt, $mine->limitReachedToday()]);
        self::assertSame('Invoices', $renamed->label);
        self::assertSame($issued->address, $this->own()->reveal($issued->id));
        self::assertNull($this->own()->mine(RecordingPurpose::OWNED_KEY), 'Of the purpose asked for only.');
    }

    public function testReplaceStopsTheOldAddressAndRevokeStopsTheNew(): void
    {
        $old = $this->own()->create(RecordingPurpose::OWNED_RESHOWABLE_KEY, 'Receipts');
        $new = $this->own()->replace($old->id);

        self::assertNotSame($old->id, $new->id);
        self::assertSame($new->id, $this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY)?->id);
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive($old->address));
        self::assertNull($this->own()->reveal($old->id), 'A revoked address is not shown again.');

        $this->own()->revoke($new->id);

        self::assertNull($this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY));
        self::assertSame(ReceiveOutcome::UnknownRecipient, $this->receive($new->address));
    }

    public function testRefusalsCarryTheirReason(): void
    {
        $this->own()->create(RecordingPurpose::OWNED_RESHOWABLE_KEY, 'Receipts');
        $someone = $this->fixtures->user('someone@example.com');
        $this->fixtures->join($someone, Role::Member);
        $theirs = self::getContainer()->get(AddressIssuer::class)->issue(RecordingPurpose::OWNED_RESHOWABLE_KEY, $someone->id(), 'Theirs');

        $this->assertRefused('address_exists', fn (): mixed => $this->own()->create(RecordingPurpose::OWNED_RESHOWABLE_KEY, 'Second'));
        $this->assertRefused('label_required', fn (): mixed => $this->own()->rename((string) $this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY)?->id, ' '));
        $this->assertRefused('not_found', fn (): mixed => $this->own()->reveal($theirs->id));
        $this->assertRefused('not_found', fn (): mixed => $this->own()->replace($theirs->id));
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $this->assertRefused('forbidden', fn (): mixed => $this->own()->mine(RecordingPurpose::OWNED_RESHOWABLE_KEY));
    }

    public function testTheOwnerOfAnAddress(): void
    {
        $issuer = self::getContainer()->get(AddressIssuer::class);
        $mine = $this->own()->create(RecordingPurpose::OWNED_RESHOWABLE_KEY, 'Receipts');
        $feed = $issuer->issue(RecordingPurpose::RESHOWABLE_KEY, null, 'Bank feed');
        $owners = self::getContainer()->get(AddressOwners::class);

        self::assertSame($this->me->id(), $owners->ownerOf($mine->id));
        self::assertNull($owners->ownerOf($feed->id));
        self::assertNull($owners->ownerOf('01937f4e-0000-7000-8000-000000000001'));
        $this->own()->revoke($mine->id);
        self::assertNull($owners->ownerOf($mine->id), 'A revoked address belongs to nobody any more.');
    }

    private function assertRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail('Not refused: '.$reason);
        } catch (Refused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }

    private function receive(string $address): ReceiveOutcome
    {
        return self::getContainer()->get(ReceiveMail::class)->receive(MailKit::mail($address), InboundChannel::Webhook);
    }

    private function own(): OwnAddresses
    {
        return self::getContainer()->get(OwnAddresses::class);
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }
}
