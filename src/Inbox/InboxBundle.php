<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox;

use Foresi\Inbox\Infrastructure\DependencyInjection\InboxActionPass;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A person's own inbox: the mail that arrives at their inbox address, each an
 * item matched to the sender's contact and seen by its owner alone. The
 * actions on an item are the operations modules declare with #[InboxAction];
 * the build fails for one Inbox could not run (InboxActionPass).
 */
final class InboxBundle extends ModuleBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new InboxActionPass());
    }
}
