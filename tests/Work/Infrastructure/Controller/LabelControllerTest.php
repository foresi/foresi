<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Identity\Domain\Role;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The labels of the organisation in the settings: the list, a new label and a
 * change of one in a dialog, and the delete that tells from how many tasks.
 */
final class LabelControllerTest extends WebTestCase
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

    public function testTheStaffFindTheLabelsInTheSettingsAndStartEmpty(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/settings/labels');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Labels');
        self::assertContains('/settings/labels', $crawler->filter('#sidebar [data-nav-group="settings"] li > a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSelectorTextContains('main', 'No labels yet');
        self::assertCount(2, $crawler->filter('main a[href="/settings/labels/new"][data-dialog]'));
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

    public function testDeletingALabelAsksAndTakesItOffItsTasks(): void
    {
        $this->signIn();
        $id = $this->label('Design', LabelColour::Green);
        $this->tagTasks($id, 2);
        $crawler = $this->client->request('GET', '/settings/labels');
        self::assertSame('2', trim($crawler->filter('tr[data-id="'.$id.'"] td')->eq(2)->text()));
        $form = $crawler->filter('tr[data-id="'.$id.'"] form[action$="/delete"]')->first();
        self::assertSame('Delete the label Design?', $form->attr('data-turbo-confirm'));
        self::assertSame('Removes the label from 2 tasks.', $form->attr('data-confirm-text'));

        $this->client->submit($form->form());

        self::assertResponseRedirects('/settings/labels', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The label Design is deleted.');
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM label'));
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task_label'));
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    private function label(string $name, LabelColour $colour): string
    {
        $this->context->switchTo($this->tenant);
        try {
            $label = new Label($name, $colour);
            $this->em->persist($label);
            $this->em->flush();

            return $label->id();
        } finally {
            $this->context->reset();
        }
    }

    private function tagTasks(string $labelId, int $count): void
    {
        $this->context->switchTo($this->tenant);
        try {
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
        } finally {
            $this->context->reset();
        }
    }
}
