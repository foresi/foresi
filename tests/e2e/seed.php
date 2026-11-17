<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Seeds one world of the end-to-end tests: one SQLite database, var/e2e/<world>.db,
 * holding one organisation, as a real installation does (one instance per customer). Each
 * spec signs in to the world of its own owner (tests/e2e/support/session.ts), so what one
 * spec creates or seeds never shows up in another. serve.sh copies the migrated database
 * once per world and runs this script for each, in the e2e environment:
 *
 *     E2E_WORLD=bank APP_ENV=e2e php tests/e2e/seed.php
 *
 * `php tests/e2e/seed.php --worlds` lists the worlds. The data of a world is in
 * tests/e2e/seed/<world>.php, which runs with the organisation already active; a world
 * without a file is the organisation and its owner only. Only example.com data and the
 * synthetic registration numbers of the registry fixtures are used.
 */

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Instance\Application\TenantClock;
use App\Instance\Application\TenantContext;
use App\Instance\Domain\Tenant;
use App\Instance\Domain\TenantId;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\SendInvoice;
use App\Kernel;
use App\Party\Application\CreateParty;
use App\Party\Application\PartyInput;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\Address;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\VatId;
use App\Shared\Domain\Currency;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Each world: its organisation and the owner who signs in (none: nobody signs in to it).
const WORLDS = [
    // 30 contacts for the address book, the contact and the layout specs.
    'main' => ['Example Company', 'testing@example.com', 'Testing Example'],
    // Nothing at all: the empty states and the import into an empty address book.
    'empty' => ['Example Empty Company', 'empty@example.com', 'Empty Example'],
    // Two firms and one contact for the firm scope.
    'group' => ['Example Group', 'group@example.com', 'Group Example'],
    // A VAT payer, a non-payer and customers for the invoice form.
    'invoicing' => ['Example Invoicing', 'invoicing@example.com', 'Invoicing Example'],
    'first-run' => ['Example First Run', 'first-run@example.com', 'First Run Example'],
    'sending' => ['Example Sending', 'sending@example.com', 'Sending Example'],
    'dialogs' => ['Example Dialogs', 'dialogs@example.com', 'Dialogs Example'],
    'bank' => ['Example Bank', 'bank@example.com', 'Bank Example'],
    'reminders' => ['Example Reminders', 'reminders@example.com', 'Reminders Example'],
    // The people spec invites into its own organisation, which has no system mail.
    'people' => ['Example People', 'people@example.com', 'People Example'],
    // The API token spec turns two-factor sign-in on for this owner and issues a token.
    'api' => ['Example API', 'api@example.com', 'Api Example'],
    // One sent invoice for the public link page; its links go to var/e2e-links.json.
    'public' => ['Example Sender', null, null],
    'paying' => ['Example Paying', 'paying@example.com', 'Paying Example'],
    'movements' => ['Example Bank Movements', 'movements@example.com', 'Movements Example'],
    'recurring' => ['Example Recurring', 'recurring@example.com', 'Recurring Example'],
    'advances' => ['Example Advances', 'advances@example.com', 'Advances Example'],
    'corrections' => ['Example Corrections', 'corrections@example.com', 'Corrections Example'],
    'accountant' => ['Example Accountant', 'accountant@example.com', 'Accountant Example'],
    'idoklad' => ['Example Move', 'idoklad@example.com', 'Move Example'],
    'idoklad-screens' => ['Example Move Screens', 'idoklad-screens@example.com', 'Move Screens Example'],
    // Also the team member member@example.com and the guest guest@example.com of its client.
    'projects' => ['Example Projects', 'projects@example.com', 'Projects Example'],
    'purchasing' => ['Example Purchasing', 'purchasing@example.com', 'Purchasing Example'],
];

if (\in_array('--worlds', $argv, true)) {
    fwrite(\STDOUT, implode("\n", array_keys(WORLDS))."\n");
    exit(0);
}
$world = getenv('E2E_WORLD');
if (!\is_string($world) || !\array_key_exists($world, WORLDS)) {
    fwrite(\STDERR, "Set E2E_WORLD to one of the worlds of --worlds.\n");
    exit(1);
}

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

// The issue day stays fixed, so the numbers of the year stay 2026xxxx; the due day follows the
// calendar, so these invoices never fall overdue and turn into reminder rows on a later day.
$day = new DateTimeImmutable('2026-11-02');
$due = new DateTimeImmutable('today')->modify('+14 days');
$recordPayment = $container->get('e2e.record_payment');
$tenantClock = $container->get('e2e.tenant_clock');
$tokenStorage = $container->get('e2e.token_storage');
\assert($recordPayment instanceof RecordPayment);
\assert($tenantClock instanceof TenantClock);
\assert($tokenStorage instanceof TokenStorageInterface);

// The world's one organisation, with its owner as a member, active while its data is seeded.
[$organisation, $ownerEmail, $ownerName] = WORLDS[$world];
$tenant = TenantId::generate();
$entityManager->persist(new Tenant($tenant, $organisation, new DateTimeImmutable()));
$entityManager->flush();
$context->switchTo($tenant);
if (null !== $ownerEmail && null !== $ownerName) {
    $owner = new User($ownerEmail, $hash, $ownerName, 'cs');
    $entityManager->persist($owner);
    $entityManager->persist(new Membership($owner, Role::Owner));
    $entityManager->flush();
}
if (is_file(__DIR__.'/seed/'.$world.'.php')) {
    require __DIR__.'/seed/'.$world.'.php';
}
$context->reset();

fwrite(\STDOUT, sprintf("Seeded the world %s.\n", $world));
