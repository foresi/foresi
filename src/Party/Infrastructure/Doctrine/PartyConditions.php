<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query;
use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Psr\Clock\ClockInterface;

/**
 * The conditions that narrow the address book, as DQL over the alias "p": the
 * one search of the screen and of the API, and the views of the list (spec D3),
 * whose fields compile to the expressions of the list's data source.
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 */
final readonly class PartyConditions
{
    public function __construct(private ContactImports $imports, private ClockInterface $clock, private ContactLists $lists, private Fields $fields)
    {
    }

    /**
     * The search, the role and the archived state, and the view's conditions joined by
     * AND. The archived state is the view's when it holds a condition on it.
     *
     * @return array{string, Parameters} the condition and its parameters
     */
    public function build(string $text, ?PartyRole $role, bool $archived, ?ImportedBy $import = null, ?ViewFilter $view = null): array
    {
        $where = $archived ? 'p.archivedAt IS NOT NULL' : 'p.archivedAt IS NULL';
        $parameters = [];
        if ($view instanceof ViewFilter && [] !== $view->on(ContactLists::ARCHIVED)) {
            $where = '1 = 1';
        }
        if ($import instanceof ImportedBy) {
            [$where, $imported] = $this->imports->condition($import, 'p.id');
            $parameters = $this->untyped($imported);
        }
        if ($role instanceof PartyRole) {
            $where .= ' AND '.$this->role($role->value);
        }
        [$searched, $bound] = $this->searched($text);
        $where .= $searched;
        $parameters += $bound;
        if ($view instanceof ViewFilter) {
            [$condition, $bound] = $this->compiled($view, 'v');
            $where .= ' AND '.$condition;
            $parameters += $bound;
        }

        return [$where, $parameters];
    }

    /**
     * What the search and the import leave, before any view: the tabs count within this (spec D9).
     *
     * @return array{string, Parameters}
     */
    public function narrowed(string $text, ?ImportedBy $import = null): array
    {
        [$where, $parameters] = $import instanceof ImportedBy ? $this->imports->condition($import, 'p.id') : ['1 = 1', []];
        [$searched, $bound] = $this->searched($text);

        return [$where.$searched, $this->untyped($parameters) + $bound];
    }

    /**
     * The contacts a view holds, out of the archive unless it asks for it or the list is
     * narrowed to an import, which can archive them; each view under its own prefix, so
     * one count query holds many side by side.
     *
     * @return array{string, Parameters}
     */
    public function inView(ViewFilter $view, string $prefix, bool $imported = false): array
    {
        [$condition, $parameters] = $this->compiled($view, $prefix);

        return [($imported || [] !== $view->on(ContactLists::ARCHIVED) ? '' : 'p.archivedAt IS NULL AND ').$condition, $parameters];
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     * @param Parameters           $parameters
     *
     * @return Query<TKey, TResult>
     */
    public static function bind(Query $query, array $parameters): Query
    {
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return $query;
    }

    /**
     * @return array{string, Parameters}
     */
    private function compiled(ViewFilter $view, string $prefix): array
    {
        // The fields of the data source bring their expressions; these two the module compiles.
        $derived = static fn (Condition $condition): array => [match ($condition->field) {
            ContactLists::ARCHIVED => DqlConditions::derivedBoolean($condition, 'p.archivedAt IS NOT NULL'),
            ContactLists::UNRELIABLE_PAYER => DqlConditions::derivedBoolean($condition, 'p.unreliablePayerResult = \''.RegistryResult::Unreliable->value.'\''),
            default => throw new \LogicException(\sprintf('The field "%s" of the contacts has no SQL.', $condition->field)),
        }, []];

        return new DqlConditions($this->lists->list(), [], $derived(...))->compile($view, $this->clock->now(...), $prefix);
    }

    /**
     * @return array{string, Parameters}
     */
    private function searched(string $text): array
    {
        $text = trim($text);
        if ('' === $text) {
            return ['', []];
        }
        $conditions = ['LOWER(p.displayName) LIKE :name ESCAPE \'!\'', 'LOWER(p.vatId) LIKE :vat ESCAPE \'!\''];
        $parameters = ['name' => '%'.$this->like(mb_strtolower($text)).'%'];
        $parameters['vat'] = '%'.$this->like(mb_strtolower((string) preg_replace('/\s+/', '', $text))).'%';
        $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text));
        if ('' !== $key) {
            $conditions[] = 'p.registrationKey LIKE :key ESCAPE \'!\'';
            $parameters['key'] = '%'.$this->like($key).'%';
        }
        $conditions[] = 'EXISTS (SELECT 1 FROM '.PartyEmail::class.' e WHERE e.party = p AND LOWER(e.address) LIKE :email ESCAPE \'!\')';
        // The custom fields that say they are searched (ADR 0045).
        $conditions = [...$conditions, ...$this->fields->searched(ContactExtension::RECORD, 'p', 'name')];
        $parameters['email'] = '%'.$this->like(mb_strtolower($text)).'%';

        return [' AND ('.implode(' OR ', $conditions).')', $this->untyped($parameters)];
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return Parameters
     */
    private function untyped(array $parameters): array
    {
        return array_map(static fn (string $value): array => [$value, null], $parameters);
    }

    /**
     * Roles live in a JSON column, which reads the same on every database as text.
     */
    public function role(string $role): string
    {
        return 'CAST_TEXT(p.roles) LIKE \'%"'.$role.'"%\'';
    }

    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
