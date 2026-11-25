<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\ExchangeRate\Contract\RateRules;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\LegalEntity;

/**
 * The installed rate sources and which one the firm converts at (ADR 0046):
 * the one it chose, while it is installed and its country's rules allow it;
 * else the default of those rules; else the first installed. The ECB of this
 * module comes after every other source, so without rules a country's own
 * source stays the default. There is no fallback to another source when the
 * chosen one is down: rates of two sources are never mixed.
 *
 * What it found is kept until the next request or message (the container
 * calls forget() then) and until the choice changes.
 */
final class RateSources
{
    /** @var array<string, RateSource>|null by name, in their order */
    private ?array $installed = null;

    private bool $resolved = false;

    private ?RateRules $rules = null;

    private ?RateSource $current = null;

    /**
     * @param iterable<RateSource> $sources
     * @param iterable<RateRules>  $allRules
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly iterable $allRules,
        private readonly RateSettingStore $settings,
        private readonly InstanceFirm $firm,
    ) {
    }

    /**
     * @return array<string, RateSource> by name, in their order
     */
    public function installed(): array
    {
        if (null === $this->installed) {
            $this->installed = [];
            foreach ($this->sources as $source) {
                $this->installed[$source->name()] ??= $source;
            }
        }

        return $this->installed;
    }

    public function named(string $name): ?RateSource
    {
        return $this->installed()[$name] ?? null;
    }

    /**
     * The rules of the firm's country, or null without a firm or without an
     * installed module that has them.
     */
    public function rules(): ?RateRules
    {
        $this->resolve();

        return $this->rules;
    }

    /**
     * The names of the installed sources the firm may choose, in the order of
     * the rules, or of the installation without rules.
     *
     * @return list<string>
     */
    public function allowed(): array
    {
        $installed = array_keys($this->installed());
        $rules = $this->rules();
        if (!$rules instanceof RateRules) {
            return $installed;
        }

        return array_values(array_filter($rules->allowedSources(), static fn (string $name): bool => \in_array($name, $installed, true)));
    }

    /**
     * The name of the source the firm uses until it chooses, or null when no
     * allowed source is installed.
     */
    public function defaultName(): ?string
    {
        $allowed = $this->allowed();
        $default = $this->rules()?->defaultSource();

        return null !== $default && \in_array($default, $allowed, true) ? $default : ($allowed[0] ?? null);
    }

    /**
     * The name the firm chose, or null while it has not chosen.
     */
    public function chosenName(): ?string
    {
        return $this->settings->setting()?->source();
    }

    /**
     * The source the firm converts at now, or null when none is installed.
     */
    public function current(): ?RateSource
    {
        $this->resolve();

        return $this->current;
    }

    /**
     * Forgets what was found, after the choice changed.
     */
    public function forget(): void
    {
        $this->resolved = false;
        $this->rules = null;
        $this->current = null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $this->rules = $this->rulesOf($this->firm->find());
        $chosen = $this->chosenName();
        $name = null !== $chosen && \in_array($chosen, $this->allowed(), true) ? $chosen : $this->defaultName();
        $this->current = null === $name ? null : $this->named($name);
    }

    private function rulesOf(?LegalEntity $firm): ?RateRules
    {
        if (!$firm instanceof LegalEntity) {
            return null;
        }
        foreach ($this->allRules as $rules) {
            if ($firm->address()->countryCode === $rules->country()) {
                return $rules;
            }
        }

        return null;
    }
}
