<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Api\Operation;

use Foresi\ExchangeRate\Application\RateSourceWrites;
use Foresi\ExchangeRate\Application\SetRateSourceInput;
use Foresi\ExchangeRate\Infrastructure\Api\ExchangeRateJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class SetRateSourceHandler implements OperationHandler
{
    public function __construct(private RateSourceWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetRateSourceInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetRateSourceInput);
        $view = $this->writes->set($input);

        return new OperationOutput(ExchangeRateJson::source($view), \sprintf('The exchange rates now come from %s.', $view->source ?? 'no source'));
    }
}
