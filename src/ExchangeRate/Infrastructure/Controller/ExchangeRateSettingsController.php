<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Controller;

use Foresi\ExchangeRate\Application\ExchangeRateRefused;
use Foresi\ExchangeRate\Application\RateSourceReads;
use Foresi\ExchangeRate\Application\RateSourceView;
use Foresi\ExchangeRate\Application\RateSourceWrites;
use Foresi\ExchangeRate\Application\SetRateSourceInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Exchange rates: the source the firm converts foreign currencies
 * at, whether a person chose it or the country's default applies, and the
 * sources the firm may choose. Changing it opens a dialog from the page
 * header. The owners and the members see it; a guest is refused with 403.
 */
final class ExchangeRateSettingsController extends AbstractController
{
    public const string ROUTE = 'app_exchange_rate_settings';
    public const string TOKEN = 'exchange_rate_source';

    public function __construct(
        private readonly RateSourceReads $reads,
        private readonly RateSourceWrites $writes,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/exchange-rates', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $view = $this->current();

        return $this->render('@ExchangeRate/settings.html.twig', [
            'view' => $view,
            'source' => null === $view->source ? null : $this->label($view->source),
            'details' => $this->details($view),
            'sources' => array_map(fn (string $name): array => ['label' => $this->label($name), 'text' => $this->text($name), 'allowed' => \in_array($name, $view->allowed, true)], $view->installed),
        ]);
    }

    /**
     * The choice of the source: a small dialog over the page.
     */
    #[Route('/settings/exchange-rates/source', name: self::ROUTE.'_source', methods: ['GET', 'POST'])]
    public function source(Request $request): Response
    {
        $view = $this->current();
        $value = $view->source ?? '';
        if (!$request->isMethod('POST')) {
            return $this->sourceForm($view, $value, null, Response::HTTP_OK);
        }

        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $value = trim($request->request->getString('source'));
        try {
            $this->writes->set(new SetRateSourceInput($value));
        } catch (ExchangeRateRefused $refused) {
            // Who may choose was asked when the form was read above.
            return $this->sourceForm($view, $value, 'exchange_rate.settings.error.'.$refused->reason(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('exchange_rate.settings.flash.source', ['source' => $this->label($value)]));

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    private function current(): RateSourceView
    {
        try {
            return $this->reads->current();
        } catch (ExchangeRateRefused $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function sourceForm(RateSourceView $view, string $value, ?string $error, int $status): Response
    {
        return $this->render('@ExchangeRate/source.html.twig', [
            'options' => array_map(fn (string $name): array => ['value' => $name, 'label' => $this->label($name), 'text' => $this->text($name)], $view->allowed),
            'value' => $value,
            'error' => $error,
        ], new Response(status: $status));
    }

    /**
     * @return list<array{label: string, value: string, note?: string}>
     */
    private function details(RateSourceView $view): array
    {
        $details = [[
            'label' => $this->translator->trans('exchange_rate.settings.in_use'),
            'value' => null === $view->source ? '' : $this->label($view->source),
            'note' => $this->translator->trans(null === $view->source ? 'exchange_rate.settings.none_installed' : ($view->chosen ? 'exchange_rate.settings.chosen' : 'exchange_rate.settings.default_applies')),
        ]];
        if (null !== $view->default) {
            $details[] = ['label' => $this->translator->trans('exchange_rate.settings.default'), 'value' => $this->label($view->default)];
        }

        return $details;
    }

    /**
     * The name of a source for people; a source without a translation shows its name.
     */
    private function label(string $name): string
    {
        $key = 'exchange_rate.source.'.$name.'.label';
        $label = $this->translator->trans($key);

        return $label === $key ? $name : $label;
    }

    private function text(string $name): ?string
    {
        $key = 'exchange_rate.source.'.$name.'.text';
        $text = $this->translator->trans($key);

        return $text === $key ? null : $text;
    }
}
