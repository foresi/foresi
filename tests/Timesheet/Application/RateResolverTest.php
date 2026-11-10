<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Application;

use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\FirmSettings;
use App\Party\Application\LegalEntities;
use App\Party\Application\Parties;
use App\Party\Application\PartyDetails;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Timesheet\Application\RateResolver;
use App\Timesheet\Application\UnknownRateTarget;
use App\Timesheet\Domain\RateSource;
use App\Work\Application\Projects;
use App\Work\Domain\Project;
use App\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * The precedence of hourly rates: the project, else its client for the
 * invoicing firm (the firm's own defaults, else the shared ones), else the person.
 */
final class RateResolverTest extends TestCase
{
    private const string FIRM = 'invoicing';
    private const string OTHER_FIRM = 'other';

    /**
     * Rates as "1500 CZK"; null for none. A firm's own entry is its own invoicing
     * defaults, with that rate or none.
     *
     * @return iterable<string, array{rates: array{project?: string, client?: string, firm?: ?string, otherFirm?: ?string, person?: string}, client: bool, expected: ?array{string, RateSource}}>
     */
    public static function levels(): iterable
    {
        yield 'the project alone' => ['rates' => ['project' => '1500 CZK'], 'client' => true, 'expected' => ['1500 CZK', RateSource::Project]];
        yield 'the client alone' => ['rates' => ['client' => '1200 CZK'], 'client' => true, 'expected' => ['1200 CZK', RateSource::Client]];
        yield 'the person alone' => ['rates' => ['person' => '900 CZK'], 'client' => true, 'expected' => ['900 CZK', RateSource::Person]];
        yield 'the project over the client' => ['rates' => ['project' => '1500 CZK', 'client' => '1200 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['1500 CZK', RateSource::Project]];
        yield 'the client over the person' => ['rates' => ['client' => '1200 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['1200 CZK', RateSource::Client]];
        yield "the invoicing firm's own client rate over the shared one" => ['rates' => ['client' => '1200 CZK', 'firm' => '50.50 EUR', 'person' => '900 CZK'], 'client' => true, 'expected' => ['50.50 EUR', RateSource::Client]];
        yield "another firm's own client rate does not count" => ['rates' => ['client' => '1200 CZK', 'otherFirm' => '60 EUR'], 'client' => true, 'expected' => ['1200 CZK', RateSource::Client]];
        yield "the firm's own defaults without a rate leave the client without one" => ['rates' => ['client' => '1200 CZK', 'firm' => null, 'person' => '900 CZK'], 'client' => true, 'expected' => ['900 CZK', RateSource::Person]];
        yield 'zero is a rate' => ['rates' => ['project' => '0 CZK', 'person' => '900 CZK'], 'client' => true, 'expected' => ['0 CZK', RateSource::Project]];
        yield 'none' => ['rates' => [], 'client' => true, 'expected' => null];
        yield 'a project without a client goes to the person' => ['rates' => ['person' => '900 USD'], 'client' => false, 'expected' => ['900 USD', RateSource::Person]];
        yield 'a project without a client and a person without a rate' => ['rates' => [], 'client' => false, 'expected' => null];
    }

    /**
     * @param array{project?: string, client?: string, firm?: ?string, otherFirm?: ?string, person?: string} $rates
     * @param ?array{string, RateSource}                                                                     $expected
     */
    #[DataProvider('levels')]
    public function testTheRateComesFromTheFirstLevelThatHasOne(array $rates, bool $client, ?array $expected): void
    {
        $firms = [self::FIRM => $this->firm('Example Firm', '12345695'), self::OTHER_FIRM => $this->firm('Other Firm', '12345687')];
        $party = $client ? new Party(PartyKind::Person, 'Example Client', null, null, '') : null;
        $own = [];
        if ($party instanceof Party) {
            $party->changeInvoicingDefaults($this->defaults($this->money($rates['client'] ?? null)));
            foreach (['firm' => self::FIRM, 'otherFirm' => self::OTHER_FIRM] as $level => $firm) {
                if (\array_key_exists($level, $rates)) {
                    $row = new PartyFirmSettings($party, $firms[$firm]);
                    $row->changeInvoicingDefaults($this->defaults($this->money($rates[$level])));
                    $own[$firm] = $row;
                }
            }
        }
        $project = new Project('Website', $party, WorkflowTemplate::Simple, 'creator', new \DateTimeImmutable('2026-11-10 10:00'));
        $project->changeHourlyRate($this->money($rates['project'] ?? null));
        $membership = new Membership(new User('person@example.com', 'hash', 'Example Person'), Role::Member);
        $membership->changeHourlyRate($this->money($rates['person'] ?? null));

        $resolved = $this->resolver($project, $membership, $firms, $own)->resolve($project->id(), $membership->id(), $firms[self::FIRM]->id());

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

        $this->resolver($project, $membership, [self::FIRM => $firm], [])->resolve($knownProject ? $project->id() : $unknown, $knownProject ? $unknown : $membership->id(), $firm->id());
    }

    /**
     * @param array<string, LegalEntity>       $firms by key
     * @param array<string, PartyFirmSettings> $own   by firm key
     */
    private function resolver(Project $project, Membership $membership, array $firms, array $own): RateResolver
    {
        $projects = self::createStub(Projects::class);
        $projects->method('project')->willReturnCallback(static fn (string $id): ?Project => $id === $project->id() ? $project : null);
        $people = self::createStub(People::class);
        $people->method('membership')->willReturnCallback(static fn (string $id): ?Membership => $id === $membership->id() ? $membership : null);
        $legalEntities = self::createStub(LegalEntities::class);
        $legalEntities->method('get')->willReturnCallback(static fn (string $id): LegalEntity => array_find($firms, static fn (LegalEntity $firm): bool => $firm->id() === $id) ?? throw new \LogicException('An unknown firm.'));
        $settings = self::createStub(FirmSettings::class);
        $settings->method('find')->willReturnCallback(static fn (Party $party, LegalEntity $firm): ?PartyFirmSettings => array_find($own, static fn (PartyFirmSettings $row): bool => $row->party() === $party && $row->legalEntity() === $firm));
        $contacts = new ContactSettingsForFirm($settings, self::createStub(PartyDetails::class), self::createStub(Parties::class), self::createStub(ClockInterface::class), $legalEntities);

        return new RateResolver($projects, $people, $legalEntities, $contacts);
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
