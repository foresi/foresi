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
 * The invoices of one firm and period as a ZIP of PDFs, for staff to pass to the
 * accountant. Nothing is sent anywhere: the browser downloads the file, which lives
 * in a temporary file until it is sent.
 */
final class InvoiceExportController extends AbstractController
{
    public const string TOKEN = 'invoice_export';
    public const int MAX_DAYS = 366;
    private const string DATE = '!Y-m-d';

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

        $month = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('first day of last month');

        return $this->form(
            $this->defaults->firmFor($this->actor->userId())?->id() ?? '',
            $month->format('Y-m-d'),
            $month->modify('last day of this month')->format('Y-m-d'),
            [],
            null,
            Response::HTTP_OK,
        );
    }

    private function download(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $firm = $this->firm($request->request->getString('firm'));
        $fromText = $request->request->getString('from');
        $toText = $request->request->getString('to');
        $from = $this->date($fromText);
        $to = $this->date($toText);
        $errors = $this->errors($from, $to);
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable || [] !== $errors) {
            return $this->form($firm->id(), $fromText, $toText, $errors, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $path = tempnam(sys_get_temp_dir(), 'foresi-bundle-');
        if (false === $path) {
            throw new \RuntimeException('The temporary file cannot be created.');
        }
        try {
            $count = $this->bundle->writeTo($path, $firm, $from, $to);
        } catch (BundleIncomplete $incomplete) {
            unlink($path);

            return $this->form($firm->id(), $fromText, $toText, [], $this->translator->trans('invoice.export.incomplete', ['numbers' => implode(', ', $incomplete->numbers)]), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $failure) {
            unlink($path);

            throw $failure;
        }
        if (0 === $count) {
            unlink($path);

            return $this->form($firm->id(), $fromText, $toText, [], $this->translator->trans('invoice.export.empty'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => 'application/zip'], false);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, \sprintf('invoices-%s-%s-%s.zip', $firm->registrationNumber()->toString(), $from->format('Ymd'), $to->format('Ymd')));
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * @return array<string, string> messages by field
     */
    private function errors(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $errors = [];
        if (!$from instanceof \DateTimeImmutable) {
            $errors['from'] = $this->translator->trans('invoice.export.date_invalid');
        }
        if (!$to instanceof \DateTimeImmutable) {
            $errors['to'] = $this->translator->trans('invoice.export.date_invalid');
        }
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            return $errors;
        }
        if ($from > $to) {
            return ['to' => $this->translator->trans('invoice.export.backwards')];
        }
        // Both days count.
        if ((int) $from->diff($to)->format('%a') + 1 > self::MAX_DAYS) {
            return ['to' => $this->translator->trans('invoice.export.too_long', ['days' => self::MAX_DAYS])];
        }

        return [];
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(string $firmId, string $from, string $to, array $errors, ?string $notice, int $status): Response
    {
        $firms = [];
        foreach ($this->firms->all() as $firm) {
            $firms[$firm->id()] = $firm->name();
        }

        return $this->render('invoicing/export.html.twig', [
            'firms' => $firms,
            'firm' => $firmId,
            'from' => $from,
            'to' => $to,
            'errors' => $errors,
            'notice' => $notice,
        ], new Response(status: $status));
    }

    private function date(string $text): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::DATE, $text);

        return false !== $date && $date->format('Y-m-d') === $text ? $date : null;
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
