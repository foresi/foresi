<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\Field;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Tests\Shared\Fixtures\Field\StoredFields;
use Foresi\Tests\Shared\Support\FixedActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The custom fields as JSON carries them (ADR 0045): each kind written and read in its
 * JSON shape, every field the actor sees with null for none, and a value the kind does
 * not take refused at its key. Through an API token a field off the API is not seen,
 * and a guest sees only the fields open to guests.
 */
final class CustomFieldPayloadTest extends TestCase
{
    private const string RECORD = 'lent_record';
    private const string RECORD_ID = '0192f1a4-0000-7000-8000-000000000101';
    private const string CONTACT = '0192f1a4-0000-7000-8000-0000000000c1';
    private const string USER = '0192f1a4-0000-7000-8000-0000000000e1';

    public function testEachKindIsWrittenInItsJsonShape(): void
    {
        $payload = $this->payload([self::RECORD_ID => [
            'code' => 'A-1',
            'seats' => 4,
            'weight' => '12.50',
            'due' => new \DateTimeImmutable('2026-12-01'),
            'seen' => new \DateTimeImmutable('2026-11-26T14:30:00+01:00'),
            'flag' => false,
            'size' => 'large',
            'tags' => [1 => 'red', 2 => 'blue'],
            'contact' => self::CONTACT,
        ]]);

        self::assertSame([
            'code' => 'A-1',
            'seats' => 4,
            'weight' => 12.5,
            'due' => '2026-12-01',
            'seen' => '2026-11-26T14:30:00+01:00',
            'flag' => false,
            'site' => null,
            'size' => 'large',
            'tags' => ['red', 'blue'],
            'contact' => self::CONTACT,
            'owner' => null,
            'secret' => null,
        ], $payload->of(self::RECORD, self::RECORD_ID));
        self::assertSame(['id' => 'x', 'custom_fields' => array_fill_keys(['code', 'seats', 'weight', 'due', 'seen', 'flag', 'site', 'size', 'tags', 'contact', 'owner', 'secret'], null)], $payload->attachOne(self::RECORD, ['id' => 'x']));
        self::assertEquals(new \stdClass(), $payload->of('other_record', self::RECORD_ID), 'A record kind without fields has an empty object.');
    }

    public function testWhatAWriteSendsIsReadByKind(): void
    {
        $read = $this->payload()->read(self::RECORD, [
            'code' => 'A-1',
            'seats' => 4,
            'weight' => 12.5,
            'due' => '2026-12-01',
            'seen' => '2026-11-26T14:30:00+01:00',
            'flag' => true,
            'site' => 'https://example.com/a',
            'size' => 'large',
            'tags' => ['red'],
            'contact' => strtoupper(self::CONTACT),
            'owner' => strtoupper(self::USER),
        ], false);

        self::assertIsArray($read);
        self::assertSame('2026-12-01', $this->date($read['due'])->format('Y-m-d'));
        self::assertSame(new \DateTimeImmutable('2026-11-26T14:30:00+01:00')->getTimestamp(), $this->date($read['seen'])->getTimestamp());
        self::assertSame(date_default_timezone_get(), $this->date($read['seen'])->getTimezone()->getName());
        unset($read['due'], $read['seen']);
        self::assertSame(['code' => 'A-1', 'seats' => 4, 'weight' => 12.5, 'flag' => true, 'site' => 'https://example.com/a', 'size' => 'large', 'tags' => ['red'], 'contact' => self::CONTACT, 'owner' => self::USER], $read);
        self::assertSame(['site' => 'HTTPS://example.com/a'], $this->payload()->read(self::RECORD, ['site' => 'HTTPS://example.com/a'], false), 'The scheme is read in any case.');
        self::assertSame(['due' => null, 'site' => null, 'contact' => null], $this->payload()->read(self::RECORD, ['due' => '', 'site' => null, 'contact' => ''], false));
    }

    public function testLeftOutAnUpdateWritesNothingAndACreateWritesNone(): void
    {
        self::assertNull($this->payload()->read(self::RECORD, new Omitted(), false));
        self::assertSame([], $this->payload()->read(self::RECORD, new Omitted(), true));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'a date of another shape' => ['due', '01.12.2026'];
        yield 'a date that does not exist' => ['due', '2026-02-30'];
        yield 'a date that is no text' => ['due', 20261201];
        yield 'a date-time without an offset' => ['seen', '2026-11-26T14:30:00'];
        yield 'a date-time on a day that does not exist' => ['seen', '2026-02-30T14:30:00+01:00'];
        yield 'a number as text' => ['seats', '4'];
        yield 'an address of another scheme' => ['site', 'ftp://example.com'];
        yield 'a contact that is no id' => ['contact', 'Example Customer'];
        yield 'a contact of no one' => ['contact', '0192f1a4-0000-7000-8000-0000000000c2'];
        yield 'a user that is no id' => ['owner', 'Example User'];
        yield 'a user id after other text' => ['owner', 'x0192f1a4-0000-7000-8000-0000000000e1'];
        yield 'a user id before other text' => ['owner', '0192f1a4-0000-7000-8000-0000000000e1x'];
        yield 'a user that is no text' => ['owner', 1];
        yield 'options that are no list' => ['tags', ['a' => 'red']];
    }

    #[DataProvider('refused')]
    public function testAValueTheKindDoesNotTakeIsRefusedAtItsKey(string $key, mixed $value): void
    {
        try {
            $this->payload()->read(self::RECORD, [$key => $value], false);
            self::fail('The value is refused.');
        } catch (FieldRefused $refused) {
            self::assertSame([$key, FieldRefused::INVALID, 'invalid_value', 'customFields.'.$key], [$refused->key, $refused->translationKey, $refused->reason(), $refused->field()]);
        }
    }

    public function testAKeyOfNoFieldIsUnknown(): void
    {
        $this->expectExceptionObject(new FieldRefused('nope', FieldRefused::UNKNOWN));

        $this->payload()->read(self::RECORD, ['nope' => 1], true);
    }

    public function testAListInPlaceOfAnObjectIsAKeyOfNoField(): void
    {
        $this->expectExceptionObject(new FieldRefused('0', FieldRefused::UNKNOWN));

        $this->payload()->read(self::RECORD, ['A-1'], true);
    }

    public function testAReferenceRefusalNamesWhatItTakes(): void
    {
        foreach (['contact' => 'the id of a contact.', 'owner' => 'the id of a user.'] as $key => $what) {
            try {
                $this->payload()->read(self::RECORD, [$key => 'Example'], false);
                self::fail('The value is refused.');
            } catch (FieldRefused $refused) {
                self::assertStringEndsWith($what, $refused->getMessage());
            }
        }
    }

    public function testAStoredValueIsEncodedOnlyAsItsKindWritesIt(): void
    {
        $fields = array_column(array_map(static fn (FieldDefinition $definition): array => [$definition->key, $definition], $this->definitions()), 1, 0);
        $moment = new \DateTimeImmutable('2026-11-26T14:30:00+01:00');

        self::assertSame([4, -3, 1000.0, 12.5], array_map(static fn (string $number): mixed => CustomFieldPayload::encoded($fields['seats'], $number), ['4', '-3', '1E3', '12.50']));
        self::assertSame('2026-11-26T14:30:00', CustomFieldPayload::encoded($fields['seen'], '2026-11-26T14:30:00'), 'A text of a date-time field stays as it is.');
        self::assertSame($moment, CustomFieldPayload::encoded($fields['code'], $moment), 'A text field does not format a moment.');
        self::assertSame('red', CustomFieldPayload::encoded($fields['tags'], 'red'), 'A multi-select with one option as text keeps it.');
        self::assertSame([2 => 'x'], CustomFieldPayload::encoded($fields['code'], [2 => 'x']), 'Only a multi-select is made a list.');
        self::assertNull(CustomFieldPayload::encoded($fields['due'], null));
    }

    public function testTheValuesOfTheRowsAreReadInOneGo(): void
    {
        $loads = $this->loads();
        $payload = new CustomFieldPayload($fields = new StoredFields($this->definitions(), [], null, $loads), new VisibleFields($fields, FixedActor::staff(), $this->scopes(false)), new ReferenceNames([]));

        $payload->attach(self::RECORD, [['id' => 'b'], ['name' => 'no id'], ['id' => 7], ['id' => 'a']]);
        $payload->attach(self::RECORD, [['name' => 'no id']]);
        $payload->attach(self::RECORD, []);

        self::assertSame([['b', 'a']], $loads->getArrayCopy());
    }

    public function testATokenSeesNoFieldOffTheApiAndAGuestOnlyThoseOpenToGuests(): void
    {
        $keys = static fn (VisibleFields $visible): array => array_map(static fn (FieldDefinition $definition): string => $definition->key, $visible->definitions(self::RECORD));
        $fields = new StoredFields($this->definitions());

        self::assertContains('secret', $keys(new VisibleFields($fields, FixedActor::staff())), 'The screens show it.');
        self::assertContains('secret', $keys(new VisibleFields($fields, FixedActor::staff(), $this->scopes(false))), 'Without a token, as on the screens.');
        self::assertNotContains('secret', $keys(new VisibleFields($fields, FixedActor::staff(), $this->scopes(true))));
        self::assertNull(new VisibleFields($fields, FixedActor::staff(), $this->scopes(true))->definition(self::RECORD, 'secret'));
        self::assertSame(['size'], $keys(new VisibleFields($fields, FixedActor::guest(), $this->scopes(true))));

        try {
            $this->payload(actor: FixedActor::staff(), token: true)->read(self::RECORD, ['secret' => 'x'], false);
            self::fail('A field off the API is refused.');
        } catch (FieldRefused $refused) {
            self::assertSame(['secret', 'unknown_field'], [$refused->key, $refused->reason()]);
        }
        self::assertSame(['size' => null], $this->payload(actor: FixedActor::guest(), token: true)->of(self::RECORD, self::RECORD_ID));
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function payload(array $values = [], ?ActorRole $actor = null, bool $token = false): CustomFieldPayload
    {
        $fields = new StoredFields($this->definitions(), $values);
        $contacts = new class implements FieldReferenceNames {
            public function kind(): FieldKind
            {
                return FieldKind::Contact;
            }

            public function names(array $ids): array
            {
                return array_intersect_key(['0192f1a4-0000-7000-8000-0000000000c1' => 'Example Customer a.s.'], array_flip($ids));
            }

            public function matches(string $text, int $limit): array
            {
                return [];
            }

            public function url(string $id): ?string
            {
                return null;
            }
        };

        return new CustomFieldPayload($fields, new VisibleFields($fields, $actor ?? FixedActor::staff(), $this->scopes($token)), new ReferenceNames([$contacts]));
    }

    /**
     * @return list<FieldDefinition>
     */
    private function definitions(): array
    {
        $field = $this->field(...);

        return [
            $field('code', FieldKind::Text),
            $field('seats', FieldKind::Number),
            $field('weight', FieldKind::Number),
            $field('due', FieldKind::Date),
            $field('seen', FieldKind::DateTime),
            $field('flag', FieldKind::Boolean),
            $field('site', FieldKind::Url),
            $field('size', FieldKind::Select, ['small' => 'test.size.small', 'large' => 'test.size.large'], guests: true),
            $field('tags', FieldKind::MultiSelect, ['red' => 'test.tag.red', 'blue' => 'test.tag.blue']),
            $field('contact', FieldKind::Contact),
            $field('owner', FieldKind::User),
            $field('secret', FieldKind::Text, api: false),
        ];
    }

    /**
     * @param array<string, string> $options
     */
    private function field(string $key, FieldKind $kind, array $options = [], bool $guests = false, bool $api = true): FieldDefinition
    {
        return new FieldDefinition($key, self::RECORD, $kind, 'test.field.'.$key, $options, 'test-fields', guests: $guests, api: $api);
    }

    /**
     * @return \ArrayObject<int, list<string>>
     */
    private function loads(): \ArrayObject
    {
        return new \ArrayObject();
    }

    private function scopes(bool $token): ScopeGuard
    {
        return new readonly class($token) implements ScopeGuard {
            public function __construct(private bool $token)
            {
            }

            public function require(string $scope): void
            {
                if (!$this->token) {
                    throw new \DomainException('No token.');
                }
            }

            public function allows(string $scope): bool
            {
                return $this->token;
            }
        };
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        self::assertInstanceOf(\DateTimeImmutable::class, $value);

        return $value;
    }
}
