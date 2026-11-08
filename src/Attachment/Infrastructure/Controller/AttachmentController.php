<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Infrastructure\Controller;

use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\AttachmentFileUnreadable;
use App\Attachment\Application\AttachmentRefused;
use App\Attachment\Application\AttachmentUpload;
use App\Attachment\Application\DeleteAttachment;
use App\Attachment\Application\UploadAttachment;
use App\Attachment\Application\VisibleAttachments;
use App\Attachment\Domain\FileName;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Downloading, uploading and deleting the files of records. A file is only
 * ever served from its own address with a policy that runs nothing and without
 * sniffing, and shown in the browser only when it is an image, a PDF or a
 * video; anything else, HTML and SVG included, is downloaded as bytes.
 *
 * The upload answers the editor with JSON; a plain form gives a return path
 * and is sent back there with the error, as the file upload pattern of GOV.UK.
 * Deleting is a form that goes back to its return path.
 */
final class AttachmentController extends AbstractController
{
    public const string ROUTE = 'app_attachment';
    public const string UPLOAD_ROUTE = 'app_attachment_upload';
    public const string DELETE_ROUTE = 'app_attachment_delete';
    public const string TOKEN = 'attachment_upload';
    public const string DELETE_TOKEN = 'attachment_delete';

    /** Every file but a PDF: no scripts, no plugins, no forms, an opaque origin. */
    public const string POLICY = "sandbox; default-src 'none'";

    /**
     * A PDF: still nothing loads or runs from the document, but without the
     * sandbox, which keeps a browser's own viewer from showing it in some
     * browsers (Firefox's PDF.js runs in the document it renders). Chrome 156
     * shows a PDF under either policy.
     */
    public const string PDF_POLICY = "default-src 'none'";

    /** Shown in the browser with their sniffed type; every other file is downloaded. */
    public const array INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];

    public function __construct(
        private readonly VisibleAttachments $attachments,
        private readonly AttachmentFiles $files,
        private readonly UploadAttachment $upload,
        private readonly DeleteAttachment $delete,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/attachments/{id}', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function download(string $id, Request $request): Response
    {
        $attachment = $this->attachments->get($id) ?? throw new NotFoundHttpException('No such attachment.');
        $tenant = $attachment->tenantId()->toString();
        try {
            // A video seeks with many range requests, and hashing a large file for each would
            // read it whole every time. A part is served from the stored file as it is: the whole
            // is proved against its SHA-256 on every full read, and was when it was uploaded.
            $path = $request->headers->has('Range')
                ? $this->files->locate($tenant, $attachment->storageKey())
                : $this->files->read($tenant, $attachment->storageKey(), $attachment->sha256());
        } catch (AttachmentFileUnreadable $unreadable) {
            throw new NotFoundHttpException('The file of the attachment cannot be read.', $unreadable);
        }
        $inline = \in_array($attachment->mediaType(), self::INLINE_TYPES, true);

        $response = new BinaryFileResponse($path, Response::HTTP_OK, [], false, null, false, false);
        $response->headers->set('Content-Type', $inline ? $attachment->mediaType() : 'application/octet-stream');
        $response->headers->set('Content-Disposition', $this->disposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->fileName()));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->setEtag($attachment->sha256());
        $response->isNotModified($request);

        return $this->guarded($response, 'application/pdf' === $attachment->mediaType() ? self::PDF_POLICY : self::POLICY);
    }

    #[Route('/attachments', name: self::UPLOAD_ROUTE, methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $json = $this->wantsJson($request);
        // Larger than the web server takes: the body was dropped, the token with it.
        if (0 === $request->request->count() && 0 === $request->files->count() && $request->server->getInt('CONTENT_LENGTH') > 0) {
            return $this->refused(AttachmentRefused::TOO_BIG, $json, '/');
        }
        $return = self::localPath($request->request->getString('return'));
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $ownerKind = $request->request->getString('owner_kind');
        $ownerId = $request->request->getString('owner_id');
        $file = $request->files->get('file');
        $upload = $file instanceof UploadedFile
            ? new AttachmentUpload(
                $file->getPathname(),
                $file->getClientOriginalName(),
                $file->isValid() ? (int) $file->getSize() : 0,
                $file->isValid(),
                \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true),
            )
            : new AttachmentUpload('', '', 0, false);

        try {
            $attachment = $this->upload->upload($ownerKind, $ownerId, $upload);
        } catch (AttachmentRefused $refused) {
            return match ($refused->reason) {
                AttachmentRefused::UNKNOWN => throw new NotFoundHttpException('No such record.', $refused),
                AttachmentRefused::NOT_ALLOWED => throw new AccessDeniedHttpException('Files cannot be attached to this record.', $refused),
                default => $this->refused($refused->reason, $json, $return),
            };
        }

        if (!$json) {
            return new RedirectResponse($return ?? '/', Response::HTTP_SEE_OTHER);
        }

        return new JsonResponse([
            'id' => $attachment->id(),
            'url' => $this->generateUrl(self::ROUTE, ['id' => $attachment->id()], UrlGeneratorInterface::ABSOLUTE_PATH),
            'name' => $attachment->fileName(),
            'mediaType' => $attachment->mediaType(),
            'size' => $attachment->size(),
        ], Response::HTTP_CREATED);
    }

    /**
     * The uploader, or whom the module of the record lets, takes a file off its
     * record; a refusal is told back on the page.
     */
    #[Route('/attachments/{id}/delete', name: self::DELETE_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::DELETE_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $return = self::localPath($request->request->getString('return')) ?? '/';
        try {
            $this->delete->delete($id);
        } catch (AttachmentRefused $refused) {
            if (AttachmentRefused::UNKNOWN === $refused->reason) {
                throw new NotFoundHttpException('No such attachment.', $refused);
            }
            $this->addFlash('error', $this->translator->trans('attachment.error.cannot_delete'));
        }

        return new RedirectResponse($return, Response::HTTP_SEE_OTHER);
    }

    private function refused(string $reason, bool $json, ?string $return): Response
    {
        $message = $this->translator->trans('attachment.error.'.$reason);
        if ($json) {
            return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('error', $message);

        return new RedirectResponse($return ?? '/', Response::HTTP_SEE_OTHER);
    }

    /**
     * JSON for the editor; a plain form names where to go back to.
     */
    private function wantsJson(Request $request): bool
    {
        return '' === $request->request->getString('return') || 'json' === $request->getPreferredFormat();
    }

    /**
     * A path of this application only: never another host, a scheme or a
     * protocol-relative address.
     */
    public static function localPath(string $path): ?string
    {
        if ('' === $path || '/' !== $path[0] || \strlen($path) > 2048) {
            return null;
        }
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\') || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * RFC 6266: the name as typed in filename*, an ASCII stand-in in filename.
     */
    private function disposition(string $disposition, string $name): string
    {
        $fallback = (string) preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/u', '_', $name);

        return HeaderUtils::makeDisposition($disposition, $name, '' === trim($fallback) ? FileName::FALLBACK : $fallback);
    }

    private function guarded(Response $response, string $policy): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', $policy);
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }
}
