<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\InvalidMailTemplate;
use App\Invoicing\Domain\MailTemplate;
use App\Invoicing\Domain\MailTemplateKind;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailTemplateTest extends TestCase
{
    public function testTheBodyIsTrimmedAndBreaksLinesTheUnixWay(): void
    {
        $template = $this->template(' Invoice {number} ', " \r\nDear customer,\r\nplease pay.\rThank you\n ");

        self::assertSame('Invoice {number}', $template->subject());
        self::assertSame("Dear customer,\nplease pay.\nThank you", $template->body());
    }

    public function testTheLimitsCountCharactersNotBytes(): void
    {
        $template = $this->template(str_repeat('č', MailTemplate::MAX_SUBJECT), str_repeat('č', MailTemplate::MAX_BODY));

        self::assertSame(MailTemplate::MAX_SUBJECT, mb_strlen($template->subject()));
        self::assertSame(MailTemplate::MAX_BODY, mb_strlen($template->body()));
    }

    #[DataProvider('tooLong')]
    public function testAnyTextPastItsLimitIsRefused(string $subject, string $body): void
    {
        $this->expectException(InvalidMailTemplate::class);

        $this->template($subject, $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tooLong(): iterable
    {
        yield 'subject' => [str_repeat('č', MailTemplate::MAX_SUBJECT + 1), 'Body'];
        yield 'body' => ['Subject', str_repeat('č', MailTemplate::MAX_BODY + 1)];
    }

    public function testTheTenantIsKnownOnlyOnceStored(): void
    {
        $this->expectException(\LogicException::class);

        $this->template('Invoice {number}', 'Body')->tenantId();
    }

    private function template(string $subject, string $body): MailTemplate
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);

        return MailTemplate::define($firm, MailTemplateKind::Invoice, DocumentLanguage::English, $subject, $body);
    }
}
