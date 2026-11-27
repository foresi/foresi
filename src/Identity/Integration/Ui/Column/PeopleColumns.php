<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\View\PeopleLists;
use Foresi\Identity\Contract\PersonContactPicker;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\ContactPages;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the list of people, those the page always showed: a person's name with their
 * photo, e-mail, role, contact, two-factor sign-in and whether they may sign in; an
 * invitation's name, e-mail, role, contact and when it expires. The contact comes with the
 * address book's picker, linked to its page when the address book has one (ADR 0049).
 */
final readonly class PeopleColumns implements DataSource
{
    public const string LIST = PeopleLists::KEY;

    public function __construct(private TranslatorInterface $translator, private ?PersonContactPicker $contactPicker = null, private ?ContactPages $contactPages = null)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $invitations = static fn (array $context): bool => true === ($context['invitations'] ?? false);
        $people = static fn (array $context): bool => true !== ($context['invitations'] ?? false);
        $label = static fn (string $key): string => 'people.column.'.$key;
        $name = static fn (PeopleRow $row): string => $row->name();
        $email = static fn (PeopleRow $row): string => $row->email();
        $role = fn (PeopleRow $row): string => $this->translator->trans('role.'.$row->role()->value);
        $contact = static fn (PeopleRow $row): string => $row->contact()['name'] ?? '';
        $twoFactor = fn (PeopleRow $row): string => $this->translator->trans(true === $row->person?->twoFactor ? 'people.two_factor.on' : 'people.two_factor.off');
        $status = fn (PeopleRow $row): string => $this->translator->trans(true === $row->person?->active ? 'people.status.active' : 'people.status.deactivated');
        $expires = fn (PeopleRow $row): string => $row->invitation?->expiresAt()->format($this->translator->trans('format.date')) ?? '';

        $attributes = [
            new Attribute('name', $label('name'), AttributeType::Text, $name, defaultVisible: true, defaultPosition: 10, cell: static fn (PeopleRow $row): array|string => $row->person instanceof \Foresi\Identity\Application\Person ? ['avatar' => $row->person->userId, 'user' => $row->person->userId, 'text' => $row->person->name] : $row->name(), locked: true, truncate: true),
            new Attribute('email', $label('email'), AttributeType::Text, $email, defaultVisible: true, defaultPosition: 20, cell: $email, phone: false, truncate: true),
            new Attribute('role', $label('role'), AttributeType::Text, $role, defaultVisible: true, defaultPosition: 30, cell: fn (PeopleRow $row): array => ['text' => $role($row), 'tone' => Role::Owner === $row->role() && !$row->isInvitation() ? 'green' : 'gray']),
        ];
        if ($this->contactPicker instanceof PersonContactPicker) {
            $attributes[] = new Attribute('contact', $label('contact'), AttributeType::Text, $contact, defaultVisible: true, defaultPosition: 40, cell: function (PeopleRow $row): array|string|null {
                $contact = $row->contact();
                if (null === $contact) {
                    return null;
                }
                $href = $this->contactPages?->urlFor($contact['id']);

                return null === $href ? $contact['name'] : ['links' => [['text' => $contact['name'], 'href' => $href]]];
            }, phone: false, truncate: true);
        }

        return [...$attributes,
            new Attribute('two_factor', $label('two_factor'), AttributeType::Text, $twoFactor, defaultVisible: true, defaultPosition: 50, cell: fn (PeopleRow $row): array => ['text' => $twoFactor($row), 'tone' => true === $row->person?->twoFactor ? 'green' : 'gray'], aliases: ['twoFactor'], phone: false, available: $people),
            new Attribute('status', $label('status'), AttributeType::Text, $status, defaultVisible: true, defaultPosition: 60, cell: fn (PeopleRow $row): array => ['text' => $status($row), 'tone' => true === $row->person?->active ? 'green' : 'amber'], phone: false, available: $people),
            new Attribute('expires', $label('expires'), AttributeType::Text, $expires, defaultVisible: true, defaultPosition: 70, cell: $expires, available: $invitations),
        ];
    }
}
