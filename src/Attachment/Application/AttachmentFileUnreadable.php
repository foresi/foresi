<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

/**
 * The stored file of an attachment is missing, of another attachment or no
 * longer the one that was uploaded.
 */
final class AttachmentFileUnreadable extends \RuntimeException
{
}
