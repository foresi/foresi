<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Foresi\Shared\Application\Field\FieldRecords;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Infrastructure\Field\DoctrineFieldSource;
use Foresi\Tests\Shared\Fixtures\Field\FixtureModules;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNote;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteFields;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * What an extension must be for its fields to count: each refusal names the class
 * and the property, when the definitions are first read.
 */
final class DoctrineFieldSourceRulesTest extends TestCase
{
    /**
     * @param list<class-string> $classes
     */
    #[DataProvider('refused')]
    public function testAnExtensionThatBreaksARuleIsRefused(array $classes, string $message): void
    {
        $source = new DoctrineFieldSource($this->em($classes), new FieldRecords([new FixtureNoteRecord()]), new ModuleGraph([FixtureModules::manifest(__NAMESPACE__.'\\Invalid\\')]), new ArrayAdapter(), true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $source->definitions('fixture_note');
    }

    /**
     * @return iterable<string, array{list<class-string>, string}>
     */
    public static function refused(): iterable
    {
        $where = static fn (string $class): string => $class.'::$value';

        yield 'a column that is not nullable' => [[Invalid\NotNullable::class], $where(Invalid\NotNullable::class).' is a nullable column'];
        yield 'a column of another type' => [[Invalid\WrongType::class], $where(Invalid\WrongType::class).' is a text field, a column of type string, not integer.'];
        yield 'a text with options' => [[Invalid\TextWithOptions::class], $where(Invalid\TextWithOptions::class).': a select and a multi-select list their options, and only they do.'];
        yield 'a select without options' => [[Invalid\SelectWithoutOptions::class], $where(Invalid\SelectWithoutOptions::class).': a select and a multi-select list their options, and only they do.'];
        yield 'an option key in capitals' => [[Invalid\BadOptionKey::class], $where(Invalid\BadOptionKey::class).': an option has a key of lowercase letters, digits and underscores, and a label, not "Big".'];
        yield 'an option without a label' => [[Invalid\EmptyOptionLabel::class], $where(Invalid\EmptyOptionLabel::class).': an option has a key of lowercase letters, digits and underscores, and a label, not "big".'];
        yield 'a sortable long text' => [[Invalid\SortableLongText::class], $where(Invalid\SortableLongText::class).': a long_text field does not sort.'];
        yield 'a searchable number' => [[Invalid\SearchableNumber::class], $where(Invalid\SearchableNumber::class).': only a text, a long text or a URL is searchable.'];
        yield 'no label' => [[Invalid\EmptyLabel::class], $where(Invalid\EmptyLabel::class).' needs a label, a translation key.'];
        yield 'a column that is no field' => [[Invalid\UnmarkedColumn::class], $where(Invalid\UnmarkedColumn::class).' is mapped but no custom field'];
        yield 'a field that is no column' => [[Invalid\UnmappedField::class], $where(Invalid\UnmappedField::class).' has #[CustomField] but is no mapped column of its own.'];
        yield 'a field on the id' => [[Invalid\FieldOnId::class], Invalid\FieldOnId::class.'::$id has #[CustomField] but is no mapped column of its own.'];
        yield 'an association' => [[Invalid\WithAssociation::class, FixtureNote::class], Invalid\WithAssociation::class.' holds custom fields only, no associations'];
        yield 'an id that is no guid' => [[Invalid\IntegerId::class], Invalid\IntegerId::class.' has the id of the fixture_note as its one id, a guid column.'];
        yield 'no record' => [[Invalid\NoRecord::class], Invalid\NoRecord::class.' implements RecordExtension but none of the extension interfaces of the records'];
        yield 'two fields of one key' => [[Invalid\FirstCode::class, Invalid\SecondCode::class], 'Two custom fields of the fixture_note have the key "test_fields_code"; the second is '.Invalid\SecondCode::class.'::$code.'];
    }

    public function testAnExtensionOfNoInstalledModuleIsRefused(): void
    {
        $source = new DoctrineFieldSource($this->em([FixtureNoteFields::class]), new FieldRecords([new FixtureNoteRecord()]), new ModuleGraph([]), new ArrayAdapter(), true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(FixtureNoteFields::class.' belongs to no installed module, so its custom fields have no key.');

        $source->definitions('fixture_note');
    }

    public function testAnEntityThatIsNoExtensionHasNoFields(): void
    {
        $source = new DoctrineFieldSource($this->em([FixtureNote::class]), new FieldRecords([new FixtureNoteRecord()]), new ModuleGraph([FixtureModules::manifest()]), new ArrayAdapter(), true);

        self::assertSame([], $source->definitions('fixture_note'));
    }

    /**
     * An entity manager of an in-memory database that maps these classes only.
     *
     * @param list<class-string> $classes
     */
    private function em(array $classes): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);
        $config->enableNativeLazyObjects(true);
        $driver = new AttributeDriver([]);
        $config->setMetadataDriverImpl(new readonly class($driver, $classes) implements MappingDriver {
            /**
             * @param list<class-string> $classes
             */
            public function __construct(private AttributeDriver $driver, private array $classes)
            {
            }

            public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
            {
                \PHPUnit\Framework\Assert::assertInstanceOf(\Doctrine\ORM\Mapping\ClassMetadata::class, $metadata, 'The ORM passes its own metadata.');
                $this->driver->loadMetadataForClass($className, $metadata);
            }

            public function getAllClassNames(): array
            {
                return $this->classes;
            }

            public function isTransient(string $className): bool
            {
                return !\in_array($className, $this->classes, true);
            }
        });

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
    }
}
