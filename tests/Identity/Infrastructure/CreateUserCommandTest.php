<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
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

    public function testCreatesUserTenantAndMembership(): void
    {
        $status = $this->create(['email' => 'Testing@Example.COM', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->users()->findByEmail('testing@example.com');
        self::assertNotNull($user);
        self::assertNotSame(self::PASSWORD, $user->passwordHash());
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash()));
        self::assertCount(1, $this->directory()->tenantIdsOf($user));
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());
    }

    public function testNewUsersGetTheProductDefaultLocale(): void
    {
        $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);

        self::assertSame('cs', $this->users()->findByEmail('testing@example.com')?->locale());
    }

    public function testDisplayNameDefaultsToTheLocalPartOfTheEmail(): void
    {
        $this->create(['email' => 'Jana.Novak@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);

        self::assertSame('jana.novak', $this->users()->findByEmail('jana.novak@example.com')?->displayName());
    }

    public function testDisplayNameCanBeEnteredAndIsTrimmed(): void
    {
        $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD, name: '  Jana Nováková ');

        self::assertSame('Jana Nováková', $this->users()->findByEmail('testing@example.com')?->displayName());
    }

    public function testReusesAnExistingTenant(): void
    {
        $this->create(['email' => 'one@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);
        $this->create(['email' => 'two@example.com', 'tenant-name' => 'Example Company', 'role' => 'member'], self::PASSWORD);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertCount(1, $em->getRepository(Tenant::class)->findAll());
        $one = $this->users()->findByEmail('one@example.com');
        $two = $this->users()->findByEmail('two@example.com');
        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertEquals($this->directory()->tenantIdsOf($one), $this->directory()->tenantIdsOf($two));
    }

    public function testRejectsAnUnknownRole(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'admin'], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $status = $this->create(['email' => 'not-an-email', 'tenant-name' => 'Example Company', 'role' => Role::Owner->value], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
    }

    public function testRejectsAShortPassword(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], 'short');

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
    }

    public function testRefusesAnExistingUser(): void
    {
        $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);

        $status = $this->create(['email' => 'TESTING@example.com', 'tenant-name' => 'Other', 'role' => 'member'], self::PASSWORD);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testRejectsAPasswordConfirmationMismatch(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD, 'another-password-1');

        self::assertSame(Command::INVALID, $status);
        self::assertNull($this->users()->findByEmail('testing@example.com'));
        self::assertSame(0, $this->rows('tenant'));
    }

    public function testTenantNameIsTrimmedForLookupAndCreation(): void
    {
        $this->create(['email' => 'one@example.com', 'tenant-name' => '  Example Company ', 'role' => 'owner'], self::PASSWORD);
        $this->create(['email' => 'two@example.com', 'tenant-name' => 'Example Company', 'role' => 'member'], self::PASSWORD);

        self::assertSame(1, $this->rows('tenant'));
        self::assertSame('Example Company', $this->em()->getConnection()->fetchOne('SELECT name FROM tenant'));
    }

    public function testRejectsABlankTenantName(): void
    {
        $status = $this->create(['email' => 'testing@example.com', 'tenant-name' => '   ', 'role' => 'owner'], self::PASSWORD);

        self::assertSame(Command::INVALID, $status);
    }

    public function testFailureLeavesNoOrphanTenant(): void
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
            $this->create(['email' => 'testing@example.com', 'tenant-name' => 'Example Company', 'role' => 'owner'], self::PASSWORD);
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->rows('tenant'));
            self::assertSame(0, $this->rows('app_user'));
        }
    }

    public function testPasswordIsNotAnArgumentOrOption(): void
    {
        $definition = new Application(self::bootKernel())->find('app:user:create')->getDefinition();

        self::assertSame(['email', 'tenant-name', 'role'], array_keys($definition->getArguments()));
    }

    /**
     * @param array<string, string> $arguments
     */
    private function create(array $arguments, string $password, ?string $confirmation = null, string $name = ''): int
    {
        $this->tester->setInputs([$name, $password, $confirmation ?? $password]);

        return $this->tester->execute($arguments, ['interactive' => true]);
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

    private function directory(): DoctrineMembershipDirectory
    {
        return self::getContainer()->get(DoctrineMembershipDirectory::class);
    }
}
