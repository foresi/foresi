<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

/**
 * Which source the firm's exchange rates come from, and which it may choose.
 */
final readonly class RateSourceReads
{
    public function __construct(private ExchangeRateAccess $access, private RateSources $sources)
    {
    }

    /**
     * @throws ExchangeRateRefused forbidden
     */
    public function current(): RateSourceView
    {
        $this->access->denyUnlessStaff();

        return $this->view();
    }

    public function view(): RateSourceView
    {
        $current = $this->sources->current()?->name();
        $chosen = $this->sources->chosenName();

        return new RateSourceView(
            $current,
            null !== $current && $current === $chosen,
            $this->sources->defaultName(),
            $this->sources->allowed(),
            array_keys($this->sources->installed()),
            $this->sources->rules()?->country(),
        );
    }
}
