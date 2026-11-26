<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Contract\Field\ProjectExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The custom fields of a project (ADR 0045) on its screens, with the test-only fields
 * lent to the projects and their code required: the new project dialog asks for every
 * field, the settings save them with the general form, each save records
 * work.project.updated with the keys that changed, and the project's page shows none.
 */
final class ProjectCustomFieldScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Fields $fields;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $browser = new SignedInBrowser($this->client, $this->em);
        $this->fields = LentFields::lend(self::getContainer(), ProjectExtension::RECORD, ['test_fields_code']);
        $browser->seed(role: Role::Owner);
        $browser->signIn();
    }

    public function testTheNewProjectDialogAsksForEveryFieldAndTheRequiredOne(): void
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $form = $crawler->filter('#project-form');
        self::assertStringContainsString('Additional details', $form->filter('[data-custom-fields-section] h3')->text());
        self::assertCount(1, $form->filter('select[name="custom_fields[test_fields_size]"]'));
        self::assertNotNull($form->filter('#custom-field-test-fields-code')->attr('required'));

        $crawler = $this->client->submit($form->form(['name' => 'Website']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-code')->attr('aria-invalid'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM project'));

        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'custom_fields[test_fields_code]' => 'WEB-1', 'custom_fields[test_fields_size]' => 'large']));
        self::assertResponseStatusCodeSame(303);
        $id = $this->projectId();
        self::assertSame('WEB-1', $this->fields->value(ProjectExtension::RECORD, $id, 'test_fields_code'));
        self::assertSame('large', $this->fields->value(ProjectExtension::RECORD, $id, 'test_fields_size'));
    }

    public function testTheSettingsSaveTheFieldsWithTheGeneralForm(): void
    {
        $id = $this->project();

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#project-settings-form');
        self::assertStringContainsString('Additional details', $form->filter('[data-custom-fields-section] h3')->text());
        self::assertSame('WEB-1', $form->filter('#custom-field-test-fields-code')->attr('value'));
        self::assertNull($form->filter('#custom-field-test-fields-code')->attr('required'), 'Only a new project is asked for it.');

        $this->client->submit($form->form(['custom_fields[test_fields_size]' => 'small', 'custom_fields[test_fields_site]' => 'https://example.com']));

        self::assertResponseRedirects();
        self::assertSame('small', $this->fields->value(ProjectExtension::RECORD, $id, 'test_fields_size'));
        self::assertSame('https://example.com', $this->fields->value(ProjectExtension::RECORD, $id, 'test_fields_site'));
        self::assertSame('WEB-1', $this->fields->value(ProjectExtension::RECORD, $id, 'test_fields_code'));
        self::assertSame([['test_fields_site', 'test_fields_size']], $this->updates($id), 'In the order the fields are declared.');
    }

    public function testEverySaveOfTheSettingsRecordsTheEventAndAWrongValueIsToldOnItsField(): void
    {
        $id = $this->project();
        $form = $this->client->request('GET', '/projects/'.$id.'/settings')->filter('#project-settings-form')->form();

        $this->client->submit($form, ['name' => 'Website relaunch']);
        self::assertResponseRedirects();
        $crawler = $this->client->submit($form, ['custom_fields[test_fields_site]' => 'example.com']);

        self::assertSame([[]], $this->updates($id), 'A save that changes no custom field names none.');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-site')->attr('aria-invalid'));
    }

    public function testTheProjectPageShowsNoField(): void
    {
        $id = $this->project();

        $crawler = $this->client->request('GET', '/projects/'.$id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-custom-fields]'));
        self::assertStringNotContainsString('WEB-1', $crawler->filter('main')->text());
    }

    private function project(): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website', 'custom_fields[test_fields_code]' => 'WEB-1']));
        self::assertResponseStatusCodeSame(303);

        return $this->projectId();
    }

    private function projectId(): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project');
        self::assertIsString($id);

        return $id;
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<list<mixed>> the custom fields each work.project.updated of the project names, oldest first
     */
    private function updates(string $id): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID,
            ['work.project.updated', $id],
        );

        return array_map(static function (mixed $data): array {
            $payload = \is_string($data) ? json_decode($data, true) : null;
            self::assertIsArray($payload);
            self::assertIsArray($payload['custom_fields']);

            return array_values($payload['custom_fields']);
        }, $rows);
    }
}
