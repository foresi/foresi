<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\View;

use Foresi\Identity\Domain\ApiTokenState;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The four tables of Settings → API as lists with views (docs/design/list-pages.md, rules 8
 * and 9): a person's own connected apps and tokens, and for the owner those of the other
 * people, which add who they belong to. Each table is a list of its own, with its views and
 * the columns each person keeps; the connected apps of a guest's own page are the same list
 * as their connected apps in the settings. Each ships one view, All, as the page showed.
 */
final class ApiTokenLists implements ListDefinitionSource
{
    public const string TOKENS = 'identity.api_tokens';
    public const string OTHERS_TOKENS = 'identity.others_api_tokens';
    public const string CONNECTIONS = 'identity.api_connections';
    public const string OTHERS_CONNECTIONS = 'identity.others_api_connections';
    public const string NAME = 'name';
    public const string PERSON = 'person';
    public const string STATE = 'state';
    public const string VERIFIED = 'verified';

    /** @var array<string, ListDefinition> */
    private array $lists = [];

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        foreach ([self::CONNECTIONS, self::TOKENS, self::OTHERS_TOKENS, self::OTHERS_CONNECTIONS] as $key) {
            yield $this->list($key);
        }
    }

    public function list(string $key): ListDefinition
    {
        return $this->lists[$key] ??= $this->build($key);
    }

    private function build(string $key): ListDefinition
    {
        $others = self::OTHERS_TOKENS === $key || self::OTHERS_CONNECTIONS === $key;
        $tokens = self::TOKENS === $key || self::OTHERS_TOKENS === $key;
        $states = array_map(static fn (ApiTokenState $state): string => $state->value, ApiTokenState::cases());
        $fields = [new Field(self::NAME, FieldType::Text, $tokens ? 'api_tokens.column.name' : 'api_tokens.column.app')];
        if ($others) {
            $fields[] = new Field(self::PERSON, FieldType::Text, 'api_tokens.column.person');
        }
        $fields[] = $tokens
            ? new Field(self::STATE, FieldType::Enum, 'api_tokens.field.state', options: $states, optionLabels: array_combine($states, array_map(static fn (string $state): string => 'api_tokens.state.'.$state, $states)))
            : new Field(self::VERIFIED, FieldType::Boolean, 'api_tokens.field.verified');

        return new ListDefinition($key, $this->fields->of($key, $fields), [new DefaultView(ListDefinition::ALL, 'api_tokens.tab.all', showCount: false, position: 0)]);
    }
}
