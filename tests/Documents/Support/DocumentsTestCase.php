<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Documents\Application\Api\AddSignerInput;
use Foresi\Documents\Application\Api\CreateDocumentInput;
use Foresi\Documents\Application\Api\CreateDocumentTemplateInput;
use Foresi\Documents\Application\Api\CreateDocumentTypeInput;
use Foresi\Documents\Application\DocumentFiles;
use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\DocumentsTransaction;
use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Application\TemplateWrites;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Id;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

abstract class DocumentsTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Membership $owner;
    protected FakeLinkTarget $contacts;
    protected FakeFirmVariables $firm;
    protected FakePdfRenderer $pdf;
    protected string $contactId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-22 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->owner = $this->person('owner@example.com', Role::Owner);
        $this->em->flush();
        $this->signIn($this->owner);
        $this->contacts = new FakeLinkTarget();
        $this->contactId = Id::generate();
        $this->contacts->add($this->contactId, 'Example Client Ltd');
        $this->firm = new FakeFirmVariables();
        $this->pdf = new FakePdfRenderer();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function signIn(Membership $membership): void
    {
        $user = $membership->user();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function person(string $email, Role $role): Membership
    {
        $user = new User($email, 'hash', 'Example Person');
        $membership = new Membership($user, $role);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }

    protected function documents(): DocumentWrites
    {
        $container = self::getContainer();

        return new DocumentWrites(
            $container->get(DocumentsAccess::class),
            $container->get(DocumentsStore::class),
            $container->get(DocumentsTransaction::class),
            new LinkTargets([$this->contacts]),
            new DocumentVariables([$this->firm, new FakeClientVariables($this->contacts)]),
            new TemplateRenderer(),
            $this->pdf,
            $container->get(DocumentFiles::class),
            $container->get(EventRecorder::class),
            $this->clock,
        );
    }

    protected function templates(): TemplateWrites
    {
        return self::getContainer()->get(TemplateWrites::class);
    }

    protected function store(): DocumentsStore
    {
        return self::getContainer()->get(DocumentsStore::class);
    }

    /** A type of its own and a template of it, version 1. */
    protected function template(string $body = '<p>{{ firm.name }} and {{ client.name }}</p>'): TemplateVersion
    {
        $type = self::getContainer()->get(\Foresi\Documents\Application\DocumentTypeWrites::class)->create(new CreateDocumentTypeInput('Test agreement', 'test_'.substr(Id::generate(), -8), true, 'simple'));

        return $this->templates()->create(new CreateDocumentTemplateInput($type->id(), 'Test template', $body));
    }

    protected function draft(?TemplateVersion $version = null, ?string $contactId = null): Document
    {
        $version ??= $this->template();
        $template = $this->store()->template($version->templateId());
        self::assertInstanceOf(\Foresi\Documents\Domain\DocumentTemplate::class, $template);

        return $this->documents()->create(new CreateDocumentInput($template->typeId(), $template->id(), 'NDA with Example Client', [['kind' => 'contact', 'id' => $contactId ?? $this->contactId]]));
    }

    protected function signer(Document $document, string $name, int $order, string $side = 'theirs'): Signer
    {
        return $this->documents()->addSigner(new AddSignerInput($document->id(), $name, $side, $order, strtolower(str_replace(' ', '.', $name)).'@example.com'));
    }

    protected function reload(Document $document): Document
    {
        $this->em->clear();
        $fresh = $this->store()->document($document->id());
        self::assertInstanceOf(Document::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<string> the JSON data of the recorded events of this name, oldest first
     */
    protected function eventData(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        return array_map(static fn (mixed $row): string => \is_string($row) ? $row : throw new \LogicException('No event data.'), $rows);
    }

    protected const string PDF = "%PDF-1.7\n% signed copy\n%%EOF\n";
}
