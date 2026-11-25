<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Which rates the law of one country lets a firm convert at (ADR 0046). The
 * country module of the firm's country offers it (tag
 * "foresi.exchange_rate.rules"): the sources a firm there may choose and the
 * one it uses until it chooses. A source the rules do not allow cannot be
 * chosen. Without rules for the firm's country every installed source is
 * allowed and the first installed is the default.
 */
#[AutoconfigureTag(self::TAG)]
interface RateRules
{
    public const string TAG = 'foresi.exchange_rate.rules';

    /**
     * The ISO 3166-1 alpha-2 code of the country, such as "CZ".
     */
    public function country(): string;

    /**
     * The names of the sources (RateSource::name()) a firm of the country may
     * convert at, in the order a person is offered them.
     *
     * @return list<string>
     */
    public function allowedSources(): array;

    /**
     * The name of the source the firm uses until it chooses one; one of the
     * allowed sources.
     */
    public function defaultSource(): string;
}
