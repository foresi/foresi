<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Contacts;

use Foresi\Contacts\Contract\ContactCards;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Integration\Contacts\ContactDefaultsCard;
use Foresi\Shared\Domain\Currency;
use Foresi\Ui\Contract\Slot\SlotItem;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The card of a contact's invoicing defaults, without the contact page around it.
 */
final class ContactDefaultsCardTest extends TestCase
{
    public function testTheCardListsTheDefaultsOfTheContact(): void
    {
        $card = $this->card(new InvoicingDefaults(30, Currency::of('EUR'), DocumentLanguage::English, PaymentMethod::Card, true));

        self::assertEquals([new SlotItem('@Invoicing/contact/_defaults_card.html.twig', ['items' => [
            ['label' => '[invoice.contact_defaults.due_days]', 'value' => '[invoice.contact_defaults.days count=30]'],
            ['label' => '[invoice.contact_defaults.currency]', 'value' => 'EUR'],
            ['label' => '[invoice.contact_defaults.language]', 'value' => '[locale.en]'],
            ['label' => '[invoice.contact_defaults.reverse_charge]', 'value' => '[invoice.contact_defaults.yes]'],
        ]])], [...$card->items(ContactCards::SLOT, ['contactId' => 'contact'])]);
    }

    public function testWithoutReverseChargeTheCardSaysNo(): void
    {
        $items = [...$this->card(InvoicingDefaults::standard())->items(ContactCards::SLOT, ['contactId' => 'contact'])];

        self::assertSame(['label' => '[invoice.contact_defaults.reverse_charge]', 'value' => '[invoice.contact_defaults.no]'], array_values((array) ($items[0]->data['items'] ?? []))[3] ?? null);
    }

    public function testOnlyTheContactCardsOfAContactGetTheCard(): void
    {
        $card = $this->card(InvoicingDefaults::standard());

        self::assertSame([], [...$card->items('party.other_slot', ['contactId' => 'contact'])]);
        self::assertSame([], [...$card->items(ContactCards::SLOT, [])]);
        self::assertSame([], [...$card->items(ContactCards::SLOT, ['contactId' => 7])]);
    }

    private function card(InvoicingDefaults $stored): ContactDefaultsCard
    {
        $defaults = self::createStub(PartyInvoicingDefaults::class);
        $defaults->method('of')->willReturn($stored);
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => '['.implode(' ', [$id, ...array_map(static fn (string $key, mixed $value): string => $key.'='.(\is_scalar($value) ? $value : get_debug_type($value)), array_keys($parameters), $parameters)]).']');

        return new ContactDefaultsCard($defaults, $translator);
    }
}
