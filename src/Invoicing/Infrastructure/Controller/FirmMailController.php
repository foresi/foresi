<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailHostPolicy;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailServers;
use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\MailVariables;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\InvalidMailServer;
use App\Invoicing\Domain\InvalidMailTemplate;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\MailTemplateKind;
use App\Invoicing\Infrastructure\Form\FirmMailSubmission;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Id;
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
 * The e-mail settings of a firm, for the owner: the texts its documents are sent
 * with, and its own mail server. Two tabs of one page, each a form of its own. The
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

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly MailServers $servers,
        private readonly MailSecrets $secrets,
        private readonly MailHostPolicy $hosts,
        private readonly MailTemplates $templates,
        private readonly MailVariables $variables,
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

            return $this->page($firm, $tab, $this->storedTemplates($firm), $this->storedServer($firm), [], Response::HTTP_OK);
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
            $this->transaction->run(fn () => $this->templates->reset($firm, MailTemplateKind::Invoice, $language));
            $this->addFlash('success', $this->translator->trans('firm.mail.flash.reset'));

            return $this->redirectToRoute('app_firm_mail', ['id' => $firm->id()]);
        }

        $submission = FirmMailSubmission::templates($request, $this->variables);
        if ([] !== $submission->errors) {
            return $this->page($firm, self::TEMPLATES, $submission->texts, $this->storedServer($firm), $submission->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $submission->texts;
        try {
            $this->transaction->run(function () use ($firm, $values): void {
                foreach (DocumentLanguage::cases() as $language) {
                    $this->storeTemplate($firm, $language, $values[$language->value]['subject'], $values[$language->value]['body']);
                }
            });
        } catch (InvalidMailTemplate|UniqueConstraintViolationException) {
            // A control character the form let through, or another request storing the same text meanwhile.
            return $this->page($firm, self::TEMPLATES, $values, $this->storedServer($firm), ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.saved'));

        return $this->redirectToRoute('app_firm_mail', ['id' => $firm->id()]);
    }

    /**
     * The built-in text is not stored as a copy, so it follows the translations.
     */
    private function storeTemplate(LegalEntity $firm, DocumentLanguage $language, string $subject, string $body): void
    {
        $builtIn = $this->templates->builtIn(MailTemplateKind::Invoice, $language);
        if (FirmMailSubmission::sameText($builtIn, $subject, $body)) {
            $this->templates->reset($firm, MailTemplateKind::Invoice, $language);

            return;
        }
        $this->templates->save($firm, MailTemplateKind::Invoice, $language, $subject, $body);
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
        if (!isset($errors['host']) && !$this->hosts->allows($submission->values['host'])) {
            $errors['host'] = ['firm.mail.error.host_refused'];
        }
        if ([] !== $errors) {
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $submission->values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $values, ['host' => ['firm.mail.error.host']], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the first server of this firm meanwhile.
            return $this->page($firm, self::SERVER, $this->storedTemplates($firm), $values, ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * @param array<string, array{subject: string, body: string}>        $templates language => text
     * @param array<string, string>                                      $server
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function page(LegalEntity $firm, string $tab, array $templates, array $server, array $errors, int $status): Response
    {
        $stored = $this->servers->for($firm);
        $url = $this->generateUrl('app_firm_mail', ['id' => $firm->id()]);

        return $this->render('invoicing/firm/mail.html.twig', [
            'firm' => $firm,
            'tab' => $tab,
            'tabs' => [
                ['label' => $this->translator->trans('firm.mail.tab.templates'), 'href' => $url, 'active' => self::TEMPLATES === $tab],
                ['label' => $this->translator->trans('firm.mail.tab.server'), 'href' => $url.'?tab='.self::SERVER, 'active' => self::SERVER === $tab],
            ],
            'languages' => array_map(fn (DocumentLanguage $language): array => [
                'code' => $language->value,
                'label' => $this->translator->trans('firm.mail.language.'.$language->value),
                'custom' => $this->templates->isCustom($firm, MailTemplateKind::Invoice, $language),
            ], DocumentLanguage::cases()),
            'templates' => $templates,
            'variables' => array_map(fn (string $name): array => ['token' => '{'.$name.'}', 'label' => $this->translator->trans('firm.mail.variable.'.$name)], $this->variables->names()),
            'server' => $server,
            'hasServer' => $stored instanceof FirmMailServer,
            'verifiedAt' => $stored?->verifiedAt(),
            'keyConfigured' => $this->secrets->isConfigured(),
            'ports' => array_combine(array_map(strval(...), FirmMailServer::PORTS), array_map(strval(...), FirmMailServer::PORTS)),
            'encryptions' => array_combine(
                array_map(static fn (MailEncryption $encryption): string => $encryption->value, MailEncryption::cases()),
                array_map(fn (MailEncryption $encryption): string => $this->translator->trans('firm.mail.encryption.'.$encryption->value), MailEncryption::cases()),
            ),
            'recipient' => $this->getUser()?->getUserIdentifier() ?? '',
            'errors' => array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors),
            'action' => $url,
            'testAction' => $this->generateUrl('app_firm_mail_test', ['id' => $firm->id()]),
            'cancel' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
        ], new Response(status: $status));
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    private function storedTemplates(LegalEntity $firm): array
    {
        $texts = [];
        foreach (DocumentLanguage::cases() as $language) {
            $text = $this->templates->textFor($firm, MailTemplateKind::Invoice, $language);
            $texts[$language->value] = ['subject' => $text->subject, 'body' => $text->body];
        }

        return $texts;
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
