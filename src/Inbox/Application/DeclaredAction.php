<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Shared\Infrastructure\Operation\OperationDefinition;

/**
 * An operation declared as an action on an inbox item: its definition and its #[InboxAction].
 */
final readonly class DeclaredAction
{
    public function __construct(public OperationDefinition $definition, public InboxAction $action)
    {
    }

    /** The operation's name, such as "inbox.actions.create_task". */
    public function name(): string
    {
        return $this->definition->operation->name;
    }
}
