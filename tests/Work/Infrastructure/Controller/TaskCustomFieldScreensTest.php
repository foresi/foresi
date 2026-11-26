<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Audit\Domain\ActorKind;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteFields;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Work\Contract\Field\TaskExtension;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkShare;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The custom fields of a task (ADR 0045) on its screens, with the test-only fields lent
 * to the tasks and their code required: the new task and new subtask dialogs ask for
 * the required ones only, the sidebar saves each field on its own through ManageTasks
 * with its key in work.task.updated, a guest changes only the fields open to guests,
 * and the history names a field by its label.
 */
final class TaskCustomFieldScreensTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];
    private const string GUEST = 'guest@example.com';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private Fields $fields;
    private string $project;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
        $this->fields = LentFields::lend(self::getContainer(), TaskExtension::RECORD, ['test_fields_code']);
        $this->browser->seed(role: Role::Owner);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        self::assertResponseStatusCodeSame(303);
        $this->project = $this->fetchId('SELECT id FROM project WHERE name = ?', ['Website']);
    }

    public function testTheNewTaskDialogAsksForTheRequiredFieldsOnly(): void
    {
        $crawler = $this->dialog('/projects/'.$this->project.'/tasks/new');

        self::assertSame(['custom_fields[test_fields_code]'], $crawler->filter('form#task-new-form [name^="custom_fields"]')->each(static fn (Crawler $input): string => (string) $input->attr('name')));
        self::assertNotNull($crawler->filter('#custom-field-test-fields-code')->attr('required'));
        $form = $crawler->filter('form#task-new-form')->form(['title' => 'Brief']);

        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-code')->attr('aria-invalid'), 'A new task needs its required field.');

        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_code' => 'BR-1'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        self::assertSame('BR-1', $this->fields->value(TaskExtension::RECORD, $this->fetchId('SELECT id FROM task WHERE title = ?', ['Brief']), 'test_fields_code'));
    }

    public function testTheNewSubtaskDialogAsksForTheRequiredFieldsToo(): void
    {
        $task = $this->task('Brief');

        $crawler = $this->dialog('/tasks/'.$task.'/subtasks/new');
        self::assertSame(['custom_fields[test_fields_code]'], $crawler->filter('form#subtask-new-form [name^="custom_fields"]')->each(static fn (Crawler $input): string => (string) $input->attr('name')));
        $form = $crawler->filter('form#subtask-new-form')->form(['title' => 'Outline']);
        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_code' => 'OU-1'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        self::assertSame('OU-1', $this->fields->value(TaskExtension::RECORD, $this->fetchId('SELECT id FROM task WHERE title = ?', ['Outline']), 'test_fields_code'));
    }

    public function testTheSidebarSavesEachFieldOnItsOwnAndTheEventNamesIt(): void
    {
        $task = $this->task('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$task);
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-task-custom-fields]');
        self::assertStringContainsString('Additional details', $section->filter('h3')->text());
        $size = $section->filter('form[action="/tasks/'.$task.'/fields/test_fields_size"]');
        self::assertCount(1, $size, 'Each field is a form of its own.');
        self::assertCount(1, $size->filter('[name^="custom_fields"]'));
        self::assertSame(['', 'small', 'large'], $size->filter('select option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('BR-1', $section->filter('#custom-field-test-fields-code')->attr('value'));

        $this->client->request('POST', '/tasks/'.$task.'/fields/test_fields_size', ['_token' => $this->token($crawler), 'custom_fields' => ['test_fields_size' => 'large']]);

        self::assertResponseRedirects('/tasks/'.$task);
        self::assertSame('large', $this->fields->value(TaskExtension::RECORD, $task, 'test_fields_size'));
        self::assertSame([[], ['test_fields_size']], $this->updated($task));
        self::assertSame('BR-1', $this->fields->value(TaskExtension::RECORD, $task, 'test_fields_code'), 'The other fields stay.');
    }

    public function testAValueTheFieldDoesNotTakeIsToldOnThePage(): void
    {
        $task = $this->task('Brief');
        $crawler = $this->client->request('GET', '/tasks/'.$task);

        $this->client->request('POST', '/tasks/'.$task.'/fields/test_fields_seats', ['_token' => $this->token($crawler), 'custom_fields' => ['test_fields_seats' => '2.5']]);

        self::assertResponseRedirects('/tasks/'.$task);
        self::assertNull($this->fields->value(TaskExtension::RECORD, $task, 'test_fields_seats'));
        self::assertStringContainsString('This value cannot be saved.', $this->client->followRedirect()->filter('main')->text());
        $this->client->request('POST', '/tasks/'.$task.'/fields/no_such_field', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestChangesOnlyTheFieldsOpenToGuests(): void
    {
        $task = $this->task('Brief');
        $guest = new User(self::GUEST, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Guest Example');
        $this->em->persist($guest);
        $this->em->flush();
        $this->browser->fixtures->join($guest, Role::Guest);
        $membership = $this->em->find(Membership::class, $this->fetchId('SELECT m.id FROM membership m WHERE m.user_id = ?', [$guest->id()]));
        $entity = $this->em->find(Task::class, $task);
        self::assertInstanceOf(Membership::class, $membership);
        self::assertInstanceOf(Task::class, $entity);
        $this->em->persist(WorkShare::of($entity, $membership, new \DateTimeImmutable()));
        $this->em->flush();
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => self::GUEST, '_password' => IdentityFixtures::PASSWORD]));

        $crawler = $this->client->request('GET', '/tasks/'.$task);
        self::assertResponseIsSuccessful();
        self::assertSame(['/tasks/'.$task.'/fields/test_fields_size'], $crawler->filter('[data-task-custom-fields] form')->each(static fn (Crawler $form): string => (string) $form->attr('action')));
        $token = $this->token($crawler);

        $this->client->request('POST', '/tasks/'.$task.'/fields/test_fields_size', ['_token' => $token, 'custom_fields' => ['test_fields_size' => 'small']]);
        self::assertResponseRedirects('/tasks/'.$task);
        self::assertSame('small', $this->fields->value(TaskExtension::RECORD, $task, 'test_fields_size'));

        $this->client->request('POST', '/tasks/'.$task.'/fields/test_fields_code', ['_token' => $token, 'custom_fields' => ['test_fields_code' => 'GUEST']]);
        self::assertResponseRedirects('/tasks/'.$task);
        self::assertSame('BR-1', $this->fields->value(TaskExtension::RECORD, $task, 'test_fields_code'), 'A field closed to guests is refused.');
        self::assertStringContainsString('You cannot change this field.', $this->client->followRedirect()->filter('main')->text());
    }

    public function testTheHistoryNamesACustomFieldByItsLabel(): void
    {
        $task = $this->task('Brief');
        // A module's extension of the task is audited under the task (AuditListener), as this entry is.
        $this->em->persist(new AuditEntry(FixtureNoteFields::class, $task, AuditAction::Updated, ['code' => ['BR-1', 'BR-2']], null, ActorKind::System, new \DateTimeImmutable('+1 minute'), null, Task::class, $task));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/tasks/'.$task);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Changed test.field.code', $crawler->filter('[data-task-history]')->text());
    }

    /**
     * A task of the project made through its dialog, with its required code.
     */
    private function task(string $title): string
    {
        $form = $this->dialog('/projects/'.$this->project.'/tasks/new')->filter('form#task-new-form')->form(['title' => $title]);
        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_code' => 'BR-1'];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        return $this->fetchId('SELECT id FROM task WHERE title = ?', [$title]);
    }

    private function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: self::DIALOG);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'.$url);
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('[data-task-property="status"] input[name="_token"]')->attr('value');
    }

    /**
     * @return array{list<mixed>, list<mixed>} the fields and the custom fields the last work.task.updated of the task names
     */
    private function updated(string $task): array
    {
        $data = $this->em->getConnection()->fetchOne(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID.' DESC',
            ['work.task.updated', $task],
        );
        self::assertIsString($data);
        $payload = json_decode($data, true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['changed']);
        self::assertIsArray($payload['custom_fields']);

        return [array_values($payload['changed']), array_values($payload['custom_fields'])];
    }

    /**
     * @param list<string> $parameters
     */
    private function fetchId(string $sql, array $parameters): string
    {
        $id = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsString($id);

        return $id;
    }
}
