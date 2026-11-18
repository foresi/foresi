<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Runs one declared operation (ADR 0032): it names the input class that
 * carries #[Operation], calls the module's use case with it and answers the
 * view the read endpoints answer. It checks nothing itself; the use case
 * decides what the token's person may do, and the scope, the confirmation
 * flag and the input are checked before it is called.
 */
#[AutoconfigureTag(self::TAG)]
interface OperationHandler
{
    public const string TAG = 'app.operation_handler';

    /**
     * @return class-string the input class carrying #[Operation]
     */
    public static function input(): string;

    /**
     * @param object $input an instance of input()
     *
     * @throws \Foresi\Shared\Application\Refused when the use case refuses
     */
    public function handle(object $input): OperationOutput;
}
