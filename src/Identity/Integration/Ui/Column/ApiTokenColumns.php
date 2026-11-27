<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\View\ApiTokenLists;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenState;
use Foresi\Identity\Integration\Ui\Controller\ApiScopeOptions;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the tables of Settings → API, those the page always showed: a token's name,
 * what it may do, its last four characters, when it was made, when it expires with an expired
 * or revoked one flagged, and when it was last used; a connected app's name, who vouches for
 * it, what it may do, when it was connected and last used. The other people's tables add the
 * person and their e-mail.
 */
final readonly class ApiTokenColumns implements DataSource
{
    public function __construct(private TranslatorInterface $translator, private ClockInterface $clock, private ApiScopeOptions $scopes)
    {
    }

    public function attributes(string $list): iterable
    {
        $tokens = ApiTokenLists::TOKENS === $list || ApiTokenLists::OTHERS_TOKENS === $list;
        $connections = ApiTokenLists::CONNECTIONS === $list || ApiTokenLists::OTHERS_CONNECTIONS === $list;
        if (!$tokens && !$connections) {
            return [];
        }
        $label = static fn (string $key): string => 'api_tokens.column.'.$key;
        $name = static fn (ApiTokenRow $row): string => $row->token->name();
        $access = fn (ApiTokenRow $row): array => ['badges' => array_map(fn (ApiScope $scope): array => ['label' => $this->scopes->label($scope), 'tone' => 'gray'], ApiScope::smallest($row->token->scopes()))];
        $accessText = fn (ApiTokenRow $row): string => implode(', ', array_map($this->scopes->label(...), ApiScope::smallest($row->token->scopes())));
        $created = fn (ApiTokenRow $row): string => $this->date($row->token->createdAt());
        $lastUsed = fn (ApiTokenRow $row): string => $row->token->lastUsedAt()?->format($this->translator->trans('format.datetime')) ?? $this->translator->trans('api_tokens.never');
        $attributes = [new Attribute('name', $label($tokens ? 'name' : 'app'), AttributeType::Text, $name, defaultVisible: true, defaultPosition: 10, cell: $name, locked: true, truncate: true)];
        if (ApiTokenLists::OTHERS_TOKENS === $list || ApiTokenLists::OTHERS_CONNECTIONS === $list) {
            $person = static fn (ApiTokenRow $row): string => $row->person->name ?? '';
            $email = static fn (ApiTokenRow $row): string => $row->person->email ?? '';
            $attributes[] = new Attribute('person', $label('person'), AttributeType::Text, $person, defaultVisible: true, defaultPosition: 20, cell: $person, phone: false, truncate: true);
            $attributes[] = new Attribute('email', $label('email'), AttributeType::Text, $email, defaultVisible: true, defaultPosition: 30, cell: $email, phone: false, truncate: true);
        }
        if ($connections) {
            $unverified = $this->translator->trans('api_tokens.connection.unverified');

            return [...$attributes,
                new Attribute('verified', $label('verified_by'), AttributeType::Text, static fn (ApiTokenRow $row): string => $row->host ?? $unverified, defaultVisible: true, defaultPosition: 40, cell: static fn (ApiTokenRow $row): array|string => $row->host ?? ['badges' => [['label' => $unverified, 'tone' => 'amber']]], phone: false),
                new Attribute('access', $label('access'), AttributeType::Text, $accessText, defaultVisible: true, defaultPosition: 50, cell: $access, phone: false),
                new Attribute('created', $label('connected'), AttributeType::Text, $created, defaultVisible: true, defaultPosition: 60, cell: $created, phone: false),
                new Attribute('last_used', $label('last_used'), AttributeType::Text, $lastUsed, defaultVisible: true, defaultPosition: 70, cell: $lastUsed, aliases: ['lastUsed']),
            ];
        }
        $token = static fn (ApiTokenRow $row): string => '…'.$row->token->lastFour();
        $expires = fn (ApiTokenRow $row): string => $this->date($row->token->expiresAt());

        return [...$attributes,
            new Attribute('access', $label('access'), AttributeType::Text, $accessText, defaultVisible: true, defaultPosition: 40, cell: $access, phone: false),
            new Attribute('token', $label('token'), AttributeType::Text, $token, defaultVisible: true, defaultPosition: 50, cell: $token, phone: false),
            new Attribute('created', $label('created'), AttributeType::Text, $created, defaultVisible: true, defaultPosition: 60, cell: $created, phone: false),
            new Attribute('expires', $label('expires'), AttributeType::Text, $expires, defaultVisible: true, defaultPosition: 70, cell: fn (ApiTokenRow $row): array|string => match ($row->token->state($this->clock->now())) {
                ApiTokenState::Active => $expires($row),
                ApiTokenState::Expired => ['text' => $expires($row), 'flag' => ['label' => $this->translator->trans('api_tokens.state.expired'), 'tone' => 'amber']],
                ApiTokenState::Revoked => ['text' => $expires($row), 'flag' => ['label' => $this->translator->trans('api_tokens.state.revoked'), 'tone' => 'red']],
            }),
            new Attribute('last_used', $label('last_used'), AttributeType::Text, $lastUsed, defaultVisible: true, defaultPosition: 80, cell: $lastUsed, aliases: ['lastUsed'], phone: false),
        ];
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }
}
