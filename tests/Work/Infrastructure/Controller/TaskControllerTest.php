<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Controller;

use App\Attachment\Domain\Attachment;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Label;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Domain\TaskLabel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The task page: what the task is, its subtasks and its discussion, and each
 * property saved on its own form.
 */
final class TaskControllerTest extends WebTestCase
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

    public function testThePageShowsTheTaskItsSubtasksItsDetailsAndItsActivity(): void
    {
        $this->signIn();
        $project = $this->seed();

        $crawler = $this->client->request('GET', '/tasks/'.$this->taskId('Wireframes'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Wireframes');
        self::assertSame("Three options.\nAnd a fourth.", $crawler->filter('[data-task-description]')->text(normalizeWhitespace: false));
        self::assertSame(['Logo'], $crawler->filter('[data-task-subtasks] tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(1, $crawler->filter('form[data-quick-add] #add-subtask'));
        self::assertSame('In progress', trim($crawler->filter('#task-status option[selected]')->text()));
        self::assertSame('Example Company', trim($crawler->filter('#task-responsible option[selected]')->text()));
        self::assertSame(['Nobody', 'Example Company', 'Jana Example'], $crawler->filter('#task-responsible option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('High', trim($crawler->filter('#task-priority option[selected]')->text()));
        self::assertSame('Design', trim($crawler->filter('#task-section option[selected]')->text()));
        self::assertSame(new \DateTimeImmutable('-3 days')->format('Y-m-d'), $crawler->filter('#task-due')->attr('value'));
        self::assertSelectorTextContains('[data-task-labels]', 'Design');
        self::assertSame('/projects/'.$project, $crawler->filter('[data-task-project]')->attr('href'));
        self::assertSelectorTextContains('[data-task-created]', 'Created by Example Company');
        self::assertCount(6, $crawler->filter('[data-task-details] noscript'));
        self::assertCount(6, $crawler->filter('[data-task-details] form[data-controller="autosubmit"]'));
        self::assertSelectorTextContains('[data-task-history]', 'Created the task');

        $this->client->request('GET', '/tasks/01900000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEachPropertySavesOnItsOwnAndTheActivityTellsIt(): void
    {
        $this->signIn();
        $project = $this->seed();
        $id = $this->taskId('Wireframes');
        $done = $this->statusId($project, 'Done');
        $jana = $this->membershipId('jana@example.com');
        $build = $this->sectionId($project, 'Build');

        foreach ([
            'status' => ['status' => $done],
            'responsible' => ['responsible' => $jana],
            'due' => ['due' => '2026-12-24'],
            'priority' => ['priority' => 'urgent'],
            'section' => ['section' => $build],
        ] as $property => $values) {
            $crawler = $this->client->request('GET', '/tasks/'.$id);
            $this->client->submit($crawler->filter('[data-task-property="'.$property.'"]')->form($values));
            self::assertResponseStatusCodeSame(303, $property);
            self::assertResponseRedirects('/tasks/'.$id);
        }

        $row = $this->em->getConnection()->fetchAssociative('SELECT status_id, responsible_id, due_on, priority, section_id FROM task WHERE id = ?', [$id]);
        self::assertSame(['status_id' => $done, 'responsible_id' => $jana, 'due_on' => '2026-12-24', 'priority' => Priority::Urgent->value, 'section_id' => $build], $row);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $history = $crawler->filter('[data-task-history] li')->each(static fn (Crawler $item): string => trim($item->text()));
        self::assertStringContainsString('Example Company: Changed Section', implode("\n", $history));
        self::assertStringContainsString('Status In progress → Done', implode("\n", $history));
        self::assertStringContainsString('Responsible Example Company → Jana Example', implode("\n", $history));
        self::assertStringContainsString('Due date '.new \DateTimeImmutable('-3 days')->format('Y-m-d').' → 2026-12-24', implode("\n", $history));
        self::assertStringContainsString('Priority High → Urgent', implode("\n", $history));
        // The person given the task follows it.
        self::assertSelectorTextContains('[data-task-watchers]', 'Jana Example');

        // The clear button takes the date away, the section goes back to none.
        $this->client->submit($crawler->filter('[data-task-property="due"] button[name="clear"]')->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('[data-task-property="section"]')->form(['section' => '']));
        self::assertSame(['due_on' => null, 'section_id' => null], $this->em->getConnection()->fetchAssociative('SELECT due_on, section_id FROM task WHERE id = ?', [$id]));
        $this->client->request('GET', '/tasks/'.$id);
        self::assertSelectorTextContains('[data-task-history]', 'Due date 2026-12-24 → —');
    }

    public function testTheTitleIsEditedInPlaceAndMustNotBeEmpty(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Wireframes');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertSame('/tasks/'.$id.'?edit=title', $crawler->filter('[data-task-edit-title]')->attr('href'));
        $crawler = $this->client->request('GET', '/tasks/'.$id.'?edit=title');
        self::assertSame('Wireframes', $crawler->filter('#title')->attr('value'));

        $crawler = $this->client->submit($crawler->filter('[data-task-title-form]')->form(['title' => '  ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter the title of the task.', trim($crawler->filter('#title-error')->text()));
        self::assertSame('true', $crawler->filter('#title')->attr('aria-invalid'));

        $crawler = $this->client->submit($crawler->filter('[data-task-title-form]')->form(['title' => str_repeat('a', Task::MAX_TITLE_LENGTH + 1)]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter a name that is not too long.', trim($crawler->filter('#title-error')->text()));

        $this->client->submit($crawler->filter('[data-task-title-form]')->form(['title' => 'Wireframes v2']));
        self::assertResponseRedirects('/tasks/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main h1', 'Wireframes v2');
        self::assertSelectorTextContains('[data-task-history]', 'Changed Title');
    }

    public function testTheDescriptionIsEditedInPlaceAndKeepsItsLines(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$id.'?edit=description');
        $this->client->submit($crawler->filter('[data-task-description-form]')->form(['description' => "<b>One</b>\nTwo"]));
        self::assertResponseRedirects('/tasks/'.$id);
        $crawler = $this->client->followRedirect();

        self::assertSame("<b>One</b>\nTwo", $crawler->filter('[data-task-description]')->text(normalizeWhitespace: false));
        self::assertCount(0, $crawler->filter('[data-task-description] b'));
    }

    public function testTheDescriptionIsShownAsMarkdown(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$id.'?edit=description');
        $this->client->submit($crawler->filter('[data-task-description-form]')->form(['description' => "# Scope\n\n- [x] **Draft**\n- [ ] [Review](javascript:alert(1))"]));
        $crawler = $this->client->followRedirect();

        $description = $crawler->filter('[data-task-description]');
        self::assertSame('Scope', $description->filter('h3')->text());
        self::assertSame('Draft', $description->filter('li strong')->text());
        self::assertCount(1, $description->filter('input[type=checkbox][checked][disabled]'));
        self::assertCount(0, $description->filter('a[href]'));
    }

    public function testTheLabelsAreTickedInADialogAndSavedTogether(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Wireframes');
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertSame('/tasks/'.$id.'/labels', $crawler->filter('[data-task-labels] a[data-dialog]')->attr('href'));

        $crawler = $this->client->request('GET', '/tasks/'.$id.'/labels', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('dialog#dialog-page input[name="labels[]"]'));

        $crawler = $this->client->request('GET', '/tasks/'.$id.'/labels');
        $form = $crawler->filter('#task-labels-form')->form();
        $checkboxes = $form['labels'];
        self::assertIsArray($checkboxes);
        foreach ($checkboxes as $checkbox) {
            self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $checkbox);
            $checkbox->hasValue() ? $checkbox->untick() : $checkbox->tick();
        }
        $this->client->submit($form);

        self::assertResponseRedirects('/tasks/'.$id);
        $crawler = $this->client->followRedirect();
        self::assertSame('Urgent', trim($crawler->filter('[data-task-labels] li')->text()));
        self::assertSelectorTextContains('[data-task-history]', 'Label added: Urgent');
        self::assertSelectorTextContains('[data-task-history]', 'Label removed: Design');
    }

    public function testIWatchAndStopWatchingAndAddAnotherWatcher(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertSelectorTextContains('[data-task-watchers]', 'Nobody watches the task.');
        $this->client->submit($crawler->filter('form[data-task-watch]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('Stop watching', trim($crawler->filter('form[data-task-watch] button')->text()));
        self::assertSame(['Example Company'], $crawler->filter('[data-task-watchers] li')->each(static fn (Crawler $item): string => trim($item->filter('span')->eq(1)->text())));

        $this->client->submit($crawler->filter('[data-task-property="watchers"]')->form(['person' => $this->membershipId('jana@example.com')]));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Example Company', 'Jana Example'], $crawler->filter('[data-task-watchers] li')->each(static fn (Crawler $item): string => trim($item->filter('span')->eq(1)->text())));
        // Nobody is left to add.
        self::assertCount(0, $crawler->filter('[data-task-property="watchers"]'));

        $this->client->submit($crawler->filter('form[data-task-watch]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('Watch', trim($crawler->filter('form[data-task-watch] button')->text()));
        self::assertSelectorTextContains('[data-task-history]', 'Watcher removed: Example Company');
    }

    public function testTheSubtaskLineAddsASubtaskAndIsReadyForTheNext(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Wireframes');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $this->client->submit($crawler->filter('form[data-quick-add]')->form(['title' => 'Icons']));

        self::assertResponseRedirects('/tasks/'.$id.'?add=1#add-subtask');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Logo', 'Icons'], $crawler->filter('[data-task-subtasks] tbody tr td:first-child')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertNotNull($crawler->filter('#add-subtask')->attr('autofocus'));
        self::assertSame(
            ['parent_id' => $id, 'section_id' => $this->sectionId($this->projectId('Website'), 'Design')],
            $this->em->getConnection()->fetchAssociative('SELECT parent_id, section_id FROM task WHERE title = ?', ['Icons']),
        );

        // An empty line adds nothing.
        $this->client->submit($crawler->filter('form[data-quick-add]')->form(['title' => ' ']));
        self::assertSame(4, $this->rows('SELECT COUNT(*) FROM task', []));
    }

    public function testASubtaskLinksToItsParentAndTakesNoSubtaskOfItsOwn(): void
    {
        $this->signIn();
        $this->seed();
        $logo = $this->taskId('Logo');

        $crawler = $this->client->request('GET', '/tasks/'.$logo);

        self::assertSame('Wireframes', trim($crawler->filter('nav a[href="/tasks/'.$this->taskId('Wireframes').'"]')->text()));
        self::assertCount(0, $crawler->filter('[data-task-subtasks]'));
        self::assertCount(0, $crawler->filter('[data-task-property="section"]'));

        $this->client->request('POST', '/tasks/'.$logo.'/subtasks', ['_token' => $this->token($crawler), 'title' => 'Too deep']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'A subtask goes under a task of the same project that is not a subtask itself.');
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM task WHERE title = ?', ['Too deep']));
    }

    public function testAnInactivePersonCannotTakeOrFollowTheTask(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $jana = $this->membershipId('jana@example.com');
        $this->scoped(function () use ($jana): void {
            $membership = $this->em->find(Membership::class, $jana) ?? throw new \LogicException('Membership.');
            $membership->deactivate(new \DateTimeImmutable());
            $this->em->flush();
        });
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertSame(['Nobody', 'Example Company'], $crawler->filter('#task-responsible option')->each(static fn (Crawler $option): string => trim($option->text())));
        $token = $this->token($crawler);

        $this->client->request('POST', '/tasks/'.$id.'/responsible', ['_token' => $token, 'responsible' => $jana]);
        self::assertResponseRedirects('/tasks/'.$id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Choose an active person of the organisation as responsible.');

        $this->client->request('POST', '/tasks/'.$id.'/watchers', ['_token' => $token, 'person' => $jana]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Choose an active person of the organisation to follow the task.');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT responsible_id FROM task WHERE id = ?', [$id]));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM task_watcher WHERE task_id = ?', [$id]));
    }

    public function testADateThatIsNoDateIsRefusedAndAStatusOfAnotherProjectIsNotFound(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $token = $this->token($crawler);

        $this->client->request('POST', '/tasks/'.$id.'/due', ['_token' => $token, 'due' => '2026-02-30']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Enter the due date as a date.');

        $this->client->request('POST', '/tasks/'.$id.'/status', ['_token' => $token, 'status' => '01900000-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testEveryChangeNeedsItsToken(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        foreach (['title', 'description', 'status', 'responsible', 'due', 'priority', 'labels', 'section', 'watch', 'unwatch', 'watchers', 'subtasks', 'comments'] as $action) {
            $this->client->request('POST', '/tasks/'.$id.'/'.$action, ['_token' => 'wrong', 'body' => 'Hello']);
            self::assertResponseStatusCodeSame(403, $action);
        }
        $comment = $this->janaComments($id);
        foreach (['edit', 'delete'] as $action) {
            $this->client->request('POST', '/comments/'.$comment.'/'.$action, ['_token' => 'wrong', 'body' => 'Changed']);
            self::assertResponseStatusCodeSame(403, $action);
        }
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM task_comment WHERE deleted_at IS NULL AND body = ?', ['Jana was here.']));
    }

    public function testAGuestIsDenied(): void
    {
        $this->signIn(Role::Guest);
        $id = '01900000-0000-7000-8000-000000000000';

        foreach (['GET' => ['', '/labels', '/mention-candidates'], 'POST' => ['/title', '/status', '/watch', '/subtasks', '/comments']] as $method => $paths) {
            foreach ($paths as $path) {
                $this->client->request($method, '/tasks/'.$id.$path);
                self::assertResponseStatusCodeSame(403, $method.' '.$path);
            }
        }
        foreach (['/edit', '/delete'] as $path) {
            $this->client->request('POST', '/comments/'.$id.$path);
            self::assertResponseStatusCodeSame(403, 'comment '.$path);
        }
        $this->client->request('POST', '/markdown/preview', ['text' => 'Hello']);
        self::assertResponseStatusCodeSame(403, 'preview');
    }

    public function testThePreviewRendersTheTextAsTheSanitisedMarkdownOfThePage(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $jana = $this->membershipId('jana@example.com');
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $editor = $crawler->filter('[data-comment-composer] [data-controller="markdown-editor"]');
        self::assertSame('/markdown/preview', $editor->attr('data-markdown-editor-preview-url-value'));
        self::assertSame('/tasks/'.$id.'/mention-candidates', $editor->attr('data-markdown-editor-mentions-url-value'));
        $token = (string) $editor->attr('data-markdown-editor-token-value');

        $this->client->request('POST', '/markdown/preview', ['_token' => $token, 'text' => "**Bold** <script>alert(1)</script> [x](javascript:alert(1)) @[Jana Example](membership:{$jana})"]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Jana Example', $html);

        $this->client->request('POST', '/markdown/preview', ['_token' => $token, 'text' => str_repeat('a', 20_000)]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/markdown/preview', ['_token' => $token, 'text' => str_repeat('a', 20_001)]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/markdown/preview', ['_token' => 'wrong', 'text' => 'Hello']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/markdown/preview');
        self::assertResponseStatusCodeSame(405);
    }

    public function testTheMentionListHoldsThePeopleWhoSeeTheTask(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $this->client->request('GET', '/tasks/'.$id.'/mention-candidates');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['results' => [
            ['id' => $this->membershipId('testing@example.com'), 'name' => 'Example Company', 'initials' => 'EC'],
            ['id' => $this->membershipId('jana@example.com'), 'name' => 'Jana Example', 'initials' => 'JE'],
        ]], $data);

        // A task one cannot see is one that does not exist.
        $this->client->request('GET', '/tasks/01900000-0000-7000-8000-000000000000/mention-candidates');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheDiscussionIsOneTimelineOldestFirstWithTheCommentBoxUnderIt(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $this->janaComments($id);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertSelectorTextContains('#task-discussion-title', 'Discussion and history');
        self::assertSame(['All', 'Comments', 'History'], $crawler->filter('[data-discussion-filter] a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame('page', $crawler->filter('[data-discussion-filter] a')->first()->attr('aria-current'));
        $this->client->submit($crawler->filter('form[data-comment-composer]')->form(['body' => 'Mine **now**.']));
        $crawler = $this->client->followRedirect();

        $items = $crawler->filter('[data-task-history] > li');
        self::assertCount(3, $items);
        self::assertNotNull($items->eq(0)->attr('data-discussion-step'));
        self::assertStringContainsString('Created the task', $items->eq(0)->text());
        self::assertSame('Jana Example', trim($items->eq(1)->filter('[data-comment-author]')->text()));
        self::assertSame('Example Company', trim($items->eq(2)->filter('[data-comment-author]')->text()));
        self::assertSame('now', $items->eq(2)->filter('[data-comment-body] strong')->text());
        // The comment box comes after the timeline.
        self::assertCount(1, $crawler->filter('[data-task-history] ~ form[data-comment-composer]'));
        self::assertNotNull($crawler->filter('#comment-body[data-comment-editor]')->getNode(0));

        $crawler = $this->client->request('GET', '/tasks/'.$id.'?activity=comments');
        self::assertSame('page', $crawler->filter('[data-discussion-filter] a')->eq(1)->attr('aria-current'));
        self::assertCount(2, $crawler->filter('[data-task-history] > li[data-comment]'));
        self::assertCount(0, $crawler->filter('[data-task-history] > li[data-discussion-step]'));

        $crawler = $this->client->request('GET', '/tasks/'.$id.'?activity=history');
        self::assertCount(0, $crawler->filter('[data-task-history] > li[data-comment]'));
        self::assertCount(1, $crawler->filter('[data-task-history] > li[data-discussion-step]'));

        // Anything else is everything.
        $crawler = $this->client->request('GET', '/tasks/'.$id.'?activity=nonsense');
        self::assertCount(3, $crawler->filter('[data-task-history] > li'));
    }

    public function testTheFilesOfTheTaskAreListedUnderTheDescriptionWithTheirSizesAndImagesAsThumbnails(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        /** @var array<string, string> $files */
        $files = $this->scoped(function () use ($id): array {
            $me = $this->em->getRepository(Membership::class)->findOneBy(['role' => Role::Owner]) ?? throw new \LogicException('Membership.');
            $files = [];
            foreach ([['photo.png', 'image/png', 1_258_291], ['brief.pdf', 'application/pdf', 2048], ['notes.txt', 'text/plain', 512]] as [$name, $type, $size]) {
                $file = Id::generate();
                $attachment = new Attachment($file, 'task', $id, $name, $type, $size, str_repeat('a', 64), 'key/'.$file, $me->user()->id(), new \DateTimeImmutable('2026-11-08 10:00'));
                $this->em->persist($attachment);
                $files[$name] = $file;
            }
            $this->em->flush();

            return $files;
        });

        $crawler = $this->client->request('GET', '/tasks/'.$id);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-task-attachments]');
        self::assertSame('Attachments (3)', trim((string) preg_replace('/\s+/', ' ', $section->filter('h2')->text())));
        self::assertSame(['photo.png', 'brief.pdf', 'notes.txt'], $section->filter('[data-attachment-link]')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame(["1.2\u{a0}MB", "2\u{a0}kB", "512\u{a0}B"], $section->filter('[data-attachment-size]')->each(static fn (Crawler $size): string => $size->text('', false)));
        self::assertSame('Example Company', $section->filter('[data-attachment-uploader]')->first()->text());
        $thumbnail = $section->filter('[data-attachment="'.$files['photo.png'].'"] img[data-attachment-thumbnail]');
        self::assertSame('/attachments/'.$files['photo.png'], $thumbnail->attr('src'));
        self::assertStringContainsString('object-cover', (string) $thumbnail->attr('class'));
        self::assertStringContainsString('size-16', (string) $thumbnail->attr('class'));
        self::assertCount(1, $section->filter('img[data-attachment-thumbnail]'));
        self::assertSame('pdf', $section->filter('[data-attachment="'.$files['brief.pdf'].'"] [data-attachment-link]')->attr('data-attachment-preview'));
        self::assertNotNull($section->filter('[data-attachment="'.$files['notes.txt'].'"] [data-attachment-link]')->attr('download'));
        self::assertCount(3, $section->filter('form[data-attachment-delete]'));
        self::assertSame('/tasks/'.$id.'#task-attachments-title', $section->filter('form[data-attachment-delete] input[name="return"]')->attr('value'));

        // The form without JavaScript, and the editors that take pasted and dropped files.
        $upload = $section->filter('form[data-attachment-upload]');
        self::assertSame('multipart/form-data', $upload->attr('enctype'));
        self::assertSame($id, $upload->filter('input[name="owner_id"]')->attr('value'));
        self::assertSame('Drag files here or choose', trim($upload->filter('[data-attachments-target="hint"]')->text()));
        self::assertSame($id, $crawler->filter('[data-comment-composer] [data-markdown-editor]')->attr('data-markdown-editor-upload-owner-value'));
        self::assertCount(1, $crawler->filter('dialog#attachment-viewer'));
    }

    public function testANewCommentIsInternalUnlessTheSwitchIsTurnedOff(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        // Posted without Turbo, so the browser keeps the fragment of the redirect to the new comment.
        self::assertSame('false', $crawler->filter('form[data-comment-composer]')->attr('data-turbo'));
        $switch = $crawler->filter('#comment-internal');
        self::assertSame('switch', $switch->attr('role'));
        self::assertNotNull($switch->attr('checked'));
        self::assertSelectorTextContains('[data-comment-composer]', 'Guests will not see internal comments.');
        self::assertNull($crawler->filter('[data-comment-form-target="internalLabel"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-comment-form-target="publicLabel"]')->attr('hidden'));

        $this->client->submit($crawler->filter('form[data-comment-composer]')->form(['body' => 'Internal one']));
        $first = $this->fetchId('SELECT id FROM task_comment WHERE body = ?', ['Internal one']);
        self::assertResponseRedirects('/tasks/'.$id.'#comment-'.$first);
        self::assertResponseStatusCodeSame(303);

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[data-comment-composer]')->form(['body' => 'Public one']);
        $checkbox = $form['internal'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();

        // SQLite answers 1 and 0, PostgreSQL true and false.
        self::assertSame(
            [['body' => 'Internal one', 'internal' => true], ['body' => 'Public one', 'internal' => false]],
            array_map(static fn (array $row): array => ['body' => $row['body'], 'internal' => (bool) $row['internal']], $this->em->getConnection()->fetchAllAssociative('SELECT body, internal FROM task_comment ORDER BY created_at, id')),
        );
        self::assertSame('Internal', trim($crawler->filter('#comment-'.$first.' [data-comment-internal]')->text()));
        $second = $this->fetchId('SELECT id FROM task_comment WHERE body = ?', ['Public one']);
        self::assertCount(0, $crawler->filter('#comment-'.$second.' [data-comment-internal]'));
    }

    public function testACommentItCannotHaveKeepsTheTextNextToTheError(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $form = $crawler->filter('form[data-comment-composer]')->form(['body' => '   ']);
        $checkbox = $form['internal'];
        self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $checkbox);
        $checkbox->untick();
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Write something first.', trim($crawler->filter('#comment-body-error')->text()));
        self::assertNull($crawler->filter('#comment-internal')->attr('checked'));

        $long = str_repeat('a', TaskComment::MAX_BODY_LENGTH + 1);
        $crawler = $this->client->submit($crawler->filter('form[data-comment-composer]')->form(['body' => $long]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A comment can have at most 20,000 characters.', trim($crawler->filter('#comment-body-error')->text()));
        self::assertSame($long, $crawler->filter('#comment-body')->text(normalizeWhitespace: false));
        self::assertSame('true', $crawler->filter('#comment-body')->attr('aria-invalid'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM task_comment', []));
    }

    public function testIEditMyCommentInPlace(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $crawler = $this->client->request('GET', '/tasks/'.$id);
        $this->client->submit($crawler->filter('form[data-comment-composer]')->form(['body' => 'First *draft*']));
        $comment = $this->fetchId('SELECT id FROM task_comment WHERE body = ?', ['First *draft*']);
        $crawler = $this->client->followRedirect();

        $edit = $crawler->filter('#comment-'.$comment.' [data-comment-edit]');
        self::assertSame('/tasks/'.$id.'?edit_comment='.$comment.'#comment-'.$comment, $edit->attr('href'));
        self::assertCount(0, $crawler->filter('#comment-'.$comment.' [data-comment-edited]'));

        $crawler = $this->client->request('GET', '/tasks/'.$id.'?edit_comment='.$comment);
        self::assertSame('First *draft*', $crawler->filter('#comment-edit-'.$comment)->text(normalizeWhitespace: false));
        self::assertCount(0, $crawler->filter('#comment-'.$comment.' [data-comment-menu]'));

        $crawler = $this->client->submit($crawler->filter('form[data-comment-edit-form]')->form(['body' => ' ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Write something first.', trim($crawler->filter('#comment-edit-'.$comment.'-error')->text()));
        self::assertSame(' ', $crawler->filter('#comment-edit-'.$comment)->text(normalizeWhitespace: false));

        $this->client->submit($crawler->filter('form[data-comment-edit-form]')->form(['body' => 'Final']));
        self::assertResponseRedirects('/tasks/'.$id.'#comment-'.$comment);
        $crawler = $this->client->followRedirect();
        self::assertSame('Final', trim($crawler->filter('#comment-'.$comment.' [data-comment-body]')->text()));
        $edited = $crawler->filter('#comment-'.$comment.' [data-comment-edited]');
        self::assertSame('edited', trim($edited->text()));
        self::assertStringStartsWith('Edited ', (string) $edited->attr('title'));
    }

    public function testICannotEditTheCommentOfAnotherButTheOwnerDeletesIt(): void
    {
        $this->signIn();
        $this->seed();
        $id = $this->taskId('Brief');
        $comment = $this->janaComments($id);

        $crawler = $this->client->request('GET', '/tasks/'.$id);
        self::assertCount(0, $crawler->filter('#comment-'.$comment.' [data-comment-edit]'));
        $delete = $crawler->filter('#comment-'.$comment.' form[data-comment-delete]');
        self::assertSame('Delete the comment?', $delete->attr('data-confirm-title'));
        self::assertSame('This cannot be undone.', $delete->attr('data-confirm-text'));

        // Its form does not open, and a post is refused.
        $crawler = $this->client->request('GET', '/tasks/'.$id.'?edit_comment='.$comment);
        self::assertCount(0, $crawler->filter('form[data-comment-edit-form]'));
        $this->client->request('POST', '/comments/'.$comment.'/edit', ['_token' => $this->token($crawler), 'body' => 'Words in her mouth']);
        self::assertResponseRedirects('/tasks/'.$id.'#comment-'.$comment);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Only the author can change this comment.');
        self::assertSame('Jana was here.', $this->em->getConnection()->fetchOne('SELECT body FROM task_comment WHERE id = ?', [$comment]));

        $this->client->submit($delete->form());
        self::assertResponseRedirects('/tasks/'.$id.'#comment-'.$comment);
        $crawler = $this->client->followRedirect();
        $item = $crawler->filter('#comment-'.$comment);
        self::assertSame('This comment was deleted', trim($item->filter('[data-comment-deleted]')->text()));
        self::assertCount(0, $item->filter('[data-comment-body]'));
        self::assertCount(0, $item->filter('[data-comment-menu]'));
        self::assertStringNotContainsString('Jana was here.', $item->text());
    }

    private function signIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->browser->signIn();
    }

    /**
     * Website, with Brief in no section; Wireframes (overdue, high, mine,
     * labelled Design) with its subtask Logo in Design; a section Build; the
     * labels Design and Urgent; Jana Example as a colleague.
     */
    private function seed(): string
    {
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        $id = $this->projectId('Website');
        $jana = new User('jana@example.com', 'not a hash', 'Jana Example');
        $this->em->persist($jana);
        $this->em->flush();
        $this->browser->fixtures->join($jana, $this->tenant, Role::Member);
        $this->scoped(function () use ($id): void {
            $project = $this->em->find(Project::class, $id) ?? throw new \LogicException('Project.');
            [$open, $active] = $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
            $me = $this->em->getRepository(Membership::class)->findOneBy(['role' => Role::Owner]) ?? throw new \LogicException('Membership.');
            $design = new Section($project, 'Design', 0);
            $build = new Section($project, 'Build', 1);
            $now = new \DateTimeImmutable();
            $userId = $me->user()->id();
            $brief = new Task($project, 'Brief', $open, 0, $userId, $now);
            $wireframes = new Task($project, 'Wireframes', $active, 1, $userId, $now, $design);
            $wireframes->assign($me);
            $wireframes->dueOn(new \DateTimeImmutable('-3 days midnight'));
            $wireframes->prioritise(Priority::High);
            $wireframes->describe("Three options.\nAnd a fourth.");
            $logo = new Task($project, 'Logo', $open, 2, $userId, $now, $design, $wireframes);
            $label = new Label('Design', LabelColour::Green);
            foreach ([$design, $build, $brief, $wireframes, $logo, $label, new Label('Urgent', LabelColour::Red), new TaskLabel($wireframes, $label)] as $entity) {
                $this->em->persist($entity);
            }
            $this->em->flush();
        });

        return $id;
    }

    /**
     * Jana Example's internal comment on the task, which reads "Jana was here.".
     */
    private function janaComments(string $taskId): string
    {
        return $this->scoped(function () use ($taskId): string {
            $task = $this->em->find(Task::class, $taskId) ?? throw new \LogicException('Task.');
            $jana = $this->em->find(Membership::class, $this->membershipId('jana@example.com')) ?? throw new \LogicException('Membership.');
            $comment = new TaskComment($task, $jana, 'Jana was here.', true, new \DateTimeImmutable());
            $this->em->persist($comment);
            $this->em->flush();

            return $comment->id();
        });
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('[data-task-details] input[name="_token"]')->attr('value');
    }

    private function projectId(string $name): string
    {
        return $this->fetchId('SELECT id FROM project WHERE name = ?', [$name]);
    }

    private function sectionId(string $project, string $name): string
    {
        return $this->fetchId('SELECT id FROM project_section WHERE project_id = ? AND name = ?', [$project, $name]);
    }

    private function statusId(string $project, string $name): string
    {
        return $this->fetchId('SELECT id FROM project_status WHERE project_id = ? AND name = ?', [$project, $name]);
    }

    private function taskId(string $title): string
    {
        return $this->fetchId('SELECT id FROM task WHERE title = ?', [$title]);
    }

    private function membershipId(string $email): string
    {
        return $this->fetchId('SELECT m.id FROM membership m JOIN app_user u ON u.id = m.user_id WHERE u.email = ?', [$email]);
    }

    /**
     * @param list<string> $parameters
     */
    private function rows(string $sql, array $parameters): int
    {
        $count = $this->em->getConnection()->fetchOne($sql, $parameters);

        return is_numeric($count) ? (int) $count : throw new \LogicException('A count.');
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
