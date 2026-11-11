<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\ApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator's token commands: issuing prints the secret once, listing never
 * prints one, and revoking finds the token in whatever organisation it is.
 */
final class ApiTokenCommandsTest extends KernelTestCase
{
    private Application $application;
    private IdentityFixtures $fixtures;
    private User $user;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->application = new Application($kernel);
        $this->fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->tenant = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->user, $this->tenant, Role::Member);
    }

    public function testIssuingPrintsTheSecretOnceWithoutTwoFactorSignIn(): void
    {
        $tester = $this->tester('app:api-token:issue');

        $status = $tester->execute(['email' => ' Testing@Example.com ', '--tenant' => $this->tenant->toString(), '--name' => 'Reporting', '--days' => '30', '--scope' => ['read', 'write']]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $records = $this->tokens()->records(null);
        self::assertCount(1, $records);
        self::assertSame('Reporting', $records[0]->name);
        self::assertSame([ApiScope::Read, ApiScope::Write], $records[0]->scopes);
        self::assertSame($this->user->id(), $records[0]->userId);
        self::assertTrue($records[0]->tenantId->equals($this->tenant));
        self::assertSame(30, $records[0]->createdAt->diff($records[0]->expiresAt)->days);
        self::assertSame(1, preg_match('/'.ApiTokenSecret::PATTERN.'/', $tester->getDisplay(), $match));
        self::assertNotNull($this->tokens()->byHash(ApiTokenSecret::hash($match[0])));
        self::assertSame(substr($match[0], -4), $records[0]->lastFour);
        self::assertStringContainsString('it will not be shown again', $tester->getDisplay());
        self::assertStringContainsString($records[0]->id, $tester->getDisplay());
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    public function testIssuingDefaultsToReadForNinetyDays(): void
    {
        $tester = $this->tester('app:api-token:issue');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => IdentityFixtures::EMAIL, '--tenant' => $this->tenant->toString(), '--name' => 'Reporting']));

        $record = $this->tokens()->records(null)[0];
        self::assertSame([ApiScope::Read], $record->scopes);
        self::assertSame(90, $record->createdAt->diff($record->expiresAt)->days);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedIssues')]
    public function testARefusedIssueSaysWhyAndIssuesNothing(array $input, int $status, string $message): void
    {
        $tester = $this->tester('app:api-token:issue');
        $input += ['email' => IdentityFixtures::EMAIL, '--tenant' => $this->tenant->toString(), '--name' => 'Reporting'];

        self::assertSame($status, $tester->execute($input));

        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame([], $this->tokens()->records(null));
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function refusedIssues(): iterable
    {
        yield 'no tenant' => [['--tenant' => ''], Command::INVALID, 'Pass the id of the organisation with --tenant.'];
        yield 'bad scope' => [['--scope' => ['read', 'admin']], Command::INVALID, 'Unknown scope "admin", expected read or write.'];
        yield 'unknown user' => [['email' => 'nobody@example.com'], Command::FAILURE, 'No user has this e-mail.'];
        yield 'no name' => [['--name' => ''], Command::FAILURE, 'Pass a name of 1 to 80 characters with --name.'];
        yield 'odd lifetime' => [['--days' => '7'], Command::FAILURE, 'A token is valid for 30, 90 or 365 days.'];
        yield 'no scope' => [['--scope' => []], Command::FAILURE, 'Pass at least one scope.'];
        yield 'another organisation' => [['--tenant' => '019a0000-0000-7000-8000-0000000000ff'], Command::FAILURE, 'The user is not the owner or a member of this organisation.'];
    }

    public function testAGuestGetsNoToken(): void
    {
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->user, $other, Role::Guest);

        $tester = $this->tester('app:api-token:issue');

        self::assertSame(Command::FAILURE, $tester->execute(['email' => IdentityFixtures::EMAIL, '--tenant' => $other->toString(), '--name' => 'Reporting']));
        self::assertSame([], $this->tokens()->records(null));
    }

    public function testListingShowsTokensButNeverASecret(): void
    {
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->user, $other, Role::Owner);
        $secret = $this->issue($this->tenant, 'First');
        $this->issue($other, 'Second');

        $all = $this->tester('app:api-token:list');
        self::assertSame(Command::SUCCESS, $all->execute([]));
        self::assertStringContainsString('First', $all->getDisplay());
        self::assertStringContainsString('Second', $all->getDisplay());
        self::assertStringContainsString(IdentityFixtures::EMAIL, $all->getDisplay());
        self::assertStringContainsString('…'.substr($secret, -4), $all->getDisplay());
        self::assertStringContainsString('active', $all->getDisplay());
        self::assertStringNotContainsString($secret, $all->getDisplay());
        self::assertStringNotContainsString(ApiTokenSecret::hash($secret), $all->getDisplay());
        self::assertStringNotContainsString(ApiTokenSecret::PREFIX, $all->getDisplay());

        $one = $this->tester('app:api-token:list');
        self::assertSame(Command::SUCCESS, $one->execute(['--tenant' => $other->toString()]));
        self::assertStringContainsString('Second', $one->getDisplay());
        self::assertStringNotContainsString('First', $one->getDisplay());
    }

    public function testListingNothingSaysSo(): void
    {
        $tester = $this->tester('app:api-token:list');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No API tokens.', $tester->getDisplay());
    }

    public function testListingAnInvalidTenantIsRefused(): void
    {
        $tester = $this->tester('app:api-token:list');

        self::assertSame(Command::INVALID, $tester->execute(['--tenant' => 'nope']));
        self::assertStringContainsString('The organisation id is not valid.', $tester->getDisplay());
    }

    public function testRevokingStopsTheToken(): void
    {
        $this->issue($this->tenant, 'Reporting');
        $id = $this->tokens()->records(null)[0]->id;

        $tester = $this->tester('app:api-token:revoke');

        self::assertSame(Command::SUCCESS, $tester->execute(['token-id' => $id]));
        self::assertStringContainsString('Revoked "Reporting"', $tester->getDisplay());
        self::assertNotNull($this->tokens()->records(null)[0]->revokedAt);
        self::assertFalse(self::getContainer()->get(TenantContext::class)->has());
    }

    #[DataProvider('unknownIds')]
    public function testRevokingAnUnknownTokenFails(string $id): void
    {
        $tester = $this->tester('app:api-token:revoke');

        self::assertSame(Command::FAILURE, $tester->execute(['token-id' => $id]));
        self::assertStringContainsString('No API token has this id.', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'not an id' => ['nope'];
        yield 'nobody\'s' => ['019a0000-0000-7000-8000-0000000000ff'];
    }

    private function issue(TenantId $tenant, string $name): string
    {
        $tester = $this->tester('app:api-token:issue');
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => IdentityFixtures::EMAIL, '--tenant' => $tenant->toString(), '--name' => $name]));
        self::assertSame(1, preg_match('/'.ApiTokenSecret::PATTERN.'/', $tester->getDisplay(), $match));

        return $match[0];
    }

    private function tester(string $command): CommandTester
    {
        return new CommandTester($this->application->find($command));
    }

    private function tokens(): ApiTokens
    {
        return self::getContainer()->get(ApiTokens::class);
    }
}
