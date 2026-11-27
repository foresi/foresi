<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Contacts\Application\CreateParty;
use Foresi\Contacts\Contract\ContactCreation;
use Foresi\Contacts\Contract\ContactPersonWrites;
use Foresi\Contacts\Contract\ContactsByEmail;
use Foresi\Contacts\Infrastructure\Directory\DoctrineContactCreation;
use Foresi\Contacts\Infrastructure\Directory\DoctrineContactPersonWrites;
use Foresi\Contacts\Infrastructure\Doctrine\DoctrineContactsByEmail;
use Foresi\Contacts\Infrastructure\Identity\RoutedPersonContactPicker;
use Foresi\Contacts\Infrastructure\Routing\RoutedContactPages;
use Foresi\Identity\Contract\PersonContactPicker;
use Foresi\Party\Contract\ContactPages;
use Foresi\Tests\Contacts\Infrastructure\Support\ExampleContactTab;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * The services of the Contacts module, loaded by Foresi\Contacts\ContactsBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Contacts\\', '../')
        ->exclude(['../config/', '../Integration/', '../ContactsBundle.php']);

    // Deals adds a contact person to a deal's client through the contract.
    $services->alias(ContactPersonWrites::class, DoctrineContactPersonWrites::class);
    // Inbox matches a sender to the address book, with nobody signed in, and creates a contact from a message.
    $services->alias(ContactsByEmail::class, DoctrineContactsByEmail::class);
    $services->alias(ContactCreation::class, DoctrineContactCreation::class);
    // The modules that name a party link it to its page here (Party\Contract\ContactPages).
    $services->alias(ContactPages::class, RoutedContactPages::class);
    // The people screens of Identity offer the contact a person represents through its picker (ADR 0049).
    $services->alias(PersonContactPicker::class, RoutedPersonContactPicker::class);

    if ('test' === $container->env()) {
        // Lets a test add a module's tab to a contact.
        $services->set(ExampleContactTab::class);
        // Public for the tests, which use the ports directly.
        $services->alias(ContactPersonWrites::class, DoctrineContactPersonWrites::class)->public();
        $services->alias(ContactsByEmail::class, DoctrineContactsByEmail::class)->public();
        $services->alias(ContactCreation::class, DoctrineContactCreation::class)->public();
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.create_party', CreateParty::class)->public();
    }
};
