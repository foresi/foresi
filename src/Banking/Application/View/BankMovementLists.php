<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application\View;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\MovementState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The bank screen and the views it ships: the tabs it had, each the same movements
 * as before (spec D12), after All, which every list starts with. The account a
 * movement came to is a field of its own, its values the firms' accounts; the firm
 * is not, as an instance holds one firm (D12).
 *
 * The bank mail is a view too: a condition "bank mail is yes" lists the messages
 * that came instead of the movements, as the Messages tab did. A message came to an
 * account, so the account narrows it; it has no direction and no state, so a
 * condition on either finds none, or all of them when it says "is not".
 */
final readonly class BankMovementLists implements ListDefinitionSource
{
    public const string KEY = 'banking.movements';
    public const string DIRECTION = 'direction';
    public const string STATE = 'state';
    public const string ACCOUNT = 'account';
    public const string BANK_MAIL = 'bank_mail';
    /** The slug of the view of the bank mail, as the Messages tab was named. */
    public const string MESSAGES = 'messages';

    public function __construct(private BankMovements $movements)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    /**
     * The list with the accounts the firms have now.
     */
    public function list(): ListDefinition
    {
        $accounts = [];
        foreach ($this->movements->accounts() as $id => $account) {
            $accounts[$id] = '' === $account['label'] ? $account['iban'] : $account['label'];
        }

        return self::of($accounts);
    }

    /**
     * @param array<string, string> $accounts the name of each account, by its id; none leaves the account out
     */
    public static function of(array $accounts): ListDefinition
    {
        return new ListDefinition(self::KEY, self::fields($accounts), self::defaults(), ['direction', 'state']);
    }

    /**
     * Whether a view lists the bank mail rather than the movements.
     */
    public static function showsMail(ViewFilter $view): bool
    {
        return [] !== array_filter($view->on(self::BANK_MAIL), static fn (Condition $condition): bool => true === $condition->value);
    }

    /**
     * @param array<string, string> $accounts
     *
     * @return list<Field>
     */
    private static function fields(array $accounts): array
    {
        $directions = array_column(MovementDirection::cases(), 'value');
        $states = array_column(MovementState::cases(), 'value');
        $fields = [
            new Field(self::DIRECTION, FieldType::Enum, 'bank.movement.field.direction', options: $directions, optionLabels: self::labels('bank.movement.tab.', $directions)),
            new Field(self::STATE, FieldType::Enum, 'bank.movement.field.state', options: $states, optionLabels: self::labels('bank.movement.state.', $states)),
        ];
        if ([] !== $accounts) {
            // An account's name is the firm's own text, not a translation key: the translator gives it back as it is.
            $fields[] = new Field(self::ACCOUNT, FieldType::Enum, 'bank.movement.list.account', options: array_map(strval(...), array_keys($accounts)), optionLabels: $accounts);
        }
        $fields[] = new Field(self::BANK_MAIL, FieldType::Boolean, 'bank.movement.field.bank_mail');

        return $fields;
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function labels(string $prefix, array $values): array
    {
        return array_combine($values, array_map(static fn (string $value): string => $prefix.$value, $values));
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        $incoming = new Condition(self::DIRECTION, 'is', MovementDirection::Incoming->value);
        $outgoing = new Condition(self::DIRECTION, 'is', MovementDirection::Outgoing->value);
        $unmatched = new Condition(self::STATE, 'is', MovementState::Unmatched->value);

        // Only the tabs that waited for someone showed a count (spec D9).
        return [
            new DefaultView('all', 'bank.movement.view.all', showCount: false, position: 0),
            new DefaultView('incoming', 'bank.movement.tab.incoming', ViewFilter::all($incoming), showCount: false, position: 1),
            new DefaultView('outgoing', 'bank.movement.tab.outgoing', ViewFilter::all($outgoing), showCount: false, position: 2),
            new DefaultView('unmatched', 'bank.movement.tab.unmatched', ViewFilter::all($incoming, $unmatched), position: 3),
            new DefaultView('outgoing_unmatched', 'bank.movement.tab.outgoing_unmatched', ViewFilter::all($outgoing, $unmatched), position: 4),
            new DefaultView(self::MESSAGES, 'bank.movement.tab.messages', ViewFilter::all(new Condition(self::BANK_MAIL, 'is', true)), position: 5),
        ];
    }
}
