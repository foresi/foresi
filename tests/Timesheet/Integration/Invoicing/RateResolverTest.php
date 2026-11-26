<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Invoicing;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Application\ProjectTimeSettingStore;
use Foresi\Timesheet\Contract\ProjectRateSource;
use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Timesheet\Integration\Invoicing\RateResolver;
use Foresi\Timesheet\Integration\Invoicing\RateSource;
use Foresi\Timesheet\Integration\Invoicing\UnknownRateTarget;
use Foresi\Work\Application\Projects;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The precedence of hourly rates: a rate another module sets for the project
 * (the hourly deal line), else the project, else its client for the invoicing
 * defaults, else the person.
 */
final class RateResolverTest extends TestCase
{
    /** @var array<string, ProjectTimeSetting> the time settings of the projects, by id */
    private array $settings = [];

    /** @var array<string, InvoicingDefaults> the defaults of the clients by party id */
    private array $clientDefaults = [];

    /**
     * Rates as "1500 CZK"; null for none.
     *
     * @return iterable<string, array{rates: array{project?: string, client?: string, person?: string}, client: bool, expected: ?array{string, RateSource}}>
     */
    public static function levels(): iterable
    {
        yield 'the project alone' => ['rates' => ['project' => '1500 CZK'], 'client' => true, 'expected' => ['1500 CZK', RateSource::Project]];
        yield 'the client alone' => ['rates' => ['client' => '1200 CZK'], 'client' => true, 'expected' => ['1200 CZK', RateSource::Client]];
        yield 'the person alone' => ['rates' => ['person' => '900 CZK'], 'client' => true, 'expected' => ['900 CZK', RateSource::Person]];
        yield 'the project over the client' => ['rates' => ['project' => '1500 CZK', 'client' => '1200 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['1500 CZK', RateSource::Project]];
        yield 'the client over the person' => ['rates' => ['client' => '1200 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['1200 CZK', RateSource::Client]];
        yield 'zero is a rate' => ['rates' => ['project' => '0 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['0 CZK', RateSource::Project]];
        yield 'none' => ['rates' => [], 'client' => true, 'expected' => null];
        yield 'a project without a client goes to the person' => ['rates' => ['person' => '900 USD'], 'client' => false, 'expected' => ['900 USD', RateSource::Person]];
        yield 'a project without a client and a person without a rate' => ['rates' => [], 'client' => false, 'expected' => null];
    }

    /**
     * @param array{project?: string, client?: string, person?: string} $rates
     * @param ?array{string, RateSource}                                $expected
     */
    #[DataProvider('levels')]
    public function testTheRateComesFromTheFirstLevelThatHasOne(array $rates, bool $client, ?array $expected): void
    {
        $party = $client ? new Party(PartyKind::Person, 'Example Client', null, null, '') : null;
        if ($party instanceof Party) {
            $this->clientDefaults[$party->id()] = $this->defaults($this->money($rates['client'] ?? null));
        }
        $project = new Project('Website', $party, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $this->rate($project, $this->money($rates['project'] ?? null));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);
        $membership->changeHourlyRate($this->money($rates['person'] ?? null));

        $resolved = $this->resolver($project, $membership)->resolve($project->id(), $membership->id());

        if (null === $expected) {
            self::assertNull($resolved);

            return;
        }
        self::assertNotNull($resolved);
        self::assertEquals($this->money($expected[0]), $resolved->rate);
        self::assertSame($expected[1], $resolved->source);
    }

    public function testALinkedDealLineRateComesFirst(): void
    {
        $party = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->clientDefaults[$party->id()] = $this->defaults(Money::of('1100', Currency::CZK));
        $project = new Project('DUD', $party, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $this->rate($project, Money::of('1000', Currency::CZK));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);
        $membership->changeHourlyRate(Money::of('900', Currency::CZK));
        $asked = new \ArrayObject();
        $none = $this->source(null, $asked);
        $line = $this->source(Money::of('1200', Currency::CZK), $asked);
        $never = $this->source(Money::of('1', Currency::CZK), $asked);

        $resolved = $this->resolver($project, $membership, [$none, $line, $never])->resolve($project->id(), $membership->id());

        self::assertNotNull($resolved);
        self::assertTrue(Money::of('1200', Currency::CZK)->equals($resolved->rate));
        self::assertSame(RateSource::DealLine, $resolved->source);
        self::assertCount(2, $asked, 'the sources are asked in turn until one has a rate');
    }

    public function testWithoutASourceTheOrderIsUnchanged(): void
    {
        $project = new Project('DUD', null, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $this->rate($project, Money::of('1000', Currency::CZK));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);
        $asked = new \ArrayObject();

        $resolved = $this->resolver($project, $membership, [$this->source(null, $asked)])->resolve($project->id(), $membership->id());

        self::assertNotNull($resolved);
        self::assertTrue(Money::of('1000', Currency::CZK)->equals($resolved->rate));
        self::assertSame(RateSource::Project, $resolved->source);
    }

    /**
     * @param \ArrayObject<int, string> $asked the projects it was asked for
     */
    private function source(?Money $rate, \ArrayObject $asked): ProjectRateSource
    {
        return new readonly class($rate, $asked) implements ProjectRateSource {
            /**
             * @param \ArrayObject<int, string> $asked
             */
            public function __construct(private ?Money $rate, private \ArrayObject $asked)
            {
            }

            public function rateFor(string $projectId): ?Money
            {
                $this->asked->append($projectId);

                return $this->rate;
            }
        };
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function unknownTargets(): iterable
    {
        yield 'a project of no organisation' => [false, '01900000-0000-7000-8000-000000000000'];
        yield 'a person of no organisation' => [true, '01900000-0000-7000-8000-000000000000'];
        yield 'no id at all' => [true, 'not an id'];
    }

    #[DataProvider('unknownTargets')]
    public function testAProjectOrAPersonOfNoOrganisationIsRefused(bool $knownProject, string $unknown): void
    {
        $project = new Project('Website', null, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);

        $this->expectException(UnknownRateTarget::class);

        $this->resolver($project, $membership)->resolve($knownProject ? $project->id() : $unknown, $knownProject ? $unknown : $membership->id());
    }

    /**
     * @param list<ProjectRateSource> $sources
     */
    private function resolver(Project $project, Membership $membership, array $sources = []): RateResolver
    {
        $projects = self::createStub(Projects::class);
        $projects->method('project')->willReturnCallback(static fn (string $id): ?Project => $id === $project->id() ? $project : null);
        $people = self::createStub(People::class);
        $people->method('membership')->willReturnCallback(static fn (string $id): ?Membership => $id === $membership->id() ? $membership : null);

        $settings = self::createStub(ProjectTimeSettingStore::class);
        $settings->method('setting')->willReturnCallback(fn (Project $of): ?ProjectTimeSetting => $this->settings[$of->id()] ?? null);

        $defaults = self::createStub(PartyInvoicingDefaults::class);
        $defaults->method('of')->willReturnCallback(fn (string $id): InvoicingDefaults => $this->clientDefaults[$id] ?? InvoicingDefaults::standard());

        return new RateResolver($projects, $people, $settings, $defaults, $sources);
    }

    private function rate(Project $project, ?Money $rate): void
    {
        $this->settings[$project->id()] = new ProjectTimeSetting($project);
        $this->settings[$project->id()]->changeHourlyRate($rate);
    }

    private function defaults(?Money $rate): InvoicingDefaults
    {
        return new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false, true, $rate);
    }

    private function money(?string $text): ?Money
    {
        if (null === $text) {
            return null;
        }
        [$amount, $currency] = explode(' ', $text);

        return Money::of($amount, Currency::from($currency));
    }
}
