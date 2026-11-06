<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailHostPolicy;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\MailVariables;
use App\Invoicing\Application\PlannedReminder;
use App\Invoicing\Application\ReminderPreview;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Application\UnknownMailVariables;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\InvalidMailServer;
use App\Invoicing\Domain\InvalidMailTemplate;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\MailTemplateKind;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Form\FirmMailSubmission;
use App\Invoicing\Infrastructure\Form\MailTokenLabels;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail settings of a firm, for the owner: the texts its documents and its
 * reminders are sent with, when reminders go, and its own mail server. Two tabs of
 * one page, each a form of its own. A firm in a country without invoicing rules
 * has no home currency to set a minimum in, so its page has no reminders card and
 * its stored reminder settings stay as they are. The
 * password is never sent back to the browser; a blank one keeps what is stored. A
 * firm of another tenant is never found, so it answers 404.
 */
final class FirmMailController extends AbstractController
{
    public const string TEMPLATES_TOKEN = 'firm_mail_templates';
    public const string SERVER_TOKEN = 'firm_mail_server';
    public const string TEST_TOKEN = 'firm_mail_test';
    private const string TEMPLATES = 'templates';
    private const string SERVER = 'server';
    /** The card of each kind of template: its title, help and icon, in the order of MailTemplateKind. */
    private const array CARDS = [
        'invoice' => ['title' => 'firm.mail.card.invoice', 'help' => 'firm.mail.templates_help', 'icon' => 'receipt-text'],
        'reminder' => ['title' => 'reminder.card.template', 'help' => 'reminder.templates_help', 'icon' => 'clock-3'],
        'advance' => ['title' => 'advance.card.template', 'help' => 'advance.templates_help', 'icon' => 'banknote'],
        'tax_document' => ['title' => 'tax_document.card.template', 'help' => 'tax_document.templates_help', 'icon' => 'file-signature'],
        'correction' => ['title' => 'correction.mail.card', 'help' => 'correction.mail.templates_help', 'icon' => 'file-pen-line'],
    ];

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly MailServers $servers,
        private readonly MailSecrets $secrets,
        private readonly MailHostPolicy $hosts,
        private readonly MailTemplates $templates,
        private readonly MailTokenLabels $labels,
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
        #[Autowire(service: 'limiter.mail_test')]
        private readonly RateLimiterFactory $testLimiter,
    ) {
    }

    #[Route('/settings/firms/{id}/mail', name: 'app_firm_mail', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function settings(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        if (!$request->isMethod('POST')) {
            $tab = self::SERVER === $request->query->getString('tab') ? self::SERVER : self::TEMPLATES;

            return $this->page($firm, $tab, $this->storedTemplates($firm), $this->storedReminders($firm), $this->storedServer($firm), [], Response::HTTP_OK);
        }

        return match ($request->request->getString('section')) {
            self::TEMPLATES => $this->saveTemplates($firm, $request),
            self::SERVER => $this->saveServer($firm, $request),
            default => throw new BadRequestHttpException('Unknown section.'),
        };
    }

    #[Route('/settings/firms/{id}/mail/test', name: 'app_firm_mail_test', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function test(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $this->checkToken($request, self::TEST_TOKEN);
        $back = $this->redirectToRoute('app_firm_mail', ['id' => $firm->id(), 'tab' => self::SERVER]);

        $server = $this->servers->for($firm);
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

    private function saveTemplates(LegalEntity $firm, Request $request): Response
    {
        $this->checkToken($request, self::TEMPLATES_TOKEN);
        $reset = $request->request->getString('reset');
        if ('' !== $reset) {
            $language = DocumentLanguage::tryFrom($reset) ?? throw new BadRequestHttpException('Unknown language.');
            $kind = MailTemplateKind::tryFrom($request->request->getString('kind', MailTemplateKind::Invoice->value)) ?? throw new BadRequestHttpException('Unknown kind.');
            $this->transaction->run(fn () => $this->templates->reset($firm, $kind, $language));
            $this->addFlash('success', $this->translator->trans('firm.mail.flash.reset'));

            return $this->redirectToRoute('app_firm_mail', ['id' => $firm->id()]);
        }

        $submissions = [];
        $errors = [];
        foreach (MailTemplateKind::cases() as $kind) {
            $submissions[$kind->value] = FirmMailSubmission::templates($request, $this->labels, $kind);
            $errors += $submissions[$kind->value]->errors;
        }
        $home = $this->homeCurrency($firm);
        $reminders = $home instanceof Currency ? FirmMailSubmission::reminders($request, $home, $request->getLocale()) : null;
        $values = $reminders instanceof FirmMailSubmission ? $reminders->values : $this->storedReminders($firm);
        $errors += $reminders->errors ?? [];
        $stored = $this->reminders->for($firm);
        $enabled = '1' === $values['enabled'];
        if ($reminders instanceof FirmMailSubmission && $enabled && !$stored->enabled() && !$this->reminders->canSwitchOn()) {
            $errors['reminders-enabled'] = ['reminder.error.gate'];
        }
        $typed = array_map(static fn (FirmMailSubmission $submission): array => $submission->typed, $submissions);
        if ([] !== $errors) {
            return $this->page($firm, self::TEMPLATES, $typed, $values, $this->storedServer($firm), $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $texts = array_map(static fn (FirmMailSubmission $submission): array => $submission->texts, $submissions);
        $first = (int) $values['firstAfterDays'];
        $every = (int) $values['everyDays'];
        $minimum = $reminders?->minimum;
        $advances = '1' === ($values['advances'] ?? '');
        $card = $reminders instanceof FirmMailSubmission;
        $today = $this->preview->today();
        try {
            $this->transaction->run(function () use ($firm, $texts, $stored, $card, $enabled, $first, $every, $minimum, $today, $advances): void {
                foreach (MailTemplateKind::cases() as $kind) {
                    foreach (DocumentLanguage::cases() as $language) {
                        $text = $texts[$kind->value][$language->value];
                        $this->storeTemplate($firm, $kind, $language, $text['subject'], $text['body']);
                    }
                }
                if ($card && ($this->changes($stored, $enabled, $first, $every, $minimum) || $stored->remindsAdvances() !== $advances)) {
                    $stored->change($enabled, $first, $every, $minimum, $today);
                    $stored->remindAdvances($advances);
                    $this->reminders->save($stored);
                }
            });
        } catch (UnknownMailVariables) {
            // The form checks the tokens first; this is the store's own check agreeing.
            return $this->page($firm, self::TEMPLATES, $typed, $values, $this->storedServer($firm), ['form' => ['firm.mail.error.unknown_token']], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidMailTemplate|UniqueConstraintViolationException) {
            // A control character the form let through, or another request storing the same text meanwhile.
            return $this->page($firm, self::TEMPLATES, $typed, $values, $this->storedServer($firm), ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.saved'));

        return $this->redirectToRoute('app_firm_mail', ['id' => $firm->id()]);
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

    /**
     * The built-in text is not stored as a copy, so it follows the translations.
     */
    private function storeTemplate(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void
    {
        $builtIn = $this->templates->builtIn($kind, $language);
        if (FirmMailSubmission::sameText($builtIn, $subject, $body)) {
            $this->templates->reset($firm, $kind, $language);

            return;
        }
        $this->templates->save($firm, $kind, $language, $subject, $body);
    }

    private function saveServer(LegalEntity $firm, Request $request): Response
    {
        $this->checkToken($request, self::SERVER_TOKEN);
        $stored = $this->servers->for($firm);
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
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $this->storedReminders($firm), $submission->values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $submission->values;
        $sealed = '' === $submission->password ? null : $this->secrets->seal($submission->password);
        $encryption = MailEncryption::from($values['encryption']);
        try {
            $this->transaction->run(function () use ($firm, $stored, $values, $sealed, $encryption): void {
                if ($stored instanceof FirmMailServer) {
                    $stored->change($values['host'], (int) $values['port'], $encryption, $values['username'], $sealed, $values['fromAddress'], $values['fromName']);

                    return;
                }
                $this->servers->save(FirmMailServer::define($firm, $values['host'], (int) $values['port'], $encryption, $values['username'], $sealed ?? '', $values['fromAddress'], $values['fromName']));
            });
        } catch (InvalidMailServer) {
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $this->storedReminders($firm), $values, ['host' => ['firm.mail.error.host']], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the first server of this firm meanwhile.
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $this->storedReminders($firm), $values, ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.server_saved'));

        return $this->redirectToRoute('app_firm_mail', ['id' => $firm->id(), 'tab' => self::SERVER]);
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
            $this->translator->trans('firm.mail.test.subject', ['firm' => $server->firm()->name()]),
            $text,
            '<p>'.htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8').'</p>',
            \sprintf('<test.%s@%s>', Id::generate(), substr($from, (int) strrpos($from, '@') + 1)),
        );
    }

    /**
     * @param array<string, array<string, array{subject: string, body: string}>> $templates kind => language => text
     * @param array<string, string>                                              $reminders the reminders card
     * @param array<string, string>                                              $server
     * @param array<string, array{0: string, 1?: array<string, string>}>         $errors
     */
    private function page(LegalEntity $firm, string $tab, array $templates, array $reminders, array $server, array $errors, int $status): Response
    {
        $stored = $this->servers->for($firm);
        $url = $this->generateUrl('app_firm_mail', ['id' => $firm->id()]);
        $settings = $this->reminders->for($firm);
        $home = $this->homeCurrency($firm);
        $kinds = [];
        foreach (MailTemplateKind::cases() as $kind) {
            $kinds[$kind->value] = array_map(fn (DocumentLanguage $language): array => [
                'code' => $language->value,
                'label' => $this->translator->trans('firm.mail.language.'.$language->value),
                'custom' => $this->templates->isCustom($firm, $kind, $language),
                'variables' => array_map(static fn (string $label): array => ['token' => '{'.$label.'}', 'label' => $label], array_values($this->labels->labels($language, $kind))),
                'link' => $this->labels->token(MailVariables::LINK, $language, $kind),
            ], DocumentLanguage::cases());
        }

        return $this->render('invoicing/firm/mail.html.twig', [
            'firm' => $firm,
            'tab' => $tab,
            'tabs' => [
                ['label' => $this->translator->trans('firm.mail.tab.templates'), 'href' => $url, 'active' => self::TEMPLATES === $tab],
                ['label' => $this->translator->trans('firm.mail.tab.server'), 'href' => $url.'?tab='.self::SERVER, 'active' => self::SERVER === $tab],
            ],
            'cards' => array_map(static fn (MailTemplateKind $kind): array => [
                'kind' => $kind->value,
                'title' => self::CARDS[$kind->value]['title'],
                'help' => self::CARDS[$kind->value]['help'],
                'icon' => self::CARDS[$kind->value]['icon'],
                'languages' => $kinds[$kind->value],
                'templates' => $templates[$kind->value],
            ], MailTemplateKind::cases()),
            'reminders' => $reminders,
            'remindersCard' => $home instanceof Currency,
            // Closed until payments are recorded; a switch that is on stays usable, so it can be turned off.
            'remindersLocked' => !$settings->enabled() && !$this->reminders->canSwitchOn(),
            'remindersOn' => $settings->enabled(),
            'maxReminders' => FirmReminders::MAX_STEPS,
            'homeCurrency' => $home instanceof Currency ? $home->value : '',
            'tomorrow' => $home instanceof Currency && $settings->enabled() ? array_map($this->previewRow(...), $this->preview->tomorrow($firm)) : [],
            // While off: what switching on today would send over the coming days.
            'planned' => $home instanceof Currency && !$settings->enabled() ? array_map(fn (PlannedReminder $planned): array => $this->previewRow($planned->invoice) + ['on' => $planned->on], $this->preview->ifSwitchedOnToday($firm)) : [],
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
            'testAction' => $this->generateUrl('app_firm_mail_test', ['id' => $firm->id()]),
            'cancel' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
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
            'href' => $this->generateUrl('app_invoice_edit', ['id' => $row->id]),
        ];
    }

    /**
     * In the editor's form, with readable labels for the tokens.
     *
     * @return array<string, array<string, array{subject: string, body: string}>> kind => language => text
     */
    private function storedTemplates(LegalEntity $firm): array
    {
        $texts = [];
        foreach (MailTemplateKind::cases() as $kind) {
            foreach (DocumentLanguage::cases() as $language) {
                $text = $this->templates->textFor($firm, $kind, $language);
                $texts[$kind->value][$language->value] = ['subject' => $this->labels->toEditor($text->subject, $language, $kind), 'body' => $this->labels->toEditor($text->body, $language, $kind)];
            }
        }

        return $texts;
    }

    /**
     * @return array<string, string>
     */
    private function storedReminders(LegalEntity $firm): array
    {
        $settings = $this->reminders->for($firm);
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
    private function homeCurrency(LegalEntity $firm): ?Currency
    {
        try {
            return $this->countries->for($firm->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    /**
     * Never the password: the form shows it blank and keeps the stored one.
     *
     * @return array<string, string>
     */
    private function storedServer(LegalEntity $firm): array
    {
        $server = $this->servers->for($firm);
        if (!$server instanceof FirmMailServer) {
            return ['host' => '', 'port' => '587', 'encryption' => MailEncryption::Starttls->value, 'username' => '', 'fromAddress' => '', 'fromName' => $firm->name()];
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

    private function firm(string $id): LegalEntity
    {
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
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
