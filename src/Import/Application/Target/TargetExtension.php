<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Application\Target;

use Foresi\Import\Contract\ImportTarget;

/**
 * What the registry of targets does to each target before it hands it out, such
 * as adding the custom fields of a FieldRecordTarget's records.
 */
interface TargetExtension
{
    public function extend(ImportTarget $target): ImportTarget;
}
