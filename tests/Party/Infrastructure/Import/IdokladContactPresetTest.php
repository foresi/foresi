<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Import;

use App\Import\Application\PresetRegistry;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\ColumnMatcher;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\MatchReason;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\ValueNormaliser;
use App\Party\Infrastructure\Import\IdokladContactPreset;
use App\Party\Infrastructure\Import\PartyImportTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdokladContactPresetTest extends KernelTestCase
{
    /** Every column iDoklad can export, in its own order. */
    private const array HEADERS = ['Firma', 'IČ', 'DIČ', 'Ulice', 'PSČ', 'Město', 'Stát', 'E-mailová adresa', 'Další příjemci', 'Telefon', 'www', 'Sleva', 'Splatnost', 'Nastavení odesílaní upomínek', 'Titul', 'Jméno', 'Příjmení', 'Mobil', 'Číslo účtu', 'Kód banky', 'IBAN', 'SWIFT'];

    public function testItIsTheContactPresetOfTheRegistry(): void
    {
        $preset = new IdokladContactPreset();
        self::assertSame('idoklad_contacts', $preset->key());
        self::assertSame('import.preset.idoklad_contacts', $preset->labelKey());
        self::assertSame(PartyImportTarget::KEY, $preset->targetKey());
        self::assertInstanceOf(IdokladContactPreset::class, self::getContainer()->get(PresetRegistry::class)->recognise('party', self::HEADERS));
        self::assertNull(self::getContainer()->get(PresetRegistry::class)->recognise('fake', self::HEADERS));
    }

    public function testEveryColumnIsAFieldOfTheTargetOrLeftOut(): void
    {
        $fields = array_map(static fn ($field): string => $field->key, self::getContainer()->get(TargetRegistry::class)->get('party')->fields());
        $columns = new IdokladContactPreset()->columns();

        self::assertSame(self::HEADERS, array_keys($columns));
        self::assertSame(['Sleva', 'Nastavení odesílaní upomínek'], array_keys(array_filter($columns, static fn (?string $field): bool => null === $field)));
        foreach (array_filter($columns, static fn (?string $field): bool => null !== $field) as $header => $field) {
            self::assertContains($field, $fields, (string) $header);
        }
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function subsets(): iterable
    {
        yield 'all columns' => [self::HEADERS, true];
        yield 'four columns' => [['Firma', 'IČ', 'DIČ', 'E-mailová adresa'], true];
        yield 'four in another order' => [['Mobil', 'Sleva', 'Firma', 'SWIFT'], true];
        yield 'four among other columns' => [['Firma', 'Note', 'IČ', 'Created', 'Telefon', 'Owner', 'Mobil', 'Tag', 'Source'], true];
        yield 'other spelling and BOM' => [["\u{FEFF}firma", 'ic', 'DIC', 'e-mailova adresa'], true];
        yield 'three columns' => [['Firma', 'IČ', 'DIČ'], false];
        yield 'none of its columns' => [['Name', 'Email', 'Phone', 'City', 'Street'], false];
    }

    /**
     * @param list<string> $headers
     */
    #[DataProvider('subsets')]
    public function testAnySubsetOfAtLeastFourHeadersIsRecognised(array $headers, bool $recognised): void
    {
        self::assertSame($recognised, new IdokladContactPreset()->recognises($headers));
    }

    public function testTheMappingOfTheRealHeadersComesFromThePreset(): void
    {
        $target = self::getContainer()->get(TargetRegistry::class)->get('party');
        $proposals = new ColumnMatcher(new ValueNormaliser())->propose(self::HEADERS, [], $target->fields(), null, new IdokladContactPreset(), static fn (): array => []);

        self::assertSame(array_fill(0, \count(self::HEADERS), MatchReason::Preset), array_map(static fn (ColumnProposal $proposal): MatchReason => $proposal->reason, $proposals));
        self::assertSame(array_values(new IdokladContactPreset()->columns()), array_map(static fn (ColumnProposal $proposal): ?string => $proposal->fieldKey, $proposals));
    }

    public function testTheCountryNamesOfIdokladAreKnown(): void
    {
        $values = new IdokladContactPreset()->values();
        $field = array_values(array_filter(self::getContainer()->get(TargetRegistry::class)->get('party')->fields(), static fn ($field): bool => 'billing_country' === $field->key))[0];

        self::assertSame(['billing_country'], array_keys($values));
        self::assertSame('CZ', new ValueNormaliser()->normalise($field, 'Česká republika', new NormalisationOptions(), $values['billing_country']));
        self::assertSame('SK', new ValueNormaliser()->normalise($field, 'Slovenská republika', new NormalisationOptions(), $values['billing_country']));
    }
}
