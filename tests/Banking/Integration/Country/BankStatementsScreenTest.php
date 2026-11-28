<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Country;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\StatementIntake;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Banking\Support\BankScreenTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class BankStatementsScreenTest extends BankScreenTestCase
{
    public function testWithoutStatementsThereIsNoTabAndThePageSaysSo(): void
    {
        $this->signedIn();

        self::assertNotContains('Statements', $this->tabs($this->page('/bank')));
        $crawler = $this->page('/bank/statements');
        self::assertSame(['All', 'Incoming', 'Outgoing', 'Unmatched0', 'Outgoing unmatched0', 'Messages'], $this->tabs($crawler));
        self::assertStringContainsString('No statements yet', $crawler->filter('main')->text());
        self::assertCount(0, $this->client->getCrawler()->filter('[data-shell-notices]'));
    }

    public function testTheStatementsAreListedNewestFirstWithTheGapOnTheOneAfterIt(): void
    {
        $this->signedIn();
        $this->submit(42, '2026-09', '900.00', '1000.00', ['100.00']);
        $this->submit(44, '2026-11', '1000.00', '1000.00', []);
        $this->submit(1, '2026-10', '0.00', '5.00', ['10.00'], 'test.other');

        self::assertSame(['All', 'Incoming', 'Outgoing', 'Unmatched2', 'Outgoing unmatched0', 'Messages', 'Statements2'], $this->tabs($this->page('/bank')));

        $crawler = $this->page('/bank/statements');
        self::assertSame('page', $crawler->filter('main nav[aria-label="Bank views"] a')->last()->attr('aria-current'));
        $chains = $crawler->filter('[data-statement-chain]');
        self::assertCount(2, $chains);
        self::assertStringContainsString('test.json', $chains->eq(0)->filter('h2')->text());
        $rows = $chains->eq(0)->filter('tbody tr');
        self::assertStringStartsWith('44/2026', trim($rows->eq(0)->filter('td')->first()->text()));
        self::assertSame('42/2026', trim($rows->eq(1)->filter('td')->first()->text()));
        self::assertStringContainsString('A statement between 42/2026 and 44/2026 is missing.', $rows->eq(0)->text());
        self::assertStringNotContainsString('missing', $rows->eq(1)->text());
        self::assertStringContainsString("The entries add up to a closing balance of 10.00\u{a0}Kč, the statement states 5.00\u{a0}Kč", $chains->eq(1)->text());
        // The bank screen says it in its tab, so the shell does not repeat it there.
        self::assertCount(0, $crawler->filter('[data-shell-notices]'));
    }

    public function testABalanceGapSaysTheDifference(): void
    {
        $this->signedIn();
        $this->submit(null, '2026-09', '900.00', '1000.00', ['100.00']);
        $this->submit(null, '2026-10', '1400.00', '1400.00', []);

        $text = $this->page('/bank/statements')->filter('[data-statement-chain] tbody tr')->first()->text();

        self::assertStringContainsString('differs from the previous closing balance', $text);
        self::assertStringContainsString("by 400.00\u{a0}Kč", $text);
    }

    public function testElsewhereTheShellSaysHowManyAccountsDoNotFollowOn(): void
    {
        $this->signedIn();
        $this->submit(42, '2026-09', '900.00', '1000.00', ['100.00']);
        $this->submit(44, '2026-11', '1000.00', '1000.00', []);

        $notices = $this->page('/settings/firm/bank')->filter('[data-shell-notices]');

        self::assertStringContainsString('Bank statements of an account do not follow on.', $notices->text());
        self::assertSame('/bank/statements', $notices->filter('a[href="/bank/statements"]')->attr('href'));
    }

    public function testAGuestDoesNotSeeTheStatements(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('GET', '/bank/statements');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $entries
     */
    private function submit(?int $number, string $month, string $opening, string $closing, array $entries, string $source = 'test.json'): void
    {
        $this->scoped(function () use ($number, $month, $opening, $closing, $entries, $source): void {
            $from = new \DateTimeImmutable($month.'-01');
            $czk = static fn (string $amount): Money => Money::of($amount, Currency::of('CZK'));
            self::getContainer()->get(StatementIntake::class)->submit(new BankStatement(
                self::IBAN,
                Currency::of('CZK'),
                array_map(static fn (string $amount): ParsedMovement => new ParsedMovement(MovementDirection::Incoming, $czk($amount), self::IBAN, null, null, null, $from, null, null, null, null, null), $entries),
                null,
                null === $number ? null : StatementSequence::withinYear($number, 2026),
                $from,
                $from->modify('last day of this month'),
                $czk($opening),
                $czk($closing),
            ), MovementProvenance::uploaded($source, '01963c5e-0000-7000-8000-0000000000aa'));
        });
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('main nav[aria-label="Bank views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }
}
