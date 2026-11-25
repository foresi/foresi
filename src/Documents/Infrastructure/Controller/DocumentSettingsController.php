<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Controller;

use Foresi\Documents\Application\Api\ArchiveDocumentTemplateInput;
use Foresi\Documents\Application\Api\ArchiveDocumentTypeInput;
use Foresi\Documents\Application\Api\CreateDocumentTemplateInput;
use Foresi\Documents\Application\Api\CreateDocumentTypeInput;
use Foresi\Documents\Application\Api\SaveTemplateVersionInput;
use Foresi\Documents\Application\Api\UpdateDocumentTypeInput;
use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\DocumentTypeWrites;
use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\TemplateWrites;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Domain\PaperSize;
use Foresi\Documents\Domain\SignatureLevel;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Documents\Infrastructure\Screen\DocumentScreen;
use Foresi\Documents\Infrastructure\Screen\TemplatePreview;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Ui\Contract\Opens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Documents: the document types (created and changed in a dialog,
 * archived after asking) and the templates. A template is written in its own
 * editor page: the HTML source with the variables beside it, as PandaDoc,
 * HubSpot and Pipedrive Smart Docs list the merge fields next to the text, a
 * click putting the placeholder at the cursor, and a preview filled with the
 * variables' examples. Saving changes the latest version until a document is
 * generated from it, and makes the next version after that, as Odoo opens a
 * used template read-only and edits a copy. Only staff see these pages.
 */
final class DocumentSettingsController extends AbstractController
{
    public const string ROUTE = 'app_document_settings';
    public const string TEMPLATE_ROUTE = 'app_document_template';

    public function __construct(
        private readonly DocumentsAccess $access,
        private readonly DocumentsStore $store,
        private readonly DocumentTypeWrites $types,
        private readonly TemplateWrites $templates,
        private readonly DocumentVariables $variables,
        private readonly DocumentScreen $screen,
        private readonly TemplatePreview $preview,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/documents', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessStaff();
        $types = $this->store->types(true);
        $names = [];
        foreach ($types as $type) {
            $names[$type->id()] = $type->name();
        }

        return $this->render('@Documents/settings/index.html.twig', [
            'typeColumns' => [
                ['key' => 'name', 'label' => $this->trans('documents.settings.column.name'), 'truncate' => true],
                ['key' => 'code', 'label' => $this->trans('documents.settings.column.code'), 'phone' => false],
                ['key' => 'signing', 'label' => $this->trans('documents.settings.column.signing')],
            ],
            'typeRows' => array_map($this->typeRow(...), $types),
            'templateColumns' => [
                ['key' => 'name', 'label' => $this->trans('documents.settings.column.name'), 'truncate' => true],
                ['key' => 'type', 'label' => $this->trans('documents.settings.column.type'), 'phone' => false],
                ['key' => 'version', 'label' => $this->trans('documents.settings.column.version')],
            ],
            'templateRows' => array_map(fn (DocumentTemplate $template): array => $this->templateRow($template, $names), $this->store->templates(null, true)),
            'hasTypes' => [] !== $this->screen->typeOptions(),
        ]);
    }

    #[Route('/settings/documents/types/new', name: 'app_document_type_new', methods: ['GET', 'POST'])]
    public function newType(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->typeForm($request, null, ['name' => '', 'code' => '', 'needsSigning' => '1', 'signatureLevel' => SignatureLevel::Simple->value]);
    }

    #[Route('/settings/documents/types/{id}/edit', name: 'app_document_type_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editType(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $type = $this->store->type($id) ?? throw new NotFoundHttpException();

        return $this->typeForm($request, $type, ['name' => $type->name(), 'code' => $type->code(), 'needsSigning' => $type->needsSigning() ? '1' : '', 'signatureLevel' => $type->signatureLevel()->value]);
    }

    #[Route('/settings/documents/types/{id}/archive', name: 'app_document_type_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveType(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $type = $this->store->type($id) ?? throw new NotFoundHttpException();
        try {
            $this->types->archive(new ArchiveDocumentTypeInput($id));
            $this->addFlash('success', $this->trans('documents.settings.flash.type_archived', ['name' => $type->name()]));
        } catch (DocumentsRefused $refused) {
            $this->refused($refused);
        }

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * A new template in a dialog: its type and name. It starts with a heading
     * and opens in the editor.
     */
    #[Route('/settings/documents/templates/new', name: 'app_document_template_new', methods: ['GET', 'POST'])]
    public function newTemplate(Request $request): Response
    {
        $this->denyUnlessStaff();
        $types = $this->screen->typeOptions();
        $values = ['typeId' => array_key_first($types) ?? '', 'name' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['typeId' => trim($request->request->getString('typeId')), 'name' => trim($request->request->getString('name'))];
            try {
                $version = $this->templates->create(new CreateDocumentTemplateInput($values['typeId'], $values['name'], '<h1>'.htmlspecialchars('' === $values['name'] ? '-' : $values['name'], \ENT_QUOTES | \ENT_HTML5).'</h1>'."\n<p></p>\n"));
                $this->addFlash('success', $this->trans('documents.settings.flash.template_created', ['name' => $values['name']]));

                return $this->redirectToRoute(self::TEMPLATE_ROUTE, ['id' => $version->templateId()]);
            } catch (DocumentsRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = [\in_array($refused->field(), ['typeId', 'name'], true) ? $refused->field() : 'name' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Documents/settings/template_new.html.twig', ['values' => $values, 'errors' => $errors, 'types' => $types], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The template editor: the HTML source, the paper and its margins, the
     * variables to insert and the preview. Saving keeps the version number
     * until the version is frozen, then makes the next one.
     */
    #[Route('/settings/documents/templates/{id}', name: self::TEMPLATE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function template(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $template = $this->store->template($id) ?? throw new NotFoundHttpException();
        $version = $this->store->latestVersion($id) ?? throw new NotFoundHttpException();
        $page = $version->page();
        $values = [
            'body' => $version->body(),
            'paper' => $page->paper->value,
            'landscape' => $page->landscape ? '1' : '',
            'marginTop' => $this->mm($page->marginTopMm),
            'marginRight' => $this->mm($page->marginRightMm),
            'marginBottom' => $this->mm($page->marginBottomMm),
            'marginLeft' => $this->mm($page->marginLeftMm),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->postedTemplate($request);
            $errors = $this->marginErrors($values);
            if ([] === $errors) {
                try {
                    $saved = $this->templates->saveVersion(new SaveTemplateVersionInput($id, $values['body'], $values['paper'], '1' === $values['landscape'], (int) $values['marginTop'], (int) $values['marginRight'], (int) $values['marginBottom'], (int) $values['marginLeft']));
                    $this->addFlash('success', $this->trans('documents.settings.flash.template_saved', ['number' => $saved->number()]));

                    return $this->redirectToRoute(self::TEMPLATE_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
                } catch (DocumentsRefused $refused) {
                    $this->denyIfForbidden($refused);
                    $field = $refused->field();
                    $errors = [\in_array($field, ['body', 'paper', 'marginTop'], true) ? $field : 'body' => $this->screen->refusal($refused)];
                }
            }
        }
        $page = [] === $errors ? $this->pageOf($values) : $version->page();

        return $this->render('@Documents/settings/template.html.twig', [
            'template' => $template,
            'type' => $this->store->type($template->typeId()),
            'version' => $version,
            'values' => $values,
            'errors' => $errors,
            'variables' => $this->variableList(),
            'preview' => $this->preview->preview($values['body'], $this->variables->printable($this->variables->examples(), preview: true), $page),
            'papers' => [PaperSize::A4->value => $this->trans('documents.settings.paper.a4'), PaperSize::Letter->value => $this->trans('documents.settings.paper.letter')],
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The preview of the source being written, filled with the examples, for
     * the editor's frame: the page's HTML, or the refusal as a sentence.
     */
    #[Route('/settings/documents/templates/{id}/preview', name: 'app_document_template_preview', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function preview(string $id, Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $this->store->template($id) ?? throw new NotFoundHttpException();
        $values = $this->postedTemplate($request);
        $page = [] === $this->marginErrors($values) ? $this->pageOf($values) : new PageSettings();

        return $this->json($this->preview->preview($values['body'], $this->variables->printable($this->variables->examples(), preview: true), $page));
    }

    #[Route('/settings/documents/templates/{id}/archive', name: 'app_document_template_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveTemplate(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $template = $this->store->template($id) ?? throw new NotFoundHttpException();
        try {
            $this->templates->archive(new ArchiveDocumentTemplateInput($id));
            $this->addFlash('success', $this->trans('documents.settings.flash.template_archived', ['name' => $template->name()]));
        } catch (DocumentsRefused $refused) {
            $this->refused($refused);
        }

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array{name: string, code: string, needsSigning: string, signatureLevel: string} $values
     */
    private function typeForm(Request $request, ?DocumentType $type, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = [
                'name' => trim($request->request->getString('name')),
                // The code never changes once the type exists.
                'code' => $type instanceof DocumentType ? $type->code() : trim($request->request->getString('code')),
                'needsSigning' => '1' === $request->request->getString('needsSigning') ? '1' : '',
                'signatureLevel' => $request->request->getString('signatureLevel'),
            ];
            try {
                if ($type instanceof DocumentType) {
                    $this->types->update(new UpdateDocumentTypeInput($type->id(), $values['name'], '1' === $values['needsSigning'], $values['signatureLevel']));
                } else {
                    $this->types->create(new CreateDocumentTypeInput($values['name'], $values['code'], '1' === $values['needsSigning'], $values['signatureLevel']));
                }
                $this->addFlash('success', $this->trans('documents.settings.flash.type_saved', ['name' => $values['name']]));

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (DocumentsRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = [\in_array($refused->field(), ['name', 'code', 'signatureLevel'], true) ? $refused->field() : 'name' => $this->screen->refusal($refused)];
            }
        }
        $levels = [];
        foreach (SignatureLevel::cases() as $level) {
            $levels[$level->value] = $this->trans('documents.settings.level.'.$level->value);
        }

        return $this->render('@Documents/settings/type.html.twig', [
            'type' => $type,
            'values' => $values,
            'errors' => $errors,
            'levels' => $levels,
            'action' => $type instanceof DocumentType ? $this->generateUrl('app_document_type_edit', ['id' => $type->id()]) : $this->generateUrl('app_document_type_new'),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array<string, mixed> a row of the types' Ui:DataTable
     */
    private function typeRow(DocumentType $type): array
    {
        $edit = $this->generateUrl('app_document_type_edit', ['id' => $type->id()]);
        $signing = $type->needsSigning()
            ? ['label' => $this->trans('documents.settings.level.'.$type->signatureLevel()->value), 'tone' => 'gray']
            : ['label' => $this->trans('documents.settings.not_signed'), 'tone' => 'outline'];
        $badges = [$signing];
        if ($type->isArchived()) {
            $badges[] = ['label' => $this->trans('documents.settings.archived'), 'tone' => 'gray'];
        }

        return [
            'id' => $type->id(),
            'href' => $edit,
            // A type is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'label' => $type->name(),
            'cells' => ['name' => $type->name(), 'code' => $type->code(), 'signing' => ['badges' => $badges]],
            'actions' => $type->isArchived() ? [] : [
                ['label' => $this->trans('documents.settings.action.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
                ['label' => $this->trans('documents.settings.action.archive'), 'href' => $this->generateUrl('app_document_type_archive', ['id' => $type->id()]), 'method' => 'POST',
                    'confirm' => ['title' => $this->trans('documents.settings.confirm.archive_type', ['name' => $type->name()]), 'text' => $this->trans('documents.settings.confirm.archive_type_text'), 'accept' => $this->trans('documents.settings.action.archive'), 'cancel' => $this->trans('documents.keep'), 'tone' => 'danger']],
            ],
        ];
    }

    /**
     * @param array<string, string> $typeNames
     *
     * @return array<string, mixed> a row of the templates' Ui:DataTable
     */
    private function templateRow(DocumentTemplate $template, array $typeNames): array
    {
        $version = $this->store->latestVersion($template->id());
        $editor = $this->generateUrl(self::TEMPLATE_ROUTE, ['id' => $template->id()]);
        $badges = [];
        if ($version instanceof TemplateVersion) {
            $badges[] = ['label' => $this->trans('documents.settings.version', ['number' => $version->number()]), 'tone' => $version->isFrozen() ? 'green' : 'gray'];
        }
        if ($template->isArchived()) {
            $badges[] = ['label' => $this->trans('documents.settings.archived'), 'tone' => 'gray'];
        }

        return [
            'id' => $template->id(),
            'href' => $editor,
            // The editor is a place to work in, a page of its own (docs/design/overlays.md).
            'opens' => Opens::PAGE,
            'label' => $template->name(),
            'cells' => ['name' => $template->name(), 'type' => $typeNames[$template->typeId()] ?? '', 'version' => ['badges' => $badges]],
            'actions' => $template->isArchived() ? [] : [
                ['label' => $this->trans('documents.settings.action.edit_template'), 'href' => $editor, 'icon' => 'pencil'],
                ['label' => $this->trans('documents.settings.action.archive'), 'href' => $this->generateUrl('app_document_template_archive', ['id' => $template->id()]), 'method' => 'POST',
                    'confirm' => ['title' => $this->trans('documents.settings.confirm.archive_template', ['name' => $template->name()]), 'text' => $this->trans('documents.settings.confirm.archive_template_text'), 'accept' => $this->trans('documents.settings.action.archive'), 'cancel' => $this->trans('documents.keep'), 'tone' => 'danger']],
            ],
        ];
    }

    /**
     * The variables the modules offer, by prefix, each with the placeholder a
     * click inserts: {{ client.name }}, or for a list a loop over its rows.
     *
     * @return list<array{prefix: string, items: list<array{placeholder: string, label: string, example: string}>}>
     */
    private function variableList(): array
    {
        $groups = [];
        foreach ($this->variables->definitions() as $prefix => $definitions) {
            $items = [];
            foreach ($definitions as $definition) {
                $path = $prefix.'.'.$definition->name;
                if (\is_array($definition->example)) {
                    $first = $definition->example[0] ?? [];
                    $fields = implode(' ', array_map(static fn (string $key): string => '{{ item.'.$key.' }}', array_keys($first)));
                    $items[] = ['placeholder' => '{% for item in '.$path.' %}'.$fields.'{% endfor %}', 'label' => $definition->label, 'example' => implode(', ', array_map(strval(...), array_values($first)))];
                } else {
                    $items[] = ['placeholder' => '{{ '.$path.' }}', 'label' => $definition->label, 'example' => $definition->example];
                }
            }
            $groups[] = ['prefix' => $prefix, 'items' => $items];
        }

        return $groups;
    }

    /**
     * @return array{body: string, paper: string, landscape: string, marginTop: string, marginRight: string, marginBottom: string, marginLeft: string}
     */
    private function postedTemplate(Request $request): array
    {
        $values = ['body' => $request->request->getString('body'), 'paper' => $request->request->getString('paper', PaperSize::A4->value), 'landscape' => '1' === $request->request->getString('landscape') ? '1' : ''];
        foreach (['marginTop', 'marginRight', 'marginBottom', 'marginLeft'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }

        return $values;
    }

    /**
     * Whole millimetres, 0 to 60, refused at their own field.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function marginErrors(array $values): array
    {
        $errors = [];
        foreach (['marginTop', 'marginRight', 'marginBottom', 'marginLeft'] as $field) {
            if (1 !== preg_match('/^\d{1,2}$/', $values[$field]) || (int) $values[$field] > (int) PageSettings::MAX_MARGIN_MM) {
                $errors[$field] = $this->trans('documents.error.invalid.margin');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $values margins already checked
     */
    private function pageOf(array $values): PageSettings
    {
        return new PageSettings(PaperSize::tryFrom($values['paper']) ?? PaperSize::A4, '1' === $values['landscape'], (float) $values['marginTop'], (float) $values['marginRight'], (float) $values['marginBottom'], (float) $values['marginLeft']);
    }

    private function mm(float $value): string
    {
        return (string) (int) round($value);
    }

    private function refused(DocumentsRefused $refused): void
    {
        $this->denyIfForbidden($refused);
        if (RefusalKind::NotFound === $refused->kind()) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $this->addFlash('error', $this->screen->refusal($refused));
    }

    private function denyUnlessStaff(): void
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (DocumentsRefused) {
            throw new AccessDeniedHttpException();
        }
    }

    private function denyIfForbidden(DocumentsRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(DocumentController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
