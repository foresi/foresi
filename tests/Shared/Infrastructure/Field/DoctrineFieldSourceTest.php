<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Shared\Application\Field\FieldRecords;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Infrastructure\Field\DoctrineFieldSource;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Foresi\Tests\Shared\Fixtures\Field\FixtureModules;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNote;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteExtension;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteFields;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The custom fields of the record extensions: their definitions from #[CustomField],
 * their values in the extension's row, which exists only while it holds one, and the
 * audit of that row under the record.
 */
final class DoctrineFieldSourceTest extends KernelTestCase
{
    private const string RECORD = FixtureNoteExtension::RECORD;

    private EntityManagerInterface $em;
    private DoctrineFieldSource $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->source = self::getContainer()->get(DoctrineFieldSource::class);
    }

    public function testEveryPropertyWithTheAttributeIsAFieldKeyedByItsModule(): void
    {
        $definitions = $this->definitions();

        self::assertSame([
            'test_fields_code', 'test_fields_long_note', 'test_fields_seats', 'test_fields_weight', 'test_fields_ratio', 'test_fields_due', 'test_fields_seen_at',
            'test_fields_flag', 'test_fields_site', 'test_fields_size', 'test_fields_tags', 'test_fields_contact', 'test_fields_owner',
        ], array_keys($definitions));
        $code = $definitions['test_fields_code'];
        self::assertSame([self::RECORD, FieldKind::Text, 'test.field.code', [], FixtureModules::ID], [$code->record, $code->kind, $code->label, $code->options, $code->module]);
        self::assertSame([true, true, false, false, true, true, true, true], [$code->filterable, $code->sortable, $code->required, $code->guests, $code->api, $code->template, $code->import, $code->searchable]);
        self::assertSame(['small' => 'test.size.small', 'large' => 'test.size.large'], $definitions['test_fields_size']->options);
        self::assertTrue($definitions['test_fields_size']->guests);
        self::assertSame([], $this->source->definitions('contact'), 'No module adds fields to a contact in the tests.');
    }

    public function testValuesAreWrittenAndReadInTheirTypes(): void
    {
        $note = $this->note();
        $this->source->write(self::RECORD, $note, [
            'test_fields_code' => 'A-1', 'test_fields_seats' => '7', 'test_fields_weight' => 12.5, 'test_fields_ratio' => '0.25',
            'test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'test_fields_seen_at' => new \DateTimeImmutable('2026-11-25 10:30:00'),
            'test_fields_flag' => false, 'test_fields_tags' => ['red', 'blue'],
        ]);
        $this->em->flush();
        $this->em->clear();

        $values = $this->source->read(self::RECORD, [$note, 'not-an-id']);

        self::assertSame([$note], array_keys($values));
        self::assertSame(['test_fields_code', 'test_fields_seats', 'test_fields_weight', 'test_fields_ratio', 'test_fields_due', 'test_fields_seen_at', 'test_fields_flag', 'test_fields_tags'], array_keys($values[$note]));
        self::assertSame('A-1', $values[$note]['test_fields_code']);
        self::assertSame(7, $values[$note]['test_fields_seats']);
        // A decimal reads as text: "12.5" on SQLite, "12.50" on PostgreSQL.
        self::assertIsString($values[$note]['test_fields_weight']);
        self::assertSame(12.5, (float) $values[$note]['test_fields_weight']);
        self::assertSame(0.25, $values[$note]['test_fields_ratio']);
        self::assertEquals(new \DateTimeImmutable('2027-01-31'), $values[$note]['test_fields_due']);
        self::assertEquals(new \DateTimeImmutable('2026-11-25 10:30:00'), $values[$note]['test_fields_seen_at']);
        self::assertFalse($values[$note]['test_fields_flag']);
        self::assertSame(['red', 'blue'], $values[$note]['test_fields_tags']);
        self::assertSame([], $this->source->read(self::RECORD, []));
        self::assertSame([], $this->source->read('contact', [$note]));
    }

    public function testAWriteChangesTheRowAndTheLastValueRemovesIt(): void
    {
        $note = $this->note();
        $this->source->write(self::RECORD, $note, ['test_fields_code' => null]);
        $this->em->flush();
        self::assertSame(0, $this->rows(), 'Nothing to keep makes no row.');

        $this->source->write(self::RECORD, $note, ['test_fields_code' => 'A-1', 'test_fields_seats' => 3]);
        $this->em->flush();
        $this->source->write(self::RECORD, $note, ['test_fields_code' => 'B-2']);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(['test_fields_code' => 'B-2', 'test_fields_seats' => 3], $this->source->read(self::RECORD, [$note])[$note]);

        $this->source->write(self::RECORD, $note, ['test_fields_code' => null]);
        $this->em->flush();
        self::assertSame(1, $this->rows(), 'A value is left.');
        $this->source->write(self::RECORD, $note, ['test_fields_seats' => null]);
        $this->em->flush();
        self::assertSame(0, $this->rows());
    }

    public function testTheExtensionIsAuditedUnderItsRecord(): void
    {
        $note = $this->note();
        $this->source->write(self::RECORD, $note, ['test_fields_code' => 'A-1']);
        $this->em->flush();
        $this->source->write(self::RECORD, $note, ['test_fields_code' => 'B-2']);
        $this->em->flush();

        /** @var list<AuditEntry> $entries */
        $entries = $this->em->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.entityClass = :class ORDER BY e.occurredAt, e.id')->setParameter('class', FixtureNoteFields::class)->getResult();

        self::assertCount(2, $entries);
        self::assertSame([FixtureNote::class, $note, $note], [$entries[0]->rootClass(), $entries[0]->rootId(), $entries[0]->entityId()]);
        self::assertSame([AuditAction::Created, AuditAction::Updated], [$entries[0]->action(), $entries[1]->action()]);
        self::assertSame(['code' => ['A-1', 'B-2']], $entries[1]->changes());
    }

    /**
     * @param array<string, mixed> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testAValueItsColumnDoesNotTakeIsRefused(array $values, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->source->write(self::RECORD, $this->note(), $values);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refused(): iterable
    {
        yield 'a key of no field' => [['test_fields_nothing' => 'x'], 'The fixture_note has no custom field "test_fields_nothing".'];
        yield 'a text too long' => [['test_fields_code' => str_repeat('x', 21)], 'The field "test_fields_code" takes a text of at most 20 characters.'];
        yield 'a fraction for a whole number' => [['test_fields_seats' => '1.5'], 'The field "test_fields_seats" takes a whole number.'];
        yield 'a word for a decimal' => [['test_fields_weight' => 'heavy'], 'The field "test_fields_weight" takes a number.'];
        yield 'a word for a float' => [['test_fields_ratio' => 'half'], 'The field "test_fields_ratio" takes a number.'];
    }

    public function testTheExpressionReadsTheExtensionsRowOfTheRecord(): void
    {
        $expression = $this->source->expression(self::RECORD, 'test_fields_due', 'n');

        self::assertSame(FixtureNoteFields::class, $expression->entity);
        self::assertSame('cf_test_fields_due', $expression->alias);
        self::assertSame('cf_test_fields_due.id = n.id', $expression->correlation);
        self::assertSame('cf_test_fields_due.due', $expression->column);
        self::assertSame(FixtureNoteFields::class.' cf_test_fields_due WHERE cf_test_fields_due.id = n.id', $expression->within());
        self::assertSame('LEFT JOIN '.FixtureNoteFields::class.' cf_test_fields_due WITH cf_test_fields_due.id = n.id', $expression->join());
    }

    public function testAnExpressionOfNoFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The fixture_note has no custom field "test_fields_nothing".');

        $this->source->expression(self::RECORD, 'test_fields_nothing', 'n');
    }

    public function testTheDefinitionsAreKeptInTheCacheOutsideDebug(): void
    {
        $cache = new ArrayAdapter();
        // The installed modules too: their extensions, such as Timesheet's of a project, are mapped as well.
        $modules = FixtureModules::graph(self::getContainer()->get(InstalledModules::class));
        $records = self::getContainer()->get(FieldRecords::class);
        $source = new DoctrineFieldSource($this->em, $records, $modules, $cache);

        $definitions = $source->definitions(self::RECORD);

        self::assertCount(13, $definitions);
        self::assertTrue($cache->hasItem('foresi.shared.custom_fields'));
        $cache->clear();
        self::assertCount(13, $source->definitions(self::RECORD), 'Read once a process.');
        self::assertFalse($cache->hasItem('foresi.shared.custom_fields'));
        self::assertCount(13, new DoctrineFieldSource($this->em, $records, $modules, $cache, true)->definitions(self::RECORD));
        self::assertFalse($cache->hasItem('foresi.shared.custom_fields'), 'Debug reads the code every time.');
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function definitions(): array
    {
        $definitions = [];
        foreach ($this->source->definitions(self::RECORD) as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return $definitions;
    }

    private function note(): string
    {
        $note = new FixtureNote('Example note');
        $this->em->persist($note);
        $this->em->flush();

        return $note->id();
    }

    private function rows(): int
    {
        $rows = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_fixture_note_fields');
        self::assertIsNumeric($rows);

        return (int) $rows;
    }
}
