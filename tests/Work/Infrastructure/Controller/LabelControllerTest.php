<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Application\View\LabelLists;
use Foresi\Work\Domain\Label;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TaskLabel;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The labels of the organisation in the settings: the list, a new label and a
 * change of one in a dialog, the delete that puts it in the trash with Undo,
 * and the trash that restores it onto its tasks.
 */
final class LabelControllerTest extends WebTestCase
{
    use ListPageAssertions;

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

    public function testTheStaffFindTheLabelsInTheSettingsAndStartEmpty(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/settings/labels');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Labels');
        self::assertContains('/settings/labels', $crawler->filter('#sidebar [data-nav-area="settings"] [data-nav-section] li > a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSelectorTextContains('main', 'No labels yet');
        self::assertCount(2, $crawler->filter('main a[href="/settings/labels/new"][data-dialog]'));
    }

    public function testTheLabelsAreAListWithViewsAndItsTrashIsOneOfThem(): void
    {
        $this->signIn();
        $this->label('Design', LabelColour::Green);
        $this->label('Launch', LabelColour::Red);
        $this->em->getConnection()->executeStatement('UPDATE label SET deleted_at = ? WHERE name = ?', ['2026-11-01 10:00:00', 'Launch']);
        self::assertListWithViews(self::getContainer()->get(LabelLists::class), 'work.labels', ['name', 'tasks', 'trashed'], ['all' => false, 'trash' => true]);

        // All leaves out the trash, with the columns the page always showed; the person's own stay.
        $crawler = $this->client->request('GET', '/settings/labels');
        self::assertSame(['Design'], $this->names($crawler));
        self::assertSame(['name', 'preview', 'tasks'], $this->columns($crawler));
        $crawler = $this->client->request('GET', '/settings/labels?cols=name,tasks');
        self::assertSame(['name', 'tasks'], $this->columns($crawler));
        self::assertListTable($crawler, '[data-labels]', ['widths' => '']);

        // The trash shows who deleted a label and when instead of its preview, and its Reset keeps the view.
        $crawler = $this->client->request('GET', '/settings/labels?view=trash');
        self::assertSame(['Launch'], $this->names($crawler));
        self::assertSame(['name', 'tasks', 'deleted_by', 'deleted_at'], $this->columns($crawler));
        self::assertListTable($crawler, '[data-labels]', ['view' => 'trash', 'widths' => '']);

        // The search and the builder's rows narrow the list on the server.
        $this->label('Delivery', LabelColour::Amber);
        self::assertSame(['Delivery'], $this->names($this->client->request('GET', '/settings/labels?q=deliv')));
        self::assertSame(['Design'], $this->names(self::applyBuilder($this->client, '/settings/labels', 'name', 'sig')));
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);

        foreach (['/settings/labels', '/settings/labels/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testANewLabelNeedsAFreeNameAndAColour(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/settings/labels/new');
        self::assertSame(['Gray', 'Green', 'Amber', 'Red'], $crawler->filter('#label-form fieldset label span.font-medium')->each(static fn (Crawler $name): string => trim($name->text())));

        $crawler = $this->client->submit($crawler->filter('#label-form')->form(['name' => ' ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the name of the label.', trim($crawler->filter('#name-error')->text()));

        $this->client->submit($crawler->filter('#label-form')->form(['name' => 'Urgent', 'colour' => 'red']));
        self::assertResponseRedirects('/settings/labels', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The label Urgent is saved.');
        self::assertSame(['Urgent', 'Urgent', '0'], $crawler->filter('tbody tr td')->slice(0, 3)->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(1, $crawler->filter('tbody [data-badges] .bg-badge-red'));

        $crawler = $this->client->request('GET', '/settings/labels/new');
        $crawler = $this->client->submit($crawler->filter('#label-form')->form(['name' => 'urgent', 'colour' => 'green']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A label of this name exists already.', trim($crawler->filter('#name-error')->text()));
    }

    public function testTheDialogFinishesWithTheList(): void
    {
        $this->signIn();
        $header = ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1'];
        $crawler = $this->client->request('GET', '/settings/labels/new', server: $header);
        self::assertCount(1, $crawler->filter('dialog#dialog-page form#label-form'));

        $this->client->submit($crawler->filter('#label-form')->form(['name' => 'Design', 'colour' => 'green']), serverParameters: $header);

        self::assertSame(['location' => '/settings/labels'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testALabelIsRenamedAndRecoloured(): void
    {
        $this->signIn();
        $id = $this->label('Design', LabelColour::Green);
        $this->label('Urgent', LabelColour::Red);
        $crawler = $this->client->request('GET', '/settings/labels');
        self::assertSame('/settings/labels/'.$id.'/edit', $crawler->filter('tr[data-id="'.$id.'"] a[data-dialog]')->attr('href'));
        self::assertSame('/settings/labels/'.$id.'/edit', $crawler->filter('tr[data-id="'.$id.'"] td:first-child a[data-row-link]')->attr('href'));

        $crawler = $this->client->request('GET', '/settings/labels/'.$id.'/edit');
        self::assertSame('Design', $crawler->filter('#name')->attr('value'));
        self::assertNotNull($crawler->filter('#colour-green')->attr('checked'));
        $crawler = $this->client->submit($crawler->filter('#label-form')->form(['name' => 'Urgent', 'colour' => 'amber']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A label of this name exists already.', trim($crawler->filter('#name-error')->text()));

        $this->client->submit($crawler->filter('#label-form')->form(['name' => 'Visual', 'colour' => 'amber']));
        self::assertResponseRedirects('/settings/labels', 303);
        self::assertSame(['name' => 'Visual', 'colour' => 'amber'], $this->em->getConnection()->fetchAssociative('SELECT name, colour FROM label WHERE id = ?', [$id]));

        $this->client->request('GET', '/settings/labels/01900000-0000-7000-8000-000000000000/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingALabelPutsItInTheTrashWhichRestoresItOntoItsTasks(): void
    {
        $this->signIn();
        $id = $this->label('Design', LabelColour::Green);
        $this->tagTasks($id, 2);
        $crawler = $this->client->request('GET', '/settings/labels');
        self::assertSame('2', trim($crawler->filter('tr[data-id="'.$id.'"] td')->eq(2)->text()));
        // The trash is the list's Trash view, its tab counting the labels in it.
        self::assertSame('Trash0', $this->tab($crawler, 'Trash'));
        $form = $crawler->filter('tr[data-id="'.$id.'"] form[action$="/delete"]')->first();
        self::assertNull($form->attr('data-turbo-confirm'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/settings/labels', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-task-trashed]', 'The label Design is in the trash.');
        self::assertCount(0, $crawler->filter('tr[data-id="'.$id.'"]'));
        self::assertSame('Trash1', $this->tab($crawler, 'Trash'));
        self::assertCount(2, $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task_label'));

        // The old address of the trash opens the Trash view.
        $this->client->request('GET', '/settings/labels/trash');
        self::assertResponseRedirects('/settings/labels?view=trash');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(['Design', '2'], $crawler->filter('tr[data-id="'.$id.'"] td')->slice(0, 2)->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSelectorTextContains('main [data-trash-purge]', '30 days');
        $this->client->submit($crawler->filter('tr[data-id="'.$id.'"] form[action$="/restore"]')->first()->form());

        self::assertResponseRedirects('/settings/labels?view=trash', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The label Design is restored.');
        self::assertSelectorTextContains('main', 'The trash is empty');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT deleted_at FROM label WHERE id = ?', [$id]));
    }

    public function testTheUndoOfTheMessageRestoresTheLabel(): void
    {
        $this->signIn();
        $id = $this->label('Design', LabelColour::Green);
        $crawler = $this->client->request('GET', '/settings/labels');
        $this->client->submit($crawler->filter('tr[data-id="'.$id.'"] form[action$="/delete"]')->first()->form());
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('[data-task-trashed] form')->form());

        self::assertResponseRedirects('/settings/labels', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The label Design is restored.');
        self::assertCount(1, $crawler->filter('tr[data-id="'.$id.'"]'));
    }

    public function testARestoreIsRefusedWhileAnotherLabelHasTheName(): void
    {
        $this->signIn();
        $id = $this->label('Design', LabelColour::Green);
        $crawler = $this->client->request('GET', '/settings/labels');
        $this->client->submit($crawler->filter('tr[data-id="'.$id.'"] form[action$="/delete"]')->first()->form());
        $this->label('design', LabelColour::Red);

        $crawler = $this->client->request('GET', '/settings/labels?view=trash');
        $this->client->submit($crawler->filter('tr[data-id="'.$id.'"] form[action$="/restore"]')->first()->form());

        self::assertResponseRedirects('/settings/labels?view=trash', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'A label of this name exists already. Rename it, then restore this one.');
        self::assertCount(1, $crawler->filter('tr[data-id="'.$id.'"]'));
    }

    public function testAGuestReachesNoTrash(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/settings/labels/trash');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('[data-labels] tbody tr[data-id]')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));
    }

    /**
     * @return list<string>
     */
    private function columns(Crawler $crawler): array
    {
        return $crawler->filter('[data-labels] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column'));
    }

    private function tab(Crawler $crawler, string $name): string
    {
        return (string) preg_replace('/\s+/', '', $crawler->filter('[data-list-toolbar] a')->reduce(static fn (Crawler $tab): bool => str_starts_with(trim($tab->text()), $name))->text());
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
    }

    private function label(string $name, LabelColour $colour): string
    {
        $label = new Label($name, $colour);
        $this->em->persist($label);
        $this->em->flush();

        return $label->id();
    }

    private function tagTasks(string $labelId, int $count): void
    {
        $label = $this->em->find(Label::class, $labelId) ?? throw new \LogicException('Label.');
        $userId = $this->em->getConnection()->fetchOne('SELECT id FROM app_user LIMIT 1');
        self::assertIsString($userId);
        $now = new \DateTimeImmutable();
        $project = new Project('Website', null, WorkflowTemplate::Simple, $userId, $now);
        $status = new ProjectStatus($project, 'To do', WorkflowTemplate::Simple->statuses()[0][1], 0);
        $this->em->persist($project);
        $this->em->persist($status);
        for ($i = 0; $i < $count; ++$i) {
            $task = new Task($project, 'Task '.$i, $status, $i, $userId, $now);
            $this->em->persist($task);
            $this->em->persist(new TaskLabel($task, $label));
        }
        $this->em->flush();
    }
}
