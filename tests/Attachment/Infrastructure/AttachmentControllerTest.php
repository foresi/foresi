<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Attachment\Infrastructure;

use App\Attachment\Infrastructure\Controller\AttachmentController;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Uploading a file to a task and opening it again: only for whom may see the
 * task, and only ever as an image, a PDF, a video or bytes to download.
 */
final class AttachmentControllerTest extends WebTestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;
    private string $task;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testAnImageUploadedToATaskIsShownInlineWithItsSniffedType(): void
    {
        $this->seed();

        $this->upload($this->file($this->png(), 'Příloha "1".png', 'text/html'));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertSame(['id', 'url', 'name', 'mediaType', 'size'], array_keys($body));
        $id = $this->uploaded('id');
        self::assertSame('/attachments/'.$id, $body['url']);
        self::assertSame(['Příloha "1".png', 'image/png', \strlen($this->png())], [$body['name'], $body['mediaType'], $body['size']]);
        self::assertSame(['owner_kind' => 'task', 'owner_id' => $this->task], $this->em->getConnection()->fetchAssociative('SELECT owner_kind, owner_id FROM attachment WHERE id = ?', [$id]));

        $this->client->request('GET', $this->uploaded('url'));

        self::assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        self::assertSame('image/png', $headers->get('Content-Type'));
        self::assertSame("inline; filename=\"P__loha _1_.png\"; filename*=utf-8''P%C5%99%C3%ADloha%20%221%22.png", $headers->get('Content-Disposition'));
        $this->assertGuarded();
        self::assertSame($this->png(), $this->client->getInternalResponse()->getContent());
    }

    public function testAnythingButImagesPdfsAndVideosIsDownloadedAsBytes(): void
    {
        $this->seed();

        foreach ([
            'page.html' => '<!doctype html><html><body><script>alert(1)</script></body></html>',
            'logo.svg' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'notes.txt' => 'plain words',
        ] as $name => $bytes) {
            $this->upload($this->file($bytes, $name, 'image/png'));
            self::assertResponseStatusCodeSame(201, $name);
            $this->client->request('GET', $this->uploaded('url'));

            self::assertResponseIsSuccessful();
            $headers = $this->client->getResponse()->headers;
            self::assertSame('application/octet-stream', $headers->get('Content-Type'), $name);
            self::assertStringStartsWith('attachment; filename=', (string) $headers->get('Content-Disposition'), $name);
            $this->assertGuarded();
        }
    }

    public function testARefusedFileIsToldInTheEditorsJson(): void
    {
        $this->seed();

        $this->upload($this->file('', 'empty.txt'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'The selected file is empty'], $this->json());

        $this->upload(new UploadedFile($this->file('x', 'big.mov')->getPathname(), 'big.mov', null, \UPLOAD_ERR_INI_SIZE, true));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'The selected file must be smaller than 50 MB'], $this->json());

        $this->upload(new UploadedFile($this->file('x', 'part.txt')->getPathname(), 'part.txt', null, \UPLOAD_ERR_PARTIAL, true));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'The selected file could not be uploaded – try again'], $this->json());
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attachment'));
    }

    public function testAPlainFormGoesBackToItsPageWithTheError(): void
    {
        $this->seed();
        $page = '/tasks/'.$this->task;

        $this->upload($this->file('report', 'report.txt'), ['return' => $page]);
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($page);
        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attachment'));

        $this->upload($this->file('', 'empty.txt'), ['return' => $page]);
        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($page);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The selected file is empty');

        foreach (['https://evil.example.com/', '//evil.example.com/', '/\\evil.example.com', 'javascript:alert(1)'] as $elsewhere) {
            $this->upload($this->file('report', 'report.txt'), ['return' => $elsewhere]);
            self::assertResponseStatusCodeSame(303, $elsewhere);
            self::assertResponseRedirects('/', null, $elsewhere);
        }
    }

    public function testAnUploadWithoutAValidTokenOrToARecordNotSeenIsRefused(): void
    {
        $this->seed();

        $this->upload($this->file('x', 'a.txt'), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->upload($this->file('x', 'a.txt'), ['owner_id' => Id::generate()]);
        self::assertResponseStatusCodeSame(404);

        $this->upload($this->file('x', 'a.txt'), ['owner_kind' => 'invoice']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attachment'));
    }

    public function testAnAttachmentThatIsUnknownDeletedOfAnotherTenantOrOfARecordNotSeenIsNotFound(): void
    {
        $this->seed();
        $this->upload($this->file('report', 'report.txt'));
        $id = $this->uploaded('id');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM attachment WHERE id = ?', [$id]);
        self::assertIsArray($row);

        $this->client->request('GET', '/attachments/'.Id::generate());
        self::assertResponseStatusCodeSame(404);

        $other = $this->browser->fixtures->tenant('Other Organisation');
        $foreign = $this->copy($row, ['tenant_id' => $other->toString()]);
        $unclaimed = $this->copy($row, ['owner_kind' => 'invoice']);
        $orphan = $this->copy($row, ['owner_id' => Id::generate()]);
        foreach ([$foreign, $unclaimed, $orphan] as $hidden) {
            $this->client->request('GET', '/attachments/'.$hidden);
            self::assertResponseStatusCodeSame(404, $hidden);
        }

        $this->client->request('GET', '/attachments/'.$id);
        self::assertResponseIsSuccessful();
        $this->em->getConnection()->update('attachment', ['deleted_at' => '2026-11-08 10:00:00'], ['id' => $id]);
        $this->client->request('GET', '/attachments/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsDenied(): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: Role::Guest);
        $this->browser->signIn();

        $this->client->request('GET', '/attachments/'.Id::generate());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/attachments');
        self::assertResponseStatusCodeSame(403);
    }

    private function assertGuarded(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame("sandbox; default-src 'none'", $headers->get('Content-Security-Policy'));
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertTrue($headers->hasCacheControlDirective('no-cache'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(UploadedFile $file, array $fields = []): void
    {
        $this->client->request('POST', '/attachments', $fields + [
            'owner_kind' => 'task',
            'owner_id' => $this->task,
            '_token' => $this->token(),
        ], ['file' => $file], ['HTTP_ACCEPT' => isset($fields['return']) ? 'text/html' : 'application/json']);
    }

    /**
     * A token of the signed-in session, as the task page will render it.
     */
    private function token(): string
    {
        $session = $this->client->getRequest()->getSession();
        $request = new Request();
        $request->setSession($session);
        $stack = self::getContainer()->get('request_stack');
        $stack->push($request);
        try {
            $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken(AttachmentController::TOKEN)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();

        return $token;
    }

    private function file(string $bytes, string $name, ?string $clientType = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, $clientType, null, true);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function uploaded(string $field): string
    {
        $value = $this->json()[$field] ?? null;
        self::assertIsString($value);

        return $value;
    }

    private function png(): string
    {
        $png = base64_decode(self::PNG, true);
        if (false === $png) {
            throw new \LogicException('The pixel is not base64.');
        }

        return $png;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $changes
     */
    private function copy(array $row, array $changes): string
    {
        $id = Id::generate();
        $this->em->getConnection()->insert('attachment', ['id' => $id] + $changes + $row);

        return $id;
    }

    private function seed(): void
    {
        [$this->tenant] = $this->browser->seed(['Example Organisation']);
        $this->browser->signIn();
        $crawler = $this->client->request('GET', '/projects/new');
        $this->client->submit($crawler->filter('#project-form')->form(['name' => 'Website']));
        $projectId = $this->em->getConnection()->fetchOne('SELECT id FROM project WHERE name = ?', ['Website']);
        self::assertIsString($projectId);
        $this->context->switchTo($this->tenant);
        try {
            $project = $this->em->find(Project::class, $projectId) ?? throw new \LogicException('Project.');
            $status = $this->em->getRepository(ProjectStatus::class)->findOneBy(['project' => $project], ['position' => 'ASC']) ?? throw new \LogicException('Status.');
            $me = $this->em->getRepository(Membership::class)->findOneBy(['role' => Role::Owner]) ?? throw new \LogicException('Membership.');
            $task = new Task($project, 'Wireframes', $status, 0, $me->user()->id(), new \DateTimeImmutable());
            $this->em->persist($task);
            $this->em->flush();
            $this->task = $task->id();
        } finally {
            $this->context->reset();
        }
    }
}
