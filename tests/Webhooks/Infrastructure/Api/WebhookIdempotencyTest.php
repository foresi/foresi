<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Webhooks\Infrastructure\Api;

use Doctrine\DBAL\Types\Types;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Idempotency\IdempotencyKeyTable;
use Foresi\Identity\Infrastructure\Idempotency\PruneIdempotencyKeysTask;
use Symfony\Component\Clock\NativeClock;

/**
 * The Idempotency-Key of the REST writes (ADR 0032), on the webhook endpoints,
 * which are written by hand: the shared listener serves them as it serves the
 * declared operations.
 */
final class WebhookIdempotencyTest extends WebhookApiTestCase
{
    private const array BODY = ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['party.*']];

    public function testARetriedCreateRegistersOneEndpointAndAnswersTheSame(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderNotSame('Idempotent-Replayed', 'true');
        $first = (string) $this->client->getResponse()->getContent();
        $location = $this->client->getResponse()->headers->get('Location');

        $this->send('POST', self::ENDPOINTS, ['event_types' => ['party.*'], 'url' => 'https://hooks.example.com/foresi'], idempotencyKey: 'create-1');

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertResponseHeaderSame('Location', (string) $location);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame($first, $this->client->getResponse()->getContent());
        self::assertCount(1, $this->endpoints());
    }

    public function testTheSameKeyWithAnotherBodyIs422(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');

        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/other'] + self::BODY, idempotencyKey: 'create-1');

        $this->assertProblem(422, 'idempotency_key_reused');
        self::assertCount(1, $this->endpoints());
    }

    public function testAKeyStillInFlightIs409(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');
        // As if the first request were still running.
        $this->em()->getConnection()->update(IdempotencyKeyTable::TABLE, [IdempotencyKeyTable::STATUS => IdempotencyKeyTable::STATUS_IN_FLIGHT], [IdempotencyKeyTable::IDEMPOTENCY_KEY => 'create-1']);

        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');

        $this->assertProblem(409, 'idempotency_key_in_flight');
        self::assertCount(1, $this->endpoints());
    }

    public function testAClaimLeftByARequestThatDiedIsTakenOverAfterAMinute(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');
        $this->em()->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET %s = :status, %s = :at', IdempotencyKeyTable::TABLE, IdempotencyKeyTable::STATUS, IdempotencyKeyTable::CREATED_AT),
            ['status' => IdempotencyKeyTable::STATUS_IN_FLIGHT, 'at' => new \DateTimeImmutable('-2 minutes')],
            ['at' => Types::DATETIME_IMMUTABLE],
        );

        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderNotSame('Idempotent-Replayed', 'true');
    }

    public function testKeysOlderThanADayArePrunedAndNoLongerCount(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'old');
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'new');
        $connection = $this->em()->getConnection();
        $connection->update(IdempotencyKeyTable::TABLE, [IdempotencyKeyTable::CREATED_AT => new \DateTimeImmutable('-25 hours')], [IdempotencyKeyTable::IDEMPOTENCY_KEY => 'old'], [IdempotencyKeyTable::CREATED_AT => Types::DATETIME_IMMUTABLE]);

        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'old');
        self::assertResponseHeaderNotSame('Idempotent-Replayed', 'true', 'A key of yesterday is a new key.');
        $connection->update(IdempotencyKeyTable::TABLE, [IdempotencyKeyTable::CREATED_AT => new \DateTimeImmutable('-25 hours')], [IdempotencyKeyTable::IDEMPOTENCY_KEY => 'old'], [IdempotencyKeyTable::CREATED_AT => Types::DATETIME_IMMUTABLE]);
        new PruneIdempotencyKeysTask($connection, new NativeClock())->run();

        self::assertSame(['new'], $connection->fetchFirstColumn('SELECT '.IdempotencyKeyTable::IDEMPOTENCY_KEY.' FROM '.IdempotencyKeyTable::TABLE));
    }

    public function testARefusedInputLetsTheKeyGoSoTheFixedRequestCanUseIt(): void
    {
        $this->send('POST', self::ENDPOINTS, ['url' => 'http://hooks.example.com/foresi', 'event_types' => ['party.*']], idempotencyKey: 'create-1');
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'url']);

        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->endpoints());
    }

    public function testAMissingScopeIsNotKept(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, $this->issued([ApiScope::from('contacts:write')]), idempotencyKey: 'create-1');
        $this->assertProblem(403, 'insufficient_scope');

        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn('SELECT '.IdempotencyKeyTable::IDEMPOTENCY_KEY.' FROM '.IdempotencyKeyTable::TABLE));
    }

    public function testAKeyBelongsToItsTokenAndAnUnusableKeyIsRefused(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: 'create-1');
        $this->send('POST', self::ENDPOINTS, self::BODY, $this->issued([ApiScope::from('webhooks:write')]), idempotencyKey: 'create-1');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderNotSame('Idempotent-Replayed', 'true');
        self::assertCount(2, $this->endpoints());

        $this->send('POST', self::ENDPOINTS, self::BODY, idempotencyKey: str_repeat('k', 256));
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'Idempotency-Key']);
    }

    /**
     * @return list<string>
     */
    private function endpoints(): array
    {
        return array_values(array_filter($this->em()->getConnection()->fetchFirstColumn('SELECT id FROM webhook_endpoint'), \is_string(...)));
    }

    public function testAWriteWithoutAKeyIsNotKept(): void
    {
        $this->send('POST', self::ENDPOINTS, self::BODY);
        $this->send('POST', self::ENDPOINTS, self::BODY);

        self::assertCount(2, $this->endpoints());
    }
}
