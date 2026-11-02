<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\Organisation;
use App\Invoicing\Application\DocumentFormat;
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\PublicDocument;
use App\Invoicing\Application\PublicDocuments;
use App\Invoicing\Application\SentFiles;
use App\Invoicing\Application\SentFileUnreadable;
use App\Invoicing\Domain\LinkAudience;
use App\Shared\Domain\Money;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one page open to anyone with the link: the document as it was sent, in its
 * own language, with nothing of the application around it and no script. It
 * needs no session and never makes a tenant active.
 *
 * An unknown, revoked or cancelled link and a malformed one get the same answer,
 * so the page tells nothing about what exists. Opening the PDF from the page
 * counts as read; the page itself does not, nor does a request without the
 * browser's same-origin mark, the firm's own copy, or a member of the firm, so a
 * mail filter that follows links does not mark the document read.
 */
final class PublicDocumentController extends AbstractController
{
    public const string PAGE = 'app_document_link';
    public const string PDF = 'app_document_link_pdf';

    /** Any token-like path part reaches the controller, which answers a malformed one like an unknown one. */
    private const string TOKEN = '[^/]{1,200}';

    private const array HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'private, no-store',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; object-src 'self'; frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
    ];

    public function __construct(
        private readonly PublicDocuments $documents,
        private readonly SentFiles $files,
        private readonly LinkTokens $tokens,
        private readonly DocumentFormat $format,
        private readonly AuditActor $actor,
        private readonly MembershipDirectory $memberships,
        private readonly LoggerInterface $logger,
        private readonly AssetMapperInterface $assets,
        #[Autowire(service: 'limiter.document_link')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/d/{token}', name: self::PAGE, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function page(Request $request, string $token): Response
    {
        $document = $this->found($request, $token);
        if (!$document instanceof PublicDocument) {
            return $this->unavailable($request, $document);
        }
        $language = $document->language->value;
        $request->setLocale($language);

        return $this->secured($this->render('invoicing/public/document.html.twig', [
            'locale' => $language,
            'stylesheet' => $this->stylesheet(),
            'document' => $document,
            'amount' => $this->format->money(Money::ofMinor($document->amountMinor, $document->currency), $document->language),
            'dueDate' => $this->format->date($document->dueDate, $document->language),
            'pdf' => $this->generateUrl(self::PDF, ['token' => $token]),
            'fileName' => $this->fileName($document),
        ]));
    }

    #[Route('/d/{token}/pdf', name: self::PDF, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function pdf(Request $request, string $token): Response
    {
        $document = $this->found($request, $token);
        if (!$document instanceof PublicDocument) {
            return $this->unavailable($request, $document);
        }
        try {
            $bytes = $this->files->read($document->tenantId, $document->pdfKey, $document->pdfSha256);
        } catch (SentFileUnreadable $unreadable) {
            $this->logger->error('The kept PDF of the send {send} was not served: {reason}', ['send' => $document->sendId, 'reason' => $unreadable->getMessage()]);

            return $this->unavailable($request, null);
        }
        if ($this->countsAsRead($request, $document)) {
            $this->documents->recordRead($document->sendId);
        }

        return $this->secured(new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $this->fileName($document)),
        ]));
    }

    /**
     * @return PublicDocument|false|null false when the client asked too often; null when there is nothing to show
     */
    private function found(Request $request, string $token): PublicDocument|false|null
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return false;
        }

        return LinkTokens::isWellFormed($token) ? $this->documents->find($this->tokens->hash($token)) : null;
    }

    private function unavailable(Request $request, ?false $why): Response
    {
        $limited = false === $why;

        return $this->secured($this->render('invoicing/public/gone.html.twig', [
            'locale' => $request->getLocale(),
            'stylesheet' => $this->stylesheet(),
            'limited' => $limited,
        ], new Response('', $limited ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_NOT_FOUND)));
    }

    private function countsAsRead(Request $request, PublicDocument $document): bool
    {
        return 'GET' === $request->getMethod()
            && 'same-origin' === $request->headers->get('Sec-Fetch-Site')
            && LinkAudience::Customer === $document->audience
            && !$this->isMemberOf($document->tenantId);
    }

    private function isMemberOf(string $tenantId): bool
    {
        $userId = $this->actor->userId();
        if (null === $userId) {
            return false;
        }

        return array_any($this->memberships->organisationsOf($userId), static fn (Organisation $organisation): bool => $organisation->id->toString() === $tenantId);
    }

    private function stylesheet(): string
    {
        return $this->assets->getPublicPath('styles/app.css') ?? throw new \LogicException('The stylesheet is not in the asset map.');
    }

    private function fileName(PublicDocument $document): string
    {
        $number = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->number), '-.');

        return ('' === $number ? 'document' : $number).'.pdf';
    }

    private function secured(Response $response): Response
    {
        $response->headers->add(self::HEADERS);

        return $response;
    }
}
