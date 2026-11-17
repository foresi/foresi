<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use App\ExchangeRate\Application\PublishedRates;
use App\ExchangeRate\Application\Quote;
use App\ExchangeRate\Application\RateStore;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Party\Application\PartyInput;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Application\RecordReceivedDocument;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// Received documents: one VAT payer, a supplier in the United States (a SaaS vendor, no VAT ID, a US
// address) and one in Czechia. The ČNB rate of 14 October 2026 is stored as a fetch would store it,
// so a document with that tax point finds its rate here and the spec never waits for the bank.
$entityManager->persist($firmAccount(new LegalEntity('Example Buyer s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 5678')));
$entityManager->flush();
$createParty->createFrom(new PartyInput(PartyKind::SoleTrader, 'Example Cloud Inc.', null, null, 'billing@example.com', '+1 555 000 0001', [PartyRole::Supplier], new Address('1 Example Way', 'Example City', '00000', 'US')));
$createParty->createFrom($party(PartyKind::Company, 'Example Office Supplies s.r.o.', [PartyRole::Supplier], 'office@example.com', 2, '12345679', 'CZ12345679'));
$rateStore = $container->get('e2e.rate_store');
\assert($rateStore instanceof RateStore);
$rateStore->save(new DateTimeImmutable('2026-10-14'), new PublishedRates(new DateTimeImmutable('2026-10-14'), ['USD' => new Quote('USD', 1, '23.500'), 'EUR' => new Quote('EUR', 1, '24.300')]), 'seed');
// A second firm with two recorded documents of its own for the screenshots: its series starts at one and
// nothing the other tests record lands in it, so the screens look the same in a full run and alone.
$studio = $firmAccount(new LegalEntity('Example Studio s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), $address(3), VatStatus::Registered, 'Example register, entry C 9012'));
$entityManager->persist($studio);
$entityManager->flush();
$purchasingOwner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'purchasing@example.com']) ?? throw new LogicException('The owner of the purchasing tenant is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($purchasingOwner->id(), $purchasingOwner->email(), 'hash'), 'main', ['ROLE_USER']));
$suppliers = [];
foreach ($entityManager->getRepository(Party::class)->findAll() as $candidate) {
    $suppliers[$candidate->displayName()] = (string) $candidate->id();
}
$recordDocument = $container->get('e2e.record_received_document');
\assert($recordDocument instanceof RecordReceivedDocument);
$october = new DateTimeImmutable('2026-10-14');
$recordDocument->create($studio->id(), new ReceivedDocumentInput(
    ReceivedDocumentKind::ForeignInvoice, PurchaseTaxTreatment::NonEuService, $suppliers['Example Cloud Inc.'], 'INV-2026-1014', '',
    $october, $october, $october->modify('+14 days'), $october->modify('+1 day'), Currency::USD, Money::of('49.00', Currency::USD),
    null, null, [new VatRecapLine(VatRate::of('21'), Money::of('49.00', Currency::USD), Money::of('0.00', Currency::USD))],
));
$recordDocument->create($studio->id(), new ReceivedDocumentInput(
    ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $suppliers['Example Office Supplies s.r.o.'], 'FV-2026-0412', '2026041200',
    $october, $october, $october->modify('+14 days'), $october->modify('+2 days'), Currency::CZK, Money::of('1795.00', Currency::CZK),
    null, null, [
        new VatRecapLine(VatRate::of('21'), Money::of('1000.00', Currency::CZK), Money::of('210.00', Currency::CZK)),
        new VatRecapLine(VatRate::of('12'), Money::of('500.00', Currency::CZK), Money::of('60.00', Currency::CZK)),
    ],
));
$tokenStorage->setToken(null);
