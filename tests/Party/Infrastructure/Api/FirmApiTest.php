<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Api;

use Foresi\Contacts\ContactsBundle;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * GET /api/v1/firm: the one firm of the instance, read with the firm:read scope.
 */
final class FirmApiTest extends ApiTestCase
{
    public function testTheFirmOfTheInstanceIsAnswered(): void
    {
        $this->get('/api/v1/firm');

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => [
            'id' => $this->firmId,
            'name' => 'Example Firm s.r.o.',
            'registration_country' => 'CZ',
            'registration_number' => '12345695',
            'vat_id' => 'CZ12345695',
            'vat_status' => 'registered',
            'vat_statuses' => [['id' => $this->firmId, 'status' => 'registered', 'period_length' => 'month', 'valid_from' => null]],
            'register_entry' => 'Example register, entry C 1',
            'address' => ['street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'],
        ]], $this->json());
    }

    public function testATokenWithoutTheFirmReadScopeReadsNoFirm(): void
    {
        $this->get('/api/v1/firm', secret: $this->issued([ApiScope::from('views:write')]));

        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testTheScopeOfAnotherResourceReadsNoFirm(): void
    {
        $this->get('/api/v1/firm', secret: $this->issued([ApiScope::from('events:read')]));

        $this->assertProblem(403, 'insufficient_scope');

        // Nor the scope of the address book, when it is installed: the firm has a scope of its own.
        if (class_exists(ContactsBundle::class)) {
            $this->get('/api/v1/firm', secret: $this->issued([ApiScope::from('contacts:write')]));

            $this->assertProblem(403, 'insufficient_scope');
        }

        $this->get('/api/v1/firm', secret: $this->issued([ApiScope::from('firm:read')]));

        self::assertResponseIsSuccessful();
    }

    public function testAnInstanceWithoutAFirmAnswersNotFound(): void
    {
        $this->flushed(function (): void {
            $firm = $this->em()->find(LegalEntity::class, $this->firmId);
            self::assertNotNull($firm);
            $this->em()->remove($firm);
        });

        $this->get('/api/v1/firm');

        $this->assertProblem(404, 'not_found');
    }

    public function testWithoutATokenTheFirmIsNotRead(): void
    {
        $this->client->request('GET', '/api/v1/firm');

        self::assertResponseStatusCodeSame(401);
    }
}
