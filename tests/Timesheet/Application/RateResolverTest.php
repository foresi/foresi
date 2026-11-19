<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Application;

use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Application\RateResolver;
use Foresi\Timesheet\Application\UnknownRateTarget;
use Foresi\Timesheet\Domain\RateSource;
use Foresi\Work\Application\Projects;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The precedence of hourly rates: the project, else its client for the
 * invoicing defaults, else the person.
 */
final class RateResolverTest extends TestCase
{
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
        $firm = $this->firm('Example Firm', '12345695');
        $party = $client ? new Party(PartyKind::Person, 'Example Client', null, null, '') : null;
        $party?->changeInvoicingDefaults($this->defaults($this->money($rates['client'] ?? null)));
        $project = new Project('Website', $party, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $project->changeHourlyRate($this->money($rates['project'] ?? null));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);
        $membership->changeHourlyRate($this->money($rates['person'] ?? null));

        $resolved = $this->resolver($project, $membership, $firm)->resolve($project->id(), $membership->id(), $firm->id());

        if (null === $expected) {
            self::assertNull($resolved);

            return;
        }
        self::assertNotNull($resolved);
        self::assertEquals($this->money($expected[0]), $resolved->rate);
        self::assertSame($expected[1], $resolved->source);
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
        $firm = $this->firm('Example Firm', '12345695');
        $project = new Project('Website', null, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);

        $this->expectException(UnknownRateTarget::class);

        $this->resolver($project, $membership, $firm)->resolve($knownProject ? $project->id() : $unknown, $knownProject ? $unknown : $membership->id(), $firm->id());
    }

    private function resolver(Project $project, Membership $membership, LegalEntity $firm): RateResolver
    {
        $projects = self::createStub(Projects::class);
        $projects->method('project')->willReturnCallback(static fn (string $id): ?Project => $id === $project->id() ? $project : null);
        $people = self::createStub(People::class);
        $people->method('membership')->willReturnCallback(static fn (string $id): ?Membership => $id === $membership->id() ? $membership : null);
        $legalEntities = self::createStub(LegalEntities::class);
        $legalEntities->method('get')->willReturnCallback(static fn (string $id): LegalEntity => $id === $firm->id() ? $firm : throw new \LogicException('An unknown firm.'));

        return new RateResolver($projects, $people, $legalEntities);
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

    private function firm(string $name, string $number): LegalEntity
    {
        return new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
    }
}
