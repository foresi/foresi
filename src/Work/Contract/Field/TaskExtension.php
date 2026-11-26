<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract\Field;

use Foresi\Shared\Contract\Field\RecordExtension;

/**
 * An entity of another module that adds custom fields to a task (ADR 0045): its id
 * is the id of the task, and each of its other properties has
 * Foresi\Shared\Contract\Field\CustomField. Changes of it show in the history of
 * the task.
 */
interface TaskExtension extends RecordExtension
{
    /** The record kind of its fields, as Foresi\Shared\Contract\Field\Fields names it. */
    public const string RECORD = 'task';

    /**
     * The id of the task the fields belong to.
     */
    public function taskId(): string;
}
