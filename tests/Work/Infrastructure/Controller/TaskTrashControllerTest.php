<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Attachment\Domain\Attachment;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Deleting a task from its page, its row and its card, without a question but
 * with an Undo in the message; the trash of the project that restores it.
 */
final class TaskTrashControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
    }

    public function testOnlyWhoMayDeleteATaskIsOfferedTheDeletion(): void
    {
        $this->signIn(Role::Member);
        $project = $this->seed();
        $mine = $this->taskId('Wireframes');
        $janas = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/projects/'.$project);
        $token = (string) $this->deleteForm($crawler, $mine)->filter('input[name="_token"]')->attr('value');
        self::assertSame('Delete task', trim($this->deleteForm($crawler, $mine)->filter('button')->text()));
        self::assertSame('list', $this->deleteForm($crawler, $mine)->filter('input[name="from"]')->attr('value'));
        self::assertCount(0, $this->deleteForms($crawler, $janas));
        // The subtask of my task is mine too.
        self::assertCount(1, $this->deleteForms($crawler, $this->taskId('Logo')));

        $crawler = $this->client->request('GET', '/projects/'.$project.'/board');
        self::assertCount(1, $crawler->filter('[data-task-id="'.$mine.'"] form[data-card-delete]'));
        self::assertSame('board', $crawler->filter('[data-task-id="'.$mine.'"] form[data-card-delete] input[name="from"]')->attr('value'));
        self::assertCount(0, $crawler->filter('[data-task-id="'.$janas.'"] form[data-card-delete]'));

        $crawler = $this->client->request('GET', '/tasks/'.$mine);
        self::assertSame('Delete task', trim($crawler->filter('[data-task-menu] form[data-task-delete] button')->text()));
        self::assertCount(1, $crawler->filter('[data-task-menu] [role="menuitem"] svg'));
        $crawler = $this->client->request('GET', '/tasks/'.$janas);
        self::assertCount(0, $crawler->filter('[data-task-menu]'));

        // A post is refused all the same.
        $this->client->request('POST', '/tasks/'.$janas.'/delete', ['_token' => $token, 'from' => 'board']);
        self::assertResponseRedirects('/projects/'.$project.'/board');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Only whoever created the task or an owner of the organisation can delete or restore it.');
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM task WHERE deleted_at IS NOT NULL'));
    }

    public function testDeletingFromTheListComesBackWithAnUndoThatRestoresTheTaskAndItsSubtasks(): void
    {
        $this->signIn();
        $project = $this->seed();
        $id = $this->taskId('Wireframes');
        $crawler = $this->client->request('GET', '/projects/'.$project);

        $this->client->submit($this->deleteForm($crawler, $id)->form());

        self::assertResponseRedirects('/projects/'.$project);
        $crawler = $this->client->followRedirect();
        $flash = $crawler->filter('[data-task-trashed]');
        self::assertSame('status', $flash->attr('role'));
        self::assertStringContainsString('The task “Wireframes” is in the trash.', $flash->text());
        self::assertSame(['Brief'], $this->listed($crawler));
        self::assertSame('Trash (1)', trim($crawler->filter('[data-project-trash]')->text()));
        $undo = $flash->filter('form[data-alert-action]');
        self::assertSame('/tasks/'.$id.'/restore?from=list', $undo->attr('action'));
        self::assertSame('Undo', trim($undo->filter('button')->text()));

        $this->client->submit($undo->form());

        self::assertResponseRedirects('/projects/'.$project);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main [role="status"]', 'The task “Wireframes” is back.');
        self::assertSame(['Brief', 'Wireframes', 'Logo'], $this->listed($crawler));
        self::assertCount(0, $crawler->filter('[data-project-trash]'));
    }

    public function testDeletingFromTheBoardStaysOnTheBoardAndFromTheTaskPageGoesToTheList(): void
    {
        $this->signIn();
        $project = $this->seed();
        $crawler = $this->client->request('GET', '/projects/'.$project.'/board');

        $this->client->submit($crawler->filter('[data-task-id="'.$this->taskId('Brief').'"] form[data-card-delete]')->form());

        self::assertResponseRedirects('/projects/'.$project.'/board');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The task “Brief” is in the trash.', $crawler->filter('[data-task-trashed]')->text());
        self::assertSame('/tasks/'.$this->taskId('Brief').'/restore?from=board', $crawler->filter('[data-task-trashed] form')->attr('action'));
        self::assertCount(0, $crawler->filter('[data-task-id="'.$this->taskId('Brief').'"]'));
        $this->client->submit($crawler->filter('[data-task-trashed] form')->form());
        self::assertResponseRedirects('/projects/'.$project.'/board');

        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId('Logo'));
        $this->client->submit($crawler->filter('form[data-task-delete]')->form());
        self::assertResponseRedirects('/projects/'.$project);
        $crawler = $this->client->followRedirect();
        self::assertSame('/tasks/'.$this->taskId('Logo').'/restore?from=list', $crawler->filter('[data-task-trashed] form')->attr('action'));
        self::assertSame(['Brief', 'Wireframes'], $this->listed($crawler));
    }

    public function testATaskInTheTrashHasNoPageAndItsFilesAreNotServed(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Wireframes');
        $file = $this->attach($id);
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $delete = $crawler->filter('form[data-task-delete]');
        $this->client->submit($delete->form());

        foreach (['/tasks/'.$id, '/tasks/'.$this->taskId('Logo'), '/tasks/'.$id.'/mention-candidates', '/attachments/'.$file] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $this->client->request('POST', '/tasks/'.$id.'/delete', ['_token' => $delete->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTrashListsWhatWasDeletedAndRestoresIt(): void
    {
        $this->signIn();
        $project = $this->seed();
        $crawler = $this->client->request('GET', '/projects/'.$project.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Trash');
        self::assertSelectorTextContains('main', 'The trash is empty');
        self::assertSame('Tasks in the trash are deleted for good after 30 days.', trim($crawler->filter('[data-trash-purge]')->text()));
        $crawler = $this->client->request('GET', '/projects/'.$project.'/settings');
        self::assertSame('Trash', trim($crawler->filter('[data-project-trash]')->text()));

        $crawler = $this->client->request('GET', '/projects/'.$project);
        $this->client->submit($this->deleteForm($crawler, $this->taskId('Logo'))->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($this->deleteForm($crawler, $this->taskId('Brief'))->form());

        $crawler = $this->client->request('GET', '/projects/'.$project.'/settings');
        self::assertSame('/projects/'.$project.'/trash', $crawler->filter('[data-project-trash]')->attr('href'));
        self::assertSame('Trash (2)', trim($crawler->filter('[data-project-trash]')->text()));
        $crawler = $this->client->request('GET', '/projects/'.$project.'/trash');
        // Deleted in the same second, the later task comes first.
        $rows = $crawler->filter('tbody tr');
        self::assertSame([
            ['Logo', 'Subtask of Wireframes', 'Wireframes', 'Example Company'],
            ['Brief', '', '—', 'Example Company'],
        ], $rows->each(static fn (Crawler $row): array => [
            trim($row->filter('td')->eq(0)->filter('span')->first()->text()),
            trim($row->filter('[data-row-secondary]')->count() > 0 ? $row->filter('[data-row-secondary]')->text() : ''),
            trim($row->filter('td')->eq(1)->text()),
            trim($row->filter('td')->eq(2)->text()),
        ]));
        $restore = $rows->eq(1)->filter('[data-row-actions] form')->first();
        self::assertSame('/tasks/'.$this->taskId('Brief').'/restore', $restore->attr('action'));
        self::assertSame('Restore', $restore->filter('button')->attr('aria-label'));

        $this->client->submit($restore->form());

        self::assertResponseRedirects('/projects/'.$project.'/trash');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main [role="status"]', 'The task “Brief” is back.');
        self::assertSame(['Logo'], $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->filter('span')->first()->text())));
        self::assertNull($this->deletedAt('Brief'));
    }

    public function testOnlyWhoMayRestoreIsOfferedTheRestore(): void
    {
        $this->signIn(Role::Member);
        $project = $this->seed();
        $this->scoped(function (): void {
            foreach (['Brief', 'Wireframes'] as $title) {
                $task = $this->em->getRepository(Task::class)->findOneBy(['title' => $title]) ?? throw new \LogicException('Task.');
                $task->delete($task->createdBy(), new \DateTimeImmutable());
            }
            $this->em->flush();
        });

        $crawler = $this->client->request('GET', '/projects/'.$project.'/trash');

        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSame(['/tasks/'.$this->taskId('Wireframes').'/restore'], array_values(array_unique($crawler->filter('tbody [data-row-actions] form')->each(static fn (Crawler $form): string => (string) $form->attr('action')))));
        $this->client->request('POST', '/tasks/'.$this->taskId('Brief').'/restore', ['_token' => $crawler->filter('tbody [data-row-actions] form input[name="_token"]')->attr('value'), 'from' => 'trash', 'project' => $project]);
        self::assertResponseRedirects('/projects/'.$project.'/trash');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main [role="alert"]', 'Only whoever created the task or an owner of the organisation can delete or restore it.');
        self::assertNotNull($this->deletedAt('Brief'));
    }

    public function testDeletingAndRestoringNeedTheirToken(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        foreach (['delete', 'restore'] as $action) {
            $this->client->request('POST', '/tasks/'.$id.'/'.$action, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertNull($this->deletedAt('Brief'));
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);
        $id = '01900000-0000-7000-8000-000000000000';

        foreach ([['POST', '/tasks/'.$id.'/delete'], ['POST', '/tasks/'.$id.'/restore'], ['GET', '/projects/'.$id.'/trash']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    /**
     * Website, with Brief by Jana Example, and Wireframes and its subtask Logo
     * by the person signed in.
     */
    private function seed(): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', ['Website']);
        self::assertIsString($id);
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $this->em->persist($jana);
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->scoped(function () use ($id, $jana): void {
            $project = $this->em->find(Project::class, $id) ?? throw new \LogicException('Project.');
            [$open] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
            $me = $this->em->getRepository(User::class)->findOneBy(['email' => IdentityFixtures::EMAIL]) ?? throw new \LogicException('User.');
            $now = new \DateTimeImmutable();
            $wireframes = new Task($project, 'Wireframes', $open, 1, $me->id(), $now);
            foreach ([new Task($project, 'Brief', $open, 0, $jana->id(), $now), $wireframes, new Task($project, 'Logo', $open, 2, $me->id(), $now, null, $wireframes)] as $task) {
                $this->em->persist($task);
            }
            $this->em->flush();
        });

        return $id;
    }

    private function attach(string $taskId): string
    {
        return $this->scoped(function () use ($taskId): string {
            $me = $this->em->getRepository(User::class)->findOneBy(['email' => IdentityFixtures::EMAIL]) ?? throw new \LogicException('User.');
            $attachment = new Attachment(Id::generate(), 'task', $taskId, 'notes.txt', 'text/plain', 5, str_repeat('a', 64), 'key/notes', $me->id(), new \DateTimeImmutable());
            $this->em->persist($attachment);
            $this->em->flush();

            return $attachment->id();
        });
    }

    private function deleteForm(Crawler $crawler, string $taskId): Crawler
    {
        $form = $this->deleteForms($crawler, $taskId);
        self::assertCount(1, $form);

        return $form;
    }

    private function deleteForms(Crawler $crawler, string $taskId): Crawler
    {
        return $crawler->filter('[data-row-actions] form[action="/tasks/'.$taskId.'/delete"]');
    }

    /**
     * @return list<string> the titles of the list, subtasks under their task
     */
    private function listed(Crawler $crawler): array
    {
        return $crawler->filter('[data-section] tbody tr td:first-child [data-row-link]')->each(static fn (Crawler $link): string => trim($link->text()));
    }

    private function taskId(string $title): string
    {
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM task WHERE title = ?', [$title]);
        self::assertIsString($id);

        return $id;
    }

    private function deletedAt(string $title): ?string
    {
        $deletedAt = $this->em->getConnection()->fetchOne('SELECT deleted_at FROM task WHERE title = ?', [$title]);

        return \is_string($deletedAt) ? $deletedAt : null;
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }
}
