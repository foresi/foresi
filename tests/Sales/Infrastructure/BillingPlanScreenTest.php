<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\DealLineWrites;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Sales\Domain\Deal;
use Foresi\Tests\Sales\Support\SalesScreenTestCase;

/**
 * The billing plan on the deal's page: a note on an open deal, the starting
 * plan once it is won, Plan invoice in the header's menu with a dialog for an
 * amount or a percentage, the row's dialog for its day, and the small day
 * dialog the cash-flow forecast opens, which returns to where it came from.
 */
final class BillingPlanScreenTest extends SalesScreenTestCase
{
    public function testTheBillingPlanOfAWonDeal(): void
    {
        $deal = $this->newDeal('Initial e-shop audit');
        $this->actAsOwner();
        self::getContainer()->get(DealLineWrites::class)->add(new AddDealLineInput($deal->id(), name: 'Audit', unit: 'piece', quantity: '1', unitPrice: '10000.00', currency: 'CZK'));

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSelectorTextContains('[data-deal-billing-note]', 'starts when the deal is won');
        self::assertCount(0, $crawler->filter('[data-billing-entry-new]'));
        $token = (string) $crawler->filter('[data-deal-win] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/sales/deals/'.$deal->id().'/win', ['_token' => $token]);
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        $table = $crawler->filter('[data-deal-billing] table');
        self::assertStringContainsString('No date', $table->text());
        self::assertStringContainsString('Planned', $table->text());
        self::assertStringContainsString("10,000.00\u{a0}Kč", $table->text());
        self::assertCount(1, $crawler->filter('[data-billing-entry-new]'), 'Plan invoice is in the header of a won deal.');

        // The starting entry lowered to the rest, a deposit of 40 % planned beside it.
        $entry = $this->entries($deal)[0];
        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/billing-plan/'.$entry->id().'/edit', server: [self::DIALOG => '1'])->filter('#billing-entry-form')->form();
        $form['amount'] = '6000';
        $form['text'] = 'Rest';
        $this->client->submit($form, [], [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();
        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/billing-plan/new')->filter('#billing-entry-form')->form();
        $form['percent'] = '40';
        $form['expectedOn'] = '2026-12-10';
        $form['text'] = 'Deposit';
        $this->client->submit($form);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());
        // More than the deal is worth is refused under the field.
        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/billing-plan/new')->filter('#billing-entry-form')->form();
        $form['amount'] = '1';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#billing-entry-form', 'exceed the one-off value');

        $entries = $this->entries($deal);
        self::assertSame([['Rest', 600_000, null], ['Deposit', 400_000, '2026-12-10']], array_map(static fn (BillingPlanEntry $entry): array => [$entry->text(), $entry->amount()?->minor(), $entry->expectedOn()?->format('Y-m-d')], $entries));

        // The day dialog of the forecast sets the day and goes back to the forecast.
        $crawler = $this->client->request('GET', '/sales/billing-plan/'.$entries[0]->id().'/day?return=/cash-flow', server: [self::DIALOG => '1']);
        $form = $crawler->filter('#billing-day-form')->form();
        $form['expectedOn'] = '2027-01-15';
        $this->client->submit($form);
        self::assertResponseRedirects('/cash-flow');
        self::assertSame('2027-01-15', $this->entries($deal)[0]->expectedOn()?->format('Y-m-d'));
        // A return elsewhere is not followed.
        $form = $this->client->request('GET', '/sales/billing-plan/'.$entries[0]->id().'/day?return=//example.com')->filter('#billing-day-form')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => $form->getValues()['_token'], 'clear' => '1']);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());
        self::assertNull($this->entries($deal)[0]->expectedOn());

        // An entry is read on its deal.
        $this->client->request('GET', '/sales/billing-plan/'.$entries[1]->id());
        self::assertResponseRedirects('/sales/deals/'.$deal->id());

        // Removed from its row's menu.
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        $remove = $crawler->filter('[data-deal-billing] form[action$="/billing-plan/'.$entries[1]->id().'/delete"]')->form();
        $this->client->submit($remove);
        self::assertCount(1, $this->entries($deal));
    }

    /**
     * @return list<BillingPlanEntry>
     */
    private function entries(Deal $deal): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(BillingPlanEntry::class)->findBy(['dealId' => $deal->id()], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
