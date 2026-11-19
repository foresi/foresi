<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Party\Application\AccountNumbers;
use Foresi\Party\Application\CompanyRegistry;
use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\CreateParty;
use Foresi\Party\Application\ImportPartyValues;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\ManageFirmBankAccounts;
use Foresi\Party\Application\Parties;
use Foresi\Party\Application\RegistrationNumberFactory;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Application\VatPayerReliability;
use Foresi\Party\Application\VatRegistry;
use Foresi\Party\Contract\BankAccountUsage;
use Foresi\Party\Domain\DomesticBankAccount;
use Foresi\Party\Domain\RegistrationNumberValidator;
use Foresi\Party\Infrastructure\CollectedBankAccountUsage;
use Foresi\Party\Infrastructure\CollectedContactImports;
use Foresi\Party\Infrastructure\Doctrine\DoctrineTransactionalWork;
use Foresi\Party\Infrastructure\Http\FilesystemFirmImages;
use Foresi\Party\Infrastructure\Registry\CollectedCompanyRegistry;
use Foresi\Party\Infrastructure\Registry\CollectedVatPayerReliability;
use Foresi\Tests\Party\Infrastructure\Support\RacingLegalEntities;
use Foresi\Tests\Party\Infrastructure\Support\RejectingParties;
use Foresi\Tests\Support\Registry\FakeCompanyRegistry;
use Foresi\Tests\Support\Registry\FakeVatPayerReliability;
use Foresi\Tests\Support\Registry\FakeVatRegistry;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Party module, loaded by Foresi\Party\PartyBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Party\\', '../')
        ->exclude(['../config/', '../Integration/', '../PartyBundle.php']);

    // Logos and stamps; never below public/. From the kernel, not from the environment
    // variable: that one is relative, and the web server would resolve it inside public/.
    $container->parameters()->set('app.firm_image_dir', '%kernel.share_dir%/firm-images');

    // Country modules plug into the core through tagged interfaces.
    $builder->registerForAutoconfiguration(RegistrationNumberValidator::class)->addTag('app.registration_number_validator');
    $builder->registerForAutoconfiguration(DomesticBankAccount::class)->addTag('app.domestic_bank_account');
    // Ports served by other modules; each collects its providers (ADR 0034, rule 3).
    $builder->registerForAutoconfiguration(CompanyRegistry::class)->addTag('app.company_registry');
    $builder->registerForAutoconfiguration(VatPayerReliability::class)->addTag('app.vat_payer_reliability');
    $builder->registerForAutoconfiguration(ContactImports::class)->addTag(ContactImports::TAG);

    $services->set(RegistrationNumberFactory::class)
        ->arg('$validators', tagged_iterator('app.registration_number_validator'));
    $services->set(ImportPartyValues::class)
        ->arg('$domesticAccounts', tagged_iterator('app.domestic_bank_account'));
    $services->set(ManageFirmBankAccounts::class)
        ->arg('$domesticAccounts', tagged_iterator('app.domestic_bank_account'));
    $services->set(AccountNumbers::class)
        ->arg('$domesticAccounts', tagged_iterator('app.domestic_bank_account'));
    // Modules that record movements, such as Banking, fix an account's currency.
    $services->alias(BankAccountUsage::class, CollectedBankAccountUsage::class);
    // Country modules offer their registers; without one, lookups come back empty.
    $services->alias(CompanyRegistry::class, CollectedCompanyRegistry::class);
    $services->alias(VatPayerReliability::class, CollectedVatPayerReliability::class);
    // An import module tells which contacts an import wrote; without one, no list is narrowed to an import.
    $services->alias(ContactImports::class, CollectedContactImports::class);

    $services->set(FilesystemFirmImages::class)
        ->arg('$root', param('app.firm_image_dir'));

    // Declared here (private) so PHPStan, which reads the dev container, knows the
    // id used by the wiring test; it is made public in the test env below.
    $services->alias('test.registration_number_factory', RegistrationNumberFactory::class);

    if ('test' === $container->env()) {
        // Inside the test cache, so a test never writes to the real share directory. One
        // directory per ParaTest process (TEST_TOKEN): the tests that store firm images clear
        // it afterwards, and two of them side by side would clear each other's files.
        $container->parameters()->set('app.firm_image_dir', '%kernel.cache_dir%/firm-images%env(default::TEST_TOKEN)%');

        // Public for the tests, which use the ports directly.
        $services->alias(TransactionalWork::class, DoctrineTransactionalWork::class)->public();
        $services->alias(BankAccountUsage::class, CollectedBankAccountUsage::class)->public();

        // Lets a test make storing a party fail with a domain error after it was written.
        $services->set(RejectingParties::class)->decorate(Parties::class);
        // Lets a test lose the race for a registration number: the form check sees no other firm.
        $services->set(RacingLegalEntities::class)->decorate(LegalEntities::class);

        // Public alias in the test env only, so the wiring of the factory can be tested.
        $services->alias('test.registration_number_factory', RegistrationNumberFactory::class)->public();
    }

    if (\in_array($container->env(), ['test', 'e2e'], true)) {
        // Registry adapters are replaced by fixture-backed fakes: no test ever calls a live register.
        // The tests use them directly, so they are public there.
        foreach ([CompanyRegistry::class => FakeCompanyRegistry::class, VatRegistry::class => FakeVatRegistry::class, VatPayerReliability::class => FakeVatPayerReliability::class] as $port => $fake) {
            $alias = $services->alias($port, $fake);
            if ('test' === $container->env()) {
                $alias->public();
            }
        }
        // Not autoconfigured, so the collecting registries never ask the fakes too.
        $services->set(FakeCompanyRegistry::class)->autoconfigure(false);
        $services->set(FakeVatRegistry::class);
        $services->set(FakeVatPayerReliability::class)->autoconfigure(false);
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.create_party', CreateParty::class)->public();
        $services->alias('e2e.registration_numbers', RegistrationNumberFactory::class)->public();
    }
};
