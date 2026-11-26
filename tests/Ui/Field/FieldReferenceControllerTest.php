<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Field;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The picker of the contact and user fields (FieldReferenceController): the staff pick
 * from it, a guest does not, and only a kind of reference has one.
 */
final class FieldReferenceControllerTest extends WebTestCase
{
    #[DataProvider('roles')]
    public function testOnlyTheStaffPick(Role $role, int $status): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $browser = new SignedInBrowser($client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed(role: $role);
        $browser->signIn();

        $client->request('GET', '/fields/user/matches?q=example');

        self::assertResponseStatusCodeSame($status);
    }

    /**
     * @return iterable<string, array{Role, int}>
     */
    public static function roles(): iterable
    {
        yield 'a member' => [Role::Member, 200];
        yield 'a guest' => [Role::Guest, 403];
    }

    public function testOnlyAReferenceHasAPicker(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $browser = new SignedInBrowser($client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed(role: Role::Owner);
        $browser->signIn();

        $client->request('GET', '/fields/text/matches?q=example');

        self::assertResponseStatusCodeSame(404);
    }
}
