<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Domain\Role;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\SignedInBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\RouterInterface;

/**
 * A guest reaches only the sign-in, their account, the public links
 * and what is shared with them: every other route of the application, those of
 * modules yet to come included, answers 403. The settings of a project, its
 * trash, sharing, new projects and the labels stay the staff's.
 */
final class GuestAccessTest extends WebTestCase
{
    /**
     * The routes a guest may use, and the firewall's own.
     */
    private const array OPEN = [
        // The sign-in, its code step and signing out.
        'app_login', '2fa_login', '2fa_login_check', 'app_logout',
        // Their account and the choice of organisation.
        'app_account_language', 'app_organisation_choose', 'app_organisation_switch', 'app_two_factor_setup', 'app_two_factor_recovery_codes',
        // Machines: the uptime monitor and the bank mail relay, on firewalls without a session.
        'app_health', 'app_inbound_mail',
        // The public links to sent documents.
        'app_document_link', 'app_document_link_pdf', 'app_document_link_open', 'app_document_link_download', 'app_document_link_other',
        // The link of an invitation, which may well be for a guest.
        'app_invitation',
        // The start page, which sends them on to what is shared with them.
        'app_dashboard', 'app_shared_with_me',
        // The work shared with them: the projects index sends them to it, the project, its board and
        // the task are what they see of them, the rest what they do on a task they see. Each answers
        // 404 for what is not shared with them (tests/Work/Infrastructure/Controller/GuestWorkTest.php).
        'app_projects', 'app_project', 'app_project_board', 'app_project_task_new', 'app_task_move', 'app_my_tasks',
        'app_task', 'app_task_comment_add', 'app_task_comment_edit', 'app_task_comment_delete', 'app_task_mention_candidates',
        'app_task_title', 'app_task_description', 'app_task_status', 'app_task_responsible', 'app_task_due', 'app_task_priority',
        'app_task_labels', 'app_task_section', 'app_task_watch', 'app_task_unwatch', 'app_task_watchers', 'app_task_subtask_new',
        'app_markdown_preview', 'app_attachment', 'app_attachment_upload', 'app_attachment_delete',
        // Their own inbox, which holds only the tasks they see.
        'app_notifications', 'app_notifications_read_all', 'app_notification_done', 'app_notification_undone',
        // Their own e-mail settings, and the signed link in an e-mail that stops one kind of them.
        'app_notification_settings', 'app_notification_unsubscribe',
        // No live components exist: an unknown one is not found before any controller runs.
        'ux_live_component',
    ];

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function staffRoutes(): iterable
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all();
        self::ensureKernelShutdown();
        foreach (array_keys($routes) as $name) {
            if (!\in_array($name, self::OPEN, true)) {
                yield $name => [$name];
            }
        }
    }

    #[DataProvider('staffRoutes')]
    public function testAGuestIsDenied(string $name): void
    {
        $this->signIn(Role::Guest);
        [$method, $url] = $this->request($name);

        $this->client->request($method, $url);

        self::assertResponseStatusCodeSame(403, $method.' '.$url);
    }

    public function testTheStartPageSendsAGuestToWhatIsSharedWithThem(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/shared');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Shared with me');
        self::assertSelectorTextContains('main', 'Nothing is shared with you yet');
        // The sidebar offers what is shared, their inbox and their tasks, nothing else of the
        // modules (the extensions group holds the test environment's own probe item).
        self::assertSame(['/shared', '/notifications', '/my-tasks'], $crawler->filter('#sidebar [data-nav-group]:not([data-nav-group="extensions"]) li > a')->each(static fn ($link): string => (string) $link->attr('href')));
    }

    public function testTheStaffKeepTheirDashboardAndTheAddressBook(): void
    {
        $this->signIn(Role::Member);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('#sidebar [data-nav-group] li > a')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertContains('/', $links);
        self::assertContains('/contacts', $links);
        self::assertNotContains('/shared', $links);
    }

    public function testAGuestKeepsTheirAccount(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
    }

    private function signIn(Role $role): void
    {
        $this->browser->seed(['Tenant A'], role: $role);
        $this->browser->signIn();
    }

    /**
     * The first method of the route and a URL with a value that fits each of its parameters.
     *
     * @return array{string, string}
     */
    private function request(string $name): array
    {
        $route = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get($name);
        self::assertNotNull($route);
        $method = $route->getMethods()[0] ?? 'GET';
        $parameters = [];
        foreach ($route->compile()->getPathVariables() as $variable) {
            self::assertIsString($variable);
            // A parameter of a few words, such as the kind of a shared item, takes the first.
            $requirement = $route->getRequirement($variable) ?? '';
            $parameters[$variable] = 1 === preg_match('/^[a-z_]+(\|[a-z_]+)*$/', $requirement) ? explode('|', $requirement)[0] : Id::generate();
        }
        $router = self::getContainer()->get(RouterInterface::class);
        $words = array_filter($parameters, static fn (string $value): bool => 1 === preg_match('/^[a-z_]+$/', $value));
        foreach ([$parameters, $words + array_map(static fn (): string => '1', $parameters), $words + array_map(static fn (): string => 'x', $parameters)] as $candidate) {
            try {
                return [$method, $router->generate($name, $candidate)];
            } catch (InvalidParameterException) {
                continue;
            }
        }
        self::fail(sprintf('No URL fits the route "%s".', $name));
    }
}
