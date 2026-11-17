<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundFiles;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\MailAuthentication;
use App\Banking\Application\MailReader;
use App\Banking\Application\PendingChanges;
use App\Banking\Application\ProcessMessage;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\ReceiveOutcome;
use App\Banking\Domain\InboundChannel;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PriceType;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * The banking test firm with an inbound address on its account, and synthetic
 * Komerční banka notices to it: a fixture of the parser's tests below the header
 * a receiving server writes, taken from a fixture of the trust tests. The notices
 * are of 150.00 CZK with VS 2026001, booked on 2 November 2026 on the test IBAN.
 */
abstract class InboundTestCase extends BankingTestCase
{
    protected const string KB_FIXTURES = __DIR__.'/../../Fixtures/Banking/kb/';
    protected const string AUTH_FIXTURES = __DIR__.'/../../Fixtures/Banking/auth/';

    protected string $address;
    protected ReceiveInboundMail $receive;
    protected ProcessMessage $process;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->address = $container->get(GenerateAddress::class)->generate($this->account->id());
        $this->receive = $container->get(ReceiveInboundMail::class);
        $this->process = $container->get(ProcessMessage::class);
    }

    /**
     * A KB notice to the address, below the topmost Authentication-Results of a
     * trust fixture, or below none.
     */
    protected function notice(string $fixture, ?string $auth = 'dmarc-pass', ?string $to = null, ?string $messageId = null): string
    {
        $raw = $this->fixture(self::KB_FIXTURES.$fixture.'.eml');
        $raw = str_replace("To: bank+token@example.com\n", 'To: '.($to ?? $this->address)."\n", $raw, $replaced);
        self::assertSame(1, $replaced, 'The fixture is addressed to the placeholder.');
        if (null !== $messageId) {
            $raw = (string) preg_replace('/^Message-ID: <[^>]+>$/m', 'Message-ID: <'.$messageId.'>', $raw, 1);
        }
        if (null === $auth) {
            return $raw;
        }
        $header = strtok($this->fixture(self::AUTH_FIXTURES.$auth.'.eml'), "\n");
        self::assertIsString($header);
        self::assertStringStartsWith('Authentication-Results: mx.example.com;', $header);

        return $header."\n".$raw;
    }

    /**
     * Stores the mail as the webhook would, and gives the id of its row.
     */
    protected function received(string $raw): string
    {
        self::assertSame(ReceiveOutcome::Stored, $this->receive->receive($raw, InboundChannel::Webhook));

        return Scalars::text($this->em->getConnection()->fetchOne('SELECT id FROM inbound_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageRow(string $id): array
    {
        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, trust, trust_basis, parser_id, parser_version, failure, failure_detail, attempts, processed_at FROM inbound_message WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function movementsOf(string $messageId): array
    {
        $this->em->clear();

        return $this->em->getConnection()->fetchAllAssociative('SELECT id, firm_bank_account_id, direction, amount_minor, currency, variable_symbol, state, unmatched_reason, trust, trust_basis, payment_id FROM bank_movement WHERE inbound_message_id = ? ORDER BY amount_minor', [$messageId]);
    }

    /**
     * The invoice the notices pay: 150.00 CZK with VAT, VS 2026001, issued the day before.
     */
    protected function kbInvoice(string $vs = '2026001', string $price = '150.00'): Invoice
    {
        return $this->issue->issue($this->input([$this->line($price, PriceType::WithVat)], ['variableSymbol' => $vs]));
    }

    /**
     * The pipeline with other parsers or another way of storing between steps.
     */
    protected function processWith(?BankMessageParsers $parsers = null, ?PendingChanges $pending = null): ProcessMessage
    {
        $container = self::getContainer();

        return new ProcessMessage(
            $container->get(InboundMessages::class),
            $container->get(InboundFiles::class),
            $container->get(MailReader::class),
            $parsers ?? $container->get(BankMessageParsers::class),
            $container->get(MailAuthentication::class),
            $container->get(FirmBankAccounts::class),
            $this->recordMovement,
            $this->pairing,
            $pending ?? $container->get(PendingChanges::class),
            $container->get(TransactionalWork::class),
            $container->get(ConflictRetry::class),
            $container->get(ClockInterface::class),
        );
    }

    private function fixture(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }
}
