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
            'secret' => null,
        ], $payload->of(self::RECORD, self::RECORD_ID));
        self::assertSame(['id' => 'x', 'custom_fields' => array_fill_keys(['code', 'seats', 'weight', 'due', 'seen', 'flag', 'site', 'size', 'tags', 'contact', 'secret'], null)], $payload->attachOne(self::RECORD, ['id' => 'x']));
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
        ], false);

        self::assertIsArray($read);
        self::assertSame('2026-12-01', self::date($read['due'])->format('Y-m-d'));
        self::assertSame((new \DateTimeImmutable('2026-11-26T14:30:00+01:00'))->getTimestamp(), self::date($read['seen'])->getTimestamp());
        self::assertSame(date_default_timezone_get(), self::date($read['seen'])->getTimezone()->getName());
        unset($read['due'], $read['seen']);
        self::assertSame(['code' => 'A-1', 'seats' => 4, 'weight' => 12.5, 'flag' => true, 'site' => 'https://example.com/a', 'size' => 'large', 'tags' => ['red'], 'contact' => self::CONTACT], $read);
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
        yield 'a date-time without an offset' => ['seen', '2026-11-26T14:30:00'];
        yield 'a number as text' => ['seats', '4'];
        yield 'an address of another scheme' => ['site', 'ftp://example.com'];
        yield 'a contact that is no id' => ['contact', 'Example Customer'];
        yield 'a contact of no one' => ['contact', '0192f1a4-0000-7000-8000-0000000000c2'];
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

    public function testATokenSeesNoFieldOffTheApiAndAGuestOnlyThoseOpenToGuests(): void
    {
        $keys = static fn (VisibleFields $visible): array => array_map(static fn (FieldDefinition $definition): string => $definition->key, $visible->definitions(self::RECORD));
        $fields = new StoredFields(self::definitions());

        self::assertContains('secret', $keys(new VisibleFields($fields, FixedActor::staff())), 'The screens show it.');
        self::assertContains('secret', $keys(new VisibleFields($fields, FixedActor::staff(), self::scopes(false))), 'Without a token, as on the screens.');
        self::assertNotContains('secret', $keys(new VisibleFields($fields, FixedActor::staff(), self::scopes(true))));
        self::assertNull(new VisibleFields($fields, FixedActor::staff(), self::scopes(true))->definition(self::RECORD, 'secret'));
        self::assertSame(['size'], $keys(new VisibleFields($fields, FixedActor::guest(), self::scopes(true))));

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
        $fields = new StoredFields(self::definitions(), $values);
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

        return new CustomFieldPayload($fields, new VisibleFields($fields, $actor ?? FixedActor::staff(), self::scopes($token)), new ReferenceNames([$contacts]));
    }

    /**
     * @return list<FieldDefinition>
     */
    private static function definitions(): array
    {
        $field = self::field(...);

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
            $field('secret', FieldKind::Text, api: false),
        ];
    }

    /**
     * @param array<string, string> $options
     */
    private static function field(string $key, FieldKind $kind, array $options = [], bool $guests = false, bool $api = true): FieldDefinition
    {
        return new FieldDefinition($key, self::RECORD, $kind, 'test.field.'.$key, $options, 'test-fields', guests: $guests, api: $api);
    }

    private static function scopes(bool $token): ScopeGuard
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

    private static function date(mixed $value): \DateTimeImmutable
    {
        self::assertInstanceOf(\DateTimeImmutable::class, $value);

        return $value;
    }
}
