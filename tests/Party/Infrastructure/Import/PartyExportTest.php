<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Import;

use App\Import\Application\ExportRecords;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Domain\ColumnMatcher;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\MatchReason;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\ValueNormaliser;
use App\Import\Infrastructure\Source\OpenSpoutSourceReader;
use App\Party\Application\CreateParty;
use App\Party\Domain\Address;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PartyExportTest extends KernelTestCase
{
    private string $path;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());
        $this->path = sys_get_temp_dir().'/foresi-party-export-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{SourceFormat, string}>
     */
    public static function formatsAndLocales(): iterable
    {
        yield 'CSV in English' => [SourceFormat::Csv, 'en'];
        yield 'XLSX in English' => [SourceFormat::Xlsx, 'en'];
        yield 'CSV in Czech' => [SourceFormat::Csv, 'cs'];
        yield 'XLSX in Czech' => [SourceFormat::Xlsx, 'cs'];
    }

    #[DataProvider('formatsAndLocales')]
    public function testTheExportedFileProposesEveryColumnAgainByItsName(SourceFormat $format, string $locale): void
    {
        $id = $this->createParty('Example Company s.r.o.')->id();
        $this->createParty('=HYPERLINK("http://example.com")');
        self::getContainer()->get(TranslatorInterface::class)->setLocale($locale);

        self::getContainer()->get(ExportRecords::class)->write('party', [$id], $format, $this->path);

        $reader = new OpenSpoutSourceReader();
        $inspection = $reader->inspect($this->path);
        $fields = self::getContainer()->get(\App\Import\Application\Target\TargetRegistry::class)->get('party')->fields();
        self::assertCount(\count($fields), $inspection->headers);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $proposals = new ColumnMatcher(new ValueNormaliser())->propose(
            $inspection->headers,
            [],
            $fields,
            null,
            null,
            static fn (string $labelKey): array => array_values(array_unique([$translator->trans($labelKey, [], 'messages', 'cs'), $translator->trans($labelKey, [], 'messages', 'en')])),
        );

        self::assertSame(array_map(static fn ($field): string => $field->key, $fields), array_map(static fn (ColumnProposal $proposal): ?string => $proposal->fieldKey, $proposals));
        self::assertSame(array_fill(0, \count($fields), MatchReason::SameName), array_map(static fn (ColumnProposal $proposal): MatchReason => $proposal->reason, $proposals));
        $rows = [...$reader->rows($this->path, new SourceSettings($format, headerRow: 1))];
        self::assertCount(1, $rows);
        self::assertContains('Example Company s.r.o.', $rows[0]);
    }

    #[DataProvider('formats')]
    public function testAPhoneWithAPlusAndANameWithAnEqualsSignComeBackAsTheyWere(SourceFormat $format): void
    {
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, '=Example Trade', null, null, 'info@example.com', '+420 123 456 789', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));

        self::getContainer()->get(ExportRecords::class)->write('party', [$party->id()], $format, $this->path);

        $reader = new OpenSpoutSourceReader();
        $headers = $reader->inspect($this->path)->headers;
        $row = [...$reader->rows($this->path, new SourceSettings($format, headerRow: 1))][0];
        $values = array_combine($headers, $row);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame('+420 123 456 789', $values[$translator->trans('import.field.party.phone')]);
        self::assertSame('=Example Trade', $values[$translator->trans('import.field.party.display_name')]);
        // CSV protects the cell with an apostrophe, XLSX writes the text as it is.
        self::assertSame(SourceFormat::Csv === $format, str_contains((string) file_get_contents($this->path), "'+420"));
    }

    public function testTheKindNamesOfTheExportReadBackInBothLanguages(): void
    {
        $kind = array_values(array_filter(self::getContainer()->get(\App\Import\Application\Target\TargetRegistry::class)->get('party')->fields(), static fn ($field): bool => 'kind' === $field->key))[0];
        $normaliser = new ValueNormaliser();

        foreach (['Firma' => 'company', 'company' => 'company', 'Company' => 'company', 'OSVČ' => 'sole_trader', 'osvc' => 'sole_trader', 'Sole trader' => 'sole_trader', 'Osoba' => 'person', 'person' => 'person'] as $text => $key) {
            self::assertSame($key, $normaliser->normalise($kind, $text, new NormalisationOptions(), []), $text);
        }
        self::assertInstanceOf(\App\Import\Domain\RowError::class, $normaliser->normalise($kind, 'Spaceship', new NormalisationOptions(), []));
    }

    /**
     * @return iterable<string, array{SourceFormat}>
     */
    public static function formats(): iterable
    {
        yield 'CSV' => [SourceFormat::Csv];
        yield 'XLSX' => [SourceFormat::Xlsx];
    }

    public function testAFormulaInANameIsNeutralised(): void
    {
        $id = $this->createParty('=HYPERLINK("http://example.com")')->id();

        self::getContainer()->get(ExportRecords::class)->write('party', [$id], SourceFormat::Csv, $this->path);

        self::assertStringContainsString("'=HYPERLINK", (string) file_get_contents($this->path));
    }

    private function createParty(string $name): \App\Party\Domain\Party
    {
        return self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, $name, null, null, 'info@example.com', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
    }
}
