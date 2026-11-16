<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * A URL that may not receive webhooks. The reason is one of the constants, for
 * the API and the screen; the message never repeats the URL, which may carry a
 * password.
 */
final class InvalidWebhookUrl extends \InvalidArgumentException
{
    public const string SCHEME = 'scheme';
    public const string HOST = 'host';
    public const string USERINFO = 'userinfo';
    public const string LENGTH = 'length';
    public const string PRIVATE_ADDRESS = 'private_address';
    public const string UNRESOLVABLE = 'unresolvable';

    private const array MESSAGES = [
        self::SCHEME => 'A webhook URL must use https.',
        self::HOST => 'A webhook URL must name a valid host.',
        self::USERINFO => 'A webhook URL may not carry a user name or password.',
        self::LENGTH => 'A webhook URL may have at most 2048 characters.',
        self::PRIVATE_ADDRESS => 'A webhook URL may not point to a private, loopback or link-local address.',
        self::UNRESOLVABLE => 'The host of the webhook URL could not be resolved.',
    ];

    /**
     * @param self::SCHEME|self::HOST|self::USERINFO|self::LENGTH|self::PRIVATE_ADDRESS|self::UNRESOLVABLE $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }
}
