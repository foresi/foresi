<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Mcp\ListCurrenciesTool;

/**
 * The currencies over REST and MCP (ADR 0049): every current ISO 4217 code with
 * its minor units, the withdrawn ones on request.
 */
final class CurrencyApiTest extends ApiTestCase
{
    public function testListsTheCurrentCurrenciesWithTheirMinorUnits(): void
    {
        $this->get('/api/v1/currencies');

        self::assertResponseIsSuccessful();
        $byCode = $this->byCode();
        self::assertSame(['code' => 'BHD', 'name' => 'Bahraini Dinar', 'minor_units' => 3, 'numeric_code' => 48, 'historic' => false], $byCode['BHD']);
        self::assertSame(0, $byCode['JPY']['minor_units']);
        self::assertSame(2, $byCode['CZK']['minor_units']);
        self::assertArrayNotHasKey('HRK', $byCode);
    }

    public function testListsTheWithdrawnOnesOnRequest(): void
    {
        $this->get('/api/v1/currencies?historic=true');

        self::assertTrue($this->byCode()['HRK']['historic']);

        $this->get('/api/v1/currencies?historic=maybe');
        $this->assertProblem(400, 'invalid_parameter');
    }

    public function testTheMcpToolGivesTheSameList(): void
    {
        $tool = new ListCurrenciesTool();

        self::assertSame('foresi_list_currencies', $tool->name());
        $output = $tool->call([]);
        self::assertIsArray($output->data['currencies']);
        self::assertContains(['code' => 'JPY', 'name' => 'Yen', 'minor_units' => 0, 'numeric_code' => 392, 'historic' => false], $output->data['currencies']);
        $historic = $tool->call(['historic' => true])->data['currencies'];
        self::assertIsArray($historic);
        self::assertContains('HRK', array_column($historic, 'code'));
    }

    /**
     * The rows of the last response by their code.
     *
     * @return array<string, array<mixed>>
     */
    private function byCode(): array
    {
        $byCode = [];
        $rows = $this->at('data');
        self::assertIsArray($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['code']);
            $byCode[$row['code']] = $row;
        }

        return $byCode;
    }
}
