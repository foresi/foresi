<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Banking\Application\GenerateAddress;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\ReceiveInboundMail;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\InboundChannel;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

// The world of the bank movements spec: one firm with two accounts and an inbound
// address for each. The first has three invoices: 20260001 paired by a verified notice, 20260002
// waiting for the unverified payment the spec pairs by hand, and 20260003 open. The bank
// mail holds one of each kind: processed, failed, rejected for its sender, about an account
// of nobody here, not from a bank, and one the queue left waiting for two hours.
$recordMovement = $container->get('e2e.record_movement');
$pairMovement = $container->get('e2e.pair_movement');
$generateAddress = $container->get('e2e.generate_address');
$receiveMail = $container->get('e2e.receive_inbound_mail');
\assert($recordMovement instanceof RecordMovement);
\assert($pairMovement instanceof PairMovement);
\assert($generateAddress instanceof GenerateAddress);
\assert($receiveMail instanceof ReceiveInboundMail);
$movementsFirm = new LegalEntity('Example Movements s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(5), VatStatus::Registered, 'Example register, entry C 2468');
$entityManager->persist($movementsFirm);
$firmAccount($movementsFirm);
$secondAccount = FirmBankAccount::open(Iban::fromString('CZ5508000000001234567899'), Currency::CZK, 'Provozní', 1);
$entityManager->persist($secondAccount);
$movementsSeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$movementsSeries->markDefault();
$entityManager->persist($movementsSeries);
$entityManager->flush();
$movementsCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Payer a.s.', [PartyRole::Customer], 'payer@example.com', 11, '12345679', 'CZ12345679'));
foreach (['Consulting' => '1000.00', 'Workshop' => '2000.00', 'Audit' => '500.00'] as $description => $price) {
    $issueInvoice->issue(new InvoiceInput($movementsSeries->id(), null, $movementsCustomer->id(), null, $day, $day, $due, PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ], true));
}
$movementsAccount = $entityManager->getRepository(FirmBankAccount::class)->findOneBy(['iban' => 'CZ6508000000192000145399']) ?? throw new LogicException('The account of the movements firm is missing.');
$verified = new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC);
$unverified = new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS);
$movement = static fn (FirmBankAccount $account, MovementDirection $direction, string $amount, ?string $vs, ?string $name, ?string $counter, ?string $message, TrustVerdict $trust): string => $recordMovement->record(new MovementInput($account->id(), null, $direction, Money::of($amount, Currency::CZK), $day, $counter, null, $name, $vs, null, null, $message, null), $trust)->id();
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '1210.00', '20260001', 'Example Payer a.s.', '2000000123/0800', 'Faktura 20260001', $verified));
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '2420.00', '20260002', 'Example Payer a.s.', '2000000123/0800', 'Workshop', $unverified));
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '350.00', null, 'Example International Wholesale and Distribution Company Ltd', '1234567890/0100', 'Platba za služby podle dohody z minulého týdne, prosím o potvrzení přijetí', $verified));
$movement($movementsAccount, MovementDirection::Outgoing, '1500.00', '112026', 'Example Landlord s.r.o.', '9876543210/0300', 'Nájem kanceláře', $verified);
$pairMovement->auto($movement($secondAccount, MovementDirection::Incoming, '800.00', '77', 'Example Buyer a.s.', '5555555555/5500', null, $verified));
$entityManager->clear();
$fixture = static fn (string $path): string => (string) file_get_contents(__DIR__.'/../../Fixtures/Banking/'.$path);
$notice = static function (string $name, string $to, ?string $auth, string $messageId) use ($fixture): string {
    $raw = str_replace("To: bank+token@example.com\n", 'To: '.$to."\n", $fixture('kb/'.$name.'.eml'));
    $raw = (string) preg_replace('/^Message-ID: <[^>]+>$/m', 'Message-ID: <'.$messageId.'>', $raw, 1);

    return null === $auth ? $raw : strtok($fixture('auth/'.$auth.'.eml'), "\n")."\n".$raw;
};
$firstAddress = $generateAddress->generate($movementsAccount->id());
$mails = [
    'processed' => $notice('incoming-booked', $firstAddress, 'dmarc-pass', 'processed@kb.example.com'),
    'failed' => $notice('incoming-unknown-status', $firstAddress, 'dmarc-pass', 'failed@kb.example.com'),
    'untrusted' => $notice('incoming-booked', $firstAddress, 'aligned-dkim-fail', 'untrusted@kb.example.com'),
    // The notice names an account that is none of the firm's.
    'mismatch' => str_replace('19-2000145399/0800', '2000000999/0800', $notice('incoming-booked', $firstAddress, 'dmarc-pass', 'mismatch@kb.example.com')),
    'notbank' => "From: Gmail Team <forwarding-noreply@example.org>\nTo: ".$firstAddress."\nSubject: Gmail Forwarding Confirmation\nMessage-ID: <forwarding@example.org>\nMIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\nConfirm forwarding to ".$firstAddress.".\n<script>alert('example')</script>\n",
    'stuck' => $notice('incoming-unknown-status', $firstAddress, 'dmarc-pass', 'stuck@kb.example.com'),
];
// The e2e queue is synchronous: each mail is processed as it is received.
foreach ($mails as $kind => $raw) {
    $receiveMail->receive($raw, InboundChannel::Webhook);
    if ('stuck' === $kind) {
        // As if the queue had given up on it two hours ago, before reading it.
        $entityManager->getConnection()->executeStatement(
            "UPDATE inbound_message SET status = 'received', trust = NULL, trust_basis = NULL, parser_id = NULL, parser_version = NULL, failure = NULL, failure_detail = NULL, attempts = 3, processed_at = NULL, received_at = ? WHERE raw_sha256 = ?",
            [new DateTimeImmutable('-2 hours'), hash('sha256', $raw)],
            [Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE],
        );
    }
}
