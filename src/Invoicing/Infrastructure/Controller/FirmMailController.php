<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Invoicing\Application\InvoiceRow;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\MailGateway;
use Foresi\Invoicing\Application\MailHostPolicy;
use Foresi\Invoicing\Application\MailMarkdown;
use Foresi\Invoicing\Application\MailMessage;
use Foresi\Invoicing\Application\MailRefused;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\MailServers;
use Foresi\Invoicing\Application\MailTemplates;
use Foresi\Invoicing\Application\PlannedReminder;
use Foresi\Invoicing\Application\ReminderPreview;
use Foresi\Invoicing\Application\ReminderSettings;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Invoicing\Domain\InvalidMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Invoicing\Infrastructure\Form\FirmMailSubmission;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Application\Markdown;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail settings of a firm, for the owner: the list of the texts its
 * documents and its reminders are sent with (each edited in a side sheet,
 * FirmMailTemplateController), when reminders go, and its own mail server. Two
 * tabs of one page; the reminders and the server are each a form of its own. A firm in a country without invoicing rules
 * has no home currency to set a minimum in, so its page has no reminders card and
 * its stored reminder settings stay as they are. The
 * password is never sent back to the browser; a blank one keeps what is stored. An
 * unknown firm answers 404.
 */
final class FirmMailController extends AbstractController
{
    public const string TEMPLATES_TOKEN = 'firm_mail_templates';
    public const string REMINDERS_TOKEN = 'firm_mail_reminders';
    public const string SERVER_TOKEN = 'firm_mail_server';
    public const string TEST_TOKEN = 'firm_mail_test';
    private const string TEMPLATES = 'templates';
    private const string SERVER = 'server';
    private const string REMINDERS = 'reminders';

    public function __construct(
        private readonly InstanceFirm $instanceFirm,
        private readonly MailServers $servers,
        private readonly MailSecrets $secrets,
        private readonly MailHostPolicy $hosts,
        private readonly MailTemplates $templates,
        private readonly ReminderSettings $reminders,
        private readonly ReminderPreview $preview,
        private readonly InvoicingCountries $countries,
        private readonly AmountText $amounts,
        private readonly MailGateway $gateway,
        private readonly TransactionalWork $transaction,
        private readonly OwnerCheck $owner,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly Markdown $markdown,
        #[Autowire(service: 'limiter.mail_test')]
        private readonly RateLimiterFactory $testLimiter,
    ) {
    }

    #[Route('/settings/firm/mail', name: 'app_firm_mail', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $this->denyUnlessOwner();
        $this->firm();
        if (!$request->isMethod('POST')) {
            $tab = self::SERVER === $request->query->getString('tab') ? self::SERVER : self::TEMPLATES;

            return $this->page($tab, $this->storedReminders(), $this->storedServer(), [], Response::HTTP_OK);
        }

        return match ($request->request->getString('section')) {
            self::REMINDERS => $this->saveReminders($request),
            self::SERVER => $this->saveServer($request),
            default => throw new BadRequestHttpException('Unknown section.'),
        };
    }

    #[Route('/settings/firm/mail/test', name: 'app_firm_mail_test', methods: ['POST'])]
    public function test(Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->firm();
        $this->checkToken($request, self::TEST_TOKEN);
        $back = $this->redirectToRoute('app_firm_mail', ['tab' => self::SERVER]);

        $server = $this->servers->get();
        $recipient = $this->getUser()?->getUserIdentifier() ?? '';
        if (!$server instanceof FirmMailServer || false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', $this->translator->trans('firm.mail.flash.test_unavailable'));

            return $back;
        }
        if (!$this->testLimiter->create($this->actor->userId() ?? $request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('firm.mail.flash.test_limited'));

            return $back;
        }

        try {
            $this->gateway->send($this->testMessage($server, $recipient), $server);
        } catch (MailRefused $refused) {
            $at = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->transaction->run(static fn () => $server->markTestFailed($at, $refused->safeReason, $refused->settingsProblem));
            $this->addFlash('error', $this->translator->trans('firm.mail.flash.test_refused', ['reason' => $refused->safeReason]));

            return $back;
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->transaction->run(static fn () => $server->markVerified($now));
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.test_sent', ['email' => $recipient]));

        return $back;
    }

    private function saveReminders(Request $request): Response
    {
        $this->checkToken($request, self::REMINDERS_TOKEN);
        $home = $this->homeCurrency() ?? throw new BadRequestHttpException('This firm has no reminders.');
        $reminders = FirmMailSubmission::reminders($request, $home, $request->getLocale());
        $values = $reminders->values;
        $errors = $reminders->errors;
        $stored = $this->reminders->get();
        $enabled = '1' === $values['enabled'];
        if ($enabled && !$stored->enabled() && !$this->reminders->canSwitchOn()) {
            $errors['reminders-enabled'] = ['reminder.error.gate'];
        }
        if ([] !== $errors) {
            return $this->page(self::TEMPLATES, $values, $this->storedServer(), $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $first = (int) $values['firstAfterDays'];
        $every = (int) $values['everyDays'];
        $minimum = $reminders->minimum;
        $advances = '1' === $values['advances'];
        $today = $this->preview->today();
        $this->transaction->run(function () use ($stored, $enabled, $first, $every, $minimum, $today, $advances): void {
            if ($this->changes($stored, $enabled, $first, $every, $minimum) || $stored->remindsAdvances() !== $advances) {
                $stored->change($enabled, $first, $every, $minimum, $today);
                $stored->remindAdvances($advances);
                $this->reminders->save($stored);
            }
        });
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.reminders_saved'));

        return $this->redirectToRoute('app_firm_mail');
    }

    /**
     * Whether the card asks for anything else than what applies, so a firm that
     * never touched reminders gets no row.
     */
    private function changes(FirmReminders $stored, bool $enabled, int $first, int $every, ?Money $minimum): bool
    {
        $current = $stored->minimum();

        return $stored->enabled() !== $enabled
            || $stored->firstAfterDays() !== $first
            || $stored->everyDays() !== $every
            || ($current instanceof Money) !== ($minimum instanceof Money)
            || ($current instanceof Money && $minimum instanceof Money && !$current->equals($minimum));
    }

    private function saveServer(Request $request): Response
    {
        $this->checkToken($request, self::SERVER_TOKEN);
        $stored = $this->servers->get();
        $submission = FirmMailSubmission::server($request, $stored instanceof FirmMailServer);
        $errors = $submission->errors;
        if (!$this->secrets->isConfigured()) {
            $errors = ['form' => ['firm.mail.error.no_key']] + $errors;
        }
        if ('' === $submission->password && $stored instanceof FirmMailServer && !isset($errors['host']) && !isset($errors['port']) && !isset($errors['encryption'])
            && $stored->needsSecretFor($submission->values['host'], (int) $submission->values['port'], MailEncryption::from($submission->values['encryption']), $submission->values['username'])) {
            // The stored password must not go to another server or account.
            $errors['password'] = ['firm.mail.error.password_again'];
        }
        if (!isset($errors['host'])) {
            try {
                $this->hosts->vet($submission->values['host']);
            } catch (MailRefused $refused) {
                // Not allowed for good, or a name that does not resolve now.
                $errors['host'] = [$refused->permanent ? 'firm.mail.error.host_refused' : 'firm.mail.error.host_unresolved'];
            }
        }
        if ([] !== $errors) {
            return $this->page(self::SERVER, $this->storedReminders(), $submission->values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $submission->values;
        $sealed = '' === $submission->password ? null : $this->secrets->seal($submission->password);
        $encryption = MailEncryption::from($values['encryption']);
        try {
            $this->transaction->run(function () use ($stored, $values, $sealed, $encryption): void {
                if ($stored instanceof FirmMailServer) {
                    $stored->change($values['host'], (int) $values['port'], $encryption, $values['username'], $sealed, $values['fromAddress'], $values['fromName']);

                    return;
                }
                $this->servers->save(FirmMailServer::define($values['host'], (int) $values['port'], $encryption, $values['username'], $sealed ?? '', $values['fromAddress'], $values['fromName']));
            });
        } catch (InvalidMailServer) {
            return $this->page(self::SERVER, $this->storedReminders(), $values, ['host' => ['firm.mail.error.host']], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the first server meanwhile.
            return $this->page(self::SERVER, $this->storedReminders(), $values, ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.server_saved'));

        return $this->redirectToRoute('app_firm_mail', ['tab' => self::SERVER]);
    }

    private function testMessage(FirmMailServer $server, string $recipient): MailMessage
    {
        $from = $server->fromAddress();
        $text = $this->translator->trans('firm.mail.test.body', ['host' => $server->host(), 'port' => (string) $server->port()]);

        return new MailMessage(
            $from,
            $server->fromName(),
            null,
            [$recipient],
            [],
            $this->translator->trans('firm.mail.test.subject', ['firm' => $this->firm()->name()]),
            $text,
            // Sent as the templates are: Markdown, the values in it as text.
            $this->markdown->toMailHtml(MailMarkdown::escape($text, true)),
            \sprintf('<test.%s@%s>', Id::generate(), substr($from, (int) strrpos($from, '@') + 1)),
        );
    }

    /**
     * @param array<string, string>                                      $reminders the reminders card
     * @param array<string, string>                                      $server
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function page(string $tab, array $reminders, array $server, array $errors, int $status): Response
    {
        $firm = $this->firm();
        $stored = $this->servers->get();
        $url = $this->generateUrl('app_firm_mail');
        $settings = $this->reminders->get();
        $home = $this->homeCurrency();

        return $this->render('@Invoicing/firm/mail.html.twig', [
            'firm' => $firm,
            'tab' => $tab,
            'tabs' => [
                ['label' => $this->translator->trans('firm.mail.tab.templates'), 'href' => $url, 'active' => self::TEMPLATES === $tab],
                ['label' => $this->translator->trans('firm.mail.tab.server'), 'href' => $url.'?tab='.self::SERVER, 'active' => self::SERVER === $tab],
            ],
            // Each kind with, per language, whether the firm wrote its own text.
            'templates' => array_map(fn (MailTemplateKind $kind): array => [
                'kind' => $kind->value,
                'title' => FirmMailTemplateController::KINDS[$kind->value]['title'],
                'help' => FirmMailTemplateController::KINDS[$kind->value]['help'],
                'icon' => FirmMailTemplateController::KINDS[$kind->value]['icon'],
                'href' => $this->generateUrl('app_firm_mail_template', ['kind' => $kind->value]),
                'languages' => array_map(fn (DocumentLanguage $language): array => [
                    'code' => $language->value,
                    'label' => $this->translator->trans('firm.mail.language.'.$language->value),
                    'custom' => $this->templates->isCustom($kind, $language),
                ], DocumentLanguage::cases()),
            ], FirmMailTemplateController::ORDER),
            'reminders' => $reminders,
            'remindersCard' => $home instanceof Currency,
            // Closed until payments are recorded; a switch that is on stays usable, so it can be turned off.
            'remindersLocked' => !$settings->enabled() && !$this->reminders->canSwitchOn(),
            'remindersOn' => $settings->enabled(),
            'maxReminders' => FirmReminders::MAX_STEPS,
            'homeCurrency' => $home instanceof Currency ? $home->value : '',
            'tomorrow' => $home instanceof Currency && $settings->enabled() ? array_map($this->previewRow(...), $this->preview->tomorrow()) : [],
            // While off: what switching on today would send over the coming days.
            'planned' => $home instanceof Currency && !$settings->enabled() ? array_map(fn (PlannedReminder $planned): array => $this->previewRow($planned->invoice) + ['on' => $planned->on], $this->preview->ifSwitchedOnToday()) : [],
            'plannedDays' => ReminderPreview::AHEAD_DAYS,
            'server' => $server,
            'hasServer' => $stored instanceof FirmMailServer,
            'verifiedAt' => $stored?->verifiedAt(),
            'lastTestAt' => $stored?->lastTestAt(),
            'lastTestError' => $stored?->lastTestError(),
            'keyConfigured' => $this->secrets->isConfigured(),
            'ports' => array_combine(array_map(strval(...), FirmMailServer::PORTS), array_map(strval(...), FirmMailServer::PORTS)),
            'encryptions' => array_combine(
                array_map(static fn (MailEncryption $encryption): string => $encryption->value, MailEncryption::cases()),
                array_map(fn (MailEncryption $encryption): string => $this->translator->trans('firm.mail.encryption.'.$encryption->value), MailEncryption::cases()),
            ),
            // The port each encryption usually runs on, preset when the encryption changes.
            'encryptionPorts' => [MailEncryption::Starttls->value => '587', MailEncryption::Tls->value => '465'],
            'recipient' => $this->getUser()?->getUserIdentifier() ?? '',
            'errors' => array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors),
            'action' => $url,
            'testAction' => $this->generateUrl('app_firm_mail_test'),
            'cancel' => $this->generateUrl('app_firm'),
        ], new Response(status: $status));
    }

    /**
     * The amount is what is left to pay, as the reminder asks for it.
     *
     * @return array{number: string, customer: string, dueDate: \DateTimeImmutable, amount: string, href: string}
     */
    private function previewRow(InvoiceRow $row): array
    {
        return [
            'number' => $row->number,
            'customer' => $row->customerName,
            'dueDate' => $row->dueDate,
            'amount' => $this->amounts->shown($row->payable->minus($row->paid)),
            'href' => $this->generateUrl('app_invoice_show', ['id' => $row->id]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function storedReminders(): array
    {
        $settings = $this->reminders->get();
        $minimum = $settings->minimum();

        return [
            'enabled' => $settings->enabled() ? '1' : '',
            'firstAfterDays' => (string) $settings->firstAfterDays(),
            'everyDays' => (string) $settings->everyDays(),
            'minimum' => $minimum instanceof Money ? $this->amounts->plain($minimum) : '',
            'advances' => $settings->remindsAdvances() ? '1' : '',
        ];
    }

    /**
     * Null for a firm in a country without invoicing rules.
     */
    private function homeCurrency(): ?Currency
    {
        try {
            return $this->countries->for($this->firm()->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    /**
     * Never the password: the form shows it blank and keeps the stored one.
     *
     * @return array<string, string>
     */
    private function storedServer(): array
    {
        $server = $this->servers->get();
        if (!$server instanceof FirmMailServer) {
            return ['host' => '', 'port' => '587', 'encryption' => MailEncryption::Starttls->value, 'username' => '', 'fromAddress' => '', 'fromName' => $this->firm()->name()];
        }

        return [
            'host' => $server->host(),
            'port' => (string) $server->port(),
            'encryption' => $server->encryption()->value,
            'username' => $server->username(),
            'fromAddress' => $server->fromAddress(),
            'fromName' => $server->fromName(),
        ];
    }

    /**
     * The firm of this instance; before the first-run setup there is none to set up.
     */
    private function firm(): LegalEntity
    {
        return $this->instanceFirm->find() ?? throw new NotFoundHttpException();
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages firms.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
