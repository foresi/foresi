<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Files attached to records; the module that owns a record decides who
 * reaches them.
 */
final class AttachmentBundle extends ModuleBundle
{
}
