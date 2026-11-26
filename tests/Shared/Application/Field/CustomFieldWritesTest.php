<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Tests\Shared\Fixtures\Field\StoredFields;
use Foresi\Tests\Shared\Support\FixedActor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The writes of a record's custom fields through its use case: a guest writes only the
 * fields open to guests, a new record needs its required fields, a refusal names its
 * field, and the keys whose value changed come back for the update event.
 */
final class CustomFieldWritesTest extends KernelTestCase
{
    private const string RECORD = 'lent_record';

    private EntityManagerInterface $em;
    private Fields $fields;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->fields = LentFields::lend(self::getContainer(), self::RECORD, ['test_fields_code']);
    }

    public function testTheKeysWhoseValueChangedComeBack(): void
    {
        $id = Id::generate();
        $writes = $this->writes(FixedActor::staff());

        self::assertSame(['test_fields_code', 'test_fields_due'], $writes->write(self::RECORD, $id, ['test_fields_code' => 'A-1', 'test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'test_fields_flag' => null], true));
        $this->em->flush();

        $again = $writes->write(self::RECORD, $id, ['test_fields_code' => 'A-1', 'test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'test_fields_seats' => 4], false);

        self::assertSame(['test_fields_seats'], $again, 'The same date and text are no change.');
        self::assertSame(['test_fields_flag'], $writes->write(self::RECORD, $id, ['test_fields_flag' => false], false), 'No answer becomes "no".');
        self::assertSame([], $writes->write(self::RECORD, $id, [], false));
    }

    public function testAnotherDayAndAnAmountSetOrClearedAreAChange(): void
    {
        $id = Id::generate();
        $writes = $this->writes(FixedActor::staff());
        $writes->write(self::RECORD, $id, ['test_fields_code' => 'A-1', 'test_fields_due' => new \DateTimeImmutable('2027-01-31')], true);
        $this->em->flush();

        self::assertSame(['test_fields_due'], $writes->write(self::RECORD, $id, ['test_fields_due' => new \DateTimeImmutable('2027-02-01')], false));
        $this->em->flush();
        self::assertSame(['test_fields_seats'], $writes->write(self::RECORD, $id, ['test_fields_seats' => 0], false), 'Nothing becomes zero.');
        $this->em->flush();
        self::assertSame(['test_fields_seats'], $writes->write(self::RECORD, $id, ['test_fields_seats' => null], false), 'Zero becomes nothing.');
    }

    public function testARefusalNamesTheKeyItQuotes(): void
    {
        $definitions = array_map(static fn (string $key): FieldDefinition => new FieldDefinition($key, self::RECORD, FieldKind::Text, 'test.field.code', [], 'test-fields'), ['test_size', 'est_sizes', 'test_sizes']);
        $fields = new StoredFields($definitions, refusal: 'The value of "test_sizes" is too long.');

        try {
            new CustomFieldWrites($fields, new VisibleFields($fields, FixedActor::staff()))->write(self::RECORD, Id::generate(), ['test_size' => 'a', 'est_sizes' => 'b', 'test_sizes' => 'c'], false);
            self::fail('The value is refused.');
        } catch (FieldRefused $refused) {
            self::assertSame(['test_sizes', FieldRefused::INVALID, 'The value of "test_sizes" is too long.'], [$refused->key, $refused->translationKey, $refused->getMessage()]);
        }
    }

    public function testAGuestSeesTheFieldsOpenToGuestsAsAList(): void
    {
        $definitions = new VisibleFields($this->fields, FixedActor::guest())->definitions(self::RECORD);

        self::assertSame([0 => 'test_fields_size'], array_map(static fn (FieldDefinition $definition): string => $definition->key, $definitions));
    }

    public function testANewRecordNeedsItsRequiredFields(): void
    {
        $writes = $this->writes(FixedActor::staff());

        try {
            $writes->write(self::RECORD, Id::generate(), ['test_fields_seats' => 4, 'test_fields_code' => ''], true);
            self::fail('A required field left empty is refused.');
        } catch (FieldRefused $refused) {
            self::assertSame(['test_fields_code', FieldRefused::REQUIRED], [$refused->key, $refused->translationKey]);
        }
        self::assertSame([], $writes->write(self::RECORD, Id::generate(), [], false), 'A change does not ask for it.');
    }

    public function testAGuestWritesOnlyTheFieldsOpenToGuests(): void
    {
        $writes = $this->writes(FixedActor::guest());
        $id = Id::generate();

        self::assertSame(['test_fields_size'], $writes->write(self::RECORD, $id, ['test_fields_size' => 'small'], true), 'A guest is not asked for a required field it does not see.');
        $this->expectExceptionObject(new FieldRefused('test_fields_code', FieldRefused::HIDDEN));

        $writes->write(self::RECORD, $id, ['test_fields_code' => 'A-1'], false);
    }

    public function testTheApplicationsOwnWorkWritesEveryField(): void
    {
        self::assertSame(['test_fields_code'], $this->writes(FixedActor::nobody())->write(self::RECORD, Id::generate(), ['test_fields_code' => 'A-1'], true));
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('refusals')]
    public function testARefusalNamesItsField(array $values, string $key, string $reason): void
    {
        try {
            $this->writes(FixedActor::staff())->write(self::RECORD, Id::generate(), $values + ['test_fields_code' => 'A-1'], false);
            self::fail('The value is refused.');
        } catch (FieldRefused $refused) {
            self::assertSame([$key, $reason], [$refused->key, $refused->translationKey]);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'an unknown key' => [['test_fields_nothing' => 'x'], 'test_fields_nothing', FieldRefused::UNKNOWN];
        yield 'an option it lacks' => [['test_fields_size' => 'huge'], 'test_fields_size', FieldRefused::INVALID];
        yield 'a fraction of a whole number' => [['test_fields_seats' => '1.5'], 'test_fields_seats', FieldRefused::INVALID];
        yield 'a text too long' => [['test_fields_seats' => 2, 'test_fields_code' => str_repeat('x', 21)], 'test_fields_code', FieldRefused::INVALID];
    }

    private function writes(ActorRole $actor): CustomFieldWrites
    {
        return new CustomFieldWrites($this->fields, new VisibleFields($this->fields, $actor));
    }
}
