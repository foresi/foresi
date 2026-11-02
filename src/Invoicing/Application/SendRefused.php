<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A send was not queued, and nothing of it was stored. The reason is one of the
 * constants, a stable name the screen translates.
 */
final class SendRefused extends \RuntimeException
{
    public const string TRASHED = 'trashed';
    public const string NO_SERVER = 'no_server';
    public const string NO_RECIPIENT = 'no_recipient';
    public const string BAD_ADDRESS = 'bad_address';
    public const string TOO_MANY_RECIPIENTS = 'too_many_recipients';
    public const string BAD_SUBJECT = 'bad_subject';
    public const string BAD_BODY = 'bad_body';
    public const string UNKNOWN_VARIABLE = 'unknown_variable';
    public const string LOCAL_LINK_BASE = 'local_link_base';
    public const string INSECURE_LINK_BASE = 'insecure_link_base';
    public const string RATE_LIMITED = 'rate_limited';
    public const string UNDRAWABLE = 'undrawable';

    /**
     * @param list<string> $details what the reason is about, such as the address or the unknown tokens
     */
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct(\sprintf('The send was refused: %s.', $reason));
    }
}
