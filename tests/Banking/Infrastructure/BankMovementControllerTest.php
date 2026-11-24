<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure;

use Foresi\Banking\Contract\MovementDirection;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Banking\Support\BankScreenTestCase;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The bank movements screen: the tabs of incoming and outgoing movements and of the
 * unmatched ones, pairing by hand in a dialog over the list with Undo in the flash,
 * and unpairing after a confirmation. Every write is read back from the database.
 *
 * @phpstan-type Pair array{_token: string, return: string, invoices?: list<string>, amounts: array<string, string>, q: string, search?: string, settle?: string}
 * The tests that need the Czech rules are in Integration/Country.
 */
final class BankMovementControllerTest extends BankScreenTestCase
{
    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
    {
        $this->signedIn();
        $this->movement('500.00', '1');
        $this->movement('600.00');
        $this->movement('90.00', null, MovementDirection::Outgoing);

        $this->client->request('GET', '/bank?'.http_build_query(['view' => 'incoming', 'conditions' => '1', 'c' => [
            ['field' => 'direction', 'op' => 'is', 'values' => ['incoming']],
            ['field' => 'state', 'op' => 'is', 'values' => ['unmatched']],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();

        self::assertCount(2, $crawler->filter('main tbody tr'));
        self::assertSame('Incoming, unsaved changes', $this->tabs($crawler)[1]);
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Waiting incoming']));
        $crawler = $this->client->followRedirect();
        self::assertCount(2, $crawler->filter('main tbody tr'));
        // Six tabs hold All and the views it shipped: a new one stays off them, listed in the Settings sheet and a tab while open.
        self::assertCount(1, $crawler->filter('main [data-settings-view-list] [data-settings-view][data-label="Waiting incoming"] input[type="checkbox"]:not([checked])'));
        self::assertSame('Waiting incoming', $crawler->filter('main [data-list-toolbar] a[aria-current="page"]')->text());
    }

    public function testAViewOfTheBankMailNarrowsByAccountAndFindsNoDirection(): void
    {
        $this->signedIn();
        $second = $this->scoped(function (): FirmBankAccount {
            $account = FirmAccounts::add($this->em(), self::OTHER_IBAN);
            $this->em()->flush();

            return $account;
        });
        $main = $this->received($this->notice('incoming-unknown-status'));
        $other = $this->received($this->notice('incoming-unknown-status', account: $second));
        $mail = static fn (Condition $condition): string => '/bank?view=messages&f='.WorkingFilter::encode(ViewFilter::all(new Condition('bank_mail', 'is', true), $condition));

        self::assertSame([$other, $main], $this->rowIds($this->page('/bank?view=messages')));
        self::assertSame([$other], $this->rowIds($this->page('/bank?tab=messages&account='.$second->id())), 'The account the select named.');
        self::assertSame([$main], $this->rowIds($this->page($mail(new Condition('account', 'is_none_of', [$second->id()])))));
        self::assertSame([], $this->rowIds($this->page($mail(new Condition('direction', 'is', 'incoming')))), 'A message has no direction.');
        self::assertSame([$other, $main], $this->rowIds($this->page($mail(new Condition('state', 'is_not', 'paired')))));
        self::assertSame([], $this->rowIds($this->page('/bank?f='.WorkingFilter::encode(ViewFilter::all(new Condition('bank_mail', 'is', false), new Condition('direction', 'is', 'outgoing'))))));
    }

    public function testTheSidebarLeadsToTheBank(): void
    {
        $this->signedIn();

        $crawler = $this->page('/bank');

        self::assertSame('page', $crawler->filter('#sidebar [data-nav-section] a[href="/bank"]')->first()->attr('aria-current'));
        self::assertCount(1, $this->page('/invoices')->filter('#sidebar [data-nav-section] a[href="/bank"]'));
    }

    public function testAClientRoleGets403(): void
    {
        $this->signedIn(Role::Guest);
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
     * @return list<string>
     */
    private function rowIds(Crawler $crawler): array
    {
        return $crawler->filter('main tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-id'));
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('main nav[aria-label="Bank views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }
}
