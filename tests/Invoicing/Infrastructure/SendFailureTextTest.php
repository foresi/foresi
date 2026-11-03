<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\MailRefused;
use App\Invoicing\Infrastructure\Form\SendFailureText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SendFailureTextTest extends KernelTestCase
{
    public function testARefusedRecipientIsNamedInTheUsersLanguage(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $texts = new SendFailureText($translator);
        $stored = 'SMTP 550: the mail server refused the recipient <unknown@example.com>.';

        $translator->setLocale('cs');
        self::assertSame('Poštovní server odmítl adresáta unknown@example.com (SMTP 550).', $texts->of(MailRefused::RECIPIENT_REFUSED, 550, $stored));
        $translator->setLocale('en');
        self::assertSame('The mail server refused the recipient unknown@example.com (SMTP 550).', $texts->of(MailRefused::RECIPIENT_REFUSED, 550, $stored));
        self::assertSame('Odd', $texts->of(MailRefused::RECIPIENT_REFUSED, 550, 'Odd'), 'without an address, the stored text');
    }
}
