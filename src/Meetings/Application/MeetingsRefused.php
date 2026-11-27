<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * Why Meetings refused a read or a write, as every surface answers it (ADR
 * 0032): the reason, its kind and the field it concerns. What the person may
 * not see is not found.
 */
final class MeetingsRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    private function __construct(private readonly string $reason, private readonly RefusalKind $kind, private readonly ?string $field, string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function meetingNotFound(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'There is no meeting with this id that you see. Find it with foresi_search_meetings.');
    }

    public static function seriesNotFound(?string $field = null): self
    {
        return new self(null === $field ? 'not_found' : 'series_not_found', null === $field ? RefusalKind::NotFound : RefusalKind::Invalid, $field, 'There is no meeting series with this id that you see. Find it with foresi_list_meeting_series.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation keep its meetings.');
    }

    public static function notSeriesMember(): self
    {
        return new self('not_series_member', RefusalKind::Forbidden, null, 'Only the owners and the members of a series change it.');
    }

    public static function notTrasher(): self
    {
        return new self('not_trasher', RefusalKind::Forbidden, null, 'Only the person who created the meeting and the owners put it in the trash or restore it.');
    }

    public static function seriesArchived(): self
    {
        return new self('series_archived', RefusalKind::Conflict, 'seriesId', 'The series is archived and takes no new meetings.');
    }

    public static function nameTaken(): self
    {
        return new self('name_taken', RefusalKind::Conflict, 'name', 'Another series of the firm has this name.');
    }

    public static function trashed(): self
    {
        return new self('trashed', RefusalKind::Conflict, null, 'The meeting is in the trash. Restore it with foresi_restore_meeting first.');
    }

    public static function staleMinutes(int $current): self
    {
        return new self('stale_minutes', RefusalKind::Conflict, 'version', 'Someone else changed the minutes. Reload to see their version.', ['current_version' => $current]);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return $this->details;
    }
}
