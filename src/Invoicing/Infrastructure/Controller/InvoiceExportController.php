<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\BundleIncomplete;
use App\Invoicing\Application\InvoiceBundle;
use App\Invoicing\Application\NewInvoiceDefaults;
use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The documents of one firm and month as a ZIP, for staff to pass to the accountant,
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
        private readonly LegalEntities $firms,
        private readonly InvoiceBundle $bundle,
        private readonly NewInvoiceDefaults $defaults,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
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

        // A link may name the firm and month, as the reminder on the start page does; else the last month.
        $month = $this->month($request->query->getString('month')) ?? \DateTimeImmutable::createFromInterface($this->clock->now())->modify('first day of last month');
        $firm = $this->linkedFirm($request->query->getString('firm')) ?? $this->defaults->firmFor($this->actor->userId());

        return $this->form($firm?->id() ?? '', $month->format('Y-m'), self::PENDING, $firm instanceof LegalEntity ? $this->bundle->counts($firm, $month, true) : null, null, null, Response::HTTP_OK);
    }

    private function download(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $firm = $this->firm($request->request->getString('firm'));
        $monthText = $request->request->getString('month');
        $scope = self::ALL === $request->request->getString('scope') ? self::ALL : self::PENDING;
        $pending = self::PENDING === $scope;
        $month = $this->month($monthText);
        if (!$month instanceof \DateTimeImmutable) {
            return $this->form($firm->id(), $monthText, $scope, null, $this->translator->trans('invoice.export.month_invalid'), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('count' === $request->request->getString('action')) {
            return $this->form($firm->id(), $monthText, $scope, $this->bundle->counts($firm, $month, $pending), null, null, Response::HTTP_OK);
        }

        $path = tempnam(sys_get_temp_dir(), 'foresi-bundle-');
        if (false === $path) {
            throw new \RuntimeException('The temporary file cannot be created.');
        }
        try {
            $count = $this->bundle->writeTo($path, $firm, $month, $pending, $request->getLocale());
        } catch (BundleIncomplete $incomplete) {
            unlink($path);

            return $this->form($firm->id(), $monthText, $scope, $this->bundle->counts($firm, $month, $pending), null, $this->translator->trans('invoice.export.incomplete', ['numbers' => implode(', ', $incomplete->numbers)]), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $failure) {
            @unlink($path);

            throw $failure;
        }
        if (0 === $count) {
            unlink($path);

            return $this->form($firm->id(), $monthText, $scope, $this->bundle->counts($firm, $month, $pending), null, $this->translator->trans('invoice.export.empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/zip'], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->bundle->fileName($firm, $month));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * @param array<string, int>|null $counts by kind
     */
    private function form(string $firmId, string $month, string $scope, ?array $counts, ?string $error, ?string $notice, int $status): Response
    {
        $firms = [];
        foreach ($this->firms->all() as $firm) {
            $firms[$firm->id()] = $firm->name();
        }

        return $this->render('invoicing/export.html.twig', [
            'firms' => $firms,
            'firm' => $firmId,
            'month' => $month,
            'scope' => $scope,
            'counts' => $counts,
            'error' => $error,
            'notice' => $notice,
        ], new Response(status: $status));
    }

    private function month(string $text): ?\DateTimeImmutable
    {
        $month = \DateTimeImmutable::createFromFormat(self::MONTH, $text.'-01');

        return false !== $month && $month->format('Y-m') === $text ? $month : null;
    }

    /**
     * The firm a link names, if the tenant has it.
     */
    private function linkedFirm(string $id): ?LegalEntity
    {
        try {
            return $this->firm($id);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    private function firm(string $id): LegalEntity
    {
        try {
            return Uuid::isValid($id) ? $this->firms->get($id) : throw new NotFoundHttpException();
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }
}
