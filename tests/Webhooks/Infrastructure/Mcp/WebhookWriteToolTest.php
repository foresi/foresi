<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Mcp;

use App\Shared\Application\SealerUnavailable;
use App\Shared\Domain\Id;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use App\Webhooks\Application\WebhookSecrets;
use App\Webhooks\Domain\WebhookSecret;
use App\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use App\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use App\Webhooks\Infrastructure\Mcp\CreateWebhookEndpointTool;
use App\Webhooks\Infrastructure\Mcp\DeleteWebhookEndpointTool;
use App\Webhooks\Infrastructure\Mcp\RedeliverWebhookTool;
use App\Webhooks\Infrastructure\Mcp\RotateWebhookSecretTool;
use App\Webhooks\Infrastructure\Mcp\UpdateWebhookEndpointTool;

/**
 * The webhook write tools do what the REST routes do, through the same use
 * cases, and refuse what they refuse in the words of a tool.
 */
final class WebhookWriteToolTest extends WebhookToolTestCase
{
    public function testCreateReturnsSecret(): void
    {
        $output = $this->call(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.invoice.paid'], 'description' => 'Accounting sync']);

        $secret = $output->data['secret'];
        self::assertIsString($secret);
        self::assertStringStartsWith('whsec_', $secret);
        self::assertSame(32, \strlen(WebhookSecret::fromString($secret)->key()));
        $id = $output->data['id'];
        self::assertIsString($id);
        $endpoint = $this->endpoint($id);
        self::assertNotNull($endpoint);
        self::assertSame($this->user->id(), $endpoint->ownerId());
        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame(AttemptTrigger::Ping, $message->trigger);
    }

    public function testOutputsMatchRest(): void
    {
        $output = $this->call(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*'], 'version' => 1]);

        $id = $output->data['id'];
        self::assertIsString($id);
        $this->get(self::ENDPOINTS.'/'.$id, secret: $this->writer);
        $shown = $this->json()['data'];
        self::assertIsArray($shown);
        $created = $output->data;
        unset($created['secret']);
        self::assertSame($shown, $created);

        // The same body over REST has the same shape, with an id and a secret of its own.
        $this->send('POST', self::ENDPOINTS, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*'], 'version' => 1]);
        $rest = $this->json()['data'];
        self::assertIsArray($rest);
        self::assertSame(array_keys($rest), array_keys($output->data));
        foreach (['url', 'description', 'event_types', 'version', 'active', 'disabled_reason', 'owner_id'] as $field) {
            self::assertSame($rest[$field], $output->data[$field]);
        }

        // An update through the tool leaves what PATCH leaves.
        $updated = $this->call(UpdateWebhookEndpointTool::class, ['endpoint_id' => $id, 'description' => 'Changed', 'active' => false]);
        $this->send('PATCH', self::ENDPOINTS.'/'.$id, ['description' => 'Changed again']);
        $patched = $this->json()['data'];
        self::assertIsArray($patched);
        self::assertSame(array_keys($patched), array_keys($updated->data));
        self::assertFalse($updated->data['active']);
        self::assertSame('manual', $updated->data['disabled_reason']);
    }

    public function testUnreadableModuleIsForbidden(): void
    {
        $refusal = $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']], $this->guest());

        self::assertSame('forbidden', $refusal->errorCode);
        self::assertSame([], $this->queued());
    }

    public function testBadValuesAreInvalidArgumentsThatNameTheArgument(): void
    {
        $refusal = $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'http://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);
        self::assertSame('invalid_argument', $refusal->errorCode);
        self::assertStringContainsString('"url"', $refusal->getMessage());

        $refusal = $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'https://internal.example.com/foresi', 'event_types' => ['invoicing.*']]);
        self::assertSame('invalid_argument', $refusal->errorCode);
        self::assertStringContainsString('"url"', $refusal->getMessage());

        $refusal = $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['*']]);
        self::assertSame('invalid_argument', $refusal->errorCode);
        self::assertStringContainsString('"event_types"', $refusal->getMessage());

        self::assertSame('invalid_argument', $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => 'invoicing.*'])->errorCode);
        self::assertSame('invalid_argument', $this->refusal(CreateWebhookEndpointTool::class, ['event_types' => ['invoicing.*']])->errorCode);
    }

    public function testUpdateChangesOnlyWhatIsGivenAndEnablesAgain(): void
    {
        $id = $this->created(['invoicing.invoice.paid']);
        $this->call(UpdateWebhookEndpointTool::class, ['endpoint_id' => $id, 'description' => 'Sync']);

        $disabled = $this->call(UpdateWebhookEndpointTool::class, ['endpoint_id' => $id, 'active' => false]);
        self::assertFalse($disabled->data['active']);
        self::assertSame('manual', $disabled->data['disabled_reason']);
        self::assertSame('Sync', $disabled->data['description']);
        self::assertSame(['invoicing.invoice.paid'], $disabled->data['event_types']);

        $enabled = $this->call(UpdateWebhookEndpointTool::class, ['endpoint_id' => $id, 'active' => true, 'event_types' => ['invoicing.*'], 'version' => 1, 'url' => 'https://hooks.example.com/other']);
        self::assertTrue($enabled->data['active']);
        self::assertNull($enabled->data['disabled_reason']);
        self::assertSame(['invoicing.*'], $enabled->data['event_types']);
        self::assertSame('https://hooks.example.com/other', $enabled->data['url']);

        self::assertNull($this->call(UpdateWebhookEndpointTool::class, ['endpoint_id' => $id, 'description' => null])->data['description']);
    }

    public function testUpdateOfAnUnknownEndpointIsNotFoundAndOfAnotherOrganisationToo(): void
    {
        self::assertSame('not_found', $this->refusal(UpdateWebhookEndpointTool::class, ['endpoint_id' => Id::generate(), 'active' => false])->errorCode);

        $foreign = $this->stored($this->fixtures->tenant('Other Organisation'));
        self::assertSame('not_found', $this->refusal(UpdateWebhookEndpointTool::class, ['endpoint_id' => $foreign, 'active' => false])->errorCode);
    }

    public function testDeleteRemovesTheEndpointWithItsDeliveries(): void
    {
        $id = $this->stored($this->tenant);
        $delivery = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, attempts: 3, nextAttemptAt: null);

        $output = $this->call(DeleteWebhookEndpointTool::class, ['endpoint_id' => $id]);

        self::assertSame(['id' => $id, 'deleted' => true], $output->data);
        self::assertNull($this->endpoint($id));
        self::assertSame([], $this->rows->attempts($delivery));
        self::assertSame('not_found', $this->refusal(DeleteWebhookEndpointTool::class, ['endpoint_id' => $id])->errorCode);
    }

    public function testDeleteIsAnnotatedDestructive(): void
    {
        $destructive = [];
        foreach ([CreateWebhookEndpointTool::class, UpdateWebhookEndpointTool::class, DeleteWebhookEndpointTool::class, RotateWebhookSecretTool::class, RedeliverWebhookTool::class] as $class) {
            $tool = $this->tool($class);
            if ($tool->isDestructive()) {
                $destructive[] = $tool->name();
            }
        }

        self::assertSame(['foresi_delete_webhook_endpoint', 'foresi_rotate_webhook_secret'], $destructive);
    }

    public function testRotateKeepsThePreviousSecretForTheGivenHours(): void
    {
        $id = $this->created();

        $output = $this->call(RotateWebhookSecretTool::class, ['endpoint_id' => $id, 'keep_previous_hours' => 2]);

        self::assertSame(['secret', 'previous_secret_expires_at'], array_keys($output->data));
        self::assertIsString($output->data['secret']);
        self::assertStringStartsWith('whsec_', $output->data['secret']);
        self::assertIsString($output->data['previous_secret_expires_at']);
        $this->get(self::ENDPOINTS.'/'.$id, secret: $this->writer);
        self::assertSame($output->data['previous_secret_expires_at'], $this->at('data', 'previous_secret_expires_at'));

        $again = $this->call(RotateWebhookSecretTool::class, ['endpoint_id' => $id, 'keep_previous_hours' => 0]);
        self::assertNotSame($output->data['secret'], $again->data['secret']);
    }

    public function testRotateRefusesHoursOutOfRange(): void
    {
        $id = $this->created();

        $refusal = $this->refusal(RotateWebhookSecretTool::class, ['endpoint_id' => $id, 'keep_previous_hours' => 169]);

        self::assertSame('invalid_argument', $refusal->errorCode);
        self::assertStringContainsString('"keep_previous_hours"', $refusal->getMessage());
    }

    public function testRedeliverQueuesOneManualAttempt(): void
    {
        $id = $this->stored($this->tenant);
        $delivery = $this->rows->insert($this->tenant->toString(), $id, T::STATUS_FAILED, attempts: 10, nextAttemptAt: null);

        $output = $this->call(RedeliverWebhookTool::class, ['endpoint_id' => $id, 'delivery_id' => $delivery]);

        $queued = $this->queued();
        self::assertCount(1, $queued);
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(SendWebhook::class, $message);
        self::assertSame(AttemptTrigger::Manual, $message->trigger);
        self::assertSame(['delivery_id' => $delivery, 'attempt_id' => $message->attemptId], $output->data);
    }

    public function testRedeliverConflictsAndNotFounds(): void
    {
        $disabled = $this->stored($this->tenant, active: false);
        $delivery = $this->rows->insert($this->tenant->toString(), $disabled, T::STATUS_FAILED, attempts: 10, nextAttemptAt: null);
        self::assertSame('conflict', $this->refusal(RedeliverWebhookTool::class, ['endpoint_id' => $disabled, 'delivery_id' => $delivery])->errorCode);

        $active = $this->stored($this->tenant);
        $pending = $this->rows->insert($this->tenant->toString(), $active);
        self::assertSame('conflict', $this->refusal(RedeliverWebhookTool::class, ['endpoint_id' => $active, 'delivery_id' => $pending])->errorCode);
        self::assertSame('not_found', $this->refusal(RedeliverWebhookTool::class, ['endpoint_id' => $active, 'delivery_id' => Id::generate()])->errorCode);
        self::assertSame([], $this->queued());
    }

    public function testAnInstallationWithoutAKeyIsUnavailable(): void
    {
        self::getContainer()->set(SodiumWebhookSecrets::class, new class implements WebhookSecrets {
            public function seal(WebhookSecret $secret): string
            {
                throw new SealerUnavailable('No key.');
            }

            public function open(string $sealed): WebhookSecret
            {
                throw new SealerUnavailable('No key.');
            }

            public function isConfigured(): bool
            {
                return false;
            }
        });

        $refusal = $this->refusal(CreateWebhookEndpointTool::class, ['url' => 'https://hooks.example.com/foresi', 'event_types' => ['invoicing.*']]);

        self::assertSame('unavailable', $refusal->errorCode);
        self::assertStringContainsString('WEBHOOK_SECRET_KEYS', $refusal->getMessage());
    }
}
