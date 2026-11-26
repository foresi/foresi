<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Infrastructure\Api;

use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;

/**
 * A project created over the API without timesheet_billable_by_default bills
 * its time by default when it has a client; without a client the field stays
 * unset, which bills nothing by default. A value sent with it, false
 * included, is kept.
 */
final class ProjectBillableByDefaultApiTest extends WorkApiTestCase
{
    private const string FIELD = 'timesheet_billable_by_default';

    public function testAProjectWithAClientBillsByDefault(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Launch', 'client_id' => $this->customerId]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->billable());
    }

    public function testAProjectWithoutAClientDoesNotBillByDefault(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Internal']);

        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->billable());
    }

    public function testAnExplicitFalseStaysFalse(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Pro bono', 'client_id' => $this->customerId, 'custom_fields' => [self::FIELD => false]]);

        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->billable());
    }

    public function testAnExplicitTrueWithoutAClientStaysTrue(): void
    {
        $this->send('POST', '/api/v1/projects', ['name' => 'Retainer', 'custom_fields' => [self::FIELD => true]]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->billable());
    }

    /**
     * The field as the project reads back after the create.
     */
    private function billable(): mixed
    {
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $this->get('/api/v1/projects/'.$id);
        self::assertResponseIsSuccessful();

        return $this->at('data', 'custom_fields', self::FIELD);
    }
}
