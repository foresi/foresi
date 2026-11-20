<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Doctrine\DBAL\Connection;
use Foresi\Invoicing\Application\MailTemplates;
use Foresi\Invoicing\Application\UnknownMailVariables;
use Foresi\Invoicing\Domain\InvalidMailTemplate;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Party\Domain\DocumentLanguage;

final class MailTemplatesTest extends InvoiceTestCase
{
    private MailTemplates $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templates = self::getContainer()->get(MailTemplates::class);
    }

    public function testCzechAndEnglishDefaultsDifferAndHoldNumberAmountDueDate(): void
    {
        $czech = $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $english = $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::English);

        self::assertNotSame($czech->body, $english->body);
        self::assertNotSame($czech->subject, $english->subject);
        self::assertSame('Invoice {number} from {firm_name}', $english->subject);
        self::assertStringStartsWith("Hello,\n\nPlease find your invoice {number} below, together with everything you need to pay it.", $english->body);
        foreach ([$czech, $english] as $text) {
            self::assertStringContainsString('{number}', $text->subject);
            foreach (['{number}', '{amount_total}', '{due_date}', '{sender_name}', '{link}'] as $token) {
                self::assertStringContainsString($token, $text->body);
            }
        }
        self::assertStringNotContainsString('{link}', $czech->subject);
        self::assertFalse($this->templates->isCustom(MailTemplateKind::Invoice, DocumentLanguage::Czech));
    }

    public function testASavedTemplateWinsForItsLanguageOnly(): void
    {
        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::Czech, ' Faktura {number} od nás ', "Dobrý den,\n{link}");
        $this->em->flush();
        $this->em->clear();

        $row = $this->connection()->fetchAssociative('SELECT kind, language, subject, body FROM mail_template');
        self::assertSame(['kind' => 'invoice', 'language' => 'cs', 'subject' => 'Faktura {number} od nás', 'body' => "Dobrý den,\n{link}"], $row);

        $saved = $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::Czech);
        self::assertSame('Faktura {number} od nás', $saved->subject);
        self::assertSame("Dobrý den,\n{link}", $saved->body);
        self::assertTrue($this->templates->isCustom(MailTemplateKind::Invoice, DocumentLanguage::Czech));
        self::assertSame('Invoice {number} from {firm_name}', $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::English)->subject);
    }

    public function testSavingAgainChangesTheOneRow(): void
    {
        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::English, 'First {number}', 'First {link}');
        $this->em->flush();
        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::English, 'Second {number}', 'Second {link}');
        $this->em->flush();
        $this->em->clear();

        self::assertSame([['subject' => 'Second {number}', 'body' => 'Second {link}']], $this->connection()->fetchAllAssociative('SELECT subject, body FROM mail_template'));
    }

    public function testResetReturnsTheBuiltInText(): void
    {
        $builtIn = $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Own {number}', 'Own body');
        $this->em->flush();

        $this->templates->reset(MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
        self::assertEquals($builtIn, $this->templates->textFor(MailTemplateKind::Invoice, DocumentLanguage::Czech));
    }

    public function testAnUnknownVariableIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Invoice {numbr}', 'Pay {amount} {link}');
            self::fail('Expected the template to be refused.');
        } catch (UnknownMailVariables $refused) {
            self::assertSame(['numbr'], $refused->inSubject);
            self::assertSame(['amount'], $refused->inBody);
        }
        $this->em->flush();

        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testTheLinkCannotBeInTheSubject(): void
    {
        $this->expectException(UnknownMailVariables::class);

        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Invoice {link}', 'Body');
    }

    public function testAnEmptySubjectIsRefused(): void
    {
        $this->expectException(InvalidMailTemplate::class);

        $this->templates->save(MailTemplateKind::Invoice, DocumentLanguage::Czech, '  ', 'Body');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
