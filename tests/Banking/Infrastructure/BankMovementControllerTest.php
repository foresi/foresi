<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\BankScreen;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\MovementPage;
use App\Banking\Application\MovementRow;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Identity\Domain\Role;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Tests\Banking\Support\BankScreenTestCase;
use App\Tests\Banking\Support\Scalars;
use App\Tests\Party\Support\FirmAccounts;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bank movements screen: the tabs of incoming and outgoing movements and of the
 * unmatched ones, pairing by hand in a dialog over the list with Undo in the flash,
 * and unpairing after a confirmation. Every write is read back from the database.
 *
 * @phpstan-type Pair array{_token: string, return: string, invoices?: list<string>, amounts: array<string, string>, q: string, search?: string, settle?: string}
 */
final class BankMovementControllerTest extends BankScreenTestCase
{
    public function testTabsListTheRightMovementsAndFilterByFirmAndAccount(): void
    {
        $this->signedIn();
        $paid = $this->invoice(vs: '20260001');
        $second = $this->scoped(function (): FirmBankAccount {
            $account = FirmAccounts::add($this->em(), $this->em()->find(LegalEntity::class, $this->firm->id()) ?? throw new \LogicException('The firm is gone.'), self::OTHER_IBAN);
            $this->em()->flush();

            return $account;
        });
        [$otherFirm, $otherAccount] = $this->scoped(fn (): array => $this->firmWithSeries('Example Other s.r.o.', 'CZ3808000000000000000123'));
        $pairedId = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified);
        $unmatchedId = $this->movement('500.00', '99999999', trust: MessageTrust::Verified);
        $outgoingId = $this->movement('80.00', null, MovementDirection::Outgoing, account: $second);
        $otherId = $this->movement('70.00', null, account: $otherAccount);

        $incoming = $this->page('/bank');
        self::assertSame('page', $incoming->filter('main nav[aria-label="Bank views"] a[href="/bank"]')->attr('aria-current'));
        self::assertSame([$otherId, $unmatchedId, $pairedId], $this->rowIds($incoming), 'Newest first, incoming only.');
        self::assertSame('20260001', $this->row($incoming, $pairedId)->filter('a[href="/invoices/'.$paid->id().'/edit"]')->text());
        self::assertStringContainsString($this->nb('+1,210.00 Kč'), $this->row($incoming, $pairedId)->text());
        self::assertStringContainsString('Example Remitter Ltd', $this->row($incoming, $unmatchedId)->text());
        self::assertStringContainsString('No invoice has its symbol', $this->row($incoming, $unmatchedId)->text());

        $outgoing = $this->page('/bank?tab=outgoing');
        self::assertSame([$outgoingId], $this->rowIds($outgoing));
        self::assertStringContainsString($this->nb('−80.00 Kč'), $this->row($outgoing, $outgoingId)->text());

        self::assertSame([$otherId], $this->rowIds($this->page('/bank?firm='.$otherFirm->id())));
        self::assertSame([$unmatchedId, $pairedId], $this->rowIds($this->page('/bank?account='.$this->account->id())));
        self::assertSame([], $this->rowIds($this->page('/bank?tab=outgoing&account='.$this->account->id())));
        self::assertSame([$unmatchedId], $this->rowIds($this->page('/bank?q=99999999')));
        // A firm or an account of no one is no filter at all.
        self::assertSame([$otherId, $unmatchedId, $pairedId], $this->rowIds($this->page('/bank?firm=nobody&account=nobody')));
    }

    public function testTheUnmatchedTabCountsOnlyUnmatchedIncoming(): void
    {
        $this->signedIn();
        $this->invoice(vs: '20260001');
        $this->movement('1210.00', '20260001', trust: MessageTrust::Verified);
        $first = $this->movement('500.00', '1');
        $second = $this->movement('600.00');
        $this->movement('90.00', null, MovementDirection::Outgoing);

        $crawler = $this->page('/bank?tab=unmatched');

        self::assertSame('2', $crawler->filter('main nav[aria-label="Bank views"] a[aria-current="page"] span')->text());
        self::assertSame([$second, $first], $this->rowIds($crawler));
        self::assertCount(2, $crawler->filter('tbody a[role="menuitem"][href*="/pair"][data-dialog]'), 'Each one offers to pair it in a dialog.');
    }

    public function testThePairDialogSuggestsBySymbolThenAmountThenCustomerAndPrefillsTheSymbol(): void
    {
        $this->signedIn();
        $payer = $this->scoped(fn (): Party => $this->party('Example Payer s.r.o.'));
        $stranger = $this->scoped(fn (): Party => $this->party('Example Stranger a.s.'));
        $bySymbol = $this->invoice(vs: '20260007', due: '2026-11-30');
        $byAmount = $this->invoice(due: '2026-11-10', customer: $stranger);
        $byCustomer = $this->invoice('700.00', customer: $payer);
        $other = $this->invoice('300.00', customer: $stranger);
        $movement = $this->movement('1210.00', '20260007', trust: MessageTrust::Verified, counterName: 'Example Payer s.r.o.', auto: false);

        $dialog = $this->dialog($this->pairUrl($movement));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<dialog', ltrim((string) $this->client->getResponse()->getContent()));
        self::assertStringContainsString($this->nb('1,210.00 Kč'), $dialog->filter('dialog h2')->text());
        self::assertSame([$bySymbol->id(), $byAmount->id(), $byCustomer->id()], $this->invoiceIds($dialog, '[data-pair-suggestions]'));
        self::assertSame([$other->id()], $this->invoiceIds($dialog, '[data-pair-others]'));
        self::assertNotNull($dialog->filter('#pair-invoice-'.$bySymbol->id())->attr('checked'));
        self::assertSame('1210.00', $dialog->filter('#pair-amount-'.$bySymbol->id())->attr('value'));
        self::assertNull($dialog->filter('#pair-invoice-'.$byAmount->id())->attr('checked'));
        self::assertStringContainsString('Same variable symbol', $dialog->filter('[data-pair-invoice="'.$bySymbol->id().'"]')->text());
        self::assertStringContainsString('Same amount', $dialog->filter('[data-pair-invoice="'.$byAmount->id().'"]')->text());
        self::assertStringContainsString('Same customer', $dialog->filter('[data-pair-invoice="'.$byCustomer->id().'"]')->text());
        self::assertSame('pair-form', $dialog->filter('#pair-submit')->attr('form'));
    }

    /**
     * A notice the bank did not vouch for may be forged: a click must not pay an
     * invoice on it. The dialog warns, ticks and fills in nothing, and pairs only
     * once the person confirms they found the payment in their internet banking.
     */
    public function testAnUnverifiedPaymentWarnsFillsInNothingAndNeedsTheBankCheck(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001');
        self::assertStringContainsString('Not verified, check it in the bank first', $this->row($this->page('/bank'), $movement)->text());

        $dialog = $this->dialog($this->pairUrl($movement));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Foresi did not verify this payment with the bank. Before you pair it, check it in your internet banking.', $dialog->filter('[data-pair-unverified]')->text());
        self::assertSame([$invoice->id()], $this->invoiceIds($dialog, '[data-pair-suggestions]'), 'Still suggested, to find it faster.');
        self::assertCount(0, $dialog->filter('[data-pair-invoice] input[type="checkbox"][checked]'), 'Nothing ticked.');
        self::assertSame([''], $dialog->filter('[data-pair-invoice] input[type="text"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')), 'No amount filled in.');
        self::assertSame('false', $dialog->filter('form#pair-form')->attr('data-pair-sum-fill-value'), 'Ticking fills in nothing either.');
        $box = $dialog->filter('#pair-checked-in-bank');
        self::assertCount(1, $box);
        self::assertNull($box->attr('checked'));
        self::assertStringContainsString('I checked the payment in the bank', $dialog->filter('[data-pair-checked]')->text());

        $form = $this->pairForm($movement);
        $form['invoices'] = [$invoice->id()];
        $form['amounts'][$invoice->id()] = '1210.00';
        $dialog = $this->dialogPost($this->pairUrl($movement), $form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirm that you checked the payment in the bank.', $dialog->filter('[data-pair-checked]')->text());
        self::assertSame([], $this->liveAllocations());
        self::assertSame('unmatched', $this->movementRow($movement)['state']);

        $form['checked_in_bank'] = '1';
        $this->dialogPost($this->pairUrl($movement), $form);

        self::assertSame(['location' => '/bank'], $this->json());
        self::assertSame([$invoice->id() => 121000], $this->liveAllocations());
        self::assertSame('paired', $this->movementRow($movement)['state']);
    }

    public function testAVerifiedPaymentNeedsNoBankCheck(): void
    {
        $this->signedIn();
        $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified, auto: false);

        $dialog = $this->dialog($this->pairUrl($movement));

        self::assertCount(0, $dialog->filter('[data-pair-unverified], #pair-checked-in-bank'));
        self::assertNull($dialog->filter('form#pair-form')->attr('data-pair-sum-fill-value'));
    }

    public function testSearchingKeepsWhatWasTickedAndFindsAnotherInvoice(): void
    {
        $this->signedIn();
        $first = $this->invoice(vs: '20260001');
        $found = $this->invoice('500.00', due: '2026-12-30');
        $this->invoice('300.00');
        $movement = $this->movement('1815.00', '20260001', trust: MessageTrust::Verified, auto: false);
        $number = $found->number();

        $form = $this->pairForm($movement);
        $form['q'] = $number;
        $form['search'] = '1';
        $dialog = $this->dialogPost($this->pairUrl($movement), $form);

        self::assertResponseIsSuccessful();
        self::assertSame([$found->id()], $this->invoiceIds($dialog, '[data-pair-others]'));
        self::assertNotNull($dialog->filter('#pair-invoice-'.$first->id())->attr('checked'), 'What was ticked stays ticked.');
        self::assertSame($number, $dialog->filter('#pair-q')->attr('value'));
        self::assertSame([], $this->liveAllocations(), 'A search pays nothing.');
    }

    public function testPairingInTheDialogStoresOnePaymentOverTwoInvoices(): void
    {
        $this->signedIn();
        $first = $this->invoice(vs: '20260001');
        $second = $this->invoice('500.00', vs: '20260001', due: '2026-11-30');
        $movement = $this->movement('1815.00', '20260001', trust: MessageTrust::Verified, auto: false);
        $form = $this->pairForm($movement, 'tab=unmatched');
        self::assertSame(['1210.00', '605.00'], [$form['amounts'][$first->id()], $form['amounts'][$second->id()]], 'Prefilled oldest due first.');

        $this->dialogPost($this->pairUrl($movement), $form);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/bank?tab=unmatched'], $this->json());
        self::assertSame([$second->id() => 60500, $first->id() => 121000], $this->liveAllocations());
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM payment WHERE cancelled_at IS NULL AND bank_movement_id = ?', [$movement])));
        $row = $this->movementRow($movement);
        self::assertSame('paired', $row['state']);
        $crawler = $this->page('/bank?tab=unmatched');
        $flash = $crawler->filter('[data-undo]');
        self::assertStringContainsString('Paired with 20260001, 20260002.', $flash->text());
        self::assertSame('/bank/movements/'.$movement.'/undo', $flash->filter('form')->attr('action'));
    }

    public function testAWrongSumAnswers422(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified, auto: false);
        $form = $this->pairForm($movement);
        $form['amounts'][$invoice->id()] = '1000.00';

        $dialog = $this->dialogPost($this->pairUrl($movement), $form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $dialog->filter('dialog#dialog-page'));
        self::assertStringContainsString($this->nb('The parts add up to 1,000.00 Kč, the payment is 1,210.00 Kč.'), $dialog->filter('[data-error-summary]')->text());
        self::assertSame('1000.00', $dialog->filter('#pair-amount-'.$invoice->id())->attr('value'));
        self::assertSame([], $this->liveAllocations());

        unset($form['invoices']);
        $this->dialogPost($this->pairUrl($movement), $form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unmatched', $this->movementRow($movement)['state']);
    }

    public function testASmallDifferenceIsWrittenOffWhenTheFirmAllowsIt(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $movement = $this->movement('1209.50', '20260001', trust: MessageTrust::Verified, auto: false);
        $form = $this->pairForm($movement);
        self::assertArrayNotHasKey('settle', $form, 'Offered, not ticked.');
        $this->dialog($this->pairUrl($movement));
        self::assertSelectorNotExists('#pair-settle', 'The switch is off by default.');

        $this->scoped(function (): void {
            $this->connection()->insert('firm_bank_pairing', ['id' => '01963c5e-0000-7000-8000-00000000f1f1', 'tenant_id' => $this->tenant->toString(), 'legal_entity_id' => $this->firm->id(), 'auto_pairing' => 1, 'small_difference' => 1]);
        });
        $dialog = $this->dialog($this->pairUrl($movement));
        self::assertCount(1, $dialog->filter('#pair-settle'));
        $form['settle'] = '1';
        $this->dialogPost($this->pairUrl($movement), $form);

        self::assertResponseIsSuccessful();
        $settlement = $this->connection()->fetchOne('SELECT settlement_minor FROM payment_allocation WHERE invoice_id = ?', [$invoice->id()]);
        self::assertSame(50, Scalars::int($settlement));
        self::assertSame([$invoice->id() => 120950], $this->liveAllocations());
    }

    public function testUndoFromTheFlashUnpairsAndBlocksAutoPairing(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified, auto: false);
        $this->dialogPost($this->pairUrl($movement), $this->pairForm($movement));
        $flash = $this->page('/bank')->filter('[data-undo] form');
        $token = Scalars::text($flash->filter('input[name="_token"]')->attr('value'));

        $this->client->request('POST', '/bank/movements/'.$movement.'/undo', ['_token' => $token, 'return' => 'tab=unmatched']);

        self::assertResponseRedirects('/bank?tab=unmatched');
        $row = $this->movementRow($movement);
        self::assertSame(['unmatched', 'blocked', null], [$row['state'], $row['unmatched_reason'], $row['payment_id']]);
        self::assertSame(1, Scalars::int($row['auto_pair_blocked']));
        self::assertSame([], $this->liveAllocations());
        self::assertSame(1, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM payment WHERE cancelled_at IS NOT NULL AND bank_movement_id = ?', [$movement])));
        $crawler = $this->page('/bank?tab=unmatched');
        self::assertStringContainsString('The pairing is undone. Invoice 20260001 is unpaid again.', $crawler->filter('main')->text());
        self::assertCount(0, $crawler->filter('[data-undo]'));

        // Undo is for the pairing just made: a second time, or for another, it does nothing.
        $this->client->request('POST', '/bank/movements/'.$movement.'/undo', ['_token' => $token]);
        self::assertResponseRedirects('/bank');
        self::assertSame([], $this->liveAllocations());
        self::assertSame('unmatched', $this->movementRow($movement)['state']);
        self::assertNotSame('', $invoice->id());
    }

    public function testUndoIsOnlyForThePairingJustMade(): void
    {
        $this->signedIn();
        $this->invoice(vs: '20260001');
        $paired = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified);
        $this->client->request('POST', '/bank/movements/'.$paired.'/undo', ['_token' => $this->formToken()]);

        self::assertResponseRedirects('/bank');
        self::assertSame('paired', $this->movementRow($paired)['state'], 'A pairing nobody made here is unpaired after the confirmation.');
    }

    public function testUnpairFromTheRowNeedsTheConfirm(): void
    {
        $this->signedIn();
        $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified);
        $list = $this->page('/bank');
        self::assertCount(1, $this->row($list, $movement)->filter('a[href="/bank/movements/'.$movement.'/unpair"][data-dialog]'));

        $dialog = $this->dialog('/bank/movements/'.$movement.'/unpair');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Invoice 20260001 will be unpaid again, and this payment will not be paired automatically any more.', $dialog->filter('dialog')->text());
        self::assertSame('paired', $this->movementRow($movement)['state'], 'Opening the confirmation changes nothing.');
        $form = $dialog->filter('form#unpair-form')->form()->getPhpValues();

        $this->client->request('POST', '/bank/movements/'.$movement.'/unpair', ['return' => 'tab=incoming'] + $form, server: self::DIALOG);

        self::assertSame(['location' => '/bank?tab=incoming'], $this->json());
        $row = $this->movementRow($movement);
        self::assertSame(['unmatched', 'blocked'], [$row['state'], $row['unmatched_reason']]);
        self::assertSame([], $this->liveAllocations());
        // A plain POST without the confirmation's token is refused.
        $this->client->request('POST', '/bank/movements/'.$movement.'/unpair', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPairingADuplicateOrRejectedMovementIsRefused(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $rejected = $this->movement('1210.00', '20260001', trust: MessageTrust::Failed);
        $outgoing = $this->movement('1210.00', '20260001', MovementDirection::Outgoing);
        $token = $this->formToken();

        foreach ([$rejected, $outgoing] as $id) {
            $this->client->request('GET', $this->pairUrl($id));
            self::assertResponseRedirects('/bank');
            $this->client->request('POST', $this->pairUrl($id), ['_token' => $token, 'invoices' => [$invoice->id()], 'amounts' => [$invoice->id() => '1210.00']]);
            self::assertResponseRedirects('/bank');
        }
        self::assertStringContainsString('This payment cannot be paired', $this->page('/bank')->filter('main')->text());
        self::assertSame([], $this->liveAllocations());
        self::assertSame('unmatched', $this->movementRow($rejected)['state']);
    }

    public function testOneQueryForMatchedInvoiceNumbersPer25Rows(): void
    {
        $this->signedIn();
        for ($i = 1; $i <= 26; ++$i) {
            $this->invoice('100.00', vs: (string) (900 + $i));
            $this->movement('121.00', (string) (900 + $i), trust: MessageTrust::Verified);
        }
        $this->em()->clear();

        $before = $this->queryCount();
        $page = $this->scoped(fn (): MovementPage => self::getContainer()->get(BankScreen::class)->page(new MovementFilter()));
        $queries = \array_slice(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? [], $before);

        self::assertCount(25, $page->rows);
        self::assertCount(25, array_filter($page->rows, static fn (MovementRow $row): bool => 1 === \count($row->invoices)));
        $numbers = array_filter($queries, static fn (array $query): bool => \is_string($query['sql']) && str_contains($query['sql'], 'payment_allocation'));
        self::assertCount(1, $numbers);
        // The accounts, the count, the rows, the numbers and the two tab counts.
        self::assertLessThanOrEqual(6, \count($queries), 'Queries used: '.\count($queries));
        $crawler = $this->page('/bank');
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertCount(25, $crawler->filter('tbody [data-links] a[href^="/invoices/"]'));
    }

    public function testThePaymentPanelOfAnInvoiceLinksToItsMovement(): void
    {
        $this->signedIn();
        $invoice = $this->invoice(vs: '20260001');
        $movement = $this->movement('1210.00', '20260001', trust: MessageTrust::Verified);

        $crawler = $this->page('/invoices/'.$invoice->id().'/edit');

        self::assertSame('/bank?movement='.$movement, $crawler->filter('[data-payments] a[data-payment-movement]')->attr('href'));
        self::assertSame([$movement], $this->rowIds($this->page('/bank?movement='.$movement)));
    }

    public function testTheSidebarLeadsToTheBank(): void
    {
        $this->signedIn();

        $crawler = $this->page('/bank');

        self::assertSame('page', $crawler->filter('nav a[href="/bank"]')->first()->attr('aria-current'));
        self::assertCount(1, $this->page('/invoices')->filter('nav a[href="/bank"]'));
    }

    public function testAnotherTenantsMovementIs404(): void
    {
        $this->signedIn();
        $foreignTenant = $this->browser->fixtures->tenant('Foreign Tenant');
        $account = $this->scoped(fn (): FirmBankAccount => $this->firmWithSeries('Example Foreign s.r.o.', self::IBAN)[1], $foreignTenant);
        $foreign = $this->movement('100.00', null, account: $account, auto: false, tenant: $foreignTenant);
        $token = $this->formToken();

        foreach (['/pair', '/unpair'] as $path) {
            $this->client->request('GET', '/bank/movements/'.$foreign.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        foreach (['/pair', '/unpair', '/undo'] as $path) {
            $this->client->request('POST', '/bank/movements/'.$foreign.$path, ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $path);
        }
        self::assertSame([], $this->rowIds($this->page('/bank?movement='.$foreign)));
    }

    public function testAClientRoleGets403(): void
    {
        $this->signedIn(Role::Client);
        $movement = $this->movement('100.00');

        foreach (['/bank', '/bank?tab=messages', $this->pairUrl($movement), '/bank/movements/'.$movement.'/unpair'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        foreach (['/pair', '/unpair', '/undo'] as $path) {
            $this->client->request('POST', '/bank/movements/'.$movement.$path, ['_token' => 'any']);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    private function pairUrl(string $movement): string
    {
        return '/bank/movements/'.$movement.'/pair';
    }

    /**
     * What the dialog would post as it opens.
     *
     * @return Pair
     */
    private function pairForm(string $movement, string $return = ''): array
    {
        $dialog = $this->dialog($this->pairUrl($movement).('' === $return ? '' : '?return='.rawurlencode($return)));
        self::assertResponseIsSuccessful();
        /** @var Pair $values */
        $values = $dialog->filter('form#pair-form')->form()->getPhpValues();

        return $values;
    }

    /**
     * @return list<string>
     */
    private function invoiceIds(Crawler $dialog, string $section): array
    {
        return $dialog->filter($section.' [data-pair-invoice]')->each(static fn (Crawler $row): string => (string) $row->attr('data-pair-invoice'));
    }

    private function formToken(): string
    {
        return $this->pairForm($this->movement('1.00', null, auto: false))['_token'];
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

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }
}
