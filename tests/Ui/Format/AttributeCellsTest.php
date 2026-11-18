<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Format;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Format\AttributeCells;
use Foresi\Ui\Format\MoneyText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;

final class AttributeCellsTest extends TestCase
{
    /**
     * @return iterable<string, array{AttributeType, mixed, mixed}>
     */
    public static function values(): iterable
    {
        yield 'text' => [AttributeType::Text, 'Example Company', 'Example Company'];
        yield 'reference by name' => [AttributeType::Reference, 'Example Firm', 'Example Firm'];
        yield 'a whole number grouped' => [AttributeType::Number, 12500, "12\u{a0}500"];
        yield 'a decimal with the separator of the language' => [AttributeType::Number, '1.5', '1,5'];
        yield 'money with its currency' => [AttributeType::Money, Money::ofMinor(121050, Currency::CZK), "1\u{a0}210,50\u{a0}Kč"];
        yield 'money in another currency' => [AttributeType::Money, Money::ofMinor(-500, Currency::EUR), "-5,00\u{a0}EUR"];
        yield 'a date in the format of the language' => [AttributeType::Date, new \DateTimeImmutable('2026-11-18'), '18. 11. 2026'];
        yield 'a datetime as it reads' => [AttributeType::DateTime, new \DateTimeImmutable('2026-11-18 09:05', new \DateTimeZone('Europe/Prague')), '18. 11. 2026 9:05'];
        yield 'an enum by the label of its value' => [AttributeType::Enum, Currency::CZK, 'Czech crowns'];
        yield 'an enum value without a label' => [AttributeType::Enum, 'other', 'other'];
        yield 'yes' => [AttributeType::Boolean, true, 'Ano'];
        yield 'no' => [AttributeType::Boolean, false, 'Ne'];
        yield 'a country by its name' => [AttributeType::Country, 'DE', 'Německo'];
        yield 'an e-mail as a link' => [AttributeType::Email, 'billing@example.com', ['links' => [['text' => 'billing@example.com', 'href' => 'mailto:billing@example.com', 'opens' => 'page']], 'plain' => true]];
        yield 'an e-mail that is no address stays text' => [AttributeType::Email, 'billing at example', 'billing at example'];
        yield 'a phone to dial' => [AttributeType::Phone, '+420 123 456 789', ['links' => [['text' => '+420 123 456 789', 'href' => 'tel:+420123456789', 'opens' => 'page']], 'plain' => true]];
        yield 'a phone without digits stays text' => [AttributeType::Phone, 'ask reception', 'ask reception'];
        yield 'a web address with its scheme' => [AttributeType::Url, 'https://example.com/a', ['links' => [['text' => 'https://example.com/a', 'href' => 'https://example.com/a', 'opens' => 'page']], 'plain' => true]];
        yield 'a web address without one' => [AttributeType::Url, 'example.com', ['links' => [['text' => 'example.com', 'href' => 'https://example.com', 'opens' => 'page']], 'plain' => true]];
        yield 'nothing' => [AttributeType::Text, null, null];
        yield 'an empty text' => [AttributeType::Email, '', null];
    }

    #[DataProvider('values')]
    public function testEachTypeHasItsFormat(AttributeType $type, mixed $value, mixed $shown): void
    {
        $options = AttributeType::Enum === $type ? ['CZK' => 'currency.CZK'] : [];
        $attribute = new Attribute('value', 'label', $type, static fn (): mixed => $value, options: $options);

        self::assertSame($shown, $this->cells()->cell($attribute, []));
    }

    public function testTheValueIsReadFromTheRowInTheListsContext(): void
    {
        $attribute = new Attribute('customer', 'label', AttributeType::Text, static fn (array $row, array $context): string => \sprintf('%s%s', \is_string($row['name'] ?? null) ? $row['name'] : '', \is_string($context['suffix'] ?? null) ? $context['suffix'] : ''));

        self::assertSame('Example Company s.r.o.', $this->cells()->cell($attribute, ['name' => 'Example Company'], ['suffix' => ' s.r.o.']));
    }

    public function testAValueThatIsNoTextIsRefused(): void
    {
        $attribute = new Attribute('value', 'label', AttributeType::Text, static fn (): array => ['a']);

        $this->expectException(\InvalidArgumentException::class);

        $this->cells()->cell($attribute, []);
    }

    public function testMoneyReadsAsTheListsHaveAlwaysShownIt(): void
    {
        $money = new MoneyText(new LocaleSwitcher('en', []));

        self::assertSame("1,210.50\u{a0}Kč", $money->shown(Money::ofMinor(121050, Currency::CZK)));
        self::assertSame('EUR', $money->unit(Currency::EUR));
        self::assertSame('-0.05', $money->grouped(Money::ofMinor(-5, Currency::EUR)));
    }

    private function cells(): AttributeCells
    {
        $translator = new Translator('cs');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'format.date' => 'j. n. Y',
            'format.datetime' => 'j. n. Y G:i',
            'ui.attribute.yes' => 'Ano',
            'ui.attribute.no' => 'Ne',
            'currency.CZK' => 'Czech crowns',
        ], 'cs');

        return new AttributeCells($translator, new MoneyText(new LocaleSwitcher('cs', [])));
    }
}
