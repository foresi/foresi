<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Currencies;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Timesheet\Domain\ProjectTimeFields;
use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The time of a project in its settings: whether its time is billable by
 * default, a custom field of the general part, and for an owner only its own
 * hourly rate, a card of its own. Both changes are in the project's history.
 */
final class ProjectTimeSettingsControllerTest extends WebTestCase
{
    private const string BILLABLE = 'custom_fields[timesheet_billable_by_default]';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    public function testWhetherTheTimeIsBillableIsACustomFieldOfTheProject(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        self::assertNull($crawler->filter('#custom-field-timesheet-billable-by-default')->attr('checked'));

        $this->client->submit($crawler->filter('#project-settings-form')->form([self::BILLABLE => '1']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertNotNull($crawler->filter('#custom-field-timesheet-billable-by-default')->attr('checked'));
        self::assertTrue($this->billable($id));

        // The change is in the history of the project, as its other fields are.
        $fields = array_values(array_filter(
            self::getContainer()->get(AuditTrail::class)->rootHistory(Project::class, $id),
            static fn (AuditEntry $entry): bool => ProjectTimeFields::class === $entry->entityClass(),
        ));
        self::assertNotSame([], $fields);

        $form = $crawler->filter('#project-settings-form')->form();
        $checkbox = $form[self::BILLABLE];
        self::assertInstanceOf(ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $this->client->submit($form);
        self::assertFalse($this->billable($id));
    }

    public function testAGuestCannotChangeWhetherTheTimeIsBillable(): void
    {
        $this->signIn(Role::Guest);
        $userId = $this->em->getConnection()->fetchOne('SELECT id FROM app_user LIMIT 1');
        self::assertIsString($userId);
        $project = new Project('Website', null, WorkflowTemplate::Simple, $userId, new \DateTimeImmutable());
        $this->em->persist($project);
        $this->em->flush();
        $id = $project->id();

        $this->client->request('POST', '/projects/'.$id.'/settings', ['name' => 'Website', 'custom_fields' => ['timesheet_billable_by_default' => '1']]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->billable($id));
    }

    public function testAnOwnerSetsTheHourlyRateOfTheProject(): void
    {
        $this->signIn();
        $id = $this->createProject('Website');
        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        self::assertSame('', $crawler->filter('#rate')->attr('value'));
        self::assertSame(Currencies::currentCodes(), $crawler->filter('#rate-currency option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));

        $this->client->submit($crawler->filter('[data-settings-time] form')->form(['rate' => 'a lot', 'rateCurrency' => 'EUR']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Enter a rate, such as 1500 or 1500.50.');
        self::assertSame([null, null], $this->rate($id));

        $this->client->submit($crawler->filter('[data-settings-time] form')->form(['rate' => '1 250.50', 'rateCurrency' => 'EUR']));
        self::assertResponseRedirects('/projects/'.$id.'/settings', 303);
        $crawler = $this->client->followRedirect();
        self::assertSame('1250.50', $crawler->filter('#rate')->attr('value'));
        self::assertSame('EUR', $crawler->filter('#rate-currency option[selected]')->attr('value'));
        self::assertSame([125050, 'EUR'], $this->rate($id));

        // The change is in the history of the project.
        $created = array_values(array_filter(
            self::getContainer()->get(AuditTrail::class)->rootHistory(Project::class, $id),
            static fn (AuditEntry $entry): bool => ProjectTimeSetting::class === $entry->entityClass() && AuditAction::Created === $entry->action(),
        ));
        self::assertCount(1, $created);

        // Saving the card unchanged keeps the rate; an empty field takes it away.
        $this->client->submit($crawler->filter('[data-settings-time] form')->form());
        self::assertSame([125050, 'EUR'], $this->rate($id));
        $this->client->submit($this->client->request('GET', '/projects/'.$id.'/settings')->filter('[data-settings-time] form')->form(['rate' => '']));
        self::assertSame([null, null], $this->rate($id));
    }

    public function testATeamMemberNeitherSeesNorChangesTheHourlyRate(): void
    {
        $this->signIn(Role::Member);
        $id = $this->createProject('Website');
        $this->em->getConnection()->insert('project_time_setting', ['id' => $id, 'project_id' => $id, 'hourly_rate_minor' => 150000, 'hourly_rate_currency' => 'CZK', 'budget_counting' => 'all']);

        $crawler = $this->client->request('GET', '/projects/'.$id.'/settings');
        self::assertCount(0, $crawler->filter('#rate, [data-project-rate], [data-settings-time]'));
        // Only the visible text: tokens and ids in the markup can contain these digits by chance.
        self::assertDoesNotMatchRegularExpression('/1[\s\x{a0}\x{202f}]?500/u', $crawler->filter('main')->text());

        $this->client->request('POST', '/projects/'.$id.'/time-settings', ['rate' => '1', 'rateCurrency' => 'USD']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([150000, 'CZK'], $this->rate($id));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    private function createProject(string $name): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => $name]));
        self::assertResponseStatusCodeSame(303);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function billable(string $project): bool
    {
        return (bool) $this->em->getConnection()->fetchOne('SELECT billable_by_default FROM project_time_field WHERE id = ?', [$project]);
    }

    /**
     * @return array{int|null, string|null}
     */
    private function rate(string $project): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT hourly_rate_minor, hourly_rate_currency FROM project_time_setting WHERE project_id = ?', [$project]);
        if (false === $row) {
            return [null, null];
        }

        $minor = $row['hourly_rate_minor'];
        $currency = $row['hourly_rate_currency'];

        return [is_numeric($minor) ? (int) $minor : null, \is_string($currency) ? $currency : null];
    }
}
