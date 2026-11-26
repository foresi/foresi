<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Module\InstalledModules;

/**
 * What every endpoint of the REST API shares: problem documents for unknown
 * routes and wrong methods, the rate limit of a token and its headers, and the
 * OpenAPI document.
 */
final class ApiPlumbingTest extends ApiTestCase
{
    public function testAnUnknownRouteIsNotFound(): void
    {
        $this->get('/api/v1/nothing');
        $this->assertProblem(404, 'not_found', ['title' => 'Not Found']);

        $this->client->request('GET', '/api/v2/invoices');
        $this->assertProblem(404, 'not_found');
    }

    public function testAWrongMethodIsNotAllowed(): void
    {
        $this->client->request('POST', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret]);

        $this->assertProblem(405, 'method_not_allowed', ['title' => 'Method Not Allowed']);
        self::assertResponseHeaderSame('Allow', 'GET, PATCH');
    }

    public function testEveryAnswerToATokenSaysItsBudget(): void
    {
        $this->get('/api/v1/me');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('RateLimit-Limit', '600');
        self::assertResponseHeaderSame('RateLimit-Remaining', '599');
        self::assertResponseHeaderSame('RateLimit-Reset', '0');

        $this->get('/api/v1/contacts/nothing');

        $this->assertProblem(404, 'not_found');
        self::assertResponseHeaderSame('RateLimit-Remaining', '598');
    }

    public function testEveryTokenHasABudgetOfItsOwn(): void
    {
        $this->get('/api/v1/me');
        $this->get('/api/v1/me', secret: $this->issued());

        self::assertResponseHeaderSame('RateLimit-Remaining', '599');
    }

    public function testAnAnswerWithoutATokenHasNoBudget(): void
    {
        $this->client->request('GET', '/api/v1/me');

        $this->assertProblem(401, 'missing_token');
        self::assertResponseNotHasHeader('RateLimit-Limit');
    }

    public function testOverTheBudgetTheAnswerIsTooManyRequests(): void
    {
        $this->get('/api/v1/me');
        $tokenId = $this->at('token', 'id');
        self::assertIsString($tokenId);
        $limiter = self::getContainer()->get('test.api_token_limiter');
        self::assertTrue($limiter->create($tokenId)->consume(599)->isAccepted());

        $this->get('/api/v1/me');

        $this->assertProblem(429, 'rate_limited', ['title' => 'Too Many Requests']);
        self::assertResponseHeaderSame('RateLimit-Limit', '600');
        self::assertResponseHeaderSame('RateLimit-Remaining', '0');
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(601, $retryAfter);
        self::assertSame((string) $retryAfter, $this->client->getResponse()->headers->get('Retry-After'));
        $reset = (int) $this->client->getResponse()->headers->get('RateLimit-Reset');
        self::assertGreaterThanOrEqual(1, $reset);
        self::assertLessThanOrEqual(601, $reset);
    }

    public function testTheOpenApiDocumentDescribesEveryEndpointAndTheBearerToken(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('Foresi API', $this->at('info', 'title'));
        self::assertSame('1', $this->at('info', 'version'));
        $paths = $this->at('paths');
        self::assertIsArray($paths);
        // Each path with its operations and the status each answers when it succeeds, by the module that serves it.
        $byModule = [
            'activities' => [
                '/api/v1/activities' => ['get' => '200', 'post' => '201'],
                '/api/v1/activities/planned' => ['get' => '200'],
                '/api/v1/activities/{activityId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/activities/{activityId}/complete' => ['post' => '200'],
                '/api/v1/activities/{activityId}/trash' => ['post' => '200'],
                '/api/v1/activities/{activityId}/restore' => ['post' => '200'],
                '/api/v1/activity-types' => ['get' => '200', 'post' => '201'],
                '/api/v1/activity-types/{typeId}' => ['patch' => '200'],
                '/api/v1/activity-types/{typeId}/archive' => ['post' => '200'],
            ],
            'catalog' => [
                '/api/v1/catalog/products' => ['get' => '200', 'post' => '201'],
                '/api/v1/catalog/products/{productId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/catalog/products/{productId}/archive' => ['post' => '200'],
                '/api/v1/catalog/products/{productId}/restore' => ['post' => '200'],
            ],
            'meetings' => [
                '/api/v1/meetings/series' => ['get' => '200', 'post' => '201'],
                '/api/v1/meetings/series/{seriesId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/meetings/series/{seriesId}/archive' => ['post' => '200'],
                '/api/v1/meetings' => ['get' => '200', 'post' => '201'],
                '/api/v1/meetings/{meetingId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/meetings/{meetingId}/minutes' => ['put' => '200'],
                '/api/v1/meetings/{meetingId}/trash' => ['post' => '200'],
                '/api/v1/meetings/{meetingId}/restore' => ['post' => '200'],
            ],
            'calendar' => [
                '/api/v1/calendar/feeds' => ['get' => '200', 'post' => '201'],
                '/api/v1/calendar/feeds/{feedId}' => ['patch' => '200'],
                '/api/v1/calendar/feeds/{feedId}/revoke' => ['post' => '200'],
            ],
            'cash-flow' => [
                '/api/v1/cash-flow/forecast' => ['get' => '200'],
                '/api/v1/cash-flow/planned-items' => ['get' => '200', 'post' => '201'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}' => ['get' => '200', 'delete' => '200', 'patch' => '200'],
                '/api/v1/cash-flow/checkpoints' => ['post' => '201'],
                '/api/v1/cash-flow/expected-dates/{subjectType}/{subjectId}' => ['delete' => '200'],
                '/api/v1/cash-flow/expected-dates' => ['post' => '200'],
                '/api/v1/cash-flow/pairings' => ['get' => '200', 'post' => '200'],
                '/api/v1/cash-flow/pairings/{subjectType}/{subjectId}' => ['delete' => '200'],
                '/api/v1/cash-flow/minimum-balance' => ['post' => '200'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}/end' => ['post' => '200'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}/skip' => ['post' => '200'],
            ],
            'documents' => [
                '/api/v1/document-types' => ['get' => '200', 'post' => '201'],
                '/api/v1/document-types/{typeId}' => ['patch' => '200'],
                '/api/v1/document-types/{typeId}/archive' => ['post' => '200'],
                '/api/v1/document-templates' => ['get' => '200', 'post' => '201'],
                '/api/v1/document-templates/{templateId}' => ['get' => '200'],
                '/api/v1/document-templates/{templateId}/versions' => ['post' => '200'],
                '/api/v1/document-templates/{templateId}/archive' => ['post' => '200'],
                '/api/v1/documents' => ['get' => '200', 'post' => '201'],
                '/api/v1/documents/{documentId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/documents/{documentId}/files/{file}' => ['get' => '200'],
                '/api/v1/documents/{documentId}/generate' => ['post' => '200'],
                '/api/v1/documents/{documentId}/mark-sent' => ['post' => '200'],
                '/api/v1/documents/{documentId}/signed-copy' => ['post' => '200'],
                '/api/v1/documents/{documentId}/cancel' => ['post' => '200'],
                '/api/v1/documents/{documentId}/trash' => ['post' => '200'],
                '/api/v1/documents/{documentId}/restore' => ['post' => '200'],
                '/api/v1/documents/{documentId}/generate-again' => ['post' => '201'],
                '/api/v1/documents/{documentId}/signers' => ['post' => '201'],
                '/api/v1/documents/{documentId}/signers/{signerId}' => ['delete' => '200', 'patch' => '200'],
                '/api/v1/documents/{documentId}/signers/{signerId}/mark-signed' => ['post' => '200'],
                '/api/v1/documents/{documentId}/signers/{signerId}/mark-declined' => ['post' => '200'],
            ],
            'events' => [
                '/api/v1/events' => ['get' => '200'],
            ],
            'exchange-rate' => [
                '/api/v1/exchange-rate/source' => ['get' => '200', 'put' => '200'],
            ],
            'identity' => [
                '/api/v1/me' => ['get' => '200', 'patch' => '200'],
            ],
            'inbox' => [
                '/api/v1/inbox/items' => ['get' => '200'],
                '/api/v1/inbox/items/{itemId}' => ['get' => '200'],
                '/api/v1/inbox/items/{itemId}/contact' => ['put' => '200', 'post' => '201'],
                '/api/v1/inbox/items/{itemId}/match-again' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/done' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/discard' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/reopen' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/outputs' => ['post' => '201'],
                '/api/v1/inbox/items/{itemId}/trash' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/restore' => ['post' => '200'],
                '/api/v1/inbox/items/{itemId}/suggestions' => ['post' => '201'],
                '/api/v1/inbox/suggestions/accept' => ['post' => '200'],
                '/api/v1/inbox/suggestions/{suggestionId}/reject' => ['post' => '200'],
                // The inbox actions of the module only the tests have (tests/Inbox/Fixtures/Action).
                '/api/v1/inbox/items/{inboxItemId}/fixture-notes' => ['post' => '201'],
                '/api/v1/inbox/items/{inboxItemId}/fixture-contact-notes' => ['post' => '201'],
                // The inbox actions of Sales and Activities.
                '/api/v1/inbox/items/{inboxItemId}/deals' => ['post' => '201'],
                '/api/v1/inbox/items/{inboxItemId}/activities' => ['post' => '201'],
                // The inbox actions of Work.
                '/api/v1/inbox/items/{inboxItemId}/tasks' => ['post' => '201'],
                '/api/v1/inbox/items/{inboxItemId}/time-entries' => ['post' => '201'],
                '/api/v1/inbox/items/{inboxItemId}/budget-changes' => ['post' => '201'],
            ],
            'invoicing' => [
                '/api/v1/invoices' => ['get' => '200', 'post' => '201'],
                '/api/v1/invoices/{invoiceId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/invoices/{invoiceId}/trash' => ['post' => '200'],
                '/api/v1/invoices/{invoiceId}/restore' => ['post' => '200'],
                '/api/v1/invoices/{invoiceId}/mark-sent' => ['post' => '200'],
                '/api/v1/payments' => ['get' => '200'],
                '/api/v1/payments/{id}' => ['get' => '200'],
                '/api/v1/recurring-invoices' => ['get' => '200', 'post' => '201'],
                '/api/v1/recurring-invoices/preview' => ['post' => '200'],
                '/api/v1/recurring-invoices/{recurringInvoiceId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/recurring-invoices/{recurringInvoiceId}/pause' => ['post' => '200'],
                '/api/v1/recurring-invoices/{recurringInvoiceId}/resume' => ['post' => '200'],
                '/api/v1/recurring-invoices/{recurringInvoiceId}/end' => ['post' => '200'],
            ],
            'mail' => [
                '/api/v1/mail/addresses' => ['get' => '200', 'post' => '201'],
                '/api/v1/mail/addresses/{addressId}' => ['delete' => '200', 'patch' => '200'],
                '/api/v1/mail/addresses/{addressId}/replace' => ['post' => '201'],
            ],
            'margin' => [
                '/api/v1/margin' => ['get' => '200'],
                '/api/v1/margin/cell' => ['get' => '200'],
                '/api/v1/margin/unrebilled' => ['get' => '200'],
                '/api/v1/margin/recurring-lines/{recurringLineId}/rebills' => ['put' => '200'],
                '/api/v1/margin/rebills' => ['post' => '201'],
                '/api/v1/margin/rebills/{invoiceLineId}' => ['delete' => '200'],
                '/api/v1/margin/documents/{documentId}' => ['get' => '200'],
                '/api/v1/margin/documents/{documentId}/assign' => ['post' => '200'],
                '/api/v1/margin/documents/{documentId}/lines/{lineKey}/allocation' => ['put' => '200'],
                '/api/v1/margin/planned-items/{plannedItemId}/allocation' => ['get' => '200', 'put' => '200'],
                '/api/v1/margin/suppliers/{contactId}/default-recipient' => ['get' => '200', 'put' => '200'],
            ],
            'party' => [
                '/api/v1/firm' => ['get' => '200'],
                '/api/v1/firm/vat-statuses' => ['post' => '201'],
                '/api/v1/firm/vat-statuses/{vatStatusId}' => ['delete' => '200'],
                '/api/v1/contacts' => ['get' => '200', 'post' => '201'],
                '/api/v1/contacts/{contactId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/contacts/{contactId}/archive' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/contact-persons' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/restore' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/contact-persons/{personId}' => ['delete' => '200'],
            ],
            'purchasing' => [
                '/api/v1/received-documents' => ['get' => '200', 'post' => '201'],
                '/api/v1/received-documents/{documentId}' => ['get' => '200'],
                '/api/v1/received-documents/{documentId}/payments' => ['get' => '200', 'post' => '201'],
                '/api/v1/received-documents/{documentId}/mark-paid' => ['post' => '200'],
                '/api/v1/received-documents/{documentId}/payments/{paymentId}' => ['delete' => '200'],
                '/api/v1/received-documents/{documentId}/trash' => ['post' => '200'],
                '/api/v1/received-documents/{documentId}/lines' => ['put' => '200'],
                '/api/v1/received-documents/{documentId}/read-again' => ['post' => '202'],
                '/api/v1/received-documents/upload' => ['post' => '201'],
            ],
            'sales' => [
                '/api/v1/sales/deals' => ['get' => '200', 'post' => '201'],
                '/api/v1/sales/deals/{dealId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/sales/deals/{dealId}/billing-plan' => ['post' => '201'],
                '/api/v1/sales/deals/{dealId}/billing-plan/{entryId}' => ['delete' => '200', 'patch' => '200'],
                '/api/v1/sales/deals/{dealId}/billing-plan/{entryId}/fulfilment' => ['put' => '200', 'delete' => '200'],
                '/api/v1/sales/deals/{dealId}/follow-ups' => ['post' => '201'],
                '/api/v1/sales/deals/{dealId}/lines' => ['post' => '201'],
                '/api/v1/sales/deals/{dealId}/lines/reorder' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/lines/{lineId}' => ['delete' => '200', 'patch' => '200'],
                '/api/v1/sales/deals/{dealId}/links' => ['post' => '201'],
                '/api/v1/sales/deals/{dealId}/links/{linkId}' => ['delete' => '200'],
                '/api/v1/sales/deals/{dealId}/lose' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/reopen' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/restore' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/stage' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/trash' => ['post' => '200'],
                '/api/v1/sales/deals/{dealId}/win' => ['post' => '200'],
                '/api/v1/sales/lost-reasons' => ['get' => '200', 'post' => '201'],
                '/api/v1/sales/lost-reasons/{reasonId}' => ['patch' => '200'],
                '/api/v1/sales/lost-reasons/{reasonId}/archive' => ['post' => '200'],
                '/api/v1/sales/stages' => ['get' => '200', 'post' => '201'],
                '/api/v1/sales/stages/reorder' => ['post' => '200'],
                '/api/v1/sales/stages/{stageId}' => ['patch' => '200'],
                '/api/v1/sales/stages/{stageId}/archive' => ['post' => '200'],
            ],
            'taxes-and-contributions' => [
                '/api/v1/taxes-and-contributions/amounts' => ['get' => '200'],
                '/api/v1/taxes-and-contributions/estimate' => ['get' => '200'],
                '/api/v1/taxes-and-contributions/profile' => ['get' => '200'],
                '/api/v1/taxes-and-contributions/profile/statuses' => ['post' => '201'],
                '/api/v1/taxes-and-contributions/profile/statuses/{statusId}' => ['delete' => '200'],
                '/api/v1/taxes-and-contributions/profile/years/{year}' => ['put' => '200'],
                '/api/v1/taxes-and-contributions/profile/authorities/{authority}' => ['put' => '200'],
            ],
            'vat' => [
                '/api/v1/vat/periods' => ['get' => '200'],
                '/api/v1/vat/periods/{key}' => ['get' => '200'],
                '/api/v1/vat/periods/{key}/return-amount' => ['put' => '200', 'delete' => '200'],
                '/api/v1/vat/periods/{key}/settle' => ['post' => '200', 'delete' => '200'],
                '/api/v1/vat/payments/{movementId}' => ['put' => '200', 'delete' => '200'],
                '/api/v1/vat/settings' => ['get' => '200', 'put' => '200'],
            ],
            'shared' => [
                '/api/v1/fields' => ['get' => '200'],
                '/api/v1/lists' => ['get' => '200'],
                '/api/v1/views' => ['get' => '200', 'post' => '201'],
                '/api/v1/views/{viewId}' => ['get' => '200', 'delete' => '200', 'patch' => '200'],
                '/api/v1/views/{viewId}/count' => ['get' => '200'],
                '/api/v1/views/restore-defaults' => ['post' => '200'],
            ],
            'work' => [
                '/api/v1/projects' => ['get' => '200', 'post' => '201'],
                '/api/v1/projects/{projectId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/projects/{projectId}/people' => ['get' => '200'],
                '/api/v1/projects/{projectId}/archive' => ['post' => '200'],
                '/api/v1/projects/{projectId}/restore' => ['post' => '200'],
                '/api/v1/projects/{projectId}/budget' => ['get' => '200'],
                '/api/v1/projects/{projectId}/budget-changes' => ['post' => '201'],
                '/api/v1/projects/{projectId}/budget-changes/{changeId}' => ['delete' => '200'],
                '/api/v1/projects/{projectId}/budget-counting' => ['put' => '200'],
                '/api/v1/tasks' => ['get' => '200', 'post' => '201'],
                '/api/v1/tasks/{taskId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/tasks/{taskId}/comments' => ['get' => '200', 'post' => '201'],
                '/api/v1/tasks/{taskId}/trash' => ['post' => '200'],
                '/api/v1/tasks/{taskId}/restore' => ['post' => '200'],
                '/api/v1/tasks/{taskId}/star' => ['put' => '200', 'delete' => '200'],
                '/api/v1/tasks/starred/reorder' => ['post' => '200'],
                '/api/v1/task-comments/{commentId}' => ['patch' => '200'],
                '/api/v1/task-comments/{commentId}/trash' => ['post' => '200'],
                '/api/v1/labels' => ['get' => '200'],
                '/api/v1/time-entries' => ['get' => '200', 'post' => '201'],
                '/api/v1/time-entries/timer' => ['post' => '201'],
                '/api/v1/time-entries/{entryId}/stop' => ['post' => '200'],
            ],
            'webhooks' => [
                '/api/v1/webhook-endpoints/{id}/deliveries' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver' => ['post' => '202'],
                '/api/v1/webhook-endpoints' => ['get' => '200', 'post' => '201'],
                '/api/v1/webhook-endpoints/{id}' => ['get' => '200', 'delete' => '204', 'patch' => '200'],
                '/api/v1/webhook-endpoints/{id}/secret/rotate' => ['post' => '200'],
                '/api/v1/webhook-endpoints/{id}/secret' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/ping' => ['post' => '202'],
            ],
        ];
        $operations = [];
        foreach (new InstalledModules(\dirname(__DIR__, 4))->graph()->all() as $module) {
            $operations += $byModule[$module->id->value] ?? [];
        }
        // The document follows the order of the routes, which follows the order the modules load in.
        $expected = array_keys($operations);
        $actual = array_keys($paths);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame([['bearer' => []]], $this->at('security'));
        self::assertSame('http', $this->at('components', 'securitySchemes', 'bearer', 'type'));
        self::assertSame('bearer', $this->at('components', 'securitySchemes', 'bearer', 'scheme'));
        foreach ($operations as $path => $methods) {
            $item = $this->at('paths', $path);
            self::assertIsArray($item);
            self::assertSame(array_keys($methods), array_keys($item), $path);
            foreach ($methods as $method => $success) {
                $this->at('paths', $path, $method, 'responses', $success);
                $this->at('paths', $path, $method, 'responses', '401');
            }
        }
    }
}
