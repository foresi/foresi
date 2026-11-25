<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Application;

use Foresi\ExchangeRate\Application\RateSources;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\ExchangeRate\Domain\RateSourceSetting;
use Foresi\ExchangeRate\Infrastructure\ChosenRateSource;
use Foresi\Tests\ExchangeRate\Support\FakeRateRules;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\ExchangeRate\Support\InMemoryRateSettings;
use Foresi\Tests\ExchangeRate\Support\OneFirm;
use PHPUnit\Framework\TestCase;

/**
 * Which source the firm converts at: the one it chose while it is installed
 * and allowed, else its country's default, else the first installed; and
 * with none installed, no source at all.
 */
final class RateSourcesTest extends TestCase
{
    private FakeRateSource $local;
    private FakeRateSource $euro;
    private FakeRateSource $other;

    protected function setUp(): void
    {
        $this->local = new FakeRateSource(name: 'local');
        $this->euro = new FakeRateSource(base: 'EUR', name: 'euro');
        $this->other = new FakeRateSource(name: 'other');
    }

    public function testWithoutASourceNothingIsEverExpectedOrFetched(): void
    {
        $sources = $this->sources([], null, 'CZ');
        $chosen = new ChosenRateSource($sources);

        self::assertNull($sources->current());
        self::assertSame([[], null], [$sources->allowed(), $sources->defaultName()]);
        self::assertSame(ChosenRateSource::NONE, $chosen->name());
        self::assertSame('UTC', $chosen->zone()->getName());
        self::assertLessThan($chosen->expectedAt(), '23:59');
        self::assertLessThan($chosen->settledAt(), '23:59');
        self::assertNull($chosen->fetch(new \DateTimeImmutable('2026-11-10')));
    }

    public function testWithoutRulesEverySourceIsAllowedAndTheFirstIsTheDefault(): void
    {
        $sources = $this->sources([$this->local, $this->euro], null, 'DE');
        $chosen = new ChosenRateSource($sources);

        self::assertSame(['local', 'euro'], $sources->allowed());
        self::assertSame('local', $sources->defaultName());
        self::assertNull($sources->rules());
        self::assertSame('local', $chosen->name());
        self::assertSame(['Europe/Prague', '14:35', '15:00'], [$chosen->zone()->getName(), $chosen->expectedAt(), $chosen->settledAt()]);
        self::assertSame('24.335', $chosen->fetch(new \DateTimeImmutable('2026-11-10'))?->quotes['EUR']->rate);
    }

    public function testTheRulesOfTheFirmsCountryDecideTheDefaultAndWhatIsAllowed(): void
    {
        $sources = $this->sources([$this->other, $this->euro, $this->local], new InMemoryRateSettings(), 'CZ');

        self::assertSame(['local', 'euro'], $sources->allowed(), 'In the order of the rules, without what is not installed or not allowed.');
        self::assertSame('local', $sources->defaultName());
        self::assertSame('CZ', $sources->rules()?->country());
        self::assertSame($this->local, $sources->current());
        self::assertNull($sources->chosenName());
    }

    public function testTheChoiceWinsWhileItIsAllowed(): void
    {
        $sources = $this->sources([$this->local, $this->euro, $this->other], new InMemoryRateSettings(new RateSourceSetting('euro')), 'CZ');
        self::assertSame($this->euro, $sources->current());

        $notAllowed = $this->sources([$this->local, $this->euro, $this->other], new InMemoryRateSettings(new RateSourceSetting('other')), 'CZ');
        self::assertSame($this->local, $notAllowed->current());

        $uninstalled = $this->sources([$this->local], new InMemoryRateSettings(new RateSourceSetting('euro')), 'CZ');
        self::assertSame($this->local, $uninstalled->current());
        self::assertSame('euro', $uninstalled->chosenName());
    }

    public function testRulesOfAnotherCountryOrNoFirmLeaveEverySourceAllowed(): void
    {
        self::assertSame(['local', 'euro', 'other'], $this->sources([$this->local, $this->euro, $this->other], null, 'SK')->allowed());
        self::assertSame(['local', 'euro', 'other'], $this->sources([$this->local, $this->euro, $this->other], null, null)->allowed());
    }

    public function testADefaultThatIsNotInstalledFallsToTheFirstAllowed(): void
    {
        self::assertSame('euro', $this->sources([$this->euro], null, 'CZ')->defaultName());
    }

    public function testWhatWasFoundIsForgottenWhenTheChoiceChanges(): void
    {
        $settings = new InMemoryRateSettings();
        $sources = $this->sources([$this->local, $this->euro], $settings, 'CZ');
        self::assertSame($this->local, $sources->current());

        $settings->add(new RateSourceSetting('euro'));
        self::assertSame($this->local, $sources->current(), 'Kept until it is forgotten.');
        $sources->forget();
        self::assertSame($this->euro, $sources->current());
        $settings->stored?->choose('local');
        $sources->forget();
        self::assertSame($this->local, $sources->current());
    }

    public function testASecondSourceOfTheSameNameIsIgnored(): void
    {
        $twin = new FakeRateSource(name: 'local');

        self::assertSame(['local' => $this->local], $this->sources([$this->local, $twin], null, null)->installed());
    }

    /**
     * @param list<RateSource> $installed
     */
    private function sources(array $installed, ?InMemoryRateSettings $settings, ?string $country): RateSources
    {
        return new RateSources($installed, [new FakeRateRules('CZ', ['local', 'euro'], 'local')], $settings ?? new InMemoryRateSettings(), OneFirm::in($country));
    }
}
