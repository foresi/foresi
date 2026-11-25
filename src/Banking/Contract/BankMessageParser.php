<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

use Foresi\Mail\Contract\InboundMail;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reads the notification mails of one bank. A country module implements it;
 * the tag foresi.banking.message_parser gathers the implementations.
 *
 * Raising the version marks earlier failures of this parser for another try.
 */
#[AutoconfigureTag(self::TAG)]
interface BankMessageParser
{
    public const string TAG = 'foresi.banking.message_parser';

    /**
     * A stable identifier, stored with every message the parser read.
     */
    public function id(): string;

    public function version(): int;

    /**
     * The domains the bank sends from; their subdomains count too.
     *
     * @return list<string>
     */
    public function senderDomains(): array;

    /**
     * Whether the mail looks like one of this bank's notifications. It decides
     * nothing about trust.
     */
    public function supports(InboundMail $mail): bool;

    /**
     * @throws ParseFailed when the mail is the bank's but cannot be read safely
     */
    public function parse(InboundMail $mail): ParseResult;
}
