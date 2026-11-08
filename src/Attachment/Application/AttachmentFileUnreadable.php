<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

/**
 * The stored file of an attachment is missing, of another tenant or no longer
 * the one that was uploaded.
 */
final class AttachmentFileUnreadable extends \RuntimeException
{
}
