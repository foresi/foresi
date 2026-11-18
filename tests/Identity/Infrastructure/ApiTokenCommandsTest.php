<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator's token commands: issuing prints the secret once, listing never
 * prints one, and revoking finds the token by its id.
 */
final class ApiTokenCommandsTest extends KernelTestCase
{
    private Application $application;
    private IdentityFixtures $fixtures;
    private User $user;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->application = new Application($kernel);
        $this->fixtures = new IdentityFixtures(self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->fixtures->join($this->user, Role::Member);
    }

    public function testIssuingPrintsTheSecretOnceWithoutTwoFactorSignIn(): void
    {
        $tester = $this->tester('app:api-token:issue');

        $status = $tester->execute(['email' => ' Testing@Example.com ', '--name' => 'Reporting', '--days' => '30', '--scope' => ['read', 'write']]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $records = $this->tokens()->records();
        self::assertCount(1, $records);
        self::assertSame('Reporting', $records[0]->name);
        self::assertEquals([ApiScope::read(), ApiScope::write()], $records[0]->scopes);
        self::assertSame($this->user->id(), $records[0]->userId);
        self::assertSame(30, $records[0]->createdAt->diff($records[0]->expiresAt)->days);
        self::assertSame(1, preg_match('/'.ApiTokenSecret::PATTERN.'/', $tester->getDisplay(), $match));
        self::assertNotNull($this->tokens()->byHash(ApiTokenSecret::hash($match[0])));
        self::assertSame(substr($match[0], -4), $records[0]->lastFour);
        self::assertStringContainsString('it will not be shown again', $tester->getDisplay());
        self::assertStringContainsString($records[0]->id, $tester->getDisplay());
    }

    public function testIssuingLastsNinetyDaysByDefaultAndTakesAResourceScope(): void
    {
        $tester = $this->tester('app:api-token:issue');

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => IdentityFixtures::EMAIL, '--name' => 'Outreach', '--scope' => ['contacts:write']]));

        $record = $this->tokens()->records()[0];
        self::assertEquals([ApiScope::from('contacts:write')], $record->scopes);
        self::assertSame(90, $record->createdAt->diff($record->expiresAt)->days);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedIssues')]
    public function testARefusedIssueSaysWhyAndIssuesNothing(array $input, int $status, string $message): void
    {
        $tester = $this->tester('app:api-token:issue');
        $input += ['email' => IdentityFixtures::EMAIL, '--name' => 'Reporting', '--scope' => ['read']];

        self::assertSame($status, $tester->execute($input));

        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame([], $this->tokens()->records());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function refusedIssues(): iterable
    {
        yield 'bad scope' => [['--scope' => ['read', 'admin']], Command::INVALID, 'Unknown scope "admin", expected read, write, or a resource such as contacts:write.'];
        yield 'undeclared resource' => [['--scope' => ['ledger:read']], Command::FAILURE, 'A scope names a resource no installed module declares'];
        yield 'unknown user' => [['email' => 'nobody@example.com'], Command::FAILURE, 'No user has this e-mail.'];
        yield 'no name' => [['--name' => ''], Command::FAILURE, 'Pass a name of 1 to 80 characters with --name.'];
        yield 'odd lifetime' => [['--days' => '7'], Command::FAILURE, 'A token is valid for 30, 90 or 365 days.'];
        yield 'no scope' => [['--scope' => []], Command::INVALID, 'Pass what the token may do with --scope'];
    }

    public function testAGuestGetsNoToken(): void
    {
        $this->fixtures->join($this->fixtures->user('guest@example.com'), Role::Guest);

        $tester = $this->tester('app:api-token:issue');

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'guest@example.com', '--name' => 'Reporting', '--scope' => ['read']]));
        self::assertStringContainsString('The user is not the owner or a team member.', $tester->getDisplay());
        self::assertSame([], $this->tokens()->records());
    }

    public function testSomeoneWithoutAMembershipGetsNoToken(): void
    {
        $this->fixtures->user('stranger@example.com');

        $tester = $this->tester('app:api-token:issue');

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'stranger@example.com', '--name' => 'Reporting', '--scope' => ['read']]));
        self::assertSame([], $this->tokens()->records());
    }

    public function testListingShowsTokensButNeverASecret(): void
    {
        $secret = $this->issue('First');
        $this->issue('Second');

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
    }

    public function testListingNothingSaysSo(): void
    {
        $tester = $this->tester('app:api-token:list');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No API tokens.', $tester->getDisplay());
    }

    public function testRevokingStopsTheToken(): void
    {
        $this->issue('Reporting');
        $id = $this->tokens()->records()[0]->id;

        $tester = $this->tester('app:api-token:revoke');

        self::assertSame(Command::SUCCESS, $tester->execute(['token-id' => $id]));
        self::assertStringContainsString('Revoked "Reporting"', $tester->getDisplay());
        self::assertNotNull($this->tokens()->records()[0]->revokedAt);
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

    private function issue(string $name): string
    {
        $tester = $this->tester('app:api-token:issue');
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => IdentityFixtures::EMAIL, '--name' => $name, '--scope' => ['read']]));
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
