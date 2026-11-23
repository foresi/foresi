<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api;

use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\AttachmentFileUnreadable;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Documents\Application\DocumentReads;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Domain\Document;
use Foresi\Shared\Application\Api\ScopeGuard;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The documents, their types and templates, and the stored files of a
 * document, read by a personal API token with the documents:read scope, for
 * the owners and members only (ADR 0041). What the reads refuse is answered by
 * the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class DocumentsApiController
{
    public const string READ_SCOPE = 'documents:read';

    public function __construct(
        private ScopeGuard $scope,
        private DocumentReads $reads,
        private DocumentsJson $json,
        private VisibleAttachments $attachments,
        private AttachmentFiles $files,
    ) {
    }

    #[Route('/api/v1/documents', name: DocumentsRoutes::DOCUMENTS, methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Parameter(name: 'type_id', description: 'Only the documents of this type.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'status', description: 'Only the documents in this status.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['draft', 'ready', 'out_for_signature', 'completed', 'declined', 'cancelled', 'expired']))]
    #[OA\Parameter(name: 'linked_kind', description: 'With linked_id: only the documents linked to this record, such as contact.', in: 'query', schema: new OA\Schema(type: 'string', example: 'contact'))]
    #[OA\Parameter(name: 'linked_id', description: 'With linked_kind: the id of the record.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'limit', description: 'How many documents, 1 to 200; 50 when left out.', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'offset', description: 'How many of the newest to skip; 0 when left out.', in: 'query', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'trashed', description: 'true lists the documents in the trash instead of the others.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The documents, newest first, without values and signers; those in the trash only with trashed.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DocumentsDocumentRow'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function documents(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $documents = $this->reads->documents(
            $this->text($request, 'type_id'),
            $this->text($request, 'status'),
            $this->text($request, 'linked_kind'),
            $this->text($request, 'linked_id'),
            $this->number($request, 'limit'),
            $this->number($request, 'offset'),
            $request->query->getBoolean('trashed'),
        );

        return new JsonResponse(['data' => array_map($this->json->documentRow(...), $documents)]);
    }

    #[Route('/api/v1/documents/{documentId}', name: DocumentsRoutes::DOCUMENT, methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Response(response: 200, description: 'The document with its links, signers, files and frozen values, also one in the trash.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DocumentsDocument')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'There is no such document.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function document(string $documentId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->document($this->reads->document($documentId))]);
    }

    #[Route('/api/v1/documents/{documentId}/files/{file}', name: DocumentsRoutes::DOCUMENT_FILE, requirements: ['file' => 'pdf|signed|certificate'], methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Parameter(name: 'file', description: 'Which file: pdf (the generated PDF), signed (the signed copy) or certificate (the provider\'s certificate).', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['pdf', 'signed', 'certificate']))]
    #[OA\Response(response: 200, description: 'The bytes of the file, as stored; its SHA-256 is in the files of the document.', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'There is no such document, or it has no such file yet.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function file(string $documentId, string $file, Request $request): BinaryFileResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $attachment = $this->attachments->get($this->reads->fileOf($documentId, $file)) ?? throw DocumentsRefused::notFound(\sprintf('The document has no %s file.', $file));
        try {
            $path = $this->files->read($attachment->id(), $attachment->storageKey(), $attachment->sha256());
        } catch (AttachmentFileUnreadable) {
            throw DocumentsRefused::notFound(\sprintf('The %s file of the document cannot be read.', $file));
        }
        $response = new BinaryFileResponse($path, Response::HTTP_OK, [], false, null, false, false);
        $response->headers->set('Content-Type', 'application/pdf' === $attachment->mediaType() ? 'application/pdf' : 'application/octet-stream');
        $fallback = (string) preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/u', '_', $attachment->fileName());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->fileName(), '' === trim($fallback) ? $file.'.pdf' : $fallback));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->setEtag($attachment->sha256());
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/api/v1/document-types', name: DocumentsRoutes::TYPES, methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Parameter(name: 'include_archived', description: 'Whether archived types are listed too; not when left out.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The document types, by name.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DocumentsType'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function types(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => array_map($this->json->type(...), $this->reads->types($request->query->getBoolean('include_archived')))]);
    }

    #[Route('/api/v1/document-templates', name: DocumentsRoutes::TEMPLATES, methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Parameter(name: 'type_id', description: 'Only the templates of this document type.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'include_archived', description: 'Whether archived templates are listed too; not when left out.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The templates, by name, each with its latest version without the body.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DocumentsTemplateRow'))], type: 'object'))]
    #[OA\Response(response: 400, description: 'A parameter cannot be used.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function templates(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $templates = $this->reads->templates($this->text($request, 'type_id'), $request->query->getBoolean('include_archived'));

        return new JsonResponse(['data' => array_map($this->json->templateRow(...), $templates)]);
    }

    #[Route('/api/v1/document-templates/{templateId}', name: DocumentsRoutes::TEMPLATE, methods: ['GET'])]
    #[OA\Tag(name: 'Documents')]
    #[OA\Response(response: 200, description: 'The template with its latest version, its versions and the variables a body can use.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DocumentsTemplate')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the documents:read scope, or is a guest\'s.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'There is no such template.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function template(string $templateId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->json->template($this->reads->template($templateId))]);
    }

    private function text(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }

    /**
     * @throws DocumentsRefused when it is not a whole number
     */
    private function number(Request $request, string $name): ?int
    {
        $value = $this->text($request, $name);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^[0-9]{1,6}\z/', $value)) {
            throw DocumentsRefused::invalid($name.'_invalid', $name, $name.' is a whole number.');
        }

        return (int) $value;
    }
}
