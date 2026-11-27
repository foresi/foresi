<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;

/**
 * With the address book, a project takes a client over the API and drops it again.
 */
final class ProjectClientApiTest extends WorkApiTestCase
{
    public function testAProjectTakesAClientAndDropsIt(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Launch', 'workflow' => 'development', 'client_id' => $this->customerId]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame($this->customerId, $this->at('data', 'client', 'id'));

        $this->send('PATCH', '/api/v1/projects/'.$id, ['client_id' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->at('data', 'client'));
    }
}
