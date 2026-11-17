<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Console\CreateUserCommand;
use App\Instance\Application\TenantContext;
use App\Instance\Domain\Tenant;
use App\Instance\Domain\TenantId;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private const string PASSWORD = 'throwaway-password-1';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class))->rebuildSchema();
        $this->tester = new CommandTester(new Application($kernel)->find('app:user:create'));
    }

    public function testCreatesTheUserTheInstanceAndTheMembership(): void
    {
        $status = $this->create(['email' => 'Testing@Example.COM', 'role' => 'owner'], self::PASSWORD);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->users()->findByEmail('testing@example.com');
        self::assertNotNull($user);
        self::assertNotSame(self::PASSWORD, $user->passwordHash());
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash()));
        self::assertSame(Role::Owner, $this->directory()->roleOf($user->id()));
        self::assertSame([CreateUserCommand::INSTANCE_NAME], $this->em()->getConnection()->fetchFirstColumn('SELECT name FROM tenant'));
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());
    }

    public function testNewUsersGetTheProductDefaultLocale(): void
    {
        $this->create(['email' => 'testing@example.com', 'role' => 'owner'], self::PASSWORD);

        self::assertSame('cs', $this->users()->findByEmail('testing@example.com')?->locale());
    }

    public function testDisplayNameDefaultsToTheLocalPartOfTheEmail(): void
    {
        $this->create(['email' => 'Jana.Novak@example.com', 'role' => 'owner'], self::PASSWORD);

        self::assertSame('jana.novak', $this->users()->findByEmail('jana.novak@example.com')?->displayName());
    }

    public function testDisplayNameCanBeEnteredAndIsTrimmed(): void
    {
        $this->create(['email' => 'testing@example.com', 'role' => 'owner'], self::PASSWORD, name: '  Jana Nováková ');

        self::assertSame('Jana Nováková', $this->users()->findByEmail('testing@example.com')?->displayName());
    }

    public function testTheSecondUserJoinsTheSameInstance(): void
    {
        $existing = $this->tenant('Example Company');
        $this->create(['email' => 'one@example.com', 'role' => 'owner'], self::PASSWORD);
        $this->create(['email' => 'two@example.com', 'role' => 'member'], self::PASSWORD);

        self::assertSame([$existing->toString()], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM tenant'));
        self::assertSame(Role::Member, $this->directory()->roleOf($this->users()->findByEmail('two@example.com')?->id() ?? ''));
        self::assertSame(2, $this->rows('membership'));
    }

    public function testRejectsAnUnknownRole(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'role' => 'admin'], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
    }

    public function testCreatesAGuestUserForAContact(): void
    {
        $contact = $this->contact();

        $status = $this->create(['email' => 'guest@example.com', 'role' => 'guest', '--contact' => $contact], self::PASSWORD);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('as guest', $this->tester->getDisplay());
        self::assertSame([['guest', $contact]], $this->em()->getConnection()->fetchAllNumeric('SELECT role, party_id FROM membership'));
    }

    public function testAMemberMayRepresentTheirOwnContact(): void
    {
        $contact = $this->contact();

        $this->create(['email' => 'member@example.com', 'role' => 'member', '--contact' => $contact], self::PASSWORD);

        self::assertSame([['member', $contact]], $this->em()->getConnection()->fetchAllNumeric('SELECT role, party_id FROM membership'));
    }

    public function testAGuestUserNeedsAContact(): void
    {
        $status = $this->create(['email' => 'guest@example.com', 'role' => 'guest'], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--contact', $this->tester->getDisplay());
        self::assertNull($this->users()->findByEmail('guest@example.com'));
        self::assertSame(0, $this->rows('tenant'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownContacts(): iterable
    {
        yield 'nobody\'s id' => ['019a0000-0000-7000-8000-0000000000ff'];
        yield 'not an id' => ['12'];
    }

    #[DataProvider('unknownContacts')]
    public function testRefusesAnUnknownContact(string $contact): void
    {
        $status = $this->create(['email' => 'guest@example.com', 'role' => 'guest', '--contact' => $contact], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString(sprintf('There is no contact "%s".', $contact), (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay()));
        self::assertNull($this->users()->findByEmail('guest@example.com'));
        self::assertSame(0, $this->rows('membership'));
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $status = $this->create(['email' => 'not-an-email', 'role' => Role::Owner->value], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
    }

    public function testRejectsAShortPassword(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'role' => 'owner'], 'short');

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
    }

    public function testRefusesAnExistingUser(): void
    {
        $this->create(['email' => 'testing@example.com', 'role' => 'owner'], self::PASSWORD);

        $status = $this->create(['email' => 'TESTING@example.com', 'role' => 'member'], self::PASSWORD);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testRejectsAPasswordConfirmationMismatch(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'role' => 'owner'], self::PASSWORD, 'another-password-1');

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
        self::assertSame(0, $this->rows('tenant'));
    }

    public function testAFailureLeavesNoTenantWithoutItsFirstUser(): void
    {
        $this->em()->getEventManager()->addEventListener(Events::prePersist, new class {
            public function prePersist(PrePersistEventArgs $args): void
            {
                if ($args->getObject() instanceof Membership) {
                    throw new \RuntimeException('Simulated failure.');
                }
            }
        });

        try {
            $this->create(['email' => 'testing@example.com', 'role' => 'owner'], self::PASSWORD);
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->rows('tenant'));
            self::assertSame(0, $this->rows('app_user'));
        }
    }

    public function testPasswordIsNotAnArgumentOrOption(): void
    {
        $definition = new Application(self::bootKernel())->find('app:user:create')->getDefinition();

        self::assertSame(['email', 'role'], array_keys($definition->getArguments()));
    }

    /**
     * @param array<string, string> $arguments
     */
    private function create(array $arguments, string $password, ?string $confirmation = null, string $name = ''): int
    {
        $this->tester->setInputs([$name, $password, $confirmation ?? $password]);

        return $this->tester->execute($arguments, ['interactive' => true]);
    }

    private function tenant(string $name): TenantId
    {
        $tenant = new Tenant(TenantId::generate(), $name, new \DateTimeImmutable());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant->id();
    }

    private function contact(): string
    {
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em()->persist($party);
        $this->em()->flush();

        return $party->id();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function rows(string $table): int
    {
        return \count($this->em()->getConnection()->fetchAllAssociative('SELECT 1 FROM '.$table));
    }

    private function users(): UserRepository
    {
        return self::getContainer()->get(UserRepository::class);
    }

    private function directory(): MembershipDirectory
    {
        return self::getContainer()->get(MembershipDirectory::class);
    }
}
