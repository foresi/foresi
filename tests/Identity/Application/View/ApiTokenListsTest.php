<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application\View;

use Foresi\Identity\Application\View\ApiTokenLists;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFields;
use PHPUnit\Framework\TestCase;

/**
 * The lists of Settings → API: the fields each one has to filter by.
 */
final class ApiTokenListsTest extends TestCase
{
    public function testItShipsTheFourListsInOrder(): void
    {
        self::assertSame(
            ['identity.api_connections', 'identity.api_tokens', 'identity.others_api_tokens', 'identity.others_api_connections'],
            array_map(static fn (\Foresi\Shared\Application\View\ListDefinition $list): string => $list->key, [...$this->lists()->lists()]),
        );
    }

    public function testTokensAreFilteredByNameAndStateAndConnectionsByAppAndVerification(): void
    {
        $tokens = $this->lists()->list(ApiTokenLists::TOKENS)->fields;
        self::assertSame(['name', 'state'], array_keys($tokens));
        self::assertSame([FieldType::Text, 'api_tokens.column.name'], [$tokens['name']->type, $tokens['name']->label]);
        self::assertSame([FieldType::Enum, 'api_tokens.field.state', ['active', 'expired', 'revoked']], [$tokens['state']->type, $tokens['state']->label, $tokens['state']->options]);
        self::assertSame(['active' => 'api_tokens.state.active', 'expired' => 'api_tokens.state.expired', 'revoked' => 'api_tokens.state.revoked'], $tokens['state']->optionLabels);

        $connections = $this->lists()->list(ApiTokenLists::CONNECTIONS)->fields;
        self::assertSame(['name', 'verified'], array_keys($connections));
        self::assertSame([FieldType::Text, 'api_tokens.column.app'], [$connections['name']->type, $connections['name']->label]);
        self::assertSame([FieldType::Boolean, 'api_tokens.field.verified'], [$connections['verified']->type, $connections['verified']->label]);
    }

    public function testTheOtherPeoplesListsAddThePerson(): void
    {
        $tokens = $this->lists()->list(ApiTokenLists::OTHERS_TOKENS)->fields;
        self::assertSame(['name', 'person', 'state'], array_keys($tokens));
        self::assertSame([FieldType::Text, 'api_tokens.column.person'], [$tokens['person']->type, $tokens['person']->label]);
        self::assertSame('api_tokens.column.name', $tokens['name']->label);

        $connections = $this->lists()->list(ApiTokenLists::OTHERS_CONNECTIONS)->fields;
        self::assertSame(['name', 'person', 'verified'], array_keys($connections));
        self::assertSame('api_tokens.column.app', $connections['name']->label);
    }

    public function testEveryListShipsTheAllViewWithoutACountAndIsBuiltOnce(): void
    {
        $lists = $this->lists();
        foreach ([ApiTokenLists::TOKENS, ApiTokenLists::OTHERS_TOKENS, ApiTokenLists::CONNECTIONS, ApiTokenLists::OTHERS_CONNECTIONS] as $key) {
            self::assertCount(1, $lists->list($key)->defaults);
            $view = $lists->list($key)->defaults[0];
            self::assertSame(['all', 'api_tokens.tab.all', false, 0], [$view->slug, $view->name, $view->showCount, $view->position]);
            self::assertSame($lists->list($key), $lists->list($key));
        }
    }

    private function lists(): ApiTokenLists
    {
        return new ApiTokenLists(new ListFields([]));
    }
}
