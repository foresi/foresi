<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Work;

use Foresi\Party\Application\View\ContactLists;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Work module relates its projects to their client (DS6): the address book's
 * list gets the relation and the name of a related project as its fields.
 */
final class ContactListsTest extends KernelTestCase
{
    public function testTheProjectsOfAContactAreAFieldWithTheNameOfARelatedProject(): void
    {
        $keys = array_keys(self::getContainer()->get(ContactLists::class)->list()->fields);

        self::assertSame(['projects', 'projects.name'], \array_slice($keys, (int) array_search('projects', $keys, true), 2));
        self::assertGreaterThan(array_search('contact_persons.name', $keys, true), array_search('projects', $keys, true), 'Other modules\' relations follow the address book\'s own.');
    }
}
