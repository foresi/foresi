<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Infrastructure\Screen;

use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGap;
use Foresi\Margin\Application\MarginCellItem;
use Foresi\Margin\Application\MarginCellView;
use Foresi\Margin\Application\MarginFigures;
use Foresi\Margin\Application\MarginRowView;
use Foresi\Margin\Application\MarginTableView;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Infrastructure\Screen\MarginScreen;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Margin\Support\FixedCostSource;
use Foresi\Ui\Format\MoneyText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the screen makes of the known gaps of a table: the note, a link for a document it can name and the
 * plain id for one it cannot.
 */
final class MarginScreenTest extends KernelTestCase
{
    public function testTheNoteCountsTheDocumentsAndLinksTheOnesItCanName(): void
    {
        $source = new FixedCostSource();
        $source->documents['d1'] = new CostDocument('d1', null, new \DateTimeImmutable('2026-11-03'), [], false, 'USD-0042', '/purchasing/documents/d1');

        $page = $this->screen($source)->table($this->table([new KnownGap('missing_exchange_rate', ['d1', 'gone'])]));

        self::assertNotNull($page['gaps']);
        self::assertSame('The cost of 2 documents is not counted, their exchange rate is missing.', $page['gaps']['text']);
        self::assertSame([['label' => 'USD-0042', 'href' => '/purchasing/documents/d1'], ['label' => 'gone', 'href' => null]], $page['gaps']['documents']);
    }

    public function testADocumentIdThatIsNoUuidGetsNoAssignLinkInsteadOfAnError(): void
    {
        $none = MarginFigures::none();
        $item = new MarginCellItem('not-a-uuid', 'Cloud Host', null, Money::ofMinor(100, Currency::CZK), 'unassigned', null, null);

        $page = $this->screen(new FixedCostSource())->cell(new MarginCellView('unassigned', null, '2026-11', $none, [], [$item]));

        self::assertNull($page['costs']['items'][0]['assign']);
    }

    public function testNoGapsNoNote(): void
    {
        self::assertNull($this->screen(new FixedCostSource())->table($this->table([]))['gaps']);
    }

    /**
     * @param list<KnownGap> $gaps
     */
    private function table(array $gaps): MarginTableView
    {
        $none = MarginFigures::none();
        $own = new MarginRowView('own', 'own', null, null, ['2026-11' => $none], $none);
        $unassigned = new MarginRowView('unassigned', 'unassigned', null, null, ['2026-11' => $none], $none);

        return new MarginTableView('2026-11', '2026-11', ['2026-11'], [$own, $unassigned], ['2026-11' => $none], $none, $gaps, 0);
    }

    private function screen(FixedCostSource $source): MarginScreen
    {
        self::bootKernel();
        $container = self::getContainer();
        $locales = $container->get(LocaleSwitcher::class);
        $locales->setLocale('en');

        return new MarginScreen($container->get(MoneyText::class), new CostSources([$source]), $locales, $container->get(TranslatorInterface::class), $container->get(UrlGeneratorInterface::class));
    }
}
