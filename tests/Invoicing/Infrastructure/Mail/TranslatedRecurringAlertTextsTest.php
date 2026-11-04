<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\RecurringAlertTexts;
use App\Party\Domain\DocumentLanguage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The alert texts come from the translations, in the document's language,
 * whatever the locale of the request, with a link into the app on DEFAULT_URI.
 */
final class TranslatedRecurringAlertTextsTest extends KernelTestCase
{
    public function testOneTemplateInCzechWithALinkToTheFailingTemplates(): void
    {
        $texts = $this->texts();

        $mail = $texts->compose('Example Firm s.r.o.', DocumentLanguage::Czech, [['customer' => 'Example Customer a.s.', 'code' => 'send.no_server']], new \DateTimeImmutable('2026-12-01'));

        self::assertSame('Pravidelná faktura se vystavila, ale neodeslala', $mail['subject']);
        self::assertStringContainsString('Pravidelná faktura firmy Example Firm s.r.o. pro Example Customer a.s. se 1. 12. 2026 vystavila', $mail['text']);
        self::assertStringContainsString('Firma nemá nastavený poštovní server.', $mail['text']);
        self::assertStringContainsString('Pravidelné faktury ve Foresi: http://localhost/invoices/recurring?tab=failing', $mail['text']);
    }

    public function testSeveralTemplatesAreListedUnderACountedSubject(): void
    {
        $texts = $this->texts();
        $two = [['customer' => 'First a.s.', 'code' => 'send.rate_limited'], ['customer' => 'Second a.s.', 'code' => 'send.rate_limited']];

        self::assertSame('2 pravidelné faktury se vystavily, ale neodeslaly', $texts->compose('Example Firm s.r.o.', DocumentLanguage::Czech, $two, new \DateTimeImmutable('2026-12-01'))['subject']);
        $english = $texts->compose('Example Firm', DocumentLanguage::English, [...$two, ['customer' => 'Third Ltd', 'code' => 'invoice.problem.customer_archived']], new \DateTimeImmutable('2026-12-01'));
        self::assertSame('3 recurring invoices could not be issued or sent', $english['subject']);
        self::assertStringContainsString("- First a.s.: The firm has used up its daily budget of e-mails.\n", $english['text']);
        self::assertStringContainsString('- Third Ltd: The customer is archived for this firm.', $english['text']);
        self::assertStringContainsString('(code invoice.problem.unheard_of)', $texts->compose('Example Firm', DocumentLanguage::English, [['customer' => 'X', 'code' => 'invoice.problem.unheard_of']], new \DateTimeImmutable('2026-12-01'))['text'], 'a code without a text of its own is named');
    }

    private function texts(): RecurringAlertTexts
    {
        self::bootKernel();

        return self::getContainer()->get(RecurringAlertTexts::class);
    }
}
