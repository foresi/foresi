<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Domain;

use App\Import\Domain\ColumnMatcher;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Domain\HeaderName;
use App\Import\Domain\Mapping;
use App\Import\Domain\MatchReason;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\Preset\AbstractSourcePreset;
use App\Import\Domain\Preset\SourcePreset;
use App\Import\Domain\Text;
use App\Import\Domain\ValueNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnMatcher::class)]
#[CoversClass(ColumnProposal::class)]
#[CoversClass(HeaderName::class)]
#[CoversClass(Mapping::class)]
#[CoversClass(AbstractSourcePreset::class)]
#[CoversClass(ValueNormaliser::class)]
#[CoversClass(NormalisationOptions::class)]
#[CoversClass(Text::class)]
#[CoversClass(FieldDefinition::class)]
final class ColumnMatcherTest extends TestCase
{
    /** @return list<FieldDefinition> */
    private function fields(): array
    {
        return [
            new FieldDefinition('display_name', 'field.display_name', FieldType::Text, true, ['nazev firmy']),
            new FieldDefinition('email', 'field.email', FieldType::Email),
            new FieldDefinition('phone', 'field.phone', FieldType::Phone),
            new FieldDefinition('birth_date', 'field.birth_date', FieldType::Date),
        ];
    }

    /**
     * @param list<string>             $headers
     * @param array<int, list<string>> $samples
     *
     * @return list<array{0: ?string, 1: MatchReason}>
     */
    private function propose(array $headers, array $samples = [], ?Mapping $remembered = null, ?SourcePreset $preset = null): array
    {
        $labels = static fn (string $key): array => match ($key) {
            'field.email' => ['E-mailová adresa', 'Email address'],
            'field.display_name' => ['Název', 'Name'],
            default => [],
        };

        return array_map(
            static fn (ColumnProposal $proposal): array => [$proposal->fieldKey, $proposal->reason],
            new ColumnMatcher(new ValueNormaliser())->propose($headers, $samples, $this->fields(), $remembered, $preset, $labels),
        );
    }

    public function testRememberedMappingWinsOverPreset(): void
    {
        $remembered = new Mapping([0 => 'phone']);
        $preset = $this->preset(['a' => 'email']);

        self::assertSame([['phone', MatchReason::Remembered]], $this->propose(['A'], [], $remembered, $preset));
    }

    public function testRememberedDecisionNotToImportIsKept(): void
    {
        self::assertSame([[null, MatchReason::Remembered]], $this->propose(['Email'], [], new Mapping([0 => null])));
    }

    public function testRememberedMappingToUnknownFieldIsIgnored(): void
    {
        self::assertSame([['email', MatchReason::SameName]], $this->propose(['Email'], [], new Mapping([0 => 'gone', 5 => 'phone'])));
    }

    public function testPresetWinsOverSameName(): void
    {
        $preset = $this->preset(['E-mail' => 'phone', 'Unknown' => 'gone']);

        self::assertSame(
            [['phone', MatchReason::Preset], [null, MatchReason::None]],
            $this->propose(['E-mail', 'Unknown'], [], null, $preset),
        );
    }

    public function testAColumnThePresetLeavesOutIsProposedAsNotImportedAndCountsForRecognition(): void
    {
        // "Email" alone would match the email field by name.
        $preset = $this->preset(['Name' => 'display_name', 'Email' => null]);

        self::assertSame(
            [['display_name', MatchReason::Preset], [null, MatchReason::Preset], ['email', MatchReason::SameName]],
            $this->propose(['Name', 'Email', 'E-mail'], [], null, $preset),
        );
        self::assertTrue($preset->recognises(['Name', 'Email']));
    }

    #[DataProvider('sameNames')]
    public function testSameName(string $header, string $field): void
    {
        self::assertSame([[$field, MatchReason::SameName]], $this->propose([$header]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sameNames(): iterable
    {
        yield 'punctuation' => ['E-mail', 'email'];
        yield 'key with underscore' => ['Display_Name', 'display_name'];
        yield 'alias without diacritics' => ['Nazev firmy', 'display_name'];
        yield 'alias with diacritics' => ['Název firmy', 'display_name'];
        yield 'translated label' => ['E-mailová adresa', 'email'];
        yield 'byte order mark' => ["\u{FEFF}Phone", 'phone'];
    }

    public function testSimilarNameNeedsSamplesTheFieldAccepts(): void
    {
        $emails = ['anna@example.com', 'bob@example.com', 'carol@example.com'];

        self::assertSame([['email', MatchReason::SimilarName]], $this->propose(['Emial'], [0 => $emails]));
        self::assertSame([[null, MatchReason::None]], $this->propose(['Emial'], [0 => ['12', '34', '56']]));
        self::assertSame([[null, MatchReason::None]], $this->propose(['Emial'], [0 => ['', ' ']]));
        self::assertSame([[null, MatchReason::None]], $this->propose(['Emial']));
    }

    public function testSimilarNameToleratesFewUnreadableSamples(): void
    {
        $samples = ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'bad'];

        self::assertSame([['email', MatchReason::SimilarName]], $this->propose(['Emial'], [0 => $samples]));
        self::assertSame([[null, MatchReason::None]], $this->propose(['Emial'], [0 => ['a@example.com', 'bad']]));
    }

    public function testDissimilarNameIsNotProposed(): void
    {
        self::assertSame([[null, MatchReason::None]], $this->propose(['Comment'], [0 => ['a@example.com']]));
        self::assertSame([[null, MatchReason::None]], $this->propose(['']));
    }

    public function testMostSimilarFieldWins(): void
    {
        $fields = [
            new FieldDefinition('first', 'x', FieldType::Text, false, ['abcdefghij1']),
            new FieldDefinition('second', 'x', FieldType::Text, false, ['abcdefghij']),
        ];
        $proposals = new ColumnMatcher(new ValueNormaliser())->propose(['abcdefghij2'], [0 => ['1']], $fields, null, null, static fn (): array => []);

        self::assertSame('first', $proposals[0]->fieldKey);

        $proposals = new ColumnMatcher(new ValueNormaliser())->propose(['abcdefghi'], [0 => ['1']], $fields, null, null, static fn (): array => []);

        self::assertSame('second', $proposals[0]->fieldKey);
    }

    public function testNumericColumnIsNeverProposedForEmail(): void
    {
        self::assertSame([[null, MatchReason::None]], $this->propose(['Emal'], [0 => ['1', '2', '3']]));
    }

    public function testDuplicateHeaderGetsNoneForTheSecondColumn(): void
    {
        self::assertSame(
            [['email', MatchReason::SameName], [null, MatchReason::None]],
            $this->propose(['Email', 'email']),
        );
    }

    public function testDuplicateFromPresetAndNameIsClaimedOnce(): void
    {
        $preset = $this->preset(['Contact' => 'email']);

        self::assertSame(
            [['email', MatchReason::Preset], [null, MatchReason::None]],
            $this->propose(['Contact', 'Email'], [], null, $preset),
        );
    }

    public function testLaterPresetColumnBeatsEarlierSameNameColumn(): void
    {
        $preset = $this->preset(['Contact' => 'email']);

        self::assertSame(
            [[null, MatchReason::None], ['email', MatchReason::Preset]],
            $this->propose(['Email', 'Contact'], [], null, $preset),
        );
    }

    public function testPresetMayOverrideTheRecognitionRule(): void
    {
        $preset = new class extends AbstractSourcePreset {
            public function key(): string
            {
                return 'few';
            }

            public function labelKey(): string
            {
                return 'import.preset.few';
            }

            public function targetKey(): string
            {
                return 'party';
            }

            public function columns(): array
            {
                return ['a' => 'email', 'b' => 'phone', 'c' => 'display_name', 'd' => 'birth_date'];
            }

            public function values(): array
            {
                return [];
            }

            protected function isRecognised(int $known, int $total): bool
            {
                return $known >= 4;
            }
        };

        self::assertTrue($preset->recognises(['A', 'B', 'C', 'D', 'x', 'y', 'z', 'w', 'v', 'u', 't']));
        self::assertFalse($preset->recognises(['A', 'B', 'C', 'x']));
    }

    public function testSimilarNameDoesNotTakeAClaimedField(): void
    {
        $emails = ['a@example.com'];

        self::assertSame(
            [['email', MatchReason::SameName], [null, MatchReason::None]],
            $this->propose(['Email', 'Emial'], [1 => $emails]),
        );
    }

    public function testProposesOnePerHeaderInOrder(): void
    {
        $proposals = new ColumnMatcher(new ValueNormaliser())->propose(['Zzz', 'Phone'], [], $this->fields(), null, null, static fn (): array => []);

        self::assertSame([0, 1], array_map(static fn (ColumnProposal $p): int => $p->column, $proposals));
    }

    public function testHeaderNameNormalisation(): void
    {
        self::assertSame('nazev firmy 1', HeaderName::normalise("  Název_Firmy (1)\u{00A0}"));
        self::assertSame('zlutoucky kun', HeaderName::normalise('ŽLUŤOUČKÝ  kůň'));
        self::assertSame('', HeaderName::normalise(' - '));
    }

    public function testMappingSignatureIgnoresSpellingOfHeaders(): void
    {
        self::assertSame(Mapping::signature(['Název', 'E-mail']), Mapping::signature(['nazev', 'e mail']));
        self::assertSame(sha1("nazev\ne mail"), Mapping::signature(['Název', 'E-mail']));
        self::assertNotSame(Mapping::signature(['a', 'b']), Mapping::signature(['b', 'a']));
    }

    public function testMissingRequiredFieldsAreListed(): void
    {
        self::assertSame(['display_name'], new Mapping([0 => 'email'])->missingRequired($this->fields()));
        self::assertSame(['display_name'], new Mapping([0 => null])->missingRequired($this->fields()));
        self::assertSame([], new Mapping([3 => 'display_name'])->missingRequired($this->fields()));
    }

    public function testMappingSurvivesJson(): void
    {
        $mapping = new Mapping([0 => 'email', 2 => null, 5 => 'phone'], ['phone' => ['mobile' => 'mobile']]);

        $stored = json_decode(json_encode($mapping->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        $restored = Mapping::fromArray($stored);

        self::assertEquals($mapping, $restored);
        self::assertSame('email', $restored->fieldFor(0));
        self::assertNull($restored->fieldFor(2));
        self::assertNull($restored->fieldFor(9));
        self::assertSame(['mobile' => 'mobile'], $restored->valueMap('phone'));
        self::assertSame([], $restored->valueMap('email'));
        self::assertEquals(new Mapping(), Mapping::fromArray([]));
    }

    /** @param array<array-key, mixed> $data */
    #[DataProvider('malformed')]
    public function testMalformedStoredMappingIsRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mapping::fromArray($data);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformed(): iterable
    {
        yield 'columns not an array' => [['columns' => 'x']];
        yield 'column index not numeric' => [['columns' => ['a' => 'email']]];
        yield 'field key not text' => [['columns' => [0 => 5]]];
        yield 'value map not an array' => [['valueMaps' => ['phone' => 'x']]];
        yield 'value not text' => [['valueMaps' => ['phone' => ['a' => 1]]]];
    }

    public function testPresetRecognisesHeadersWhateverTheirSpelling(): void
    {
        $preset = $this->preset(['Name' => 'display_name', 'E-mail' => 'email', '2024' => 'phone']);

        self::assertTrue($preset->recognises(['NAME', 'e mail', '2024']));
        self::assertFalse($preset->recognises(['Name', 'Other']));
        self::assertFalse($preset->recognises([]));
    }

    public function testPresetRecognitionThreshold(): void
    {
        $columns = [];
        foreach (range(1, 7) as $i) {
            $columns["col $i"] = 'email';
        }
        $preset = $this->preset($columns);
        $known = array_map(static fn (int $i): string => "Col $i", range(1, 7));

        self::assertTrue($preset->recognises([...$known, 'a', 'b', 'c']), '7 of 10 is enough');
        self::assertFalse($preset->recognises([...array_slice($known, 0, 6), 'a', 'b', 'c', 'd']), '6 of 10 is not');
    }

    /**
     * @param array<int, string>      $headers
     * @param array<int, string|null> $columns the current choice of each column
     */
    private function likely(array $headers, array $columns, string $fieldKey = 'display_name'): ?int
    {
        $labels = static fn (string $key): array => match ($key) {
            'field.display_name' => ['Název', 'Name'],
            'field.email' => ['E-mailová adresa', 'Email address'],
            default => [],
        };
        $field = array_values(array_filter($this->fields(), static fn (FieldDefinition $field): bool => $field->key === $fieldKey))[0];

        return new ColumnMatcher(new ValueNormaliser())->likelyColumn($headers, new Mapping($columns), $field, $labels);
    }

    public function testTheMessageAboutAMissingFieldGoesToTheColumnWhoseHeaderNamesIt(): void
    {
        // "Nazev firmy" is an alias, "Název" the label; a column mapped elsewhere still counts.
        self::assertSame(2, $this->likely(['Telefon', 'Mesto', 'Nazev firmy'], [0 => 'phone', 1 => null, 2 => 'email']));
        self::assertSame(1, $this->likely(['Telefon', 'Název', 'Nazev firmy'], [0 => null, 1 => 'email', 2 => 'email']));
    }

    public function testASimilarHeaderIsEnoughAndTheClosestWins(): void
    {
        // "Nazve" is a transposition of "Nazev" (similar), "Name" is the English label (the same).
        self::assertSame(1, $this->likely(['Nazve', 'Name'], [0 => null, 1 => null]));
        self::assertSame(0, $this->likely(['Nazve', 'Telefon'], [0 => 'phone', 1 => null]));
        self::assertSame(1, $this->likely(['Emial', 'Email'], [0 => null, 1 => null], 'email'));
    }

    public function testAColumnWithoutAHeaderIsPassedOver(): void
    {
        self::assertSame(1, $this->likely(['', 'Name'], [0 => 'email', 1 => 'phone']));
    }

    public function testEqualCandidatesGoToTheLeftmostColumn(): void
    {
        self::assertSame(0, $this->likely(['Name', 'Name'], [0 => 'email', 1 => null]));
    }

    public function testWithoutASimilarHeaderTheFirstColumnLeftOutGetsTheMessage(): void
    {
        // "Nazev xyz" is not similar enough; column 1 is the first not imported.
        self::assertSame(1, $this->likely(['Telefon', 'Mesto', 'Nazev xyz', ''], [0 => 'phone', 1 => null, 2 => null, 3 => null]));
        // A column missing from the mapping is not imported either.
        self::assertSame(1, $this->likely(['Telefon', 'Mesto'], [0 => 'phone']));
    }

    public function testWhenEveryColumnIsImportedTheFirstColumnGetsTheMessage(): void
    {
        self::assertSame(0, $this->likely(['Telefon', 'Mesto'], [0 => 'phone', 1 => 'email']));
        self::assertSame(3, $this->likely([3 => 'Telefon', 4 => 'Mesto'], [3 => 'phone', 4 => 'email']));
    }

    public function testNoColumnsMeansNoSelectForTheMessage(): void
    {
        self::assertNull($this->likely([], []));
    }

    /** @param array<array-key, string|null> $columns */
    private function preset(array $columns): AbstractSourcePreset
    {
        return new class($columns) extends AbstractSourcePreset {
            /** @param array<array-key, string|null> $columns */
            public function __construct(private readonly array $columns)
            {
            }

            public function key(): string
            {
                return 'example';
            }

            public function labelKey(): string
            {
                return 'import.preset.example';
            }

            public function targetKey(): string
            {
                return 'party';
            }

            public function columns(): array
            {
                return $this->columns;
            }

            public function values(): array
            {
                return [];
            }
        };
    }
}
