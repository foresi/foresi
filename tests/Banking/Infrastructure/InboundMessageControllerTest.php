<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\ProcessMessage;
use App\Banking\Domain\InboundMessage;
use App\Identity\Domain\Role;
use App\Party\Domain\FirmBankAccount;
use App\Tests\Banking\Support\BankScreenTestCase;
use App\Tests\Banking\Support\Scalars;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Messages tab of the bank screen: every bank mail that came, what became of
 * it in words, and what a person can do about it: look at it, try it again,
 * record it anyway after a trust rejection, or dismiss it. Every write is read
 * back from the database.
 */
final class InboundMessageControllerTest extends BankScreenTestCase
{
    public function testTheMessagesTabSaysWhatBecameOfEachMessage(): void
    {
        $this->signedIn();
        $processed = $this->received($this->notice('incoming-booked'));
        $failed = $this->received($this->notice('incoming-unknown-status'));
        $untrusted = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail', messageId: 'forged@kb.example.com'));
        $notBank = $this->received($this->plainMail('Gmail forwarding confirmation', 'Confirm the forwarding.'));

        $crawler = $this->page('/bank?tab=messages');

        self::assertSame('2', $crawler->filter('main nav[aria-label="Bank views"] a[aria-current="page"] span')->text(), 'The failed and the rejected one wait for someone.');
        self::assertSame([$notBank, $untrusted, $failed, $processed], $this->rowIds($crawler), 'Newest first.');
        self::assertStringContainsString('Processed', $this->row($crawler, $processed)->text());
        self::assertStringContainsString('The bank changed the layout of its e-mail', $this->row($crawler, $failed)->text());
        self::assertStringContainsString('The sender could not be verified', $this->row($crawler, $untrusted)->text());
        self::assertStringContainsString('Not an e-mail from a bank', $this->row($crawler, $notBank)->text());
        self::assertStringContainsString('Gmail forwarding confirmation', $this->row($crawler, $notBank)->text());
        self::assertStringContainsString('someone@example.org', $this->dialog('/bank/messages/'.$notBank)->filter('dialog')->text(), 'The sender is in the message.');
    }

    public function testTryAgainReprocessesAFailedMessage(): void
    {
        $this->signedIn();
        $id = $this->received($this->notice('incoming-booked'), process: false);
        $path = Scalars::text(self::getContainer()->getParameter('app.inbound_mail_dir')).'/'.Scalars::text($this->connection()->fetchOne('SELECT raw_key FROM inbound_message WHERE id = ?', [$id]));
        $raw = (string) file_get_contents($path);
        file_put_contents($path, $raw.' ');
        $this->retryInScope($id);
        self::assertSame('failed', $this->messageRow($id)['status']);
        file_put_contents($path, $raw);
        $crawler = $this->page('/bank?tab=messages');
        // In the row on a wide screen, in its menu on a phone.
        $retry = $this->row($crawler, $id)->filter('form[action="/bank/messages/'.$id.'/retry"]');
        self::assertCount(2, $retry);

        $this->client->submit($retry->first()->form());

        self::assertResponseRedirects('/bank?tab=messages');
        $row = $this->messageRow($id);
        self::assertSame('processed', $row['status']);
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_movement WHERE inbound_message_id = ?', [$id])));
        self::assertStringContainsString('The e-mail is processed.', $this->page('/bank?tab=messages')->filter('main')->text());
    }

    public function testRecordAnywayExistsOnlyForTrustRejections(): void
    {
        $this->signedIn();
        [$otherFirm, $otherAccount] = $this->scoped(fn (): array => $this->firmWithSeries('Example Other s.r.o.', self::OTHER_IBAN));
        $untrusted = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail', messageId: 'forged@kb.example.com'));
        $mismatch = $this->received($this->notice('incoming-booked', account: $otherAccount, messageId: 'other@kb.example.com'));
        self::assertSame(['rejected', 'account_mismatch'], [$this->messageRow($mismatch)['status'], $this->messageRow($mismatch)['failure']]);
        self::assertNotSame('', $otherFirm->id());

        $crawler = $this->page('/bank?tab=messages');
        self::assertCount(1, $this->row($crawler, $untrusted)->filter('a[href^="/bank/messages/'.$untrusted.'/accept"][data-dialog]'));
        self::assertCount(0, $this->row($crawler, $mismatch)->filter('a[href$="/accept"]'));
        $this->client->request('GET', '/bank/messages/'.$mismatch.'/accept');
        self::assertResponseRedirects('/bank?tab=messages');

        $dialog = $this->dialog('/bank/messages/'.$untrusted.'/accept');
        self::assertStringContainsString('The e-mail failed the check of its sender and may be forged', $dialog->filter('dialog')->text());
        self::assertStringContainsString('checked in your internet banking that the payment really came', $dialog->filter('dialog')->text());
        $this->client->request('POST', '/bank/messages/'.$untrusted.'/accept', $dialog->filter('form#accept-form')->form()->getPhpValues(), server: self::DIALOG);

        self::assertSame(['location' => '/bank?tab=messages'], $this->json());
        self::assertSame('processed', $this->messageRow($untrusted)['status']);
        // Who recorded it anyway, and when, stays on the message.
        $audit = $this->connection()->fetchAssociative('SELECT changes, user_id, occurred_at FROM audit_entry WHERE entity_class = ? AND entity_id = ?', [InboundMessage::class, $untrusted]);
        self::assertIsArray($audit);
        self::assertSame(['status' => ['rejected', 'processed'], 'trust_basis' => ['dkim-fail', 'accepted-by-user']], json_decode(Scalars::text($audit['changes']), true));
        self::assertNotNull($audit['user_id']);
        self::assertNotNull($audit['occurred_at']);
        $movement = $this->connection()->fetchAssociative('SELECT state, unmatched_reason, trust_basis FROM bank_movement WHERE inbound_message_id = ?', [$untrusted]);
        self::assertSame(['state' => 'unmatched', 'unmatched_reason' => 'unverified', 'trust_basis' => 'accepted-by-user'], $movement);

        $this->client->request('POST', '/bank/messages/'.$mismatch.'/accept', ['_token' => $this->messageToken()]);
        self::assertResponseRedirects('/bank?tab=messages');
        self::assertSame('rejected', $this->messageRow($mismatch)['status']);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_movement WHERE inbound_message_id = ?', [$mismatch])));
    }

    public function testAMemberCannotRecordAnyway(): void
    {
        $this->signedIn(Role::Member);
        $untrusted = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail', messageId: 'forged@kb.example.com'));
        $token = $this->messageToken();

        self::assertCount(0, $this->row($this->page('/bank?tab=messages'), $untrusted)->filter('a[href*="/accept"]'), 'A member is not offered it.');
        $this->client->request('GET', '/bank/messages/'.$untrusted.'/accept');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/bank/messages/'.$untrusted.'/accept', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('rejected', $this->messageRow($untrusted)['status']);
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_movement WHERE inbound_message_id = ?', [$untrusted])));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entry WHERE entity_id = ?', [$untrusted])));
    }

    public function testDismissingAnAccountMismatchClearsTheNoticeAndKeepsTheRawAndAuditsIt(): void
    {
        $this->signedIn();
        $otherAccount = $this->scoped(fn (): FirmBankAccount => $this->firmWithSeries('Example Other s.r.o.', self::OTHER_IBAN)[1]);
        $id = $this->received($this->notice('incoming-booked', account: $otherAccount));
        self::assertStringContainsString('A bank e-mail could not be processed.', $this->page('/invoices')->filter('body')->text());

        $dialog = $this->dialog('/bank/messages/'.$id.'/dismiss');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('It stays stored', $dialog->filter('dialog')->text());
        self::assertSame('rejected', $this->messageRow($id)['status'], 'Opening the confirmation changes nothing.');
        $this->client->request('POST', '/bank/messages/'.$id.'/dismiss', $dialog->filter('form#dismiss-form')->form()->getPhpValues(), server: self::DIALOG);

        self::assertSame(['location' => '/bank?tab=messages'], $this->json());
        self::assertSame('dismissed', $this->messageRow($id)['status']);
        $key = Scalars::text($this->connection()->fetchOne('SELECT raw_key FROM inbound_message WHERE id = ?', [$id]));
        self::assertFileExists(Scalars::text(self::getContainer()->getParameter('app.inbound_mail_dir')).'/'.$key);
        $audit = $this->connection()->fetchAssociative('SELECT action, changes, user_id FROM audit_entry WHERE entity_class = ? AND entity_id = ?', [InboundMessage::class, $id]);
        self::assertIsArray($audit);
        self::assertSame('updated', $audit['action']);
        self::assertSame(['status' => ['rejected', 'dismissed']], json_decode(Scalars::text($audit['changes']), true));
        self::assertNotNull($audit['user_id']);
        self::assertStringNotContainsString('could not be processed', $this->page('/invoices')->filter('body')->text());
        $row = $this->row($this->page('/bank?tab=messages'), $id);
        self::assertStringContainsString('Dismissed', $row->text());
        self::assertCount(0, $row->filter('a[href$="/dismiss"], form[action$="/retry"]'));

        // Dismissed already: a second time changes nothing.
        $this->client->request('POST', '/bank/messages/'.$id.'/dismiss', ['_token' => $this->messageToken()]);
        self::assertResponseRedirects('/bank?tab=messages');
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entry WHERE entity_id = ?', [$id])));
    }

    public function testAMessageLeftWaitingIsCountedAsStuckAndCanBeTriedAgain(): void
    {
        $this->signedIn();
        $fresh = $this->received($this->notice('incoming-booked'), process: false);
        $stuck = $this->received($this->notice('incoming-booked', messageId: 'stuck@kb.example.com'), process: false);
        $this->connection()->executeStatement("UPDATE inbound_message SET received_at = '2026-11-20 10:30:00' WHERE id = ?", [$stuck]);

        $crawler = $this->page('/bank?tab=messages');
        self::assertSame('1', $crawler->filter('main nav[aria-label="Bank views"] a[aria-current="page"] span')->text(), 'Only the one waiting over an hour.');
        self::assertStringContainsString('Waiting to be processed for over an hour', $this->row($crawler, $stuck)->text());
        self::assertCount(0, $this->row($crawler, $fresh)->filter('form[action$="/retry"]'), 'A fresh one is still in the queue.');
        self::assertStringContainsString('A bank e-mail could not be processed.', $this->page('/invoices')->filter('body')->text());

        $this->client->submit($this->row($crawler, $stuck)->filter('form[action="/bank/messages/'.$stuck.'/retry"]')->first()->form());

        self::assertSame('processed', $this->messageRow($stuck)['status']);
        self::assertStringNotContainsString('could not be processed', $this->page('/invoices')->filter('body')->text());
    }

    public function testTheMessageViewEscapesHtml(): void
    {
        $this->signedIn();
        $id = $this->received($this->plainMail('Hello <b>there</b>', "Line one\n<script>alert('x')</script>"));

        $dialog = $this->dialog('/bank/messages/'.$id);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("<script>alert('x')</script>", $content);
        self::assertStringContainsString('&lt;script&gt;', $content);
        self::assertStringContainsString("<script>alert('x')</script>", $dialog->filter('[data-message-text]')->text());
        self::assertStringContainsString('Hello <b>there</b>', $dialog->filter('[data-message-subject]')->text());
    }

    /**
     * Only the owner sees the inbound address; a forwarding confirmation quotes it, and
     * staff who are not the owner read bank mail too.
     */
    public function testTheMessageViewHidesTheInboundAddress(): void
    {
        $this->signedIn(Role::Member);
        $address = $this->address();
        $token = Scalars::text(preg_replace('/^bank\+([a-z2-7]{26})@example\.com$/D', '$1', $address));
        $id = $this->received($this->plainMail('Forwarding confirmation for '.$address, 'Confirm forwarding to '.strtoupper($address).'.'));

        $this->dialog('/bank/messages/'.$id);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase($token, $content);
        self::assertStringContainsString('bank+…@example.com', $content);
        self::assertStringNotContainsStringIgnoringCase($token, $this->page('/bank?tab=messages')->html());
    }

    public function testAParserSignatureIsNeverShownAsAParser(): void
    {
        $this->signedIn();
        $id = $this->received($this->plainMail('Newsletter', 'Hello.'));
        self::assertStringStartsWith('parsers:', Scalars::text($this->messageRow($id)['parser_id']));
        $processed = $this->received($this->notice('incoming-booked'));

        $list = $this->page('/bank?tab=messages')->filter('main')->html();
        $dialog = $this->dialog('/bank/messages/'.$id)->filter('dialog')->text();
        $known = $this->dialog('/bank/messages/'.$processed)->filter('dialog')->text();

        self::assertStringNotContainsString('parsers:', $list);
        self::assertStringNotContainsString('parsers:', $dialog);
        self::assertStringContainsString(Scalars::text($this->messageRow($processed)['parser_id']), $known);
    }

    public function testAnotherTenantsMessageIs404(): void
    {
        $this->signedIn();
        $foreignTenant = $this->browser->fixtures->tenant('Foreign Tenant');
        $foreignAccount = $this->scoped(fn (): FirmBankAccount => $this->firmWithSeries('Example Foreign s.r.o.', self::IBAN)[1], $foreignTenant);
        $tenant = $this->tenant;
        $this->tenant = $foreignTenant;
        $foreign = $this->received($this->notice('incoming-unknown-status', account: $foreignAccount));
        $this->tenant = $tenant;
        $token = $this->messageToken();

        foreach (['', '/accept', '/dismiss'] as $path) {
            $this->client->request('GET', '/bank/messages/'.$foreign.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        foreach (['/retry', '/accept', '/dismiss'] as $path) {
            $this->client->request('POST', '/bank/messages/'.$foreign.$path, ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $path);
        }
        self::assertSame('failed', $this->messageRow($foreign)['status']);
    }

    public function testAClientRoleGets403(): void
    {
        $this->signedIn(Role::Client);
        $id = $this->received($this->notice('incoming-unknown-status'));

        foreach (['', '/accept', '/dismiss'] as $path) {
            $this->client->request('GET', '/bank/messages/'.$id.$path);
            self::assertResponseStatusCodeSame(403, $path);
        }
        foreach (['/retry', '/accept', '/dismiss'] as $path) {
            $this->client->request('POST', '/bank/messages/'.$id.$path, ['_token' => 'any']);
            self::assertResponseStatusCodeSame(403, $path);
        }
        self::assertSame('failed', $this->messageRow($id)['status']);
    }

    private function retryInScope(string $id): void
    {
        $this->scoped(function () use ($id): void {
            self::getContainer()->get(ProcessMessage::class)->process($id);
        });
    }

    private function messageToken(): string
    {
        $id = $this->received($this->plainMail('Token', 'Token.'));

        return Scalars::text($this->dialog('/bank/messages/'.$id.'/dismiss')->filter('form#dismiss-form input[name="_token"]')->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $crawler): array
    {
        return $crawler->filter('main tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-id'));
    }

    private function row(Crawler $crawler, string $id): Crawler
    {
        $row = $crawler->filter('main tbody tr[data-id="'.$id.'"]');
        self::assertCount(1, $row);

        return $row;
    }
}
