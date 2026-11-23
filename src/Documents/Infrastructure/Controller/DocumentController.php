<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Controller;

use Foresi\Documents\Application\Api\AddSignerInput;
use Foresi\Documents\Application\Api\CancelDocumentInput;
use Foresi\Documents\Application\Api\CreateDocumentInput;
use Foresi\Documents\Application\Api\GenerateDocumentAgainInput;
use Foresi\Documents\Application\Api\GenerateDocumentInput;
use Foresi\Documents\Application\Api\MarkDocumentSentInput;
use Foresi\Documents\Application\Api\MarkSignerSignedInput;
use Foresi\Documents\Application\Api\RemoveSignerInput;
use Foresi\Documents\Application\Api\RestoreDocumentInput;
use Foresi\Documents\Application\Api\TrashDocumentInput;
use Foresi\Documents\Application\Api\UpdateSignerInput;
use Foresi\Documents\Application\DocumentFilter;
use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\DocumentWrites;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\SignerSide;
use Foresi\Documents\Infrastructure\Screen\DocumentScreen;
use Foresi\Documents\Infrastructure\Screen\TemplatePreview;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Id;
use Foresi\Ui\View\QuickPins;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Documents under Sales: the list of every document with its type and status
 * filters, "New document" in a dialog that another module opens with a link
 * filled in, and one document as a record (the side sheet over the list, a
 * page at its address). Its header carries what its status allows next, as
 * PandaDoc and DocuSign move a document from draft to sent to completed:
 * Generate, Mark sent, Upload signed copy, mark a signer signed, Cancel and
 * Generate again; a cancelled one goes to the trash, with Undo in the message,
 * and comes back with Restore, as deals do. The status filter's "In the trash"
 * lists the trash. Its signers are grouped by their order, as DocuSign shows a
 * routing order, and are added, changed and removed in dialogs. Only the
 * owners and members of the organisation see them.
 */
final class DocumentController extends AbstractController
{
    public const string TOKEN = 'documents';
    public const string LIST_ROUTE = 'app_documents';
    public const string SHOW_ROUTE = 'app_document';
    public const string NEW_ROUTE = 'app_document_new';
    public const string UNDO_FLASH = 'documents_undo';

    /** The status filter's value for the documents in the trash. */
    private const string TRASHED = 'trashed';

    /** The most documents a list shows; the filters narrow it. */
    private const int SHOWN = 200;

    public function __construct(
        private readonly DocumentsAccess $access,
        private readonly DocumentsStore $store,
        private readonly DocumentWrites $writes,
        private readonly DocumentVariables $variables,
        private readonly LinkTargets $targets,
        private readonly DocumentScreen $screen,
        private readonly TemplatePreview $preview,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/documents', name: self::LIST_ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $type = $request->query->getString('type');
        $trashed = self::TRASHED === $request->query->getString('status');
        $status = DocumentStatus::tryFrom($request->query->getString('status'));
        // The list keeps no view: its quick filters, pinned in the Display sheet, stay in its address.
        $pins = QuickPins::read($request->query->all(), ['type' => $this->translator->trans('documents.filter.type'), 'status' => $this->translator->trans('documents.filter.status')], ['type', 'status']);
        $set = array_filter(['type' => Id::isValid($type) ? $type : null, 'status' => $trashed ? self::TRASHED : $status?->value], static fn (?string $value): bool => null !== $value);
        $documents = $this->store->documents(new DocumentFilter(
            typeId: Id::isValid($type) ? $type : null,
            status: $status,
            limit: self::SHOWN + 1,
            trashed: $trashed,
        ));

        return $this->render('@Documents/index.html.twig', [
            'columns' => $this->screen->columns(),
            'rows' => $this->screen->rows(\array_slice($documents, 0, self::SHOWN)),
            'more' => \count($documents) > self::SHOWN,
            'filters' => [
                ['name' => 'type', 'label' => $this->translator->trans('documents.filter.type'), 'options' => $this->screen->typeFilterOptions(), 'value' => Id::isValid($type) ? $type : ''],
                ['name' => 'status', 'label' => $this->translator->trans('documents.filter.status'), 'options' => $this->screen->statusOptions() + [self::TRASHED => $this->translator->trans('documents.filter.trashed')], 'value' => $trashed ? self::TRASHED : ($status->value ?? '')],
            ],
            'pinned' => $pins->pinned,
            'pinnable' => $pins->pinnable,
            'pinsQuery' => $pins->query,
            'picker' => [
                'keep' => $set,
                'resetHref' => $this->generateUrl(self::LIST_ROUTE, $set),
                'modified' => $pins->modified,
            ],
            'narrowed' => Id::isValid($type) || null !== $status || $trashed,
            'trash' => $trashed,
            'hasTypes' => [] !== $this->screen->typeOptions(),
        ]);
    }

    /**
     * A new document in a dialog: its type, a template of that type, its
     * title, and the link of the record it was opened from
     * (?link_kind=contact&link_id=...), shown and sent along. Further records
     * come as links[]=kind:id, repeated (a deal and its client); a malformed
     * or repeated one is left out.
     */
    #[Route('/documents/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        $source = $request->isMethod('POST') ? $request->request : $request->query;
        [$link, $more] = $this->links($source);
        if (!$request->isMethod('POST')) {
            $typeId = $request->query->getString('type');
            $types = $this->screen->typeOptions();
            $typeId = isset($types[$typeId]) ? $typeId : (array_key_first($types) ?? '');

            return $this->newForm($link, $more, ['typeId' => $typeId, 'templateId' => $this->screen->firstTemplate('' === $typeId ? null : $typeId)?->id() ?? '', 'title' => ''], [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['typeId' => trim($request->request->getString('typeId')), 'templateId' => trim($request->request->getString('templateId')), 'title' => trim($request->request->getString('title'))];
        try {
            $document = $this->writes->create(new CreateDocumentInput($values['typeId'], $values['templateId'], $values['title'], array_map(static fn (RecordLink $link): array => ['kind' => $link->kind, 'id' => $link->id], array_values(array_filter([$link, ...$more])))));
        } catch (DocumentsRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->newForm($link, $more, $values, $this->errors($refused, ['typeId', 'templateId', 'title', 'links'], 'title'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('documents.flash.created', ['title' => $document->title()]));

        return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $document->id()]);
    }

    #[Route('/documents/{id}', name: self::SHOW_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyUnlessStaff();
        $document = $this->document($id);
        $type = $this->store->type($document->typeId());
        $draftPreview = null;
        if ($document->status()->isDraft()) {
            $version = $this->store->latestVersion($document->templateId());
            if ($version instanceof \Foresi\Documents\Domain\TemplateVersion) {
                $links = array_map(static fn (DocumentLink $link): RecordLink => new RecordLink($link->kind(), $link->targetId()), $this->store->links($document));
                $draftPreview = $this->preview->preview($version->body(), $this->variables->printable($this->variables->values($links), preview: true), $version->page());
            }
        }

        return $this->render('@Documents/show.html.twig', [
            'document' => $document,
            'status' => $this->screen->status($document->status()),
            'details' => $this->screen->details($document),
            'links' => $this->screen->links($document),
            'signing' => $type instanceof DocumentType && $type->needsSigning(),
            'groups' => $this->screen->signerGroups($document),
            'pending' => $this->screen->pendingSigners($document),
            'draftPreview' => $draftPreview,
            'final' => $document->status()->isFinal(),
            'trashed' => $document->isTrashed(),
            'signable' => \in_array($document->status(), [DocumentStatus::Ready, DocumentStatus::OutForSignature], true),
        ]);
    }

    #[Route('/documents/{id}/generate', name: 'app_document_generate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function generate(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->generate(new GenerateDocumentInput($id)), 'documents.flash.generated');
    }

    #[Route('/documents/{id}/send', name: 'app_document_send', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function send(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->markSent(new MarkDocumentSentInput($id)), 'documents.flash.sent');
    }

    #[Route('/documents/{id}/cancel', name: 'app_document_cancel', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function cancel(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->cancel(new CancelDocumentInput($id)), 'documents.flash.cancelled');
    }

    /**
     * A cancelled document goes to the trash, and the list says so with Undo, as deals do.
     */
    #[Route('/documents/{id}/trash', name: 'app_document_trash', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function trash(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $this->document($id);
        try {
            $document = $this->writes->trash(new TrashDocumentInput($id));
        } catch (DocumentsRefused $refused) {
            $this->refused($refused);

            return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
        }
        $this->addFlash(self::UNDO_FLASH, [
            'message' => $this->translator->trans('documents.flash.trashed', ['title' => $document->title()]),
            'label' => $this->translator->trans('documents.action.undo'),
            'href' => $this->generateUrl('app_document_restore', ['id' => $id]),
            'token' => self::TOKEN,
        ]);

        return $this->redirectToRoute(self::LIST_ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/documents/{id}/restore', name: 'app_document_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->restore(new RestoreDocumentInput($id)), 'documents.flash.restored');
    }

    /**
     * A completed, declined or cancelled document again, as a new draft with
     * the same type, links and signers and the latest template version.
     */
    #[Route('/documents/{id}/again', name: 'app_document_again', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function again(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $this->document($id);
        try {
            $copy = $this->writes->generateAgain(new GenerateDocumentAgainInput($id));
        } catch (DocumentsRefused $refused) {
            $this->refused($refused);

            return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
        }
        $this->addFlash('success', $this->translator->trans('documents.flash.again'));

        return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $copy->id()]);
    }

    /**
     * The signed copy, a PDF chosen in a small dialog. From a ready document
     * it also means the document went out for signature.
     */
    #[Route('/documents/{id}/signed-copy', name: 'app_document_signed_copy', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function signedCopy(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $document = $this->document($id);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $file = $request->files->get('file');
            try {
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    throw DocumentsRefused::invalid('file_invalid', 'file', 'Choose the signed PDF.');
                }
                $this->writes->attachSignedFile($id, $file->getClientOriginalName(), $file->getPathname());
                $this->addFlash('success', $this->translator->trans('documents.flash.signed_copy'));

                return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
            } catch (DocumentsRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = $this->errors($refused, ['file'], 'file');
            }
        }

        return $this->render('@Documents/signed_copy.html.twig', ['document' => $document, 'errors' => $errors], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/documents/{id}/signers/new', name: 'app_document_signer_new', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function newSigner(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $document = $this->document($id);
        $groups = $this->screen->signerGroups($document);
        // A new signer signs after the last order by default, as a sequential step.
        $values = ['name' => '', 'email' => '', 'side' => SignerSide::Theirs->value, 'order' => (string) ([] === $groups ? 1 : min(99, $groups[\count($groups) - 1]['order'] + 1))];

        return $this->signerForm($request, $document, null, $values);
    }

    #[Route('/documents/{id}/signers/{signerId}/edit', name: 'app_document_signer_edit', requirements: ['id' => Requirement::UUID, 'signerId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editSigner(string $id, string $signerId, Request $request): Response
    {
        $this->denyUnlessStaff();
        $document = $this->document($id);
        $signer = $this->store->signer($document, $signerId) ?? throw new NotFoundHttpException();

        return $this->signerForm($request, $document, $signer, ['name' => $signer->name(), 'email' => $signer->email() ?? '', 'side' => $signer->side()->value, 'order' => (string) $signer->order()]);
    }

    #[Route('/documents/{id}/signers/{signerId}/remove', name: 'app_document_signer_remove', requirements: ['id' => Requirement::UUID, 'signerId' => Requirement::UUID], methods: ['POST'])]
    public function removeSigner(string $id, string $signerId, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->removeSigner(new RemoveSignerInput($id, $signerId)), 'documents.flash.signer_removed');
    }

    #[Route('/documents/{id}/signers/{signerId}/signed', name: 'app_document_signer_signed', requirements: ['id' => Requirement::UUID, 'signerId' => Requirement::UUID], methods: ['POST'])]
    public function markSigned(string $id, string $signerId, Request $request): RedirectResponse
    {
        return $this->act($id, $request, fn (): mixed => $this->writes->markSigned(new MarkSignerSignedInput($id, $signerId)), 'documents.flash.signer_signed');
    }

    /**
     * @param array{name: string, email: string, side: string, order: string} $values
     */
    private function signerForm(Request $request, Document $document, ?Signer $signer, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['name' => trim($request->request->getString('name')), 'email' => trim($request->request->getString('email')), 'side' => $request->request->getString('side'), 'order' => trim($request->request->getString('order'))];
            // The screen names the field it refuses; the use case reports a signer's fields under its name.
            if ('' !== $values['email'] && false === filter_var($values['email'], \FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = $this->translator->trans('documents.error.invalid.email');
            }
            if (1 !== preg_match('/^\d{1,2}$/', $values['order']) || (int) $values['order'] < 1) {
                $errors['order'] = $this->translator->trans('documents.error.invalid.order');
            }
            if ([] === $errors) {
                $email = '' === $values['email'] ? null : $values['email'];
                try {
                    if ($signer instanceof Signer) {
                        $this->writes->updateSigner(new UpdateSignerInput($document->id(), $signer->id(), $values['name'], $values['side'], (int) $values['order'], $email, $signer->partyPersonId()));
                    } else {
                        $this->writes->addSigner(new AddSignerInput($document->id(), $values['name'], $values['side'], (int) $values['order'], $email));
                    }
                    $this->addFlash('success', $this->translator->trans($signer instanceof Signer ? 'documents.flash.signer_saved' : 'documents.flash.signer_added', ['name' => $values['name']]));

                    return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $document->id()]);
                } catch (DocumentsRefused $refused) {
                    $this->denyIfForbidden($refused);
                    $errors = $this->errors($refused, ['name', 'side'], 'name');
                }
            }
        }

        return $this->render('@Documents/signer.html.twig', [
            'document' => $document,
            'signer' => $signer,
            'values' => $values,
            'errors' => $errors,
            'sides' => [SignerSide::Theirs->value => $this->translator->trans('documents.signer.side.theirs'), SignerSide::Ours->value => $this->translator->trans('documents.signer.side.ours')],
            'action' => $signer instanceof Signer
                ? $this->generateUrl('app_document_signer_edit', ['id' => $document->id(), 'signerId' => $signer->id()])
                : $this->generateUrl('app_document_signer_new', ['id' => $document->id()]),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param list<RecordLink>                                         $more   the links after the first
     * @param array{typeId: string, templateId: string, title: string} $values
     * @param array<string, string>                                    $errors
     */
    private function newForm(?RecordLink $link, array $more, array $values, array $errors, int $status): Response
    {
        return $this->render('@Documents/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'link' => $link,
            'more' => $more,
            'linkViews' => array_map($this->targets->describe(...), array_values(array_filter([$link, ...$more]))),
            'types' => $this->screen->typeOptions(),
            'templates' => $this->screen->templateOptions(),
        ], new Response(status: $status));
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(string $id, Request $request, \Closure $write, string $flash): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $this->document($id);
        try {
            $write();
            $this->addFlash('success', $this->translator->trans($flash));
        } catch (DocumentsRefused $refused) {
            $this->refused($refused);
        }

        return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
    }

    private function refused(DocumentsRefused $refused): void
    {
        $this->denyIfForbidden($refused);
        if (RefusalKind::NotFound === $refused->kind()) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $this->addFlash('error', $this->screen->refusal($refused));
    }

    /**
     * The link of a new document, from the address it was opened at.
     */
    /**
     * The link of the record the screen was opened from (link_kind and
     * link_id) and the further links (links[]=kind:id), each dropped when it
     * is malformed or repeats an earlier one, in the order given.
     *
     * @param InputBag<string>|InputBag<bool|float|int|string> $source
     *
     * @return array{?RecordLink, list<RecordLink>} the first link and the further ones
     */
    private function links(InputBag $source): array
    {
        $first = $this->link($source->getString('link_kind'), $source->getString('link_id'));
        $seen = $first instanceof RecordLink ? [$first->kind.':'.$first->id => true] : [];
        $more = [];
        $entries = $source->all()['links'] ?? [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            $parts = \is_string($entry) ? explode(':', $entry, 2) : [];
            $link = 2 === \count($parts) ? $this->link($parts[0], $parts[1]) : null;
            if (!$link instanceof RecordLink || isset($seen[$link->kind.':'.$link->id])) {
                continue;
            }
            $seen[$link->kind.':'.$link->id] = true;
            $more[] = $link;
        }

        return [$first, $more];
    }

    private function link(string $kind, string $id): ?RecordLink
    {
        $kind = trim($kind);
        if ('' === $kind || \strlen($kind) > DocumentLink::MAX_KIND || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $kind) || !Id::isValid($id)) {
            return null;
        }

        return new RecordLink($kind, $id);
    }

    /**
     * @param list<string> $fields the fields of the form
     *
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(DocumentsRefused $refused, array $fields, string $fallback): array
    {
        $field = $refused->field();

        return [\in_array($field, $fields, true) ? $field : $fallback => $this->screen->refusal($refused)];
    }

    private function document(string $id): Document
    {
        return $this->store->document($id) ?? throw new NotFoundHttpException('No such document.');
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
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
