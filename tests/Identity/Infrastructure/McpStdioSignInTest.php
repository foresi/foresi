<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\ManageApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Console\McpStdioSignIn;
use App\Identity\Infrastructure\Security\ApiTokenLogin;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The stdio MCP server signs in from FORESI_API_TOKEN before it starts, and
 * checks the token again before every tool call. A refusal is one line on
 * stderr, never the token, and the server does not start.
 */
final class McpStdioSignInTest extends KernelTestCase
{
    private User $user;
    private TenantId $tenant;
    private SplitOutput $output;

    protected function setUp(): void
    {
        self::bootKernel();
        $fixtures = new IdentityFixtures($this->em(), $this->context());
        $fixtures->rebuildSchema();
        $this->user = $fixtures->user();
        $this->tenant = $fixtures->tenant('Tenant A');
        $fixtures->join($this->user, $this->tenant, Role::Member);
        $this->output = new SplitOutput();
    }

    public function testAValidTokenSignsTheServerInAsItsPersonAndOrganisation(): void
    {
        $signIn = $this->signIn($this->issued());

        $event = $this->start($signIn);

        self::assertTrue($event->commandShouldRun());
        $user = $this->tokens()->getToken()?->getUser();
        self::assertInstanceOf(SecurityUser::class, $user);
        self::assertSame($this->user->id(), $user->id());
        self::assertTrue($this->context()->current()->equals($this->tenant));
        self::assertSame('', $this->output->errors());
        self::assertSame('', $this->output->written());
    }

    public function testATokenWithSpacesAroundItIsAccepted(): void
    {
        $event = $this->start($this->signIn(' '.$this->issued()."\n"));

        self::assertTrue($event->commandShouldRun());
    }

    public function testOtherCommandsAreLeftAlone(): void
    {
        $event = $this->start($this->signIn(null), 'app:api-token:list');

        self::assertTrue($event->commandShouldRun());
        self::assertNull($this->tokens()->getToken());
        self::assertSame('', $this->output->errors());
    }

    #[DataProvider('refusedSecrets')]
    public function testWithoutAValidTokenTheServerDoesNotStart(?string $secret, string $message): void
    {
        $event = $this->start($this->signIn($secret));

        self::assertFalse($event->commandShouldRun());
        self::assertSame($message."\n", $this->output->errors());
        self::assertSame('', $this->output->written());
        self::assertNull($this->tokens()->getToken());
        self::assertFalse($this->context()->has());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function refusedSecrets(): iterable
    {
        yield 'unset' => [null, 'Set FORESI_API_TOKEN to a personal API token (Settings, API) to start the MCP server.'];
        yield 'blank' => ['  ', 'Set FORESI_API_TOKEN to a personal API token (Settings, API) to start the MCP server.'];
        yield 'unknown' => ['not-a-token', 'The token in FORESI_API_TOKEN was refused: it is not a valid Foresi API token.'];
    }

    #[DataProvider('refusedTokens')]
    public function testARefusedTokenSaysWhyWithoutShowingIt(string $change, string $reason): void
    {
        $secret = $this->issued();
        $this->change($change);

        $this->start($this->signIn($secret));

        self::assertSame("The token in FORESI_API_TOKEN was refused: {$reason}\n", $this->output->errors());
        self::assertStringNotContainsString($secret, $this->output->errors());
        self::assertNull($this->tokens()->getToken());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'revoked' => ['revoke', 'it has been revoked.'];
        yield 'expired' => ['expire', 'it has expired.'];
        yield 'left the organisation' => ['leave', 'its person no longer works for its organisation.'];
        yield 'now a guest' => ['guest', 'guests do not sign in with a token.'];
    }

    public function testEveryToolCallChecksTheTokenAgainWithAFreshEntityManager(): void
    {
        $signIn = $this->signIn($this->issued());
        $this->start($signIn);
        $loaded = $this->em()->find(User::class, $this->user->id());
        self::assertInstanceOf(User::class, $loaded);

        $signIn->confirm();

        self::assertFalse($this->em()->contains($loaded));
        self::assertTrue($this->context()->current()->equals($this->tenant));
        self::assertNotNull($this->tokens()->getToken());
    }

    public function testAToolCallAfterTheTokenWasRevokedIsRefusedAndSignsOut(): void
    {
        $signIn = $this->signIn($this->issued());
        $this->start($signIn);
        $this->change('revoke');

        try {
            $signIn->confirm();
            self::fail('A revoked token was still accepted.');
        } catch (ToolRefusal $refused) {
            self::assertSame('token_revoked', $refused->errorCode);
            self::assertSame('The API token of this connection was refused: it has been revoked. Restart the MCP server with a valid token.', $refused->getMessage());
        }
        self::assertNull($this->tokens()->getToken());
        self::assertFalse($this->context()->has());

        $this->expectException(ToolRefusal::class);
        $signIn->confirm();
    }

    public function testWithoutAStdioSignInAToolCallIsLeftToTheFirewall(): void
    {
        $signIn = $this->signIn(null);

        $signIn->confirm();

        self::assertNull($this->tokens()->getToken());
    }

    public function testTheCommandStopsBeforeTheServerWithoutTheVariable(): void
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $status = $tester->run(['command' => McpStdioSignIn::COMMAND], ['capture_stderr_separately' => true]);

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $status);
        self::assertSame('', $tester->getDisplay());
        self::assertSame("Set FORESI_API_TOKEN to a personal API token (Settings, API) to start the MCP server.\n", $tester->getErrorOutput());
    }

    private function signIn(?string $secret): McpStdioSignIn
    {
        $container = self::getContainer();

        return new McpStdioSignIn($container->get(ApiTokenLogin::class), $this->tokens(), $this->context(), $this->em(), $secret);
    }

    private function start(McpStdioSignIn $signIn, string $command = McpStdioSignIn::COMMAND): ConsoleCommandEvent
    {
        $event = new ConsoleCommandEvent(new Command($command), new ArrayInput([]), $this->output);
        $signIn->onCommand($event);

        return $event;
    }

    private function issued(): string
    {
        $this->context()->switchTo($this->tenant);
        try {
            return self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Claude', [ApiScope::Read])->secret;
        } finally {
            $this->context()->reset();
        }
    }

    private function change(string $change): void
    {
        $this->context()->switchTo($this->tenant);
        $token = $this->em()->getRepository(ApiToken::class)->findOneBy([]);
        self::assertInstanceOf(ApiToken::class, $token);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        match ($change) {
            'revoke' => $token->revoke(null, new \DateTimeImmutable('-1 second')),
            'expire' => $this->em()->getConnection()->executeStatement('UPDATE api_token SET expires_at = ?', [new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:s')]),
            'leave' => $membership->deactivate(new \DateTimeImmutable()),
            'guest' => $membership->changeRole(Role::Guest, $this->party()),
            default => throw new \LogicException($change),
        };
        $this->em()->flush();
        $this->em()->clear();
        $this->context()->reset();
    }

    private function party(): Party
    {
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em()->persist($party);

        return $party;
    }

    private function tokens(): TokenStorageInterface
    {
        return self::getContainer()->get('security.token_storage');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function context(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
