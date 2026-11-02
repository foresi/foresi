<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A message did not go out. A permanent refusal is not retried. The reason is
 * safe to store and show: an SMTP code and a fixed phrase, never the server's own
 * text, which may echo the username or more. No previous exception is kept, so
 * that text cannot travel with this one into a queue or a log.
 *
 * A settings problem is one the firm most likely fixes in its e-mail settings:
 * a refused login, a failed secure connection or certificate, a host that is not
 * allowed, or a password the configured keys cannot open.
 */
final class MailRefused extends \RuntimeException
{
    public const string PASSWORD_UNREADABLE = 'password_unreadable';
    public const string BAD_ADDRESS = 'bad_address';
    public const string LOGIN_REFUSED = 'login_refused';
    public const string MESSAGE_REFUSED = 'message_refused';
    public const string TRY_LATER = 'try_later';
    public const string UNEXPECTED_ANSWER = 'unexpected_answer';
    public const string SECURE_CONNECTION = 'secure_connection';
    public const string UNREACHABLE = 'unreachable';
    public const string UNRESOLVED = 'unresolved';
    public const string HOST_NOT_ALLOWED = 'host_not_allowed';
    public const string NO_SERVER = 'no_server';
    public const string UNBUILDABLE = 'unbuildable';
    public const string NOT_HANDED_OVER = 'not_handed_over';
    public const string COPY_UNKNOWN = 'copy_unknown';
    public const string GAVE_UP = 'gave_up';

    /**
     * @param string   $reasonCode one of the constants: what the screen translates, beside the English reason kept for logs and old rows
     * @param int|null $smtpCode   the code of the server's answer, if it gave one
     */
    public function __construct(
        public readonly bool $permanent,
        public readonly string $safeReason,
        public readonly bool $settingsProblem = false,
        public readonly string $reasonCode = self::UNREACHABLE,
        public readonly ?int $smtpCode = null,
    ) {
        parent::__construct($safeReason);
    }
}
