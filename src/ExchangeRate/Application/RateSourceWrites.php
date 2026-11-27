<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\ExchangeRate\Contract\Event\RateSourceChanged;
use Foresi\ExchangeRate\Domain\RateSourceSetting;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * Chooses the source of the firm's exchange rates. A source must be installed
 * and allowed by the rules of the firm's country. A change of the source in
 * use records exchange-rate.source.changed; the audit log keeps who set the
 * row and when.
 */
final readonly class RateSourceWrites
{
    public function __construct(
        private ExchangeRateAccess $access,
        private RateSources $sources,
        private RateSettingStore $store,
        private RateSourceReads $reads,
        private TransactionalWork $transaction,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws ExchangeRateRefused forbidden, or invalid with source_unknown or source_not_allowed
     */
    public function set(SetRateSourceInput $input): RateSourceView
    {
        $this->access->denyUnlessStaff();
        $name = trim($input->source);
        $allowed = $this->sources->allowed();
        if (!$this->sources->named($name) instanceof \Foresi\ExchangeRate\Contract\RateSource) {
            throw ExchangeRateRefused::unknownSource($name, $allowed);
        }
        if (!\in_array($name, $allowed, true)) {
            throw ExchangeRateRefused::notAllowed($name, $this->sources->rules()?->country() ?? '', $allowed);
        }

        $previous = $this->sources->current()?->name();
        $this->transaction->run(function () use ($name, $previous): void {
            $setting = $this->store->setting();
            if ($setting instanceof RateSourceSetting) {
                $setting->choose($name);
            } else {
                $this->store->add(new RateSourceSetting($name));
            }
            if (null !== $previous && $previous !== $name) {
                $this->events->record(new RateSourceChanged($name, $previous));
            }
        });
        $this->sources->forget();

        return $this->reads->view();
    }
}
