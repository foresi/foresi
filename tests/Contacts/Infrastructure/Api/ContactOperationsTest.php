<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure\Api;

use Foresi\Contacts\Application\Contact\LinkContactPersonInput;
use Foresi\Party\Application\PartyRules;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The input schemas the contact writes publish over REST and MCP (ADR 0032):
 * each length a client is told is the length the rules of the address book
 * take, so a value the schema allows is never refused as too long.
 */
final class ContactOperationsTest extends KernelTestCase
{
    private const array LENGTHS = [
        'display_name' => PartyRules::MAX_NAME,
        'street' => PartyRules::MAX_STREET,
        'city' => PartyRules::MAX_CITY,
        'postal_code' => PartyRules::MAX_POSTAL_CODE,
        'country_code' => PartyRules::COUNTRY_CODE_LENGTH,
        'registration_number' => PartyRules::MAX_REGISTRATION_NUMBER,
        'registration_country' => PartyRules::COUNTRY_CODE_LENGTH,
        'vat_id' => PartyRules::MAX_VAT_ID,
        'email' => PartyRules::MAX_EMAIL,
        'phone' => PartyRules::MAX_PHONE,
        'mobile' => PartyRules::MAX_PHONE,
        'website' => PartyRules::MAX_WEBSITE,
    ];

    public function testTheCreateAndUpdateSchemasGiveTheLengthsOfTheRules(): void
    {
        foreach (['contacts.create', 'contacts.update'] as $name) {
            self::assertSame(self::LENGTHS, $this->lengths($name), $name);
        }
    }

    public function testTheKindAndTheRolesAreEnumerated(): void
    {
        $create = InputSchema::of($this->operations()->get('contacts.create')->fields);
        $properties = $create['properties'];
        self::assertIsArray($properties);

        self::assertSame(['type' => 'string', 'enum' => ['person', 'sole_trader', 'company'], 'description' => 'What the contact is.'], $properties['kind']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['customer', 'supplier', 'partner', 'authority']], 'description' => 'What the contact is to the organisation.'], $properties['roles']);
        self::assertSame(['kind', 'display_name', 'street', 'city', 'postal_code', 'country_code'], $create['required']);
    }

    public function testALinkTakesAPositionOfTheRulesAndIsNotPrimaryUnlessAsked(): void
    {
        $link = $this->operations()->get('contacts.link_person');
        $properties = InputSchema::of($link->fields)['properties'];
        self::assertIsArray($properties);
        $position = $properties['position'];
        self::assertIsArray($position);

        self::assertSame(PartyRules::MAX_POSITION, $position['maxLength']);
        $input = InputMapper::map($link, ['contact_id' => 'c', 'person_id' => 'p']);
        self::assertInstanceOf(LinkContactPersonInput::class, $input);
        self::assertFalse($input->primary);
        self::assertSame('', $input->position);
    }

    /**
     * @return array<string, int> the longest value of each field that declares one
     */
    private function lengths(string $operation): array
    {
        $properties = InputSchema::of($this->operations()->get($operation)->fields)['properties'];
        self::assertIsArray($properties);
        $lengths = [];
        foreach ($properties as $wire => $property) {
            if (\is_string($wire) && \is_array($property) && isset($property['maxLength']) && \is_int($property['maxLength'])) {
                $lengths[$wire] = $property['maxLength'];
            }
        }

        return $lengths;
    }

    private function operations(): Operations
    {
        self::bootKernel();

        return self::getContainer()->get(Operations::class);
    }
}
