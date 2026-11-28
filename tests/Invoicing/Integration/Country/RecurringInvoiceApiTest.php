<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\Support\Messenger\RunMessages;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Recurring invoices over REST and MCP: previewed without saving, made from an
 * invoice with a line left out, read with their next issues, changed under the
 * version of the form, paused, resumed and ended, behind invoices:read and
 * invoices:write.
 */
final class RecurringInvoiceApiTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    private const string OTHER_IBAN = 'CZ0708000000001234567890';

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-11-20 10:00:00');
        $this->writer = $this->issued([ApiScope::from('invoices:read'), ApiScope::from('invoices:write')]);
    }

    public function testFromAnInvoiceWithoutOneLineIssuesOnlyAndShowsItsNextIssues(): void
    {
        $invoiceId = $this->invoice();

        $preview = $this->tool('foresi_preview_recurring_invoice', ['from_invoice_id' => $invoiceId, 'delivery' => 'review', 'first_issue_on' => '2026-12-01', 'count' => 3]);
        self::assertFalse($preview['isError'] ?? false);
        $draft = $this->data($preview);
        self::assertTrue($draft['saves']);
        self::assertSame($this->customerId, $this->field($draft, 'customer')['id'] ?? null);
        self::assertSame(14, $draft['due_days'], 'the due days of the invoice');
        self::assertSame(['Hosting', 'Development'], array_column($this->rows($draft, 'lines'), 'text'));
        self::assertSame(['2026-12-01', '2027-01-01', '2027-02-01'], array_column($this->rows($draft, 'next_issues'), 'issue_date'));
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertSame(0, $this->at('total'), 'a preview saves nothing');

        $kept = array_values(array_filter($this->rows($draft, 'lines'), static fn (array $line): bool => 'Development' !== ($line['text'] ?? null)));
        $this->send('POST', '/api/v1/recurring-invoices', ['from_invoice_id' => $invoiceId, 'lines' => $kept, 'delivery' => 'review', 'first_issue_on' => '2026-12-01']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/recurring-invoices/'.$id);
        self::assertSame('review', $this->at('data', 'delivery'));
        self::assertSame('active', $this->at('data', 'state'));
        self::assertSame('2026-12-01', $this->at('data', 'next_issue_on'));
        self::assertSame(['month', 1, 'never'], [$this->at('data', 'schedule', 'repeat'), $this->at('data', 'schedule', 'every'), $this->at('data', 'schedule', 'end')]);
        $lineId = $this->at('data', 'lines', 0, 'id');
        self::assertIsString($lineId);
        self::assertTrue(Uuid::isValid($lineId));
        self::assertSame([['id' => $lineId, 'text' => 'Hosting', 'unit_price' => '1000.00', 'quantity' => '1.0000', 'unit' => 'month', 'price_type' => 'without_vat', 'vat' => 'base', 'discount' => '0.00', 'supply_code' => null]], $this->at('data', 'lines'));
        self::assertSame(['2026-12-01', '2026-12-01', '2026-12-15'], [$this->at('data', 'next_issues', 0, 'issue_date'), $this->at('data', 'next_issues', 0, 'tax_point_date'), $this->at('data', 'next_issues', 0, 'due_date')]);
        self::assertSame('PO-1', $this->at('data', 'order_number'));

        $listed = $this->data($this->tool('foresi_list_recurring_invoices', []));
        self::assertSame([$id], array_column($this->rows($listed, 'items'), 'id'));
        $read = $this->data($this->tool('foresi_get_recurring_invoice', ['id' => $id]));
        self::assertSame(['Hosting'], array_column($this->rows($read, 'lines'), 'text'));
        self::assertCount(5, $this->rows($read, 'next_issues'));
    }

    public function testAChangeNeedsTheVersionItWasReadIn(): void
    {
        $id = $this->created();
        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        $version = $this->at('data', 'version');
        self::assertIsInt($version);

        $call = $this->tool('foresi_update_recurring_invoice', ['recurring_invoice_id' => $id, 'version' => $version, 'due_days' => 30, 'delivery' => 'send', 'repeat' => 'year', 'idempotency_key' => 'update-1']);
        self::assertFalse($call['isError'] ?? false);
        $data = $this->data($call);
        self::assertSame([30, 'send', 'year'], [$data['due_days'], $data['delivery'], $this->field($data, 'schedule')['repeat'] ?? null]);
        self::assertSame(['Hosting'], array_column($this->rows($data, 'lines'), 'text'), 'the lines stay when left out');

        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $version, 'due_days' => 10]);
        $this->assertProblem(409, 'changed_meanwhile');
    }

    public function testTheLinesAsReadCanBeSentBackWithTheirIds(): void
    {
        $id = $this->created();
        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        $version = $this->at('data', 'version');
        $lines = $this->at('data', 'lines');
        self::assertIsArray($lines);
        $lineId = $this->at('data', 'lines', 0, 'id');
        $lines[0] = ['text' => 'Hosting renamed'] + (array) $lines[0];

        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $version, 'lines' => $lines]);

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['Hosting renamed', $lineId], [$this->at('data', 'lines', 0, 'text'), $this->at('data', 'lines', 0, 'id')], 'the line is revised in place, so its id stays');

        $version = $this->at('data', 'version');
        $call = $this->tool('foresi_update_recurring_invoice', ['recurring_invoice_id' => $id, 'version' => $version, 'lines' => $lines, 'idempotency_key' => 'lines-1']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame($lineId, $this->rows($this->data($call), 'lines')[0]['id']);
    }

    public function testPauseResumeAndEnd(): void
    {
        $id = $this->created();

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/pause');
        self::assertResponseIsSuccessful();
        self::assertSame('paused', $this->at('data', 'state'));
        self::assertSame([], $this->at('data', 'next_issues'));

        $call = $this->tool('foresi_resume_recurring_invoice', ['recurring_invoice_id' => $id, 'idempotency_key' => 'resume-1']);
        self::assertFalse($call['isError'] ?? false);
        self::assertSame('active', $this->data($call)['state']);

        $this->get('/api/v1/recurring-invoices', ['state' => 'active'], secret: $this->writer);
        self::assertSame([$id], $this->ids());

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/end');
        self::assertResponseIsSuccessful();
        self::assertSame('ended', $this->at('data', 'state'));
        $version = $this->at('data', 'version');

        $this->send('POST', '/api/v1/recurring-invoices/'.$id.'/resume');
        $this->assertProblem(409, 'ended');
        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $version, 'due_days' => 10]);
        $this->assertProblem(409, 'ended');
    }

    public function testWhatCannotBeSavedIsNamed(): void
    {
        $this->send('POST', '/api/v1/recurring-invoices', ['delivery' => 'review', 'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'customer_id', 'reason' => 'customer_missing']);

        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['first_issue_on' => '2026-11-01']));
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'first_issue_on', 'reason' => 'first_issue_past']);

        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['end' => 'after_count']));
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'ends_after', 'reason' => 'end_after_invalid']);

        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(['first_issue_on' => '2026-11-01']));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->at('data', 'saves'));
        $problems = $this->at('data', 'problems');
        self::assertIsArray($problems);
        self::assertStringStartsWith('recurrence.firstIssueOn: first_issue_past', \is_string($problems[0] ?? null) ? $problems[0] : '');

        $this->get('/api/v1/recurring-invoices/01940000-0000-7000-8000-000000000001', secret: $this->writer);
        $this->assertProblem(404, 'not_found');

        // Reading and previewing need invoices:read only; saving needs invoices:write.
        $reader = $this->issued([ApiScope::from('invoices:read')]);
        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(), secret: $reader);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(), secret: $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertSame([], $this->ids());
    }

    public function testARetriedCreateWithAKeySavesOnce(): void
    {
        $first = $this->tool('foresi_create_recurring_invoice', $this->fields() + ['idempotency_key' => 'recurring-1']);
        $again = $this->tool('foresi_create_recurring_invoice', $this->fields() + ['idempotency_key' => 'recurring-1']);

        self::assertFalse($first['isError'] ?? false);
        self::assertSame($this->data($first)['id'], $this->data($again)['id']);
        $this->get('/api/v1/recurring-invoices', secret: $this->writer);
        self::assertCount(1, $this->ids());
    }

    public function testAChangeKeepsEveryFieldItLeavesOutAndTakesEveryFieldItSends(): void
    {
        $seriesId = $this->series();
        $this->flushed(fn (): \Foresi\Party\Domain\FirmBankAccount => FirmAccounts::add($this->em(), self::OTHER_IBAN));
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields([
            'series_id' => $seriesId, 'bank_account' => self::OTHER_IBAN, 'constant_symbol' => '0308', 'order_number' => 'PO-7',
            'description' => 'Hosting {month/year}', 'text_before' => 'Above the lines', 'text_after' => 'Below the lines', 'language' => 'en',
            'every' => 2, 'last_day_of_month' => true, 'end' => 'after_count', 'ends_after' => 6, 'tax_point' => 'last_day_of_month',
            'due_days' => 21, 'delivery' => 'send', 'name' => 'Hosting plan', 'copy_to_me' => false, 'copy_to_others' => false,
        ]));
        self::assertResponseStatusCodeSame(201);
        $before = $this->detail();
        $id = $before['id'];
        self::assertIsString($id);
        self::assertSame($seriesId, $this->field($before, 'series')['id'] ?? null);
        self::assertSame([self::OTHER_IBAN, '0308', 'PO-7', 'en', 21, 'send', false, false], [$before['bank_account'], $before['constant_symbol'], $before['order_number'], $before['language'], $before['due_days'], $before['delivery'], $before['copy_to_me'], $before['copy_to_others']]);
        self::assertSame(['first_issue_on' => '2026-12-01', 'repeat' => 'month', 'every' => 2, 'last_day_of_month' => true, 'end' => 'after_count', 'ends_on' => null, 'ends_after' => 6], $before['schedule']);

        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $before['version'], 'name' => 'Hosting of Example']);
        self::assertResponseIsSuccessful();
        $after = $this->detail();
        self::assertSame('Hosting of Example', $after['name']);
        $kept = static fn (array $data): array => array_diff_key($data, ['version' => true, 'name' => true]);
        self::assertSame($kept($before), $kept($after), 'every field left out keeps what it had');

        $otherCustomer = $this->customer();
        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, [
            'version' => $after['version'], 'customer_id' => $otherCustomer, 'bank_account' => self::IBAN, 'constant_symbol' => '0008', 'order_number' => 'PO-8',
            'description' => 'Support {month/year}', 'text_before' => 'Above', 'text_after' => 'Below', 'first_issue_on' => '2027-01-15',
            'every' => 3, 'last_day_of_month' => false, 'ends_after' => 4, 'name' => 'Support plan', 'copy_to_me' => true, 'copy_to_others' => true,
        ]);
        self::assertResponseIsSuccessful();
        $changed = $this->detail();
        self::assertSame($otherCustomer, $this->field($changed, 'customer')['id'] ?? null);
        self::assertSame(
            [self::IBAN, '0008', 'PO-8', 'Support {month/year}', 'Above', 'Below', 'Support plan', true, true],
            [$changed['bank_account'], $changed['constant_symbol'], $changed['order_number'], $changed['description'], $changed['text_before'], $changed['text_after'], $changed['name'], $changed['copy_to_me'], $changed['copy_to_others']],
        );
        self::assertSame(['first_issue_on' => '2027-01-15', 'repeat' => 'month', 'every' => 3, 'last_day_of_month' => false, 'end' => 'after_count', 'ends_on' => null, 'ends_after' => 4], $changed['schedule']);
    }

    public function testAChangedEndKeepsWhatItNeedsAndDropsWhatTheOldEndNeeded(): void
    {
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['end' => 'on_date', 'ends_on' => '2027-06-30']));
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        self::assertSame(['on_date', '2027-06-30', null], $this->end($this->changed($id, ['name' => 'Hosting'])['schedule']), 'the date stays when the end is left out');
        self::assertSame(['on_date', '2027-09-30', null], $this->end($this->changed($id, ['ends_on' => '2027-09-30'])['schedule']));
        self::assertSame(['after_count', null, 3], $this->end($this->changed($id, ['end' => 'after_count', 'ends_after' => 3])['schedule']), 'the date goes with its end');
        self::assertSame(['after_count', null, 3], $this->end($this->changed($id, ['name' => 'Hosting again'])['schedule']), 'the count stays when the end is left out');
        self::assertSame(['never', null, null], $this->end($this->changed($id, ['end' => 'never'])['schedule']), 'the count goes with its end');

        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $this->at('data', 'version'), 'end' => 'on_date']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'ends_on', 'reason' => 'end_on_missing']);
    }

    public function testTheScheduleIsReadWithinItsBounds(): void
    {
        $refused = [
            [['every' => 0], 'every', 'every_invalid'],
            [['every' => 100], 'every', 'every_invalid'],
            [['ends_on' => '2027-06-30'], 'ends_on', 'end_invalid'],
            [['ends_after' => 2], 'ends_after', 'end_invalid'],
            [['end' => 'on_date'], 'ends_on', 'end_on_missing'],
            [['end' => 'after_count', 'ends_after' => 0], 'ends_after', 'end_after_invalid'],
            [['end' => 'after_count', 'ends_after' => 1000], 'ends_after', 'end_after_invalid'],
            [['first_issue_on' => '2026-02-30'], 'first_issue_on', 'date_invalid'],
        ];
        foreach ($refused as [$fields, $parameter, $reason]) {
            $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields($fields));
            $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter, 'reason' => $reason]);
        }

        $read = [
            [['every' => 99], ['every' => 99, 'last_day_of_month' => false]],
            [['end' => 'after_count', 'ends_after' => 1], ['end' => 'after_count', 'ends_after' => 1]],
            [['end' => 'after_count', 'ends_after' => 999], ['end' => 'after_count', 'ends_after' => 999]],
            [['end' => 'on_date', 'ends_on' => '2027-06-30'], ['end' => 'on_date', 'ends_on' => '2027-06-30']],
            [['last_day_of_month' => true], ['repeat' => 'month', 'last_day_of_month' => true]],
            [['repeat' => 'year', 'last_day_of_month' => true], ['repeat' => 'year', 'last_day_of_month' => true]],
            [['repeat' => 'week', 'last_day_of_month' => true], ['repeat' => 'week', 'last_day_of_month' => false]],
            [['repeat' => 'week'], ['repeat' => 'week', 'last_day_of_month' => false]],
        ];
        foreach ($read as [$fields, $schedule]) {
            $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields($fields));
            self::assertResponseIsSuccessful(json_encode($fields, \JSON_THROW_ON_ERROR));
            $read = $this->at('data', 'schedule');
            self::assertIsArray($read);
            self::assertSame($schedule, array_intersect_key($read, $schedule));
        }
    }

    public function testANewTemplateTakesTheDefaultsOfItsCustomer(): void
    {
        $customer = $this->customer();
        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(['customer_id' => $customer, 'lines' => [
            ['text' => 'Hosting', 'unit_price' => '1000.00'],
            ['text' => 'Support', 'unit_price' => '500.00'],
        ]]));
        self::assertResponseIsSuccessful();
        self::assertSame([30, 'en'], [$this->at('data', 'due_days'), $this->at('data', 'language')]);
        self::assertSame(['reverse_charge', 'reverse_charge'], array_column($this->linesOf(), 'vat'), 'a customer taxed instead gets reverse charge');
        self::assertSame('Hosting', $this->at('data', 'description'), 'the first line names it');
        self::assertSame([true, true], [$this->at('data', 'copy_to_me'), $this->at('data', 'copy_to_others')]);

        // A customer the address book does not know gets the lines of the firm; the rules refuse it.
        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(['customer_id' => '01940000-0000-7000-8000-0000000000AB']));
        self::assertResponseIsSuccessful();
        self::assertSame('01940000-0000-7000-8000-0000000000ab', $this->at('data', 'customer', 'id'));
        self::assertSame(['base'], array_column($this->linesOf(), 'vat'));
        self::assertFalse($this->at('data', 'saves'));
    }

    public function testFromAnInvoiceEveryFieldSentReplacesWhatTheInvoiceGives(): void
    {
        $invoiceId = $this->invoice(['description' => " Monthly   hosting\n and support ", 'due_date' => '2026-11-02']);
        $this->send('POST', '/api/v1/recurring-invoices/preview', ['from_invoice_id' => $invoiceId, 'delivery' => 'review', 'first_issue_on' => '2026-12-01']);
        self::assertResponseIsSuccessful();
        self::assertSame(" Monthly   hosting\n and support ", $this->at('data', 'description'));
        self::assertSame('Monthly hosting and support', $this->at('data', 'name'), 'the name is the description on one line');
        self::assertSame(0, $this->at('data', 'due_days'), 'due on the day of issue');

        $long = $this->invoice(['description' => str_repeat('č', RecurringInvoice::MAX_NAME_LENGTH + 10)]);
        $this->send('POST', '/api/v1/recurring-invoices/preview', ['from_invoice_id' => $long, 'delivery' => 'review', 'first_issue_on' => '2026-12-01']);
        self::assertResponseIsSuccessful();
        self::assertSame(str_repeat('č', RecurringInvoice::MAX_NAME_LENGTH), $this->at('data', 'name'), 'a long description is cut to the length of a name');

        $seriesId = $this->series();
        $customer = $this->customer();
        $this->flushed(fn (): \Foresi\Party\Domain\FirmBankAccount => FirmAccounts::add($this->em(), self::OTHER_IBAN));
        $this->send('POST', '/api/v1/recurring-invoices/preview', [
            'from_invoice_id' => $invoiceId, 'delivery' => 'review', 'first_issue_on' => '2026-12-01', 'customer_id' => $customer, 'series_id' => $seriesId,
            'bank_account' => self::OTHER_IBAN, 'constant_symbol' => '0558', 'order_number' => 'PO-9', 'description' => 'Support', 'text_before' => 'Above',
            'text_after' => 'Below', 'due_days' => 3, 'name' => 'Support plan',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            [$customer, $seriesId, self::OTHER_IBAN, '0558', 'PO-9', 'Support', 'Above', 'Below', 3, 'Support plan'],
            [$this->at('data', 'customer', 'id'), $this->at('data', 'series_id'), $this->at('data', 'bank_account'), $this->at('data', 'constant_symbol'), $this->at('data', 'order_number'), $this->at('data', 'description'), $this->at('data', 'text_before'), $this->at('data', 'text_after'), $this->at('data', 'due_days'), $this->at('data', 'name')],
        );

        $this->send('POST', '/api/v1/recurring-invoices/preview', ['from_invoice_id' => '01940000-0000-7000-8000-000000000001', 'delivery' => 'review']);
        $this->assertProblem(404, 'not_found');
    }

    public function testADateDueTodayIsHandedToTheWorkerOnSaveOnChangeAndOnResume(): void
    {
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields(['first_issue_on' => '2026-11-20']));
        self::assertResponseStatusCodeSame(201);
        $today = $this->at('data', 'id');
        self::assertIsString($today);
        new RunMessages($this->asyncQueue(), self::getContainer()->get(MessageBusInterface::class))->run();
        self::assertSame([$today], $this->queued(), 'the run of today has had its hour');
        $this->get('/api/v1/recurring-invoices/'.$today, secret: $this->writer);
        self::assertSame([1, '2026-12-20'], [$this->at('data', 'issued_count'), $this->at('data', 'next_issue_on')]);
        $next = $this->at('data', 'next_issues');
        self::assertIsArray($next);
        self::assertSame(['2026-12-20', '2027-01-20', '2027-02-20', '2027-03-20', '2027-04-20'], array_column($next, 'issue_date'), 'what was issued today is not next');

        $changed = $this->created();
        self::assertSame([], $this->queued(), 'a date to come waits for its day');
        $this->changed($changed, ['first_issue_on' => '2026-11-20']);
        self::assertSame([$changed], $this->queued());

        $paused = $this->created();
        $this->send('POST', '/api/v1/recurring-invoices/'.$paused.'/pause');
        self::assertResponseIsSuccessful();
        self::mockTime('2026-12-01 10:00:00');
        $this->send('POST', '/api/v1/recurring-invoices/'.$paused.'/resume');
        self::assertResponseIsSuccessful();
        self::assertSame([$paused], $this->queued());
    }

    public function testAPreviewCountsTheInvoicesTheEndAllows(): void
    {
        $this->send('POST', '/api/v1/recurring-invoices/preview', $this->fields(['end' => 'after_count', 'ends_after' => 2]));
        self::assertResponseIsSuccessful();
        $next = $this->at('data', 'next_issues');
        self::assertIsArray($next);
        self::assertSame(['2026-12-01', '2027-01-01'], array_column($next, 'issue_date'));
    }

    public function testATemplateInTheTrashIssuesNothingNext(): void
    {
        $id = $this->created();
        $this->flushed(function () use ($id): void {
            $template = $this->em()->find(RecurringInvoice::class, $id);
            self::assertInstanceOf(RecurringInvoice::class, $template);
            $template->trash(new \DateTimeImmutable('2026-11-20 10:00:00'), null);
        });

        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'trashed'));
        self::assertSame([], $this->at('data', 'next_issues'));
    }

    /**
     * An issued invoice of two lines.
     */
    /**
     * @param array<string, mixed> $overrides
     */
    private function invoice(array $overrides = []): string
    {
        $this->send('POST', '/api/v1/invoices', $overrides + [
            'customer_id' => $this->customerId,
            'issue_date' => '2026-11-02',
            'order_number' => 'PO-1',
            'lines' => [
                ['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month'],
                ['text' => 'Development', 'unit_price' => '5000.00', 'quantity' => '2', 'unit' => 'h'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => $this->customerId,
            'delivery' => 'review',
            'first_issue_on' => '2026-12-01',
            'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month']],
        ];
    }

    private function created(): string
    {
        $this->send('POST', '/api/v1/recurring-invoices', $this->fields());
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(): array
    {
        $data = $this->at('data');
        self::assertIsArray($data);

        return array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * The template after a change of the fields, under the version it has now.
     *
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function changed(string $id, array $change): array
    {
        $this->get('/api/v1/recurring-invoices/'.$id, secret: $this->writer);
        $this->send('PATCH', '/api/v1/recurring-invoices/'.$id, ['version' => $this->at('data', 'version')] + $change);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->detail();
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function end(mixed $schedule): array
    {
        self::assertIsArray($schedule);

        return [$schedule['end'] ?? null, $schedule['ends_on'] ?? null, $schedule['ends_after'] ?? null];
    }

    /**
     * @return list<array<mixed>>
     */
    private function linesOf(): array
    {
        return $this->rows($this->detail(), 'lines');
    }

    /**
     * The templates handed to the worker since the last look, which forgets them.
     *
     * @return list<string>
     */
    private function queued(): array
    {
        $queue = $this->asyncQueue();
        $ids = [];
        foreach ($queue->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof IssueRecurringTemplate) {
                $ids[] = $message->templateId;
            }
        }
        $queue->reset();

        return $ids;
    }

    private function asyncQueue(): InMemoryTransport
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return $queue;
    }

    /**
     * A second series of invoices.
     */
    private function series(): string
    {
        return $this->flushed(function (): string {
            $series = new Series('Services', SeriesFormat::fromString('S{YYYY}{NNNN}'), DocumentKind::Invoice);
            $this->em()->persist($series);

            return $series->id();
        });
    }

    /**
     * A second customer, taxed instead of the firm, who pays in 30 days and reads English.
     */
    private function customer(): string
    {
        return $this->flushed(function (): string {
            $em = $this->em();
            $customer = new Party(PartyKind::Company, 'Example Buyer GmbH', new RegistrationNumber('CZ', '27082440'), VatId::fromString('CZ27082440'), '+420 000 000 001');
            $em->persist($customer);
            self::getContainer()->get(PartyInvoicingDefaults::class)->change($customer->id(), new InvoicingDefaults(30, Currency::of('CZK'), DocumentLanguage::English, PaymentMethod::BankTransfer, true));
            $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Buyer Street 3', 'Buyer City', '300 00', 'CZ')));
            $em->persist(new PartyEmail($customer, 'buyer@example.com', EmailPurpose::Invoices));

            return $customer->id();
        });
    }

    /**
     * @param array<mixed> $result
     *
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $data = \is_array($result['structuredContent'] ?? null) ? $result['structuredContent']['data'] ?? null : null;
        self::assertIsArray($data, json_encode($result, \JSON_THROW_ON_ERROR));

        return array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    private function field(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $rows = [];
        foreach ($this->field($data, $key) as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $result = $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments])['result'] ?? null;
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->session ??= $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
