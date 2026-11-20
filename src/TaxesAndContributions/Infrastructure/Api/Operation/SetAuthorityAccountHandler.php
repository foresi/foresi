<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;

final readonly class SetAuthorityAccountHandler implements OperationHandler
{
    public function __construct(private ProfileWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetAuthorityAccountInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetAuthorityAccountInput);
        $account = $this->writes->setAuthority($input);

        return new OperationOutput(TaxesJson::authority($account), \sprintf('Set the account of %s.', $account->authority));
    }
}
