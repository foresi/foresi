<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilter;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantFilterBypassTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantFilterBypass $bypass;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $bypass = $container->get(TenantFilterBypass::class);
        $this->em = $em;
        $this->context = $context;
        $this->bypass = $bypass;

        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $a = TenantId::generate();
        foreach ([[$a, 'a1'], [TenantId::generate(), 'b1']] as [$tenant, $text]) {
            $context->switchTo($tenant);
            $em->persist(new TestNote($text));
            $em->flush();
        }
        $context->switchTo($a);
    }

    public function testSeesAllTenantsInsideAndIsScopedAgainAfterwards(): void
    {
        $inside = $this->bypass->run(fn (): int => \count($this->em->getRepository(TestNote::class)->findAll()));

        self::assertSame(2, $inside);
        self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testReturnsTheCallbackResult(): void
    {
        $active = $this->context->current()->toString();

        self::assertSame($active, $this->bypass->run(fn (): string => $this->context->current()->toString()));
        self::assertSame([2, 3], $this->bypass->run(fn (): array => [\count($this->em->getRepository(TestNote::class)->findAll()), 3]));
    }

    public function testRestoresTheFilterWhenTheCallbackThrows(): void
    {
        try {
            $this->bypass->run($this->explode(...));
            self::fail('The exception must propagate.');
        } catch (\RuntimeException) {
            self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
            self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
        }
    }

    private function explode(): string
    {
        throw new \RuntimeException('boom');
    }

    public function testRefusesObjectsAndRestoresTheFilter(): void
    {
        foreach ([
            fn (): object => $this->em->getRepository(TestNote::class)->findAll()[0],
            fn (): array => $this->em->getRepository(TestNote::class)->findAll(),
            static fn (): array => ['nested' => [new \stdClass()]],
        ] as $callback) {
            try {
                $this->bypass->run($callback);
                self::fail('Objects must be refused.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('scalar', $e->getMessage());
                self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
            }
        }
    }

    public function testRefusesObjectsWhenTheFilterIsAlreadyOff(): void
    {
        $this->em->getFilters()->disable(TenantFilter::NAME);

        $this->expectException(\LogicException::class);
        $this->bypass->run(static fn (): object => new \stdClass());
    }

    public function testStaysFailClosedWithoutActiveTenant(): void
    {
        $this->context->reset();

        self::assertSame(2, $this->bypass->run(fn (): int => \count($this->em->getRepository(TestNote::class)->findAll())));

        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    public function testNestedRunRestoresOnlyAtTheOutermostLevel(): void
    {
        $this->bypass->run(function (): void {
            $this->bypass->run(static fn (): null => null);
            self::assertFalse($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        });

        self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
    }

    public function testLeavesAFilterThatWasSwitchedOffAlone(): void
    {
        $this->em->getFilters()->disable(TenantFilter::NAME);

        $this->bypass->run(static fn (): null => null);

        self::assertFalse($this->em->getFilters()->isEnabled(TenantFilter::NAME));
    }
}
