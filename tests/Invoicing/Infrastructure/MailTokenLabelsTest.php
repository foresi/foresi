<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\MailVariables;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Party\Domain\DocumentLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailTokenLabelsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{DocumentLanguage}>
     */
    public static function languages(): iterable
    {
        foreach (DocumentLanguage::cases() as $language) {
            yield $language->value => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryNameGoesToALabelAndBack(DocumentLanguage $language): void
    {
        $labels = $this->labels();
        $stored = implode(' ', array_map(static fn (string $name): string => '{'.$name.'}', self::getContainer()->get(MailVariables::class)->names()));

        $editor = $labels->toEditor($stored, $language);

        self::assertStringNotContainsString('{number}', $editor);
        self::assertCount(20, array_unique(array_values($labels->labels($language))), 'labels are unique');
        self::assertSame(['text' => $stored, 'unknown' => []], $labels->fromEditor($editor, $language));
    }

    public function testCaseSpacingAndStableNamesAreAccepted(): void
    {
        self::assertSame(['text' => '{number} {number} {due_date}', 'unknown' => []], $this->labels()->fromEditor('{ číslo   DOKLADU } {number} {Datum splatnosti}', DocumentLanguage::Czech));
    }

    public function testLabelsMatchWithoutDiacriticsAndInAnyUnicodeForm(): void
    {
        $decomposed = \Normalizer::normalize('{Číslo dokladu} {Částka k úhradě}', \Normalizer::FORM_D);
        \assert(\is_string($decomposed));

        self::assertSame(['text' => '{number} {amount_total}', 'unknown' => []], $this->labels()->fromEditor($decomposed, DocumentLanguage::Czech));
        self::assertSame(['text' => '{number} {amount_total} {registration_number}', 'unknown' => []], $this->labels()->fromEditor('{cislo dokladu} {CASTKA K UHRADE} {ico}', DocumentLanguage::Czech));
    }

    public function testALongTokenIsUnknownAndShortenedForTheMessage(): void
    {
        $result = $this->labels()->fromEditor('{'.str_repeat('a', 200).'}', DocumentLanguage::Czech);

        self::assertSame('{'.str_repeat('a', 200).'}', $result['text']);
        self::assertSame(['{'.str_repeat('a', 40).'…}'], $result['unknown']);
    }

    public function testAnUnknownTokenIsListedAsTyped(): void
    {
        self::assertSame(['text' => 'A {Cislo} {Document number} {Cislo}', 'unknown' => ['{Cislo}', '{Document number}']], $this->labels()->fromEditor('A {Cislo} {Document number} {Cislo}', DocumentLanguage::Czech));
    }

    private function labels(): MailTokenLabels
    {
        return self::getContainer()->get(MailTokenLabels::class);
    }
}
