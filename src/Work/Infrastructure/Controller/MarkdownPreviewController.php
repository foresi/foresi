<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\OpenToGuest;
use App\Shared\Application\Markdown;
use App\Ui\Component\MarkdownEditor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Preview tab of the Markdown editor: the text as the page will show it,
 * rendered and sanitised by the Markdown port, for anyone who writes a comment,
 * a guest included.
 */
#[OpenToGuest]
final class MarkdownPreviewController extends AbstractController
{
    public const int MAX_LENGTH = 20_000;

    public function __construct(
        private readonly Markdown $markdown,
    ) {
    }

    #[Route('/markdown/preview', name: 'app_markdown_preview', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(MarkdownEditor::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $text = $request->request->getString('text');
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response($this->markdown->toHtml($text), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
