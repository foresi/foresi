<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * Why a new sign-in address was not taken, or its link did not work.
 */
final class EmailChangeRefused extends \DomainException implements Refused
{
    public const string INVALID = 'invalid';
    public const string SAME = 'same';
    public const string TAKEN = 'taken';
    public const string MAIL_UNAVAILABLE = 'mail_unavailable';
    public const string LINK_UNKNOWN = 'link_unknown';
    public const string UNKNOWN_USER = 'not_found';

    public const string FIELD = 'newEmail';

    private const array KINDS = [
        self::INVALID => RefusalKind::Invalid,
        self::SAME => RefusalKind::Invalid,
        self::TAKEN => RefusalKind::Conflict,
        self::MAIL_UNAVAILABLE => RefusalKind::Unavailable,
        self::LINK_UNKNOWN => RefusalKind::NotFound,
        self::UNKNOWN_USER => RefusalKind::NotFound,
    ];

    public function __construct(private readonly string $reason)
    {
        if (!isset(self::KINDS[$reason])) {
            throw new \InvalidArgumentException(\sprintf('Unknown reason "%s" to refuse an e-mail change.', $reason));
        }
        parent::__construct(\sprintf('The e-mail change is refused: %s.', $reason));
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return self::KINDS[$this->reason];
    }

    public function field(): ?string
    {
        return \in_array($this->reason, [self::INVALID, self::SAME, self::TAKEN], true) ? self::FIELD : null;
    }

    public function details(): array
    {
        return [];
    }
}
