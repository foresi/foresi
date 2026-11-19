<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\LinkedMailBody;
use Foresi\Invoicing\Application\MailSamples;
use Foresi\Invoicing\Application\MailTemplates;
use Foresi\Invoicing\Application\MailText;
use Foresi\Invoicing\Application\MailVariables;
use Foresi\Invoicing\Application\UnknownMailVariables;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Domain\InvalidMailTemplate;
use Foresi\Invoicing\Domain\MailTemplateKind;
use Foresi\Invoicing\Infrastructure\Form\FirmMailSubmission;
use Foresi\Invoicing\Infrastructure\Form\MailTokenLabels;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Application\Markdown;
use Foresi\Shared\Domain\Currency;
use Foresi\Ui\Component\MarkdownEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One kind of mail template, for the owner: both document languages in a side
 * sheet over the list of templates (Settings › Firm › E-mails), saved together;
 * "Restore the built-in text" per language. The body is Markdown; its preview
 * fills the variables with made-up values of a document of the firm, so the
 * writer sees the mail as the customer gets it. Without JavaScript the sheet is
 * a page of its own.
 */
final class FirmMailTemplateController extends AbstractController
{
    /** The kinds as the list shows them: the documents first, the reminder last. */
    public const array ORDER = [MailTemplateKind::Invoice, MailTemplateKind::Advance, MailTemplateKind::PaymentTaxDocument, MailTemplateKind::CorrectionDocument, MailTemplateKind::Reminder];
    /** The name, help and icon of each kind. */
    public const array KINDS = [
        'invoice' => ['title' => 'firm.mail.card.invoice', 'help' => 'firm.mail.templates_help', 'icon' => 'receipt-text'],
        'reminder' => ['title' => 'reminder.card.template', 'help' => 'reminder.templates_help', 'icon' => 'clock-3'],
        'advance' => ['title' => 'advance.card.template', 'help' => 'advance.templates_help', 'icon' => 'banknote'],
        'tax_document' => ['title' => 'tax_document.card.template', 'help' => 'tax_document.templates_help', 'icon' => 'file-signature'],
        'correction' => ['title' => 'correction.mail.card', 'help' => 'correction.mail.templates_help', 'icon' => 'file-pen-line'],
    ];
    /** The title the preview gives the document of each kind. */
    private const array DOCUMENT_TYPES = [
        'invoice' => 'invoice.pdf.title',
        'reminder' => 'invoice.pdf.title',
        'advance' => 'invoice.pdf.title_advance',
        'tax_document' => 'invoice.pdf.title_payment_tax_document',
        'correction' => 'invoice.pdf.title_correction_document',
    ];
    /** Where the preview's link leads: nowhere real. */
    private const string SAMPLE_LINK = 'https://example.com/d/sample';

    public function __construct(
        private readonly InstanceFirm $instanceFirm,
        private readonly MailTemplates $templates,
        private readonly MailTokenLabels $labels,
        private readonly MailSamples $samples,
        private readonly InvoicingCountries $countries,
        private readonly Markdown $markdown,
        private readonly TransactionalWork $transaction,
        private readonly OwnerCheck $owner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/firm/mail/templates/{kind}', name: 'app_firm_mail_template', requirements: ['kind' => new EnumRequirement(MailTemplateKind::class)], methods: ['GET', 'POST'])]
    public function edit(MailTemplateKind $kind, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm();
        if (!$request->isMethod('POST')) {
            return $this->sheet($firm, $kind, $this->stored($firm, $kind), [], Response::HTTP_OK);
        }
        $this->checkToken($request, FirmMailController::TEMPLATES_TOKEN);

        $reset = $request->request->getString('reset');
        if ('' !== $reset) {
            $language = DocumentLanguage::tryFrom($reset) ?? throw new BadRequestHttpException('Unknown language.');
            $this->transaction->run(fn () => $this->templates->reset($firm, $kind, $language));
            $this->addFlash('success', $this->translator->trans('firm.mail.flash.reset'));

            return $this->redirectToRoute('app_firm_mail');
        }

        $submission = FirmMailSubmission::templates($request, $this->labels, $kind);
        if ([] !== $submission->errors) {
            return $this->sheet($firm, $kind, $submission->typed, $submission->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->transaction->run(function () use ($firm, $kind, $submission): void {
                foreach (DocumentLanguage::cases() as $language) {
                    $text = $submission->texts[$language->value];
                    $this->store($firm, $kind, $language, $text['subject'], $text['body']);
                }
            });
        } catch (UnknownMailVariables) {
            // The form checks the tokens first; this is the store's own check agreeing.
            return $this->sheet($firm, $kind, $submission->typed, ['form' => ['firm.mail.error.unknown_token']], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidMailTemplate|UniqueConstraintViolationException) {
            // A control character the form let through, or another request storing the same text meanwhile.
            return $this->sheet($firm, $kind, $submission->typed, ['form' => ['firm.mail.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.mail.flash.template_saved', ['name' => $this->translator->trans(self::KINDS[$kind->value]['title'])]));

        return $this->redirectToRoute('app_firm_mail');
    }

    /**
     * The Preview tab of the body: "text" is the body and "subject" the subject,
     * both as typed, with readable labels for the tokens. Answers the subject and
     * the sanitised HTML of the body, its variables filled with made-up values.
     */
    #[Route('/settings/firm/mail/templates/{kind}/preview/{language}', name: 'app_firm_mail_template_preview', requirements: ['kind' => new EnumRequirement(MailTemplateKind::class), 'language' => new EnumRequirement(DocumentLanguage::class)], methods: ['POST'])]
    public function preview(MailTemplateKind $kind, DocumentLanguage $language, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm();
        if (!$this->isCsrfTokenValid(MarkdownEditor::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $values = $this->samples->valuesFor(
            $firm,
            $kind,
            $language,
            $this->currency($firm),
            $this->translator->trans('firm.mail.sample.customer', locale: $language->value),
            $this->translator->trans(self::DOCUMENT_TYPES[$kind->value], locale: $language->value),
        );
        $text = new MailText();
        $subject = $text->render($this->labels->fromEditor($request->request->getString('subject'), $language, $kind)['text'], $values, true);
        $body = $text->renderBody($this->labels->fromEditor(str_replace(["\r\n", "\r"], "\n", $request->request->getString('text')), $language, $kind)['text'], $values);

        return $this->render('@Invoicing/firm/_mail_preview.html.twig', [
            'subject' => $subject,
            'body' => new LinkedMailBody($body, self::SAMPLE_LINK, $this->markdown)->html(),
        ]);
    }

    /**
     * The built-in text is not stored as a copy, so it follows the translations.
     */
    private function store(LegalEntity $firm, MailTemplateKind $kind, DocumentLanguage $language, string $subject, string $body): void
    {
        if (FirmMailSubmission::sameText($this->templates->builtIn($kind, $language), $subject, $body)) {
            $this->templates->reset($firm, $kind, $language);

            return;
        }
        $this->templates->save($firm, $kind, $language, $subject, $body);
    }

    /**
     * @param array<string, array{subject: string, body: string}>        $texts  language => text, with readable labels
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function sheet(LegalEntity $firm, MailTemplateKind $kind, array $texts, array $errors, int $status): Response
    {
        $names = MailTemplateKind::Invoice === $kind ? '' : $kind->value.'_';
        $ids = str_replace('_', '-', $names);
        $languages = [];
        foreach (DocumentLanguage::cases() as $language) {
            $code = $language->value;
            $languages[] = [
                'code' => $code,
                'label' => $this->translator->trans('firm.mail.language.'.$code),
                'custom' => $this->templates->isCustom($firm, $kind, $language),
                'subject' => ['name' => $names.'subject['.$code.']', 'id' => $ids.'subject-'.$code],
                'body' => ['name' => $names.'body['.$code.']', 'id' => $ids.'body-'.$code],
                'text' => $texts[$code],
                'invalid' => isset($errors[$ids.'subject-'.$code]) || isset($errors[$ids.'body-'.$code]),
                'variables' => array_map(static fn (string $label): array => ['token' => '{'.$label.'}', 'label' => $label], array_values($this->labels->labels($language, $kind))),
                'link' => $this->labels->token(MailVariables::LINK, $language, $kind),
                'preview' => $this->generateUrl('app_firm_mail_template_preview', ['kind' => $kind->value, 'language' => $code]),
            ];
        }
        // The first language with a problem is the one that shows.
        $invalid = array_values(array_filter($languages, static fn (array $language): bool => $language['invalid']));

        return $this->render('@Invoicing/firm/mail_template.html.twig', [
            'firm' => $firm,
            'kind' => $kind->value,
            'title' => $this->translator->trans(self::KINDS[$kind->value]['title']),
            'help' => $this->translator->trans(self::KINDS[$kind->value]['help']),
            'languages' => $languages,
            'selected' => [] === $invalid ? $languages[0]['code'] : $invalid[0]['code'],
            'errors' => array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors),
            'action' => $this->generateUrl('app_firm_mail_template', ['kind' => $kind->value]),
            'cancel' => $this->generateUrl('app_firm_mail'),
        ], new Response(status: $status));
    }

    /**
     * In the editor's form, with readable labels for the tokens.
     *
     * @return array<string, array{subject: string, body: string}> language => text
     */
    private function stored(LegalEntity $firm, MailTemplateKind $kind): array
    {
        $texts = [];
        foreach (DocumentLanguage::cases() as $language) {
            $text = $this->templates->textFor($firm, $kind, $language);
            $texts[$language->value] = ['subject' => $this->labels->toEditor($text->subject, $language, $kind), 'body' => $this->labels->toEditor($text->body, $language, $kind)];
        }

        return $texts;
    }

    /**
     * The firm's home currency; a firm in a country without invoicing rules previews in euros.
     */
    private function currency(LegalEntity $firm): Currency
    {
        try {
            return $this->countries->for($firm->address()->countryCode)->homeCurrency();
        } catch (UnsupportedCountry) {
            return Currency::EUR;
        }
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
