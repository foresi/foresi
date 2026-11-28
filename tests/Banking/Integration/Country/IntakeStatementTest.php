<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Country;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementIntake;
use Foresi\Banking\Contract\StatementRefused;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Invoicing\Application\PaymentState;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Banking\Integration\Invoicing\Support\InvoiceBankingTestCase;

final class IntakeStatementTest extends InvoiceBankingTestCase
{
    private const string ACTOR = '01963c5e-0000-7000-8000-0000000000aa';

    private StatementIntake $intake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intake = self::getContainer()->get(StatementIntake::class);
    }

    public function testAnUploadedStatementIsStoredWithItsFileAndItsMovementPays(): void
    {
        $invoice = $this->issue->issue($this->input());
        $file = new StatementFile('november.json', '{"test-statement": 1}');

        $result = $this->intake->submit($this->statement(42, '1000.00', '2210.00', [$this->entry('1210.00', 'L-1')]), $this->uploaded(), $file);

        self::assertNotNull($result->statementId);
        self::assertCount(1, $result->recorded);
        self::assertSame([[], 0, 0, 0], [$result->corroborated, $result->alreadyKnown, $result->gapsDetected, $result->gapsClosed]);
        $this->em->clear();
        $stored = $this->em->getConnection()->fetchAssociative('SELECT firm_bank_account_id, channel, source_id, sequence_kind, sequence_number, sequence_year, opening_minor, closing_minor, entries_net_minor, entry_count, trust, trust_basis, actor_id, file_sha256, file_name FROM bank_statement WHERE id = ?', [$result->statementId]);
        self::assertIsArray($stored);
        self::assertSame(
            [$this->account->id(), 'statement', 'test.json', 'within_year', 42, 2026, 100000, 221000, 121000, 1, 'verified', 'uploaded', self::ACTOR, $file->sha256(), 'november.json'],
            [$stored['firm_bank_account_id'], $stored['channel'], $stored['source_id'], $stored['sequence_kind'], self::int($stored['sequence_number']), self::int($stored['sequence_year']), self::int($stored['opening_minor']), self::int($stored['closing_minor']), self::int($stored['entries_net_minor']), self::int($stored['entry_count']), $stored['trust'], $stored['trust_basis'], $stored['actor_id'], $stored['file_sha256'], $stored['file_name']],
        );
        $movement = $this->movementRow($result->recorded[0]);
        self::assertSame(['statement', 'test.json', $result->statementId, 'L-1', 'verified', 'uploaded', 'paired'], [$movement['channel'], $movement['source_id'], $movement['bank_statement_id'], $movement['bank_reference'], $movement['trust'], $movement['trust_basis'], $movement['state']]);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
    }

    public function testTheSameFileAgainChangesNothing(): void
    {
        $file = new StatementFile('november.json', '{"test-statement": 1}');
        $statement = $this->statement(42, '1000.00', '1010.00', [$this->entry('10.00', null)]);
        $first = $this->intake->submit($statement, $this->uploaded(), $file);

        $again = $this->intake->submit($statement, $this->uploaded(), $file);

        self::assertSame([$first->statementId, [], [], 1], [$again->statementId, $again->recorded, $again->corroborated, $again->alreadyKnown]);
        self::assertSame(1, self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM bank_statement')));
        self::assertSame(1, self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM bank_movement')));
    }

    public function testAnEntryTheSourceToldBeforeByItsReferenceIsKnown(): void
    {
        $this->intake->submit($this->statement(42, null, null, [$this->entry('10.00', 'L-1')]), $this->uploaded());

        $result = $this->intake->submit($this->statement(43, null, null, [$this->entry('10.00', 'L-1'), $this->entry('20.00', 'L-2')]), $this->uploaded());

        self::assertSame([1, 1], [\count($result->recorded), $result->alreadyKnown]);
        // Another source with the same reference is not the same line.
        $api = $this->intake->submit($this->statement(null, null, null, [$this->entry('30.00', 'L-1')]), MovementProvenance::bankApi('cz.fio'));
        self::assertCount(1, $api->recorded);
    }

    public function testAnEntryWithoutAReferenceInAnotherStatementOfTheSourceIsAPossibleDuplicate(): void
    {
        $first = $this->intake->submit($this->statement(42, null, null, [$this->entry('10.00', null), $this->entry('10.00', null)]), $this->uploaded());

        $second = $this->intake->submit($this->statement(43, null, null, [$this->entry('10.00', null)]), $this->uploaded());

        self::assertSame(['unmatched', 'unmatched'], array_map(fn (string $id): string => self::string($this->movementRow($id)['state']), $first->recorded), 'two equal lines of one statement are two movements');
        self::assertSame('possible_duplicate', $this->movementRow($second->recorded[0])['state']);
    }

    public function testAStatementConfirmsTheMovementAMailToldAndItsTrustRisesAndItPays(): void
    {
        $invoice = $this->issue->issue($this->input());
        $mail = $this->recordMovement->record($this->incoming('1210.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::unverified());
        self::assertNull($this->pairing->auto($mail->id()));
        self::assertSame(UnmatchedReason::Unverified, $this->storedMovement($mail->id())->unmatchedReason());

        $result = $this->intake->submit($this->statement(42, null, null, [$this->entry('1210.00', 'L-7', self::COUNTER_IBAN)]), $this->uploaded());

        self::assertSame([[], [$mail->id()]], [$result->recorded, $result->corroborated]);
        $row = $this->movementRow($mail->id());
        self::assertSame(
            ['mail', 'mail', null, 'test.json', 'L-7', $result->statementId, 'verified', 'uploaded', 'paired'],
            [$row['channel'], $row['source_id'], $row['bank_reference'], $row['corroborated_source_id'], $row['corroborated_reference'], $row['corroborated_statement_id'], $row['trust'], $row['trust_basis'], $row['state']],
        );
        self::assertNotNull($row['corroborated_at']);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));

        // The statement again from another file: its line is known by the reference it confirmed.
        $again = $this->intake->submit($this->statement(42, null, null, [$this->entry('1210.00', 'L-7', self::COUNTER_IBAN)]), $this->uploaded(), new StatementFile('again.json', 'other bytes'));
        self::assertSame([[], [], 1], [$again->recorded, $again->corroborated, $again->alreadyKnown]);
    }

    public function testALessTrustedSourceConfirmsWithoutLoweringTheTrust(): void
    {
        $verified = $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::verified());

        $result = $this->intake->submit($this->statement(null, null, null, [$this->entry('10.00', 'X-1', self::COUNTER_IBAN)]), MovementProvenance::unverified(MovementChannel::Api, 'example.aggregator'));

        self::assertSame([$verified->id()], $result->corroborated);
        $row = $this->movementRow($verified->id());
        self::assertSame(['verified', 'dmarc', 'example.aggregator'], [$row['trust'], $row['trust_basis'], $row['corroborated_source_id']]);
    }

    public function testTwoMovementsTheEntryMayBeMakeItAPossibleDuplicate(): void
    {
        $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::unverified());
        $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::unverified());

        $result = $this->intake->submit($this->statement(null, null, null, [$this->entry('10.00', 'L-1', self::COUNTER_IBAN)]), $this->uploaded());

        self::assertSame([], $result->corroborated);
        self::assertSame(MovementState::PossibleDuplicate->value, $this->movementRow($result->recorded[0])['state']);
    }

    public function testAnotherCounterAccountIsAnotherMovement(): void
    {
        $this->recordMovement->record($this->incoming('10.00', messageId: '01963c5e-0000-7000-8000-000000000001'), self::unverified());

        $result = $this->intake->submit($this->statement(null, null, null, [$this->entry('10.00', 'L-1', 'DE89370400440532013000')]), $this->uploaded());

        self::assertSame([[], 1], [$result->corroborated, \count($result->recorded)]);
    }

    public function testAMissingStatementIsAGapUntilItComes(): void
    {
        $this->intake->submit($this->statement(42, '1000.00', '1000.00', []), $this->uploaded());
        $later = $this->intake->submit($this->statement(44, '1000.00', '1000.00', [], '2026-12'), $this->uploaded());
        self::assertSame([1, 0], [$later->gapsDetected, $later->gapsClosed]);

        $missing = $this->intake->submit($this->statement(43, '1000.00', '1000.00', [], '2026-11'), $this->uploaded());

        self::assertSame([0, 1], [$missing->gapsDetected, $missing->gapsClosed]);
        $events = $this->em->getConnection()->fetchAllAssociative("SELECT name, data FROM event_log WHERE name LIKE 'banking.statement.%' ORDER BY id");
        self::assertSame(['banking.statement.gap_detected', 'banking.statement.gap_closed'], array_column($events, 'name'));
        $data = json_decode($this->string($events[0]['data']), true);
        self::assertIsArray($data);
        self::assertSame([$later->statementId, $this->account->id(), 'number'], [$data['statement_id'] ?? null, $data['account_id'] ?? null, $data['kind'] ?? null]);
    }

    public function testAStatementThatDoesNotAddUpIsRecordedWithAGap(): void
    {
        $result = $this->intake->submit($this->statement(42, '1000.00', '5000.00', [$this->entry('10.00', 'L-1')]), $this->uploaded());

        self::assertSame([1, 1], [\count($result->recorded), $result->gapsDetected]);
    }

    public function testABankMailIsNotStoredAsAStatement(): void
    {
        $result = $this->intake->submit($this->statement(null, null, null, [$this->entry('10.00', null)]), MovementProvenance::mail('cz.kb', MovementTrust::Verified, 'dmarc', '01963c5e-0000-7000-8000-000000000001'));

        self::assertNull($result->statementId);
        self::assertSame(0, self::int($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM bank_statement')));
        $row = $this->movementRow($result->recorded[0]);
        self::assertSame(['mail', 'cz.kb', null, '01963c5e-0000-7000-8000-000000000001'], [$row['channel'], $row['source_id'], $row['bank_statement_id'], $row['inbound_message_id']]);
    }

    public function testAStatementOfAnotherAccountIsRefused(): void
    {
        $this->expectException(StatementRefused::class);
        $this->intake->submit(new BankStatement('DE89370400440532013000', Currency::of('CZK'), []), $this->uploaded());
    }

    private function uploaded(): MovementProvenance
    {
        return MovementProvenance::uploaded('test.json', self::ACTOR);
    }

    /**
     * @param list<ParsedMovement> $entries
     */
    private function statement(?int $number, ?string $opening, ?string $closing, array $entries, string $month = '2026-11'): BankStatement
    {
        $from = new \DateTimeImmutable($month.'-01');

        return new BankStatement(
            $this->account->iban(),
            Currency::of('CZK'),
            $entries,
            null,
            null === $number ? null : StatementSequence::withinYear($number, 2026),
            $from,
            $from->modify('last day of this month'),
            null === $opening ? null : Money::of($opening, Currency::of('CZK')),
            null === $closing ? null : Money::of($closing, Currency::of('CZK')),
        );
    }

    private function entry(string $amount, ?string $reference, ?string $counterIban = null): ParsedMovement
    {
        return new ParsedMovement(MovementDirection::Incoming, Money::of($amount, Currency::of('CZK')), $this->account->iban(), '123456789/0800', $counterIban, 'Example Customer a.s.', new \DateTimeImmutable('2026-11-20'), '20260001', '0308', null, 'Payment for an invoice', $reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(string $id): array
    {
        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM bank_movement WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
