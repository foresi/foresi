<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\DocumentTemplates;

use Foresi\Deals\Contract\Field\DealExtension;
use Foresi\Deals\Integration\DocumentTemplates\DealVariables;
use Foresi\DocumentTemplates\Contract\RecordLink;
use Foresi\DocumentTemplates\Contract\VariableDefinition;
use Foresi\Tests\Deals\Support\SalesTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The custom fields of a deal in a document template, as {{ deal.custom.<key> }},
 * in the language of the person who generates the document.
 */
final class DealCustomFieldVariablesTest extends SalesTestCase
{
    public function testTheFieldsAreVariablesOfTheDealBelowCustom(): void
    {
        $fields = LentFields::lend(self::getContainer(), DealExtension::RECORD);
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        $deal = $this->deal('Roof audit');
        $fields->write(DealExtension::RECORD, $deal->id(), ['test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'test_fields_flag' => false]);
        $this->em->flush();
        $source = self::getContainer()->get(DealVariables::class);

        self::assertContains('custom.test_fields_due', array_map(static fn (VariableDefinition $definition): string => $definition->name, $source->definitions()));
        $values = $source->values([new RecordLink('deal', $deal->id())]);
        self::assertIsArray($values);
        self::assertSame('Roof audit', $values['title']);
        self::assertIsArray($values['custom']);
        self::assertSame(['2027-01-31', 'No', ''], [$values['custom']['test_fields_due'], $values['custom']['test_fields_flag'], $values['custom']['test_fields_code']]);
    }
}
