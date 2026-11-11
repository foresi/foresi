<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application\Query;

use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\Query\SupplierChoices;
use App\Purchasing\Application\Query\SupplierOption;
use App\Tests\Purchasing\Application\PurchasingTestCase;

final class SupplierChoicesTest extends PurchasingTestCase
{
    public function testAContactIsFoundByAtLeastThreeLettersOfItsName(): void
    {
        $this->contact('Abacus Ltd');

        self::assertSame([], $this->choices()->search('ab'), 'Two letters are too few.');
        self::assertSame([], $this->choices()->search('  ab  '), 'The spaces around do not count.');
        self::assertSame(['Abacus Ltd'], $this->names($this->choices()->search('aba')));
        self::assertSame(['Abacus Ltd'], $this->names($this->choices()->search(' ABA ')));
    }

    public function testTheLengthIsCountedInLettersNotBytes(): void
    {
        $this->contact('Žižka Ltd');

        self::assertSame([], $this->choices()->search('Ži'));
        self::assertSame(['Žižka Ltd'], $this->names($this->choices()->search('Žiž')));
    }

    public function testDigitsAreEnoughToFindARegistrationNumber(): void
    {
        self::assertSame(['Supplier Example s.r.o.'], $this->names($this->choices()->search('87')));
        self::assertSame(['Supplier Example s.r.o.'], $this->names($this->choices()->search('87654321')));
        self::assertSame([], $this->choices()->search('99'));
    }

    public function testAnOptionCarriesTheNumbersOfTheContact(): void
    {
        $options = $this->choices()->search('Supplier');

        self::assertEquals([new SupplierOption($this->supplier->id(), 'Supplier Example s.r.o.', '87654321', 'CZ87654321')], $options);
    }

    public function testAtMostTenContactsAreOfferedInNameOrderFromTheFirstPage(): void
    {
        // More than the 25 an address book page holds, so that a wrong page shows.
        for ($i = 26; $i >= 1; --$i) {
            $this->contact(\sprintf('Bulk %02d Ltd', $i));
        }
        $this->em->flush();

        $options = $this->choices()->search('Bulk');

        self::assertSame(array_map(static fn (int $i): string => \sprintf('Bulk %02d Ltd', $i), range(1, 10)), $this->names($options));
    }

    public function testAContactIsFoundById(): void
    {
        $option = $this->choices()->find($this->supplier->id());

        self::assertEquals(new SupplierOption($this->supplier->id(), 'Supplier Example s.r.o.', '87654321', 'CZ87654321'), $option);
        self::assertNull($this->choices()->find('0190b4a1-0000-7000-8000-000000000000'));
    }

    public function testAContactWithoutNumbersIsFoundWithoutThem(): void
    {
        $person = new Party(PartyKind::Person, 'Plain Person', null, null, '');
        $this->em->persist($person);
        $this->em->flush();

        self::assertEquals(new SupplierOption($person->id(), 'Plain Person', null, null), $this->choices()->find($person->id()));
    }

    public function testAGuestSearchesNothing(): void
    {
        $this->signIn($this->guest);
        $this->expectException(PurchasingNotAllowed::class);

        $this->choices()->search('Supplier');
    }

    public function testAGuestFindsNothingById(): void
    {
        $this->signIn($this->guest);
        $this->expectException(PurchasingNotAllowed::class);

        $this->choices()->find($this->supplier->id());
    }

    private function contact(string $name): Party
    {
        $party = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        return $party;
    }

    /**
     * @param list<SupplierOption> $options
     *
     * @return list<string>
     */
    private function names(array $options): array
    {
        return array_map(static fn (SupplierOption $option): string => $option->name, $options);
    }

    private function choices(): SupplierChoices
    {
        return self::getContainer()->get(SupplierChoices::class);
    }
}
