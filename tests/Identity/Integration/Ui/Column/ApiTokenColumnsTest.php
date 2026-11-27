<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\View\ApiTokenLists;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Integration\Ui\Column\ApiTokenColumns;
use Foresi\Identity\Integration\Ui\Column\ApiTokenRow;
use Foresi\Identity\Integration\Ui\Controller\ApiScopeOptions;
use Foresi\Shared\Application\Api\ApiResources;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * The columns of the tables of Settings → API: which each table has, in which order, and what
 * each shows of a token or a connected app.
 */
final class ApiTokenColumnsTest extends TestCase
{
    public function testAnotherListHasNoColumns(): void
    {
        self::assertSame([], $this->columns()->attributes('identity.people'));
    }

    public function testTheTokensTableShowsTheNameAccessTokenCreatedExpiresAndLastUsed(): void
    {
        self::assertSame([
            ['name', 'api_tokens.column.name', 'text', true, 10, true, true, true, []],
            ['access', 'api_tokens.column.access', 'text', true, 40, false, false, false, []],
            ['token', 'api_tokens.column.token', 'text', true, 50, false, false, false, []],
            ['created', 'api_tokens.column.created', 'text', true, 60, false, false, false, []],
            ['expires', 'api_tokens.column.expires', 'text', true, 70, false, true, false, []],
            ['last_used', 'api_tokens.column.last_used', 'text', true, 80, false, false, false, ['lastUsed']],
        ], ColumnTestSupport::describe($this->columns()->attributes(ApiTokenLists::TOKENS)));
    }

    public function testTheOtherPeoplesTokensAddThePersonAndTheEmail(): void
    {
        self::assertSame([
            ['name', 'api_tokens.column.name', 'text', true, 10, true, true, true, []],
            ['person', 'api_tokens.column.person', 'text', true, 20, false, false, true, []],
            ['email', 'api_tokens.column.email', 'text', true, 30, false, false, true, []],
            ['access', 'api_tokens.column.access', 'text', true, 40, false, false, false, []],
            ['token', 'api_tokens.column.token', 'text', true, 50, false, false, false, []],
            ['created', 'api_tokens.column.created', 'text', true, 60, false, false, false, []],
            ['expires', 'api_tokens.column.expires', 'text', true, 70, false, true, false, []],
            ['last_used', 'api_tokens.column.last_used', 'text', true, 80, false, false, false, ['lastUsed']],
        ], ColumnTestSupport::describe($this->columns()->attributes(ApiTokenLists::OTHERS_TOKENS)));
    }

    public function testTheConnectionsTableShowsTheAppWhoVouchesForItAccessConnectedAndLastUsed(): void
    {
        self::assertSame([
            ['name', 'api_tokens.column.app', 'text', true, 10, true, true, true, []],
            ['verified', 'api_tokens.column.verified_by', 'text', true, 40, false, false, false, []],
            ['access', 'api_tokens.column.access', 'text', true, 50, false, false, false, []],
            ['created', 'api_tokens.column.connected', 'text', true, 60, false, false, false, []],
            ['last_used', 'api_tokens.column.last_used', 'text', true, 70, false, true, false, ['lastUsed']],
        ], ColumnTestSupport::describe($this->columns()->attributes(ApiTokenLists::CONNECTIONS)));
    }

    public function testTheOtherPeoplesConnectionsAddThePersonAndTheEmail(): void
    {
        self::assertSame(
            ['name', 'person', 'email', 'verified', 'access', 'created', 'last_used'],
            array_column(ColumnTestSupport::describe($this->columns()->attributes(ApiTokenLists::OTHERS_CONNECTIONS)), 0),
        );
        $described = ColumnTestSupport::describe($this->columns()->attributes(ApiTokenLists::OTHERS_CONNECTIONS));
        self::assertSame(['person', 'api_tokens.column.person', 'text', true, 20, false, false, true, []], $described[1]);
        self::assertSame(['email', 'api_tokens.column.email', 'text', true, 30, false, false, true, []], $described[2]);
        self::assertSame(['name', 'api_tokens.column.app', 'text', true, 10, true, true, true, []], $described[0]);
    }

    public function testATokenRowShowsItsNameAccessLastFourDatesAndState(): void
    {
        $attributes = [...$this->columns()->attributes(ApiTokenLists::OTHERS_TOKENS)];
        $person = new Person('m1', 'u1', 'Alex Example', 'alex@example.com', Role::Member, null, null, false, true);
        $row = new ApiTokenRow($this->token(), $person);

        $name = ColumnTestSupport::find($attributes, 'name');
        self::assertSame('CI', $name->value($row));
        self::assertSame('CI', $name->cell($row));
        $who = ColumnTestSupport::find($attributes, 'person');
        self::assertSame('Alex Example', $who->value($row));
        self::assertSame('Alex Example', $who->cell($row));
        self::assertSame('', $who->value(new ApiTokenRow($this->token())));
        $email = ColumnTestSupport::find($attributes, 'email');
        self::assertSame('alex@example.com', $email->value($row));
        self::assertSame('alex@example.com', $email->cell($row));
        self::assertSame('', $email->value(new ApiTokenRow($this->token())));
        $access = ColumnTestSupport::find($attributes, 'access');
        self::assertSame('api_tokens.scope.read, api_tokens.scope.resource_write[resource=api_tokens.resource.contacts]', $access->value($row));
        self::assertSame(['badges' => [['label' => 'api_tokens.scope.read', 'tone' => 'gray'], ['label' => 'api_tokens.scope.resource_write[resource=api_tokens.resource.contacts]', 'tone' => 'gray']]], $access->cell($row));
        $token = ColumnTestSupport::find($attributes, 'token');
        self::assertSame('…abcd', $token->value($row));
        self::assertSame('…abcd', $token->cell($row));
        $created = ColumnTestSupport::find($attributes, 'created');
        self::assertSame('2026-01-02', $created->value($row));
        self::assertSame('2026-01-02', $created->cell($row));
        $expires = ColumnTestSupport::find($attributes, 'expires');
        self::assertSame('2026-04-02', $expires->value($row));
        self::assertSame('2026-04-02', $expires->cell($row), 'An active token shows only the day.');
        $used = ColumnTestSupport::find($attributes, 'last_used');
        self::assertSame('api_tokens.never', $used->value($row));
        self::assertSame('api_tokens.never', $used->cell($row));
    }

    public function testALastUsedTokenShowsWhenItWasUsed(): void
    {
        $token = $this->token();
        new \ReflectionProperty(ApiToken::class, 'lastUsedAt')->setValue($token, new \DateTimeImmutable('2026-02-03 14:05'));
        $used = ColumnTestSupport::find($this->columns()->attributes(ApiTokenLists::TOKENS), 'last_used');

        self::assertSame('2026-02-03 14:05', $used->value(new ApiTokenRow($token)));
        self::assertSame('2026-02-03 14:05', $used->cell(new ApiTokenRow($token)));
    }

    public function testAnExpiredOrRevokedTokenIsFlaggedNextToItsExpiryDay(): void
    {
        self::assertSame(['text' => '2026-04-02', 'flag' => ['label' => 'api_tokens.state.expired', 'tone' => 'amber']], $this->expiresCell('2026-05-01'));
        self::assertSame(['text' => '2026-04-02', 'flag' => ['label' => 'api_tokens.state.revoked', 'tone' => 'red']], $this->expiresCell('2026-02-01', true));
        self::assertSame('2026-04-02', $this->expiresCell('2026-04-01'));
    }

    public function testAConnectedAppShowsItsVerifyingHostOrAnAmberUnverifiedBadge(): void
    {
        $attributes = [...$this->columns()->attributes(ApiTokenLists::CONNECTIONS)];
        $verified = ColumnTestSupport::find($attributes, 'verified');
        $vouched = new ApiTokenRow($this->token(), null, 'app.example.com');
        $unknown = new ApiTokenRow($this->token());

        self::assertSame('app.example.com', $verified->value($vouched));
        self::assertSame('app.example.com', $verified->cell($vouched));
        self::assertSame('api_tokens.connection.unverified', $verified->value($unknown));
        self::assertSame(['badges' => [['label' => 'api_tokens.connection.unverified', 'tone' => 'amber']]], $verified->cell($unknown));
        self::assertSame('2026-01-02', ColumnTestSupport::find($attributes, 'created')->value($unknown));
        self::assertSame('api_tokens.never', ColumnTestSupport::find($attributes, 'last_used')->value($unknown));
        self::assertSame('CI', ColumnTestSupport::find($attributes, 'name')->value($unknown));
    }

    private function expiresCell(string $now, bool $revoked = false): mixed
    {
        $token = $this->token();
        if ($revoked) {
            $token->revoke(null, new \DateTimeImmutable('2026-02-01'));
        }

        return ColumnTestSupport::find($this->columns($now)->attributes(ApiTokenLists::TOKENS), 'expires')->cell(new ApiTokenRow($token));
    }

    private function token(): ApiToken
    {
        return new ApiToken('u1', 'CI', [ApiScope::read(), ApiScope::of('contacts', true), ApiScope::of('contacts', false)], str_repeat('a', 64), 'abcd', new \DateTimeImmutable('2026-01-02 10:30'), new \DateTimeImmutable('2026-04-02 10:30'));
    }

    private function columns(string $now = '2026-02-01'): ApiTokenColumns
    {
        $clock = new readonly class($now) implements ClockInterface {
            public function __construct(private string $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->now);
            }
        };
        $resources = new class implements ApiResources {
            public function declared(): array
            {
                return ['contacts'];
            }

            public function byModule(): array
            {
                return ['party' => ['contacts']];
            }

            public function guest(): array
            {
                return [];
            }
        };

        return new ApiTokenColumns(ColumnTestSupport::translator(), $clock, new ApiScopeOptions($resources, ColumnTestSupport::translator(), new GuestScopes($resources)));
    }
}
