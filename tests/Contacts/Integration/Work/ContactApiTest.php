<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Work;

use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The contacts of the API filtered by the projects the Work module relates to
 * their client (spec DS6).
 */
final class ContactApiTest extends ApiTestCase
{
    public function testTheProjectsOfAContactFilterTheList(): void
    {
        [$alpha, $beta] = $this->flushed(function (): array {
            $alpha = new Party(PartyKind::SoleTrader, 'Alpha Example', null, null, '');
            $beta = new Party(PartyKind::SoleTrader, 'Beta Example', null, null, '');
            $this->em()->persist($alpha);
            $this->em()->persist($beta);

            return [$alpha, $beta];
        });
        $ids = function (array ...$conditions) use ($alpha, $beta): array {
            $this->get('/api/v1/contacts', ['filter' => json_encode(['conditions' => $conditions], \JSON_THROW_ON_ERROR)]);
            self::assertResponseIsSuccessful();

            return array_values(array_intersect($this->ids(), [$alpha->id(), $beta->id()]));
        };

        // None here has a project.
        self::assertSame([], $ids(['field' => 'projects', 'op' => 'is_not_empty']));
        self::assertSame([$beta->id(), $alpha->id()], $ids(['field' => 'projects', 'op' => 'is_empty']));
        self::assertSame([], $ids(['field' => 'projects.name', 'op' => 'contains', 'value' => 'web']));
    }
}
