<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\DocumentTemplates\Application\DocumentVariables;
use Foresi\DocumentTemplates\Application\TemplateRenderer;
use Foresi\DocumentTemplates\Contract\RecordLink;
use Foresi\DocumentTemplates\Contract\VariableDefinition;
use Foresi\Import\Application\Target\TargetRegistry;
use Foresi\Import\Contract\BlankCells;
use Foresi\Import\Contract\FieldDefinition;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Import\Contract\RowError;
use Foresi\Import\Contract\WriteResult;
use Foresi\Import\Domain\NormalisationOptions;
use Foresi\Import\Domain\ValueNormaliser;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Party\Application\Query\ContactTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The fleet's custom fields of a contact in the contacts' import and export and in
 * the document templates, with nothing in the module but the flags of its fields:
 * the fleet manager is never imported, the account code never printed.
 */
final class FleetImportTemplatesTest extends ContactTestCase
{
    private ImportTarget $target;

    /** The schema, and an owner of the firm signed in, who imports and generates documents. */
    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        $this->target = self::getContainer()->get(TargetRegistry::class)->get('party');
    }

    public function testTheContactsImportOffersTheFieldsThatAllowIt(): void
    {
        $fields = $this->fields();

        self::assertSame(
            ['custom_acme_fleet_industry', 'custom_acme_fleet_contract_until', 'custom_acme_fleet_account_code', 'custom_acme_fleet_services'],
            array_keys(array_filter($fields, static fn (FieldDefinition $field): bool => 'custom' === $field->group)),
        );
        self::assertSame('acme_fleet.field.industry', $fields['custom_acme_fleet_industry']->labelKey);
        self::assertSame(['logistics', 'construction', 'retail'], $fields['custom_acme_fleet_industry']->choices);
    }

    public function testAnImportedRowWritesTheFieldsAndTheExportWritesThemBack(): void
    {
        $id = $this->create([
            'display_name' => 'Example Haulage',
            'custom_acme_fleet_industry' => 'Logistics',
            'custom_acme_fleet_contract_until' => '31. 3. 2027',
            'custom_acme_fleet_account_code' => 'HAUL-1',
            'custom_acme_fleet_services' => 'Leasing; insurance',
        ])->id;
        $this->em->flush();
        $this->em->clear();

        self::assertSame('logistics', $this->value($id, 'acme_fleet_industry'));
        self::assertSame(['leasing', 'insurance'], $this->value($id, 'acme_fleet_services'));
        $until = $this->value($id, 'acme_fleet_contract_until');
        self::assertInstanceOf(\DateTimeImmutable::class, $until);
        self::assertSame('2027-03-31', $until->format('Y-m-d'));

        $row = [...$this->target->export([$id])][0];
        self::assertSame(['Logistics', '2027-03-31', 'HAUL-1', 'Leasing, Insurance'], [$row['custom_acme_fleet_industry'], $row['custom_acme_fleet_contract_until'], $row['custom_acme_fleet_account_code'], $row['custom_acme_fleet_services']]);
        self::assertArrayNotHasKey('custom_acme_fleet_fleet_manager', $row);
    }

    public function testAnUndoPutsTheFieldsBack(): void
    {
        $id = $this->create(['display_name' => 'Example Haulage', 'custom_acme_fleet_industry' => 'retail'])->id;
        $this->em->flush();
        $result = self::getContainer()->get(AuditCorrelation::class)->within('import:2', fn (): WriteResult => $this->target->update($id, $this->read(['display_name' => 'Example Haulage', 'custom_acme_fleet_industry' => 'construction']), BlankCells::Keep));
        $this->em->flush();
        self::assertSame('construction', $this->value($id, 'acme_fleet_industry'));

        $this->target->revert($id, $result->before);
        $this->em->flush();

        self::assertSame('retail', $this->value($id, 'acme_fleet_industry'));
    }

    public function testATemplateReadsTheFieldsOfTheClient(): void
    {
        $id = $this->create(['display_name' => 'Example Haulage', 'custom_acme_fleet_industry' => 'logistics', 'custom_acme_fleet_account_code' => 'HAUL-1'])->id;
        $this->em->flush();
        $variables = self::getContainer()->get(DocumentVariables::class);

        $names = array_map(static fn (VariableDefinition $definition): string => $definition->name, $variables->definitions()['client']);
        self::assertContains('custom.acme_fleet_industry', $names);
        self::assertNotContains('custom.acme_fleet_account_code', $names, 'template: false');

        $html = self::getContainer()->get(TemplateRenderer::class)->render('<p>{{ client.name }}, {{ client.custom.acme_fleet_industry }}</p>', $variables->printable($variables->values([new RecordLink('contact', $id)])));
        self::assertSame('<p>Example Haulage, Logistics</p>', $html);
    }

    /**
     * @param array<string, string> $row
     */
    private function create(array $row): WriteResult
    {
        return self::getContainer()->get(AuditCorrelation::class)->within('import:1', fn (): WriteResult => $this->target->create($this->read($row)));
    }

    /**
     * @param array<string, string> $row
     *
     * @return array<string, mixed>
     */
    private function read(array $row): array
    {
        $fields = $this->fields();
        $values = [];
        foreach ($row as $key => $text) {
            $value = new ValueNormaliser()->normalise($fields[$key], $text, new NormalisationOptions());
            self::assertNotInstanceOf(RowError::class, $value, $key);
            $values[$key] = $value;
        }
        self::assertSame([], $this->target->validate($values));

        return $values;
    }

    private function value(string $id, string $key): mixed
    {
        $fields = self::getContainer()->get(Fields::class);
        \assert($fields instanceof Fields);

        return $fields->value(ContactExtension::RECORD, $id, $key);
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function fields(): array
    {
        $fields = [];
        foreach ($this->target->fields() as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }
}
