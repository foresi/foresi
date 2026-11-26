<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Operation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Watches every declared operation the runner runs, over REST and MCP alike,
 * such as Inbox recording what an action on an item made. It sees the
 * declaration and the input, never the request. A screen that runs a handler
 * itself does not go through the runner, so no observer sees it: the Inbox
 * action dialog calls RecordOutputs itself.
 */
#[AutoconfigureTag(self::TAG)]
interface OperationObserver
{
    public const string TAG = 'foresi.shared.operation_observer';

    /**
     * Before the handler, after the scope and the input are checked. A Refused
     * thrown here refuses the operation; nothing is made. Throw nothing else:
     * any other throwable fails the operation as an error.
     *
     * @param object $input an instance of the operation's input class
     *
     * @throws \Foresi\Shared\Contract\Refused
     */
    public function before(Operation $operation, object $input): void;

    /**
     * After a successful handle(), with the answer so far. The handler has
     * committed its record; a failure here must not undo it. The observer
     * answers the output to send: the same one, or one that says what it could
     * not do, such as a longer summary. The runner logs a \Throwable thrown
     * here and sends the output it had.
     *
     * @param object $input an instance of the operation's input class
     */
    public function after(Operation $operation, object $input, OperationOutput $output): OperationOutput;
}
