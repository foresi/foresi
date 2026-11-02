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
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\PublicDocument;
use App\Invoicing\Application\PublicDocuments;
use App\Invoicing\Application\SentFiles;
use App\Invoicing\Application\SentFileUnreadable;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Infrastructure\Pdf\QrSvg;
use App\Party\Domain\PaymentMethod;
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
 *
 * A paid or overpaid invoice shows neither the payment instructions nor the QR
 * code; a part payment shows what remains and asks for that, without the code,
 * which asks for the whole amount. The PDF is the one sent and stays as it was.
 */
final class PublicDocumentController extends AbstractController
{
    public const string PAGE = 'app_document_link';
    public const string PDF = 'app_document_link_pdf';
    public const string OPEN = 'app_document_link_open';
    public const string DOWNLOAD = 'app_document_link_download';

    /** Any token-like path part reaches the controller, which answers a malformed one like an unknown one. */
    private const string TOKEN = '[^/]{1,200}';

    private const array LANGUAGES = ['cs', 'en'];

    private const array HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'private, no-store',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; object-src 'self'; frame-src 'self'; frame-ancestors 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
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
        private readonly QrSvg $qr,
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
        // Cash and card are paid on the spot: no account to pay to. A paid invoice
        // asks for nothing more, so it shows no way to pay it twice.
        $settled = $document->payment->isSettled();
        $partly = PaymentState::PartlyPaid === $document->payment;
        $iban = PaymentMethod::BankTransfer === $document->paymentMethod && !$settled ? $document->bankAccount : null;
        $remaining = $partly ? $this->format->money(Money::ofMinor($document->remainingMinor, $document->currency), $document->language) : null;

        return $this->secured($this->render('invoicing/public/document.html.twig', [
            'locale' => $language,
            'stylesheet' => $this->stylesheet(),
            'document' => $document,
            'amount' => $this->amount($document),
            'dueDate' => $this->format->date($document->dueDate, $document->language),
            'pdf' => $this->generateUrl(self::PDF, ['token' => $token]),
            'open' => $this->generateUrl(self::OPEN, ['token' => $token]),
            'download' => $this->generateUrl(self::DOWNLOAD, ['token' => $token]),
            'transfer' => null !== $iban,
            'paid' => $settled,
            'remaining' => $remaining,
            'iban' => null === $iban ? null : trim(chunk_split($iban, 4, ' ')),
            // The stored code asks for the whole amount and none other can be built
            // from what was sent, so after a part payment it would ask for too much.
            'qr' => null !== $iban && !$partly && null !== $document->paymentCode ? $this->qr->svg($document->paymentCode) : null,
        ]));
    }

    /**
     * The PDF the page embeds. A browser loads it on its own, so it never counts as read.
     */
    #[Route('/d/{token}/pdf', name: self::PDF, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function pdf(Request $request, string $token): Response
    {
        return $this->pdfResponse($request, $token, HeaderUtils::DISPOSITION_INLINE, false);
    }

    /**
     * The PDF behind the page's "Open PDF" button.
     */
    #[Route('/d/{token}/open', name: self::OPEN, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function open(Request $request, string $token): Response
    {
        return $this->pdfResponse($request, $token, HeaderUtils::DISPOSITION_INLINE, true);
    }

    /**
     * The PDF behind the page's "Download" button.
     */
    #[Route('/d/{token}/download', name: self::DOWNLOAD, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function download(Request $request, string $token): Response
    {
        return $this->pdfResponse($request, $token, HeaderUtils::DISPOSITION_ATTACHMENT, true);
    }

    /**
     * Any other address under /d/: the same answer as an unknown link, headers included.
     */
    #[Route('/d/{rest}', name: 'app_document_link_other', requirements: ['rest' => '.*'], methods: ['GET'], priority: -10)]
    public function other(Request $request): Response
    {
        return $this->unavailable($request, $this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted() ? null : false);
    }

    private function pdfResponse(Request $request, string $token, string $disposition, bool $button): Response
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
        if ($button && $this->countsAsRead($request, $document)) {
            $this->documents->recordRead($document->sendId);
        }

        return $this->secured(new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $this->fileName($document)),
        ]));
    }

    /**
     * The amount as the PDF shows it: crowns as "Kč" on a Czech document.
     */
    private function amount(PublicDocument $document): string
    {
        return $this->format->money(Money::ofMinor($document->amountMinor, $document->currency), $document->language);
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
        // No document says which language to speak, so the browser does, among the ones the page has.
        $language = $request->getPreferredLanguage(self::LANGUAGES) ?? self::LANGUAGES[0];
        $request->setLocale($language);

        return $this->secured($this->render('invoicing/public/gone.html.twig', [
            'locale' => $language,
            'stylesheet' => $this->stylesheet(),
            'limited' => $limited,
        ], new Response('', $limited ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_NOT_FOUND)));
    }

    /**
     * A person on the page pressed a button: a same-origin request the browser
     * made for a navigation or a download, not a prefetch, not a frame or an
     * embed, and not by the firm itself.
     */
    private function countsAsRead(Request $request, PublicDocument $document): bool
    {
        $headers = $request->headers;
        $purpose = strtolower(($headers->get('Sec-Purpose') ?? '').' '.($headers->get('Purpose') ?? '').' '.($headers->get('X-Moz') ?? ''));

        return 'GET' === $request->getMethod()
            && 'same-origin' === $headers->get('Sec-Fetch-Site')
            && !str_contains($purpose, 'prefetch') && !str_contains($purpose, 'prerender')
            && !\in_array($headers->get('Sec-Fetch-Dest'), ['object', 'embed', 'iframe', 'frame'], true)
            && '?0' !== $headers->get('Sec-Fetch-User')
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
