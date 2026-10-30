<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Seeds the end-to-end database (var/e2e.db): the tenant "Example Company" with
 * the user testing@example.com and 30 parties, plus a second, empty tenant for
 * the first-run screen. Run it after the migrations, in the e2e environment:
 *
 *     APP_ENV=e2e php tests/e2e/seed.php
 *
 * Only example.com data and the synthetic registration numbers of the registry
 * fixtures are used.
 */

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Kernel;
use App\Party\Application\CreateParty;
use App\Party\Application\PartyInput;
use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\Address;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\VatId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

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
\assert($entityManager instanceof EntityManagerInterface);
\assert($context instanceof TenantContext);
\assert($createParty instanceof CreateParty);
\assert($registrationNumbers instanceof RegistrationNumberFactory);
\assert($hashers instanceof PasswordHasherFactoryInterface);

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

$address = static fn (int $n): Address => new Address(sprintf('Example Street %d', $n), 'Example City', '100 00', 'CZ');
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

fwrite(\STDOUT, sprintf("Seeded %d parties.\n", \count($inputs)));
