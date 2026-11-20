<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Invoicing\Application\BundleIncomplete;
use Foresi\Invoicing\Application\DocumentMonths;
use Foresi\Invoicing\Application\InvoiceBundle;
use Foresi\Party\Application\InstanceFirm;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The documents of one month as a ZIP, for staff to pass to the accountant,
 * with how many of each kind it holds shown before the download. Nothing is sent
 * anywhere: the browser downloads the file, which lives in a temporary file until
 * it is sent. The documents it holds are marked exported.
 */
final class InvoiceExportController extends AbstractController
{
    public const string TOKEN = 'invoice_export';
    public const string PENDING = 'pending';
    public const string ALL = 'all';
    private const string MONTH = '!Y-m-d';

    public function __construct(
        private readonly InstanceFirm $firm,
        private readonly InvoiceBundle $bundle,
        private readonly StaffCheck $staff,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly DocumentMonths $months,
    ) {
    }

    #[Route('/invoices/export', name: 'app_invoice_export', methods: ['GET', 'POST'])]
    public function export(Request $request): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with invoices.');
        }
        if ($request->isMethod('POST')) {
            return $this->download($request);
        }

        // A link may name the month, as the reminder on the start page does; else the last month.
        $month = $this->month($request->query->getString('month')) ?? $this->lastMonth();

        return $this->form($month->format('Y-m'), self::PENDING, null !== $this->firm->find() ? $this->bundle->counts($month, true) : null, null, null, Response::HTTP_OK);
    }

    private function download(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        if (null === $this->firm->find()) {
            throw new NotFoundHttpException();
        }
        $monthText = $request->request->getString('month');
        $scope = self::ALL === $request->request->getString('scope') ? self::ALL : self::PENDING;
        $pending = self::PENDING === $scope;
        $month = $this->month($monthText);
        if (!$month instanceof \DateTimeImmutable) {
            return $this->form($monthText, $scope, null, $this->translator->trans('invoice.export.month_invalid'), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('count' === $request->request->getString('action')) {
            return $this->form($monthText, $scope, $this->bundle->counts($month, $pending), null, null, Response::HTTP_OK);
        }

        $path = tempnam(sys_get_temp_dir(), 'foresi-bundle-');
        if (false === $path) {
            throw new \RuntimeException('The temporary file cannot be created.');
        }
        try {
            $count = $this->bundle->writeTo($path, $month, $pending, $request->getLocale());
        } catch (BundleIncomplete $incomplete) {
            unlink($path);

            return $this->form($monthText, $scope, $this->bundle->counts($month, $pending), null, $this->translator->trans('invoice.export.incomplete', ['numbers' => implode(', ', $incomplete->numbers)]), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $failure) {
            @unlink($path);

            throw $failure;
        }
        if (0 === $count) {
            unlink($path);

            return $this->form($monthText, $scope, $this->bundle->counts($month, $pending), null, $this->translator->trans('invoice.export.empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/zip'], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->bundle->fileName($month));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * @param array<string, int>|null $counts by kind
     */
    private function form(string $month, string $scope, ?array $counts, ?string $error, ?string $notice, int $status): Response
    {
        return $this->render('@Invoicing/export.html.twig', [
            'month' => $month,
            // A pack is of one month: those with a document, the last one and the one chosen.
            'months' => MonthChoices::of([...$this->months->months(null), $this->lastMonth()->format('Y-m'), ...($this->month($month) instanceof \DateTimeImmutable ? [$month] : [])], $this->translator->getLocale()),
            'scope' => $scope,
            'counts' => $counts,
            'error' => $error,
            'notice' => $notice,
        ], new Response(status: $status));
    }

    private function lastMonth(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->modify('first day of last month');
    }

    private function month(string $text): ?\DateTimeImmutable
    {
        $month = \DateTimeImmutable::createFromFormat(self::MONTH, $text.'-01');

        return false !== $month && $month->format('Y-m') === $text ? $month : null;
    }
}
