<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Banking\Support\BankScreenTestCase;
use Foresi\Tests\Support\Scalars;

/**
 * The Messages tab of the bank screen: every bank mail that came, what became of
 * it in words, and what a person can do about it: look at it, try it again,
 * record it anyway after a trust rejection, or dismiss it. Every write is read
 * back from the database.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class InboundMessageControllerTest extends BankScreenTestCase
{
    public function testTheMessageViewEscapesHtml(): void
    {
        $this->signedIn();
        $id = $this->received($this->plainMail('Hello <b>there</b>', "Line one\n<script>alert('x')</script>"));

        $dialog = $this->dialog('/bank/messages/'.$id);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("<script>alert('x')</script>", $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
        self::assertStringContainsString("<script>alert('x')</script>", $dialog->filter('[data-message-text]')->text());
        self::assertStringContainsString('Hello <b>there</b>', $dialog->filter('[data-message-subject]')->text());
    }

    /**
     * Only the owner sees the inbound address; a forwarding confirmation quotes it, and
     * staff who are not the owner read bank mail too.
     */
    public function testTheMessageViewHidesTheInboundAddress(): void
    {
        $this->signedIn(Role::Member);
        $address = $this->address();
        $token = Scalars::text(preg_replace('/^bank\+([a-z2-7]{26})@example\.com$/D', '$1', $address));
        $id = $this->received($this->plainMail('Forwarding confirmation for '.$address, 'Confirm forwarding to '.strtoupper($address).'.'));

        $this->dialog('/bank/messages/'.$id);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase($token, $content);
        self::assertStringContainsString('bank+…@example.com', $content);
        self::assertStringNotContainsStringIgnoringCase($token, $this->page('/bank?tab=messages')->html());
    }
}
