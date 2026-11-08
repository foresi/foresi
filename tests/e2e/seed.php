<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Seeds the end-to-end database (var/e2e.db): the tenant "Example Company" with
 * the user testing@example.com and 30 parties, a second, empty tenant for the
 * first-run screen, a third with two firms and one contact for the firm scope,
 * and a fourth with a VAT payer, a non-payer and customers for the invoice form;
 * the payer has a mail server, whose password is sealed with the e2e-only key of
 * serve.sh. A fifth tenant, without users, holds one sent invoice, whose links go
 * to var/e2e-links.json for the public link specs. A sixth, "Example Sending" with
 * sending@example.com, holds the invoices of the send dialog specs, one of them refused
 * by the recipient's server and one opened. "Example Dialogs" with dialogs@example.com
 * holds three invoices for the dialog specs, "Example Reminders" with
 * reminders@example.com overdue invoices for the reminder specs, and "Example Recurring"
 * with recurring@example.com recurring templates in each state for the recurring list
 * specs, and "Example Accountant" with accountant@example.com the documents of last and
 * this month for the accountant pack specs, and "Example Move" with idoklad@example.com and
 * "Example Move Screens" with idoklad-screens@example.com a stored iDoklad pull each for the
 * iDoklad import spec, and "Example People" with people@example.com the organisation the
 * people spec invites into, and "Example Projects" with projects@example.com and the team member
 * member@example.com the project "Website" with sections, tasks, a subtask and labels for the
 * projects spec; its task "Design the homepage" carries two comments (an internal one of the
 * owner that mentions Jana Example, a public one of hers), a PNG and a PDF for the discussion spec. Run it after the migrations, in the e2e
 * environment:
 *
 *     APP_ENV=e2e php tests/e2e/seed.php
 *
 * Only example.com data and the synthetic registration numbers of the registry
 * fixtures are used.
 */

use App\Attachment\Application\AttachmentUpload;
use App\Attachment\Application\UploadAttachment;
use App\Banking\Application\GenerateAddress;
use App\Banking\Application\MovementInput;
use App\Banking\Application\PairMovement;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\RecordMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\InboundChannel;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringLifecycle;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxPointRule;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Kernel;
use App\Party\Application\CreateParty;
use App\Party\Application\PartyInput;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tests\Support\Mail\RecordingMailGateway;
use App\Work\Application\ManageComments;
use App\Work\Application\ManageLabels;
use App\Work\Application\ManageProjects;
use App\Work\Application\ManageSections;
use App\Work\Application\ManageTasks;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Priority;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'e2e';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('e2e', true);
$kernel->boot();
$container = $kernel->getContainer();

$entityManager = $container->get('e2e.entity_manager');
$context = $container->get('e2e.tenant_context');
$createParty = $container->get('e2e.create_party');
$registrationNumbers = $container->get('e2e.registration_numbers');
$hashers = $container->get('e2e.password_hashers');
$mailSecrets = $container->get('e2e.mail_secrets');
$issueInvoice = $container->get('e2e.issue_invoice');
$sendInvoice = $container->get('e2e.send_invoice');
$linkTokens = $container->get('e2e.link_tokens');
\assert($entityManager instanceof EntityManagerInterface);
\assert($context instanceof TenantContext);
\assert($createParty instanceof CreateParty);
\assert($registrationNumbers instanceof RegistrationNumberFactory);
\assert($hashers instanceof PasswordHasherFactoryInterface);
\assert($mailSecrets instanceof MailSecrets);
\assert($issueInvoice instanceof IssueInvoice);
\assert($sendInvoice instanceof SendInvoice);
\assert($linkTokens instanceof LinkTokens);

$password = getenv('E2E_PASSWORD');
// Hashed the way the application hashes it, so the sign-in form accepts it.
$hash = $hashers->getPasswordHasher(SecurityUser::class)->hash(false === $password || '' === $password ? 'throwaway-password-1' : $password);

/**
 * @return TenantId the tenant, with its owner already a member
 */
$tenantWithOwner = static function (string $tenantName, string $email, string $displayName) use ($entityManager, $context, $hash): TenantId {
    $tenant = new Tenant(TenantId::generate(), $tenantName, new DateTimeImmutable());
    $entityManager->persist($tenant);
    $entityManager->flush();

    $context->switchTo($tenant->id());
    $user = new User($email, $hash, $displayName, 'cs');
    $entityManager->persist($user);
    $entityManager->persist(new Membership($user, Role::Owner));
    $entityManager->flush();
    $context->reset();

    return $tenant->id();
};

$main = $tenantWithOwner('Example Company', 'testing@example.com', 'Testing Example');
$tenantWithOwner('Example Empty Company', 'empty@example.com', 'Empty Example');
$group = $tenantWithOwner('Example Group', 'group@example.com', 'Group Example');
$invoicing = $tenantWithOwner('Example Invoicing', 'invoicing@example.com', 'Invoicing Example');
$tenantWithOwner('Example First Run', 'first-run@example.com', 'First Run Example');
$sending = $tenantWithOwner('Example Sending', 'sending@example.com', 'Sending Example');
$dialogs = $tenantWithOwner('Example Dialogs', 'dialogs@example.com', 'Dialogs Example');
$bank = $tenantWithOwner('Example Bank', 'bank@example.com', 'Bank Example');
$reminders = $tenantWithOwner('Example Reminders', 'reminders@example.com', 'Reminders Example');
// The people spec invites into its own organisation, which has no system mail.
$tenantWithOwner('Example People', 'people@example.com', 'People Example');

$address = static fn (int $n): Address => new Address(sprintf('Example Street %d', $n), 'Example City', '100 00', 'CZ');
// The firm's one bank account, the primary one; the firm itself is persisted by the caller.
$firmAccount = static function (LegalEntity $firm) use ($entityManager): LegalEntity {
    $account = FirmBankAccount::open($firm, Iban::fromString('CZ6508000000192000145399'), Currency::CZK, '', 0);
    $account->markPrimary(true);
    $entityManager->persist($account);

    return $firm;
};
/** @var Closure(PartyKind, string, list<PartyRole>, string, int, ?string=, ?string=): PartyInput $party */
$party = static fn (PartyKind $kind, string $name, array $roles, string $email, int $n, ?string $registration = null, ?string $vat = null): PartyInput => new PartyInput(
    $kind,
    $name,
    null === $registration ? null : $registrationNumbers->create('CZ', $registration),
    null === $vat ? null : VatId::fromString($vat),
    $email,
    '+420 000 000 '.str_pad((string) $n, 3, '0', \STR_PAD_LEFT),
    $roles,
    $address($n),
);

$inputs = [];
// Named parties the specs rely on.
$inputs[] = $party(PartyKind::Company, 'Example Supplies s.r.o.', [PartyRole::Supplier], 'supplies@example.com', 1, '12345695', 'CZ12345695');
$inputs[] = $party(PartyKind::Person, 'Alex Example', [PartyRole::Partner], 'alex@example.com', 2);
$inputs[] = $party(PartyKind::Person, 'Blair Example', [PartyRole::Partner], 'blair@example.com', 3);
// Long values: truncation with the full value in a title.
$inputs[] = $party(PartyKind::Company, 'Example International Wholesale and Distribution Company Ltd', [PartyRole::Customer, PartyRole::Supplier], 'very.long.address.for.the.purpose.of.testing.truncation@example.com', 4, '00000019');
$inputs[] = $party(PartyKind::Company, 'Example Long Name Manufacturing and Trading Association Group', [PartyRole::Customer], 'long.name@example.com', 5, '00000001');

$prefixes = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'];
for ($i = 6; $i <= 30; ++$i) {
    $prefix = $prefixes[($i - 6) % \count($prefixes)];
    // A sole trader needs no identifier, which keeps the seed free of invented numbers.
    $kind = 0 === $i % 3 ? PartyKind::Person : PartyKind::SoleTrader;
    $roles = match ($i % 4) {
        0 => [PartyRole::Customer],
        1 => [PartyRole::Supplier],
        2 => [PartyRole::Customer, PartyRole::Supplier],
        default => [PartyRole::Partner],
    };
    $name = sprintf('%s Example %02d', $prefix, $i);
    $inputs[] = $party($kind, $name, $roles, sprintf('contact%02d@example.com', $i), $i);
}

$context->switchTo($main);
foreach ($inputs as $input) {
    $createParty->createFrom($input);
}
$context->reset();

$context->switchTo($group);
foreach (['Example Trading s.r.o.' => '12345695', 'Example International Services and Holding s.r.o.' => '12345687'] as $name => $number) {
    $entityManager->persist(new LegalEntity($name, new RegistrationNumber('CZ', $number), null, $address(1), VatStatus::NotRegistered, ''));
}
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example Partner s.r.o.', [PartyRole::Customer], 'partner@example.com', 1, '12345679'));
$context->reset();

$context->switchTo($invoicing);
$entityManager->persist($firmAccount(new LegalEntity('Example Payer s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 1234')));
$entityManager->persist($firmAccount(new LegalEntity('Example Small Trader', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register')));
$entityManager->flush();
$entityManager->persist(new LegalEntity('Example No Account s.r.o.', new RegistrationNumber('CZ', '12345679'), null, $address(3), VatStatus::NotRegistered, ''));
$payerFirm = $entityManager->getRepository(LegalEntity::class)->findOneBy(['name' => 'Example Payer s.r.o.']) ?? throw new LogicException('The payer firm is missing.');
$standard = new Series($payerFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$standard->markDefault();
$entityManager->persist($standard);
$entityManager->persist(new Series($payerFirm, 'Second', SeriesFormat::fromString('B{YY}{NNN}'), DocumentKind::Invoice));
// A throwaway password: the e2e mail gateway records and never connects.
$entityManager->persist(FirmMailServer::define($payerFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Payer s.r.o.'));
$entityManager->flush();
$createParty->createFrom($party(PartyKind::Company, 'Example German GmbH', [PartyRole::Customer], 'german@example.com', 7, null, 'DE123456789'));
$createParty->createFrom($party(PartyKind::Company, 'Example Domestic a.s.', [PartyRole::Customer], 'domestic@example.com', 3, '12345679', 'CZ12345679'));
$createParty->createFrom($party(PartyKind::Company, 'Example Builder a.s.', [PartyRole::Customer], 'builder@example.com', 4, '00000019', 'CZ00000019'));
$createParty->createFrom($party(PartyKind::Company, 'Example Euro a.s.', [PartyRole::Customer], 'euro@example.com', 5, '00000001', 'CZ00000001'));
$createParty->createFrom($party(PartyKind::SoleTrader, 'Example Vendor', [PartyRole::Supplier], 'vendor@example.com', 6));
$defaultsOf = static function (string $name, InvoicingDefaults $defaults) use ($entityManager): void {
    $entityManager->getRepository(Party::class)->findOneBy(['displayName' => $name])?->changeInvoicingDefaults($defaults);
};
$defaultsOf('Example Builder a.s.', new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, true));
$defaultsOf('Example German GmbH', new InvoicingDefaults(14, Currency::CZK, DocumentLanguage::English, PaymentMethod::BankTransfer, true));
$defaultsOf('Example Euro a.s.', new InvoicingDefaults(14, Currency::EUR, DocumentLanguage::English, PaymentMethod::BankTransfer, false));
$entityManager->flush();
$context->reset();

// A sent invoice of a tenant nobody signs in to, for the public link page. Its links
// are derived from the e2e secret; the specs read them from var/e2e-links.json.
$sender = new Tenant(TenantId::generate(), 'Example Sender', new DateTimeImmutable());
$entityManager->persist($sender);
$entityManager->flush();
$context->switchTo($sender->id());
$senderFirm = new LegalEntity('Example Studio s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 5678');
$entityManager->persist($senderFirm);
$firmAccount($senderFirm);
$senderSeries = new Series($senderFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$senderSeries->markDefault();
$entityManager->persist($senderSeries);
$entityManager->persist(FirmMailServer::define($senderFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'studio@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'studio@example.com', 'Example Studio s.r.o.'));
$entityManager->flush();
$senderCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Client a.s.', [PartyRole::Customer], 'billing@example.com', 2, '12345679', 'CZ12345679'));
$day = new DateTimeImmutable('2026-11-02');
$senderInvoice = $issueInvoice->issue(new InvoiceInput($senderFirm->id(), $senderSeries->id(), null, $senderCustomer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '20260001', '', '', 'Website redesign', '', '', DocumentLanguage::Czech, [
    new LineInput('Website redesign', Quantity::fromString('1'), 'ks', Money::of('12500.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
// Delivered at once: the mail queue of the e2e environment is synchronous.
$senderSend = $sendInvoice->send(new SendRequest($senderInvoice->id(), ['billing@example.com'], [], true, 'Faktura '.$senderInvoice->number(), "Dobrý den,\nposíláme fakturu ".$senderInvoice->number().".\n\n{link}"));
$context->reset();
file_put_contents(dirname(__DIR__, 2).'/var/e2e-links.json', json_encode([
    'customer' => $linkTokens->token($senderSend->id(), LinkAudience::Customer),
    'copy' => $linkTokens->token($senderSend->id(), LinkAudience::Copy),
    'number' => $senderInvoice->number(),
], \JSON_THROW_ON_ERROR));

// The tenant of the send dialog specs: a payer firm with a mail server and one without,
// and a customer with an address for invoices and one for a copy. Its invoices are
// issued here, so the specs of the other tenants see nothing of them.
$context->switchTo($sending);
$sendingFirm = new LegalEntity('Example Sending s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(1), VatStatus::Registered, 'Example register, entry C 9012');
$entityManager->persist($sendingFirm);
$firmAccount($sendingFirm);
$sendingSeries = new Series($sendingFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$sendingSeries->markDefault();
$entityManager->persist($sendingSeries);
$entityManager->persist(FirmMailServer::define($sendingFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Sending s.r.o.'));
$quietFirm = new LegalEntity('Example Quiet s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(2), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($quietFirm);
$firmAccount($quietFirm);
$quietSeries = new Series($quietFirm, Series::STANDARD, SeriesFormat::fromString('Q{YYYY}{NNNN}'), DocumentKind::Invoice);
$quietSeries->markDefault();
$entityManager->persist($quietSeries);
$entityManager->flush();
$recipient = $createParty->createFrom($party(PartyKind::Company, 'Example Recipient a.s.', [PartyRole::Customer], '', 8, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($recipient, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->persist(new PartyEmail($recipient, 'accounts@example.com', EmailPurpose::Copy));
$entityManager->flush();
$sendingInvoice = static fn (LegalEntity $firm, Series $series, string $description, string $price): Invoice => $issueInvoice->issue(new InvoiceInput($firm->id(), $series->id(), null, $recipient->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
]));
$sendingInvoice($sendingFirm, $sendingSeries, 'Annual support', '24000.00');
$sendingInvoice($quietFirm, $quietSeries, 'Logo design', '8000.00');
// Delivered at once by the synchronous e2e queue: one refused by the recipient's server,
// one sent and opened, so the list, the log and the notice have each state to show.
$sendingText = static fn (Invoice $invoice): array => ['Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"];
$hosting = $sendingInvoice($sendingFirm, $sendingSeries, 'Hosting', '3600.00');
$failedSend = $sendInvoice->send(new SendRequest($hosting->id(), [RecordingMailGateway::REFUSING_RECIPIENT], [], false, ...$sendingText($hosting)));
$renewal = $sendingInvoice($sendingFirm, $sendingSeries, 'Domain renewal', '450.00');
$readSend = $sendInvoice->send(new SendRequest($renewal->id(), ['billing@example.com'], ['accounts@example.com'], true, ...$sendingText($renewal)));
$entityManager->getConnection()->executeStatement('UPDATE invoice_send SET first_read_at = created_at, read_count = 1 WHERE id = ?', [$readSend->id()]);
$context->reset();

// The tenant of the dialog specs: a firm with a mail server and a customer, and three
// invoices to send, to move to the trash and to mark delivered in dialogs.
$context->switchTo($dialogs);
$dialogFirm = new LegalEntity('Example Dialogs s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(3), VatStatus::Registered, 'Example register, entry C 3456');
$entityManager->persist($dialogFirm);
$firmAccount($dialogFirm);
$dialogSeries = new Series($dialogFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$dialogSeries->markDefault();
$entityManager->persist($dialogSeries);
$entityManager->persist(FirmMailServer::define($dialogFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Dialogs s.r.o.'));
$entityManager->flush();
$buyer = $createParty->createFrom($party(PartyKind::Company, 'Example Buyer a.s.', [PartyRole::Customer], '', 9, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($buyer, 'buyer@example.com', EmailPurpose::Invoices));
$entityManager->flush();
foreach (['Support' => '1000.00', 'Hosting' => '2000.00', 'Licence' => '3000.00'] as $description => $price) {
    $issueInvoice->issue(new InvoiceInput($dialogFirm->id(), $dialogSeries->id(), null, $buyer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
$context->reset();

// The tenant of the bank settings spec: a firm with no bank account yet.
$context->switchTo($bank);
$entityManager->persist(new LegalEntity('Example Bank Settings s.r.o.', new RegistrationNumber('CZ', '12345695'), null, $address(4), VatStatus::NotRegistered, ''));
$entityManager->flush();
$context->reset();

// The tenant of the pay dialog spec: three invoices of one firm, one unpaid to pay in the
// dialog, one paid in part and one paid in full, so the list shows each state.
$paying = $tenantWithOwner('Example Paying', 'paying@example.com', 'Paying Example');
$recordPayment = $container->get('e2e.record_payment');
\assert($recordPayment instanceof RecordPayment);
$context->switchTo($paying);
$payingFirm = new LegalEntity('Example Paying s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 7890');
$entityManager->persist($payingFirm);
$firmAccount($payingFirm);
$payingSeries = new Series($payingFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$payingSeries->markDefault();
$entityManager->persist($payingSeries);
$entityManager->flush();
$payer = $createParty->createFrom($party(PartyKind::Company, 'Example Payer a.s.', [PartyRole::Customer], 'payer@example.com', 10, '12345679', 'CZ12345679'));
$payable = [];
foreach (['Consulting' => '1000.00', 'Workshop' => '2000.00', 'Audit' => '3000.00'] as $description => $price) {
    $payable[$description] = $issueInvoice->issue(new InvoiceInput($payingFirm->id(), $payingSeries->id(), null, $payer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
foreach (['Workshop' => '1000.00', 'Audit' => '3630.00'] as $description => $amount) {
    $money = Money::of($amount, Currency::CZK);
    $recordPayment->record(new PaymentRequest($payingFirm->id(), $day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($payable[$description]->id(), $money)]));
}
$context->reset();

// The tenant of the bank movements spec: two firms with an account each and an inbound
// address. The first has three invoices: 20260001 paired by a verified notice, 20260002
// waiting for the unverified payment the spec pairs by hand, and 20260003 open. The bank
// mail holds one of each kind: processed, failed, rejected for its sender, about another
// firm's account, not from a bank, and one the queue left waiting for two hours.
$movementsTenant = $tenantWithOwner('Example Bank Movements', 'movements@example.com', 'Movements Example');
$recordMovement = $container->get('e2e.record_movement');
$pairMovement = $container->get('e2e.pair_movement');
$generateAddress = $container->get('e2e.generate_address');
$receiveMail = $container->get('e2e.receive_inbound_mail');
\assert($recordMovement instanceof RecordMovement);
\assert($pairMovement instanceof PairMovement);
\assert($generateAddress instanceof GenerateAddress);
\assert($receiveMail instanceof ReceiveInboundMail);
$context->switchTo($movementsTenant);
$movementsFirm = new LegalEntity('Example Movements s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(5), VatStatus::Registered, 'Example register, entry C 2468');
$entityManager->persist($movementsFirm);
$firmAccount($movementsFirm);
$secondFirm = new LegalEntity('Example Second Firm s.r.o.', new RegistrationNumber('CZ', '12345687'), null, $address(6), VatStatus::NotRegistered, 'Example trade register');
$entityManager->persist($secondFirm);
$secondAccount = FirmBankAccount::open($secondFirm, Iban::fromString('CZ5508000000001234567899'), Currency::CZK, 'Provozní', 0);
$secondAccount->markPrimary(true);
$entityManager->persist($secondAccount);
$movementsSeries = new Series($movementsFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$movementsSeries->markDefault();
$entityManager->persist($movementsSeries);
$entityManager->flush();
$movementsCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Payer a.s.', [PartyRole::Customer], 'payer@example.com', 11, '12345679', 'CZ12345679'));
foreach (['Consulting' => '1000.00', 'Workshop' => '2000.00', 'Audit' => '500.00'] as $description => $price) {
    $issueInvoice->issue(new InvoiceInput($movementsFirm->id(), $movementsSeries->id(), null, $movementsCustomer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ], true));
}
$movementsAccount = $entityManager->getRepository(FirmBankAccount::class)->findOneBy(['firm' => $movementsFirm]) ?? throw new LogicException('The account of the movements firm is missing.');
$verified = new TrustVerdict(MessageTrust::Verified, TrustVerdict::DMARC);
$unverified = new TrustVerdict(MessageTrust::Unverified, TrustVerdict::NO_RESULTS);
$movement = static fn (FirmBankAccount $account, MovementDirection $direction, string $amount, ?string $vs, ?string $name, ?string $counter, ?string $message, TrustVerdict $trust): string => $recordMovement->record(new MovementInput($account->id(), null, $direction, Money::of($amount, Currency::CZK), $day, $counter, null, $name, $vs, null, null, $message, null), $trust)->id();
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '1210.00', '20260001', 'Example Payer a.s.', '2000000123/0800', 'Faktura 20260001', $verified));
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '2420.00', '20260002', 'Example Payer a.s.', '2000000123/0800', 'Workshop', $unverified));
$pairMovement->auto($movement($movementsAccount, MovementDirection::Incoming, '350.00', null, 'Example International Wholesale and Distribution Company Ltd', '1234567890/0100', 'Platba za služby podle dohody z minulého týdne, prosím o potvrzení přijetí', $verified));
$movement($movementsAccount, MovementDirection::Outgoing, '1500.00', '112026', 'Example Landlord s.r.o.', '9876543210/0300', 'Nájem kanceláře', $verified);
$pairMovement->auto($movement($secondAccount, MovementDirection::Incoming, '800.00', '77', 'Example Buyer a.s.', '5555555555/5500', null, $verified));
$entityManager->clear();
$fixture = static fn (string $path): string => (string) file_get_contents(__DIR__.'/../Fixtures/Banking/'.$path);
$notice = static function (string $name, string $to, ?string $auth, string $messageId) use ($fixture): string {
    $raw = str_replace("To: bank+token@example.com\n", 'To: '.$to."\n", $fixture('kb/'.$name.'.eml'));
    $raw = (string) preg_replace('/^Message-ID: <[^>]+>$/m', 'Message-ID: <'.$messageId.'>', $raw, 1);

    return null === $auth ? $raw : strtok($fixture('auth/'.$auth.'.eml'), "\n")."\n".$raw;
};
$firstAddress = $generateAddress->generate($movementsAccount->id());
$secondAddress = $generateAddress->generate($secondAccount->id());
$mails = [
    'processed' => $notice('incoming-booked', $firstAddress, 'dmarc-pass', 'processed@kb.example.com'),
    'failed' => $notice('incoming-unknown-status', $firstAddress, 'dmarc-pass', 'failed@kb.example.com'),
    'untrusted' => $notice('incoming-booked', $firstAddress, 'aligned-dkim-fail', 'untrusted@kb.example.com'),
    'mismatch' => $notice('incoming-booked', $secondAddress, 'dmarc-pass', 'mismatch@kb.example.com'),
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
$context->reset();

// The tenant of the reminder specs, dated from the day the seed runs so its invoices stay
// overdue. "Example Reminding" reminds automatically since two months ago: R0001 has had two
// reminders today, R0002 gets its first tomorrow, R0003 is not due yet. "Example Manual" has
// reminders off: S0001 is overdue, for a reminder by hand, and S0002 is due in five days,
// so the settings page shows when its first reminder would go out.
$context->switchTo($reminders);
$today = new DateTimeImmutable('today');
$reminderFirm = static function (string $name, string $number, string $prefix) use ($entityManager, $address, $mailSecrets, $firmAccount): array {
    $firm = $firmAccount(new LegalEntity($name, new RegistrationNumber('CZ', $number), VatId::fromString('CZ'.$number), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
    $entityManager->persist($firm);
    $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString($prefix.'{NNNN}'), DocumentKind::Invoice);
    $series->markDefault();
    $entityManager->persist($series);
    $entityManager->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', $name));
    $entityManager->flush();

    return [$firm, $series];
};
[$remindingFirm, $remindingSeries] = $reminderFirm('Example Reminding s.r.o.', '12345695', 'R');
[$manualFirm, $manualSeries] = $reminderFirm('Example Manual s.r.o.', '12345687', 'S');
$automatic = FirmReminders::standard($remindingFirm);
$automatic->change(true, 7, 7, null, $today->modify('-60 days'));
$entityManager->persist($automatic);
$entityManager->flush();
$debtor = $createParty->createFrom($party(PartyKind::Company, 'Example Debtor a.s.', [PartyRole::Customer], '', 10, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($debtor, 'billing@example.com', EmailPurpose::Invoices));
$entityManager->flush();
// Each one sent, which the synchronous e2e queue delivers at once. Sends are dated now, so a
// history reads in order: the invoice, its e-mail, then its reminders.
$reminded = static function (LegalEntity $firm, Series $series, string $description, string $price, int $issuedDaysAgo, int $dueDaysAgo) use ($issueInvoice, $sendInvoice, $debtor, $today): Invoice {
    $issued = $today->modify(sprintf('-%d days', $issuedDaysAgo));
    $invoice = $issueInvoice->issue(new InvoiceInput($firm->id(), $series->id(), null, $debtor->id(), null, $issued, $issued, $today->modify(sprintf('%+d days', -$dueDaysAgo)), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
    $sendInvoice->send(new SendRequest($invoice->id(), ['billing@example.com'], [], false, 'Faktura '.$invoice->number(), "Dobrý den,\nposíláme fakturu ".$invoice->number().".\n\n{link}"));

    return $invoice;
};
$twiceReminded = $reminded($remindingFirm, $remindingSeries, 'Server care', '12000.00', 45, 31);
foreach ([1, 2] as $step) {
    $sendInvoice->send(new SendRequest($twiceReminded->id(), ['billing@example.com'], [], false, 'Upomínka: faktura '.$twiceReminded->number(), "Dobrý den,\nfaktura ".$twiceReminded->number()." zatím není uhrazená.\n\n{link}", SendPurpose::Reminder, $step));
}
$reminded($remindingFirm, $remindingSeries, 'Backups', '2400.00', 20, 6);
$reminded($remindingFirm, $remindingSeries, 'Monitoring', '900.00', 4, -10);
$reminded($manualFirm, $manualSeries, 'Training', '5000.00', 30, 16);
$reminded($manualFirm, $manualSeries, 'Consulting', '7500.00', 9, -5);
$context->reset();

// The tenant of the recurring list specs: a firm with a mail server and templates in each
// state, one failing, and an invoice one of them issued that waits to be sent. Dates count
// from the real today: a template cannot start in the past.
$recurringTenant = $tenantWithOwner('Example Recurring', 'recurring@example.com', 'Recurring Example');
$context->switchTo($recurringTenant);
$recurringFirm = $firmAccount(new LegalEntity('Example Recurring s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(4), VatStatus::Registered, 'Example register, entry C 7890'));
$entityManager->persist($recurringFirm);
$recurringSeries = new Series($recurringFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$recurringSeries->markDefault();
$entityManager->persist($recurringSeries);
$entityManager->persist(FirmMailServer::define($recurringFirm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $mailSecrets->seal('throwaway-smtp-password'), 'invoices@example.com', 'Example Recurring s.r.o.'));
$entityManager->flush();
$client = $createParty->createFrom($party(PartyKind::Company, 'Example Client a.s.', [PartyRole::Customer], '', 10, '12345679', 'CZ12345679'));
$entityManager->persist(new PartyEmail($client, 'client@example.com', EmailPurpose::Invoices));
$entityManager->flush();
$saveRecurring = $container->get('e2e.save_recurring');
$recurringLifecycle = $container->get('e2e.recurring_lifecycle');
$issueRecurring = $container->get('e2e.issue_recurring');
\assert($saveRecurring instanceof SaveRecurringInvoice);
\assert($recurringLifecycle instanceof RecurringLifecycle);
\assert($issueRecurring instanceof IssueRecurringOccurrence);
$tenantClock = $container->get('e2e.tenant_clock');
\assert($tenantClock instanceof TenantClock);
// The firm's today, not the server's: after midnight in the firm's zone they differ.
$today = $tenantClock->today();
$firstOfNextMonth = $today->modify('first day of next month');
$recurring = static fn (string $description, string $price, Recurrence $recurrence, RecurringDelivery $delivery = RecurringDelivery::Send): RecurringInvoice => $saveRecurring->create(new RecurringInput(
    $recurringFirm->id(),
    $recurringSeries->id(),
    $client->id(),
    PaymentMethod::BankTransfer,
    'CZ6508000000192000145399',
    '',
    '',
    $description,
    '',
    '',
    DocumentLanguage::Czech,
    [new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
    $recurrence,
    TaxPointRule::IssueDate,
    14,
    $delivery,
    'Example '.str_replace(' {month/year}', '', $description),
));
$every = static fn (RecurrenceUnit $unit, int $every = 1, ?DateTimeImmutable $first = null, RecurrenceEnd $end = RecurrenceEnd::Never, ?int $endAfter = null): Recurrence => new Recurrence($first ?? $firstOfNextMonth, $unit, $every, false, $end, null, $endAfter);
$recurring('Hosting {month/year}', '1200.00', $every(RecurrenceUnit::Month));
$recurring('Domain renewal', '450.00', $every(RecurrenceUnit::Year), RecurringDelivery::Review);
$recurring('Support licence', '24000.00', $every(RecurrenceUnit::Month, 12, end: RecurrenceEnd::AfterCount, endAfter: 3));
$recurringLifecycle->pause($recurring('Backup storage', '300.00', $every(RecurrenceUnit::Month))->id());
$recurringLifecycle->end($recurring('Old maintenance', '900.00', $every(RecurrenceUnit::Month))->id());
$failing = $recurring('Monitoring', '600.00', $every(RecurrenceUnit::Month));
// An issue refused for want of an address fails until a run goes clean; a refused send would end once its invoice is sent.
$entityManager->getConnection()->executeStatement("UPDATE recurring_invoice SET last_failure_code = 'invoice.problem.customer_address_incomplete', last_failure_at = CURRENT_TIMESTAMP WHERE id = ?", [$failing->id()]);
// Issued today for review, so it waits under "To send".
$held = $recurring('Consulting {month/year}', '5000.00', $every(RecurrenceUnit::Month, first: $today), RecurringDelivery::Review);
$issueRecurring->issue($held->id(), $today, $today);
$context->reset();

// The tenant of the advances spec: a payer firm and one customer, an advance Z920260001 of
// 1 210 Kč the spec pays, and an advance Z920260002 paid before its supply was known, so it
// waits for its tax document; the spec issues it from the list.
$advancesTenant = $tenantWithOwner('Example Advances', 'advances@example.com', 'Advances Example');
$context->switchTo($advancesTenant);
$advancesFirm = new LegalEntity('Example Advances s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(9), VatStatus::Registered, 'Example register, entry C 9753');
$entityManager->persist($advancesFirm);
$firmAccount($advancesFirm);
$entityManager->flush();
$advancesCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Advance Buyer a.s.', [PartyRole::Customer], 'buyer@example.com', 14, '12345679', 'CZ12345679'));
$advanceOf = static fn (string $description, ?TaxOnReceiptChoice $choice): Invoice => $issueInvoice->issue(new InvoiceInput($advancesFirm->id(), null, null, $advancesCustomer->id(), null, $day, null, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
], true, DocumentKind::Advance, $choice));
$advanceOf('First milestone', null);
$waiting = $advanceOf('Second milestone', TaxOnReceiptChoice::Skip);
$money = Money::of('1210.00', Currency::CZK);
$recordPayment->record(new PaymentRequest($advancesFirm->id(), $day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($waiting->id(), $money)]));
// The supply is known now: by the rules VAT arose on the payment, which has no tax document yet.
$entityManager->getConnection()->executeStatement('UPDATE invoice SET tax_on_receipt = NULL WHERE id = ?', [$waiting->id()]);
$context->reset();

// The tenant of the corrections spec: a payer firm and one customer, an unpaid invoice
// 20260001 of 1 210 Kč the spec credits in part, and an invoice 20260002 of 1 210 Kč paid in
// full, which the spec credits in full and refunds by hand.
$corrections = $tenantWithOwner('Example Corrections', 'corrections@example.com', 'Corrections Example');
$context->switchTo($corrections);
$correctionsFirm = new LegalEntity('Example Corrections s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(10), VatStatus::Registered, 'Example register, entry C 8642');
$entityManager->persist($correctionsFirm);
$firmAccount($correctionsFirm);
$correctionsSeries = new Series($correctionsFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$correctionsSeries->markDefault();
$entityManager->persist($correctionsSeries);
$entityManager->flush();
$correctionsCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Credit Buyer a.s.', [PartyRole::Customer], 'credit-buyer@example.com', 15, '12345679', 'CZ12345679'));
$correctable = [];
foreach (['Consulting', 'Workshop'] as $description) {
    $correctable[$description] = $issueInvoice->issue(new InvoiceInput($correctionsFirm->id(), $correctionsSeries->id(), null, $correctionsCustomer->id(), null, $day, $day, $day->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
        new LineInput($description, Quantity::fromString('1'), 'ks', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0')),
    ]));
}
$money = Money::of('1210.00', Currency::CZK);
$recordPayment->record(new PaymentRequest($correctionsFirm->id(), $day, $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($correctable['Workshop']->id(), $money)]));
$context->reset();

// The tenant of the accountant pack spec: a payer firm and one customer, two invoices of last
// month (1 210 Kč at 21 % and 560 Kč at 12 %), an advance of last month for 1 210 Kč and an
// invoice of this month for 2 420 Kč. Nothing is exported yet, so the overview reminds of last
// month. Dates count from the real today, as the overview and the export page do.
$accountantTenant = $tenantWithOwner('Example Accountant', 'accountant@example.com', 'Accountant Example');
$context->switchTo($accountantTenant);
$accountantFirm = new LegalEntity('Example Ledger s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(11), VatStatus::Registered, 'Example register, entry C 7531');
$entityManager->persist($accountantFirm);
$firmAccount($accountantFirm);
$accountantSeries = new Series($accountantFirm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$accountantSeries->markDefault();
$entityManager->persist($accountantSeries);
$entityManager->flush();
$accountantCustomer = $createParty->createFrom($party(PartyKind::Company, 'Example Ledger Buyer a.s.', [PartyRole::Customer], 'ledger-buyer@example.com', 16, '12345679', 'CZ12345679'));
$accountantToday = $tenantClock->today();
$lastMonth = $accountantToday->modify('first day of last month');
$accountantDocument = static fn (DateTimeImmutable $date, string $description, string $price, VatRateKind $rate, DocumentKind $kind = DocumentKind::Invoice): Invoice => $issueInvoice->issue(new InvoiceInput($accountantFirm->id(), DocumentKind::Invoice === $kind ? $accountantSeries->id() : null, null, $accountantCustomer->id(), null, $date, DocumentKind::Invoice === $kind ? $date : null, $date->modify('+14 days'), PaymentMethod::BankTransfer, 'CZ6508000000192000145399', '', '', '', $description, '', '', DocumentLanguage::Czech, [
    new LineInput($description, Quantity::fromString('1'), 'ks', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, $rate, null, Percent::fromString('0')),
], true, $kind));
$accountantDocument($lastMonth->modify('+9 days'), 'Bookkeeping', '1000.00', VatRateKind::Base);
$accountantDocument($lastMonth->modify('+11 days'), 'Printed manuals', '500.00', VatRateKind::Reduced);
$accountantDocument($lastMonth->modify('+14 days'), 'Annual audit deposit', '1000.00', VatRateKind::Base, DocumentKind::Advance);
$accountantDocument($accountantToday, 'Payroll', '2000.00', VatRateKind::Base);
$context->reset();

// The tenant of the iDoklad spec: a payer firm with stored iDoklad keys and a finished pull whose
// records are inserted as a pull stores them, so the e2e server never calls iDoklad. Three
// contacts, three number series, invoice 20260008 whose totals match and 20260009 whose VAT is a
// koruna off, a credit note (refused), an advance Z20260003 of 1 210 Kč paid on 5 October with its
// tax document D20260001, and one monthly template. Nothing is checked or imported yet. The spec
// walks the first tenant; its screenshots import into the second, which nothing else touches.
$idokladTenants = [
    $tenantWithOwner('Example Move', 'idoklad@example.com', 'Move Example'),
    $tenantWithOwner('Example Move Screens', 'idoklad-screens@example.com', 'Move Screens Example'),
];
foreach ($idokladTenants as $idokladTenant) {
    $context->switchTo($idokladTenant);
    $idokladFirm = new LegalEntity('Example Move s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $address(12), VatStatus::Registered, 'Example register, entry C 4321');
    $entityManager->persist($idokladFirm);
    $firmAccount($idokladFirm);
    $entityManager->persist(new IdokladConnection($idokladFirm, 'example-client-id', $mailSecrets->seal('throwaway-idoklad-secret')));
    $pulledAt = new DateTimeImmutable('-1 hour');
    /** @param array<string, mixed> $payload */
    $idokladRecord = static function (IdokladResource $resource, int $id, array $payload) use ($entityManager, $idokladFirm, $pulledAt): void {
        $entityManager->persist(new IdokladRecord($idokladFirm, $resource, $id, ['Id' => $id] + $payload, $pulledAt));
    };
    $myAddress = ['NickName' => 'Example Move s.r.o.', 'IdentificationNumber' => '12345695', 'VatIdentificationNumber' => 'CZ12345695', 'Email' => 'office@example.com', 'Street' => 'Example Street 12', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2, 'Iban' => 'CZ65 0800 0000 1920 0014 5399', 'AccountNumber' => '19-2000145399', 'BankCode' => '0800'];
    $buyerAddress = ['NickName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'trading@example.com', 'Street' => 'Example Street 13', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2];
    $idokladItem = static fn (string $name, float $amount, float $unitPrice, float $net, float $vat): array => ['Id' => crc32($name), 'Name' => $name, 'Amount' => $amount, 'Unit' => 'h', 'ItemType' => 0, 'PriceType' => 1, 'VatRateType' => 1, 'VatRate' => 21.0, 'DiscountPercentage' => 0.0, 'Prices' => ['UnitPrice' => $unitPrice, 'TotalWithoutVat' => $net, 'TotalVat' => $vat, 'TotalWithVat' => $net + $vat]];
    /** @param array<string, mixed> $fields */
    $idokladDocument = static fn (array $fields): array => $fields + [
        'DocumentNumber' => '20260008',
        'DocumentSerialNumber' => 8,
        'VariableSymbol' => '20260008',
        'ConstantSymbolId' => 7,
        'CurrencyId' => 1,
        'ExchangeRate' => 1.0,
        'ExchangeRateAmount' => 1.0,
        'DateOfIssue' => '2026-10-01',
        'DateOfTaxing' => '2026-10-01',
        'DateOfMaturity' => '2026-10-15',
        'Description' => 'Consulting',
        'OrderNumber' => '',
        'ItemsTextPrefix' => '',
        'ItemsTextSuffix' => '',
        'PartnerId' => 501,
        'PartnerAddress' => $buyerAddress,
        'MyAddress' => $myAddress,
        'PaymentOptionId' => 1,
        'ReportLanguage' => 1,
        'VatRegime' => 1,
        'IsSentToPurchaser' => 0,
        'Exported' => 0,
        'ProformaInvoices' => [],
        'Items' => [$idokladItem('Consulting', 2.0, 1000.0, 2000.0, 420.0)],
        'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 420.0, 'TotalWithVat' => 2420.0, 'TotalWithoutVatHc' => 2000.0, 'TotalVatHc' => 420.0, 'TotalWithVatHc' => 2420.0],
        'Metadata' => ['DateCreated' => '2026-10-01T09:00:00.123', 'DateLastChange' => '2026-10-02T10:39:22.873'],
    ];
    $idokladRecord(IdokladResource::Countries, 2, ['Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'CurrencyId' => 1]);
    $idokladRecord(IdokladResource::Currencies, 1, ['Code' => 'CZK', 'Symbol' => 'Kč']);
    $idokladRecord(IdokladResource::PaymentOptions, 1, ['Code' => 'B', 'Name' => 'Převodem', 'IsDefault' => true]);
    $idokladRecord(IdokladResource::ConstantSymbols, 7, ['Code' => '0308', 'Name' => 'Služby', 'CountryId' => 2]);
    $idokladRecord(IdokladResource::BankAccounts, 1, ['Iban' => 'CZ65 0800 0000 1920 0014 5399']);
    $idokladRecord(IdokladResource::Contacts, 501, ['CompanyName' => 'Example Trading s.r.o.', 'IdentificationNumber' => '23456787', 'VatIdentificationNumber' => 'CZ23456787', 'Email' => 'trading@example.com', 'Street' => 'Example Street 13', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
    $idokladRecord(IdokladResource::Contacts, 502, ['CompanyName' => 'Example Retail a.s.', 'IdentificationNumber' => '34567895', 'Email' => 'retail@example.com', 'Street' => 'Example Street 14', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
    $idokladRecord(IdokladResource::Contacts, 503, ['Firstname' => 'Robin', 'Surname' => 'Example', 'Email' => 'robin@example.com', 'Street' => 'Example Street 15', 'City' => 'Example City', 'PostalCode' => '100 00', 'CountryId' => 2]);
    $idokladRecord(IdokladResource::NumericSequences, 1, ['DocumentType' => 0, 'Name' => 'Faktury', 'NumberFormat' => 'RRRRNNNN', 'Year' => 2026, 'LastNumber' => 9, 'IsDefault' => true]);
    $idokladRecord(IdokladResource::NumericSequences, 3, ['DocumentType' => 1, 'Name' => 'Zálohy', 'NumberFormat' => 'ZRRRRNNNN', 'Year' => 2026, 'LastNumber' => 3, 'IsDefault' => true]);
    $idokladRecord(IdokladResource::NumericSequences, 4, ['DocumentType' => 10, 'Name' => 'Daňové doklady', 'NumberFormat' => 'DRRRRNNNN', 'Year' => 2026, 'LastNumber' => 1, 'IsDefault' => true]);
    $idokladRecord(IdokladResource::IssuedInvoices, 101, $idokladDocument([]));
    $idokladRecord(IdokladResource::IssuedInvoices, 102, $idokladDocument(['DocumentNumber' => '20260009', 'DocumentSerialNumber' => 9, 'VariableSymbol' => '20260009', 'DateOfIssue' => '2026-10-10', 'DateOfTaxing' => '2026-10-10', 'DateOfMaturity' => '2026-10-24', 'Prices' => ['TotalWithoutVat' => 2000.0, 'TotalVat' => 421.0, 'TotalWithVat' => 2421.0]]));
    $idokladRecord(IdokladResource::CreditNotes, 301, ['DocumentNumber' => 'O20260001', 'DateOfIssue' => '2026-10-12']);
    // An advance has no tax point of its own; its tax document has.
    $idokladRecord(IdokladResource::ProformaInvoices, 201, array_diff_key($idokladDocument([
        'DocumentNumber' => 'Z20260003',
        'DocumentSerialNumber' => 3,
        'VariableSymbol' => '20260003',
        'Description' => 'Deposit',
        'Items' => [$idokladItem('Deposit', 1.0, 1000.0, 1000.0, 210.0)],
        'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
    ]), ['DateOfTaxing' => true]));
    $idokladRecord(IdokladResource::IssuedDocumentPayments, 401, ['InvoiceId' => 201, 'InvoiceDocumentNumber' => 'Z20260003', 'DocumentType' => 1, 'DateOfPayment' => '2026-10-05T00:00:00', 'CurrencyId' => 1, 'ExchangeRate' => 1.0, 'ExchangeRateAmount' => 1.0, 'PaymentOptionId' => 1, 'Prices' => ['PaymentAmount' => 1210.0, 'PaymentAmountHc' => 1210.0], 'IssuedTaxDocumentId' => 601, 'Partner' => 'Example Trading s.r.o.']);
    $idokladRecord(IdokladResource::IssuedTaxDocuments, 601, [
        'DocumentNumber' => 'D20260001',
        'DocumentSerialNumber' => 1,
        'DateOfIssue' => '2026-10-05T00:00:00',
        'DateOfTaxing' => '2026-10-05T00:00:00',
        'PaymentId' => 401,
        'ProformaInvoiceId' => 201,
        'CurrencyId' => 1,
        'ExchangeRate' => 1.0,
        'ExchangeRateAmount' => 1.0,
        'PartnerId' => 501,
        'PartnerAddress' => $buyerAddress,
        'MyAddress' => $myAddress,
        'VariableSymbol' => '20260003',
        'ReportLanguage' => 1,
        'IsSentToPartner' => 0,
        'Exported' => 0,
        'Items' => [['Id' => 1, 'Name' => 'Deposit', 'PriceType' => 0, 'VatRateType' => 1, 'VatRate' => 21.0, 'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0]]],
        'Prices' => ['TotalWithoutVat' => 1000.0, 'TotalVat' => 210.0, 'TotalWithVat' => 1210.0],
    ]);
    $idokladRecord(IdokladResource::RecurringInvoices, 901, [
        'InvoiceTemplate' => [
            'DocumentType' => 0,
            'CurrencyId' => 1,
            'Partner' => ['Id' => 502, 'CompanyName' => 'Example Retail a.s.'],
            'NumericSequenceId' => 1,
            'PaymentOptionId' => 1,
            'BankAccountId' => 1,
            'ConstantSymbolId' => 7,
            'OrderNumber' => '',
            'Description' => 'Hosting {month/year}',
            'ItemsTextPrefix' => '',
            'ItemsTextSuffix' => '',
            'ReportLanguage' => 1,
            'TaxingType' => 1,
            'InvoiceMaturity' => 14,
            'DiscountPercentage' => 0.0,
            'VatReverseChargeCodeId' => null,
            'Note' => '',
            'Items' => [['Id' => 1, 'ItemType' => 0, 'Name' => 'Hosting', 'Amount' => 1.0, 'Unit' => 'ks', 'PriceType' => 1, 'VatRateType' => 1, 'Prices' => ['UnitPrice' => 1000.0]]],
        ],
        'RecurringSetting' => [
            'TemplateName' => 'Monthly hosting',
            'RecurrenceType' => 2,
            'RecurrenceCount' => 1,
            'IssueLastDayOfMonth' => false,
            'DateOfStart' => '2026-01-01T00:00:00',
            'TypeOfEnd' => 0,
            'DateOfEnd' => null,
            'CopyCountEnd' => 0,
            'CopyCountActual' => 10,
            'DateOfLastIssue' => '2026-10-01T00:00:00',
            'DateOfNextIssue' => '2026-11-01T00:00:00',
            'IsActive' => true,
            'SendToPurchaser' => true,
            'SendToSupplier' => false,
            'SendToOtherEmails' => false,
            'SendToAccountant' => false,
        ],
    ]);
    $idokladPull = new IdokladPull($idokladFirm, $pulledAt->modify('-5 minutes'));
    foreach ([[IdokladResource::Contacts, 3], [IdokladResource::NumericSequences, 3], [IdokladResource::IssuedInvoices, 2], [IdokladResource::ProformaInvoices, 1], [IdokladResource::IssuedTaxDocuments, 1], [IdokladResource::CreditNotes, 1], [IdokladResource::RecurringInvoices, 1], [IdokladResource::IssuedDocumentPayments, 1]] as [$resource, $records]) {
        $idokladPull->counted($resource, $records);
    }
    $idokladPull->noteChange('2026-10-02T10:39:22.873');
    $idokladPull->finish($pulledAt, 42);
    $entityManager->persist($idokladPull);
    $entityManager->flush();
    $context->reset();
}

// Projects: the owner of "Example Projects" signs in for the application services, the way the
// browser would, and keeps a project for a client with tasks in every state of the workflow.
$projectsTenant = $tenantWithOwner('Example Projects', 'projects@example.com', 'Projects Example');
// No firm gives it a zone, so it gets the seed's own: its today is the today of the due dates below.
$projectsTenantRecord = $entityManager->find(Tenant::class, $projectsTenant);
\assert($projectsTenantRecord instanceof Tenant);
$projectsTenantRecord->changeTimezone(new DateTimeZone('Europe/Prague'));
$entityManager->flush();
$manageProjects = $container->get('e2e.manage_projects');
$manageSections = $container->get('e2e.manage_sections');
$manageTasks = $container->get('e2e.manage_tasks');
$manageLabels = $container->get('e2e.manage_labels');
$manageComments = $container->get('e2e.manage_comments');
$uploadAttachment = $container->get('e2e.upload_attachment');
$tokenStorage = $container->get('e2e.token_storage');
\assert($manageComments instanceof ManageComments);
\assert($uploadAttachment instanceof UploadAttachment);
\assert($manageProjects instanceof ManageProjects);
\assert($manageSections instanceof ManageSections);
\assert($manageTasks instanceof ManageTasks);
\assert($manageLabels instanceof ManageLabels);
\assert($tokenStorage instanceof TokenStorageInterface);

$context->switchTo($projectsTenant);
$teamUser = new User('member@example.com', $hash, 'Jana Example', 'cs');
$entityManager->persist($teamUser);
$teamMember = new Membership($teamUser, Role::Member);
$entityManager->persist($teamMember);
$entityManager->flush();
$projectClient = $createParty->createFrom($party(PartyKind::Company, 'Example Client s.r.o.', [PartyRole::Customer], 'client@example.com', 8, '12345695', 'CZ12345695'));
$projectOwner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'projects@example.com']) ?? throw new LogicException('The owner of the projects tenant is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($projectOwner->id(), $projectOwner->email(), 'hash'), 'main', ['ROLE_USER']));

$design = $manageLabels->create('Design', LabelColour::Green);
$bug = $manageLabels->create('Bug', LabelColour::Red);
$website = $manageProjects->create('Website', $projectClient->id(), WorkflowTemplate::Development, 'The new website of the client.');
$backlog = $manageSections->add($website->id(), 'Backlog');
$shared = $manageSections->add($website->id(), 'Shared with client');
// Open, In progress, Review, Testing, Blocked, Done, Closed.
$statuses = $entityManager->getRepository(ProjectStatus::class)->findBy(['project' => $website], ['position' => 'ASC']);
$ownerMembership = $entityManager->getRepository(Membership::class)->findOneBy(['user' => $projectOwner]) ?? throw new LogicException('The owner membership is missing.');
$day = static fn (string $modifier): DateTimeImmutable => $today->modify($modifier);

$homepage = $manageTasks->create($website->id(), 'Design the homepage', $shared->id(), null, $statuses[1]->id(), $ownerMembership->id(), $day('-2 days'), Priority::High);
$manageTasks->setLabels($homepage->id(), [$design->id()]);
$manageComments->add($homepage->id(), sprintf("First sketch is ready, **please review**.\n\n- hero with the new logo\n- a short pitch\n\n@[Jana Example](membership:%s) can you check the copy?", $teamMember->id()), true);
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($teamUser->id(), $teamUser->email(), 'hash'), 'main', ['ROLE_USER']));
$manageComments->add($homepage->id(), 'Looks good, the *pitch* reads well.', false);
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($projectOwner->id(), $projectOwner->email(), 'hash'), 'main', ['ROLE_USER']));
// A small wireframe PNG and a one-page PDF, both written for the occasion and handed over like an upload.
$png = tempnam(sys_get_temp_dir(), 'seed');
$sketch = imagecreatetruecolor(320, 200);
\assert(false !== $sketch);
$paper = (int) imagecolorallocate($sketch, 0xF4, 0xF2, 0xEC);
$ink = (int) imagecolorallocate($sketch, 0x2F, 0x4A, 0x3C);
$soft = (int) imagecolorallocate($sketch, 0xC9, 0xD6, 0xCD);
imagefill($sketch, 0, 0, $paper);
imagefilledrectangle($sketch, 0, 0, 319, 28, $ink);
imagefilledrectangle($sketch, 24, 48, 296, 120, $soft);
imagefilledrectangle($sketch, 24, 136, 144, 184, $soft);
imagefilledrectangle($sketch, 176, 136, 296, 184, $soft);
imagepng($sketch, $png);
$uploadAttachment->upload('task', $homepage->id(), new AttachmentUpload($png, 'homepage-sketch.png', (int) filesize($png)));
$pdf = tempnam(sys_get_temp_dir(), 'seed');
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n");
$uploadAttachment->upload('task', $homepage->id(), new AttachmentUpload($pdf, 'brief.pdf', (int) filesize($pdf)));
$manageTasks->create($website->id(), 'Pick the colour palette', $shared->id(), $homepage->id(), $statuses[0]->id(), $teamMember->id());
$contact = $manageTasks->create($website->id(), 'Fix the contact form', $backlog->id(), null, $statuses[0]->id(), $ownerMembership->id(), $day('+0 days'), Priority::Urgent);
$manageTasks->setLabels($contact->id(), [$bug->id()]);
$manageTasks->create($website->id(), 'Write the copy for the about page', $backlog->id(), null, $statuses[0]->id(), $teamMember->id(), $day('+7 days'), Priority::Medium);
$manageTasks->create($website->id(), 'Review the cookie banner', $shared->id(), null, $statuses[2]->id(), $teamMember->id(), null, Priority::Low);
$manageTasks->create($website->id(), 'Set up the domain', $backlog->id(), null, $statuses[5]->id(), $ownerMembership->id());
$tokenStorage->setToken(null);
$context->reset();

fwrite(\STDOUT, sprintf("Seeded %d parties.\n", \count($inputs)));
