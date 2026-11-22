<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\SetVatSettingsInput;
use Foresi\Vat\Application\VatSettingsWrites;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class SetVatSettingsHandler implements OperationHandler
{
    public function __construct(private VatSettingsWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetVatSettingsInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetVatSettingsInput);
        $settings = $this->writes->set($input);

        return new OperationOutput(VatJson::settings($settings), 'Set the VAT payment details.');
    }
}
