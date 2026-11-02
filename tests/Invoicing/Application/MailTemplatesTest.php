<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\UnknownMailVariables;
use App\Invoicing\Domain\InvalidMailTemplate;
use App\Invoicing\Domain\MailTemplateKind;
use App\Party\Domain\DocumentLanguage;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;

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
        $czech = $this->templates->textFor($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $english = $this->templates->textFor($this->firm, MailTemplateKind::Invoice, DocumentLanguage::English);

        self::assertNotSame($czech->body, $english->body);
        self::assertNotSame($czech->subject, $english->subject);
        self::assertSame('Invoice {number}', $english->subject);
        self::assertStringStartsWith('Based on your order I am sending invoice no. {number} for {amount_total}, due on {due_date}.', $english->body);
        foreach ([$czech, $english] as $text) {
            self::assertStringContainsString('{number}', $text->subject);
            foreach (['{number}', '{amount_total}', '{due_date}', '{sender_name}', '{link}'] as $token) {
                self::assertStringContainsString($token, $text->body);
            }
        }
        self::assertStringNotContainsString('{link}', $czech->subject);
        self::assertFalse($this->templates->isCustom($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech));
    }

    public function testASavedTemplateWinsForItsFirmAndLanguageOnly(): void
    {
        $other = $this->firm(vatId: 'CZ12345687', registrationNumber: '12345687');
        $this->em->flush();

        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, ' Faktura {number} od nás ', "Dobrý den,\n{link}");
        $this->em->flush();
        $this->em->clear();

        $row = $this->connection()->fetchAssociative('SELECT legal_entity_id, kind, language, subject, body FROM mail_template');
        self::assertSame(['legal_entity_id' => $this->firm->id(), 'kind' => 'invoice', 'language' => 'cs', 'subject' => 'Faktura {number} od nás', 'body' => "Dobrý den,\n{link}"], $row);

        $firm = $this->em->find($this->firm::class, $this->firm->id());
        \assert(null !== $firm);
        $saved = $this->templates->textFor($firm, MailTemplateKind::Invoice, DocumentLanguage::Czech);
        self::assertSame('Faktura {number} od nás', $saved->subject);
        self::assertSame("Dobrý den,\n{link}", $saved->body);
        self::assertTrue($this->templates->isCustom($firm, MailTemplateKind::Invoice, DocumentLanguage::Czech));
        self::assertSame('Invoice {number}', $this->templates->textFor($firm, MailTemplateKind::Invoice, DocumentLanguage::English)->subject);
        $otherFirm = $this->em->find($other::class, $other->id());
        \assert(null !== $otherFirm);
        self::assertNotSame('Faktura {number} od nás', $this->templates->textFor($otherFirm, MailTemplateKind::Invoice, DocumentLanguage::Czech)->subject);
    }

    public function testSavingAgainChangesTheOneRow(): void
    {
        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::English, 'First {number}', 'First {link}');
        $this->em->flush();
        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::English, 'Second {number}', 'Second {link}');
        $this->em->flush();
        $this->em->clear();

        self::assertSame([['subject' => 'Second {number}', 'body' => 'Second {link}']], $this->connection()->fetchAllAssociative('SELECT subject, body FROM mail_template'));
    }

    public function testResetReturnsTheBuiltInText(): void
    {
        $builtIn = $this->templates->textFor($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Own {number}', 'Own body');
        $this->em->flush();

        $this->templates->reset($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
        $firm = $this->em->find($this->firm::class, $this->firm->id());
        \assert(null !== $firm);
        self::assertEquals($builtIn, $this->templates->textFor($firm, MailTemplateKind::Invoice, DocumentLanguage::Czech));
    }

    public function testAnUnknownVariableIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Invoice {numbr}', 'Pay {amount} {link}');
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

        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Invoice {link}', 'Body');
    }

    public function testAnEmptySubjectIsRefused(): void
    {
        $this->expectException(InvalidMailTemplate::class);

        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, '  ', 'Body');
    }

    public function testAnotherTenantsTemplateIsNotFound(): void
    {
        $this->templates->save($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Ours {number}', 'Ours');
        $this->em->flush();
        $this->em->clear();

        // The firm as another tenant would name it: by its id alone.
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        self::assertNotSame('Ours {number}', $this->templates->textFor($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech)->subject);
        self::assertFalse($this->templates->isCustom($this->firm, MailTemplateKind::Invoice, DocumentLanguage::Czech));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
