<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Perf;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Application\ManageApiTokens;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The SQL queries of each page of the performance baseline (tests/Perf/pages.json), on
 * the tiny data set and on ten times as much. A page whose count grows with the data runs
 * a query per row (N+1); a page over its budget runs more queries than it did when the
 * budget was set. Both fail. When a change adds a query on purpose, raise the budget in the
 * same pull request. Excluded from the default suite; the perf workflow runs it on every
 * pull request (docs/performance.md).
 */
#[Group('perf')]
final class PageQueryCountTest extends WebTestCase
{
    // The factor of the larger data set.
    private const int SCALE = 10;

    /**
     * The queries each page may run, as measured on the tiny set when the budget was set.
     *
     * @var array<string, int>
     */
    private const array BUDGETS = [
        'dashboard' => 148,
        'contacts' => 160,
        'contacts search' => 135,
        'contact' => 121,
        'invoices' => 190,
        'invoice' => 150,
        'received documents' => 171,
        // The project rows count overdue tasks, so the pages that list projects read the
        // firm's timezone for today once (InstanceClock).
        'projects' => 133,
        'project' => 216,
        // My priorities is a seventh shipped view of the task list, which a first visit stores
        // one lookup by system key at a time (SavedViews).
        'tasks' => 212,
        'task' => 173,
        'time week' => 122,
        'time report' => 155,
        'deals' => 189,
        'cash flow' => 152,
        'api contacts' => 10,
        'api invoices' => 10,
        'api time entries' => 9,
    ];

    /**
     * Pages known to run a query per record, with the queries they may run on the larger
     * set until that is fixed. The deals board loads each deal's contact (its addresses,
     * bank accounts and links) and lines one at a time.
     *
     * @var array<string, int>
     */
    private const array KNOWN_GROWTH = [
        'deals' => 352,
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    public function testNoPageRunsMoreQueriesWithMoreDataOrMoreThanItsBudget(): void
    {
        $small = $this->countsOf(PerfSize::tiny());
        $scaled = $this->countsOf(PerfSize::scaled(self::SCALE));

        $rows = [];
        $problems = [];
        foreach ($small as $page => $count) {
            $budget = self::BUDGETS[$page] ?? 0;
            $rows[] = ['page' => $page, 'small' => $count, 'scaled' => $scaled[$page], 'budget' => $budget];
            $grown = self::KNOWN_GROWTH[$page] ?? $count;
            if ($scaled[$page] > $grown) {
                $problems[] = \sprintf('%s runs %d queries on the tiny set and %d on %d times as much data.', $page, $count, $scaled[$page], self::SCALE);
            }
            if ($count > $budget) {
                $problems[] = \sprintf('%s runs %d queries, over its budget of %d.', $page, $count, $budget);
            }
        }
        $this->write($rows);

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @return array<string, int> the queries of each page, by page name
     */
    private function countsOf(PerfSize $size): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $world = PerfData::seed(self::getContainer(), $size);
        $em->clear();
        $token = self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($world->ownerUserId, 'Perf', null)->secret;
        $em->clear();

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $world->ownerEmail, '_password' => PerfData::DEFAULT_PASSWORD]));
        self::assertResponseRedirects();

        $samples = [
            'contact' => $world->contactId,
            'invoice' => $world->invoiceId,
            'project' => $world->projectId,
            'task' => $world->taskId,
            'deal' => $world->dealId,
            'receivedDocument' => $world->receivedDocumentId,
        ];
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $counts = [];
        foreach ($this->pages() as $page) {
            $path = preg_replace_callback('/\{(\w+)\}/', static fn (array $key): string => $samples[$key[1]] ?? throw new \LogicException(\sprintf('The seed has no sample %s.', $key[1])), $page['path']);
            \assert(\is_string($path));
            $em->clear();
            $queries->reset();
            $server = $page['api'] ? ['HTTP_AUTHORIZATION' => 'Bearer '.$token] : [];
            $this->client->request('GET', $path, server: $server);
            self::assertResponseIsSuccessful(\sprintf('%s (%s) on the %s set.', $page['name'], $path, $size->name));
            $counts[$page['name']] = \count($queries->getData()['default'] ?? []);
        }

        return $counts;
    }

    /**
     * @return list<array{name: string, path: string, api: bool}>
     */
    private function pages(): array
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/pages.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($json) && \is_array($json['pages']));

        return array_map(static function (mixed $page): array {
            \assert(\is_array($page) && \is_string($page['name']) && \is_string($page['path']));

            return ['name' => $page['name'], 'path' => $page['path'], 'api' => true === ($page['api'] ?? false)];
        }, array_values($json['pages']));
    }

    /**
     * @param list<array{page: string, small: int, scaled: int, budget: int}> $rows
     */
    private function write(array $rows): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');
        $dir = $root.'/var/perf';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir.'/queries.json', json_encode(['scale' => self::SCALE, 'pages' => $rows], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }
}
