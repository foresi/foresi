<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Import;

use Foresi\Import\Contract\RecordExport;
use Foresi\Import\Contract\SourceFormat;
use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Party;
use Foresi\Party\Infrastructure\Controller\PartyController;
use Foresi\Party\Infrastructure\View\ContactViews;
use Foresi\Shared\Domain\Id;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exports contacts through the import target: the file has the columns the
 * import maps and imports again.
 */
final class ContactExportController extends AbstractController
{
    public function __construct(
        private readonly PartyQuery $parties,
        private readonly RecordExport $export,
        private readonly ContactImports $imports,
        private readonly ClockInterface $clock,
        private readonly ContactViews $contactViews,
    ) {
    }

    /**
     * Exports the selected contacts, or the whole view as it is filtered.
     * Unknown ids are ignored. The file name carries the date only, never a
     * name or a number. It is streamed, so a large address book needs no limit.
     */
    #[Route('/contacts/export', name: 'app_contacts_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(PartyController::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $format = 'xlsx' === $request->request->getString('format') ? SourceFormat::Xlsx : SourceFormat::Csv;
        // The header action exports the whole view as it is filtered, not a selection.
        if ('view' === $request->request->getString('scope')) {
            // The view as the list shows it, the conditions changed in its builder among them.
            [, , $working] = $this->contactViews->of($request->request);
            // A list narrowed to an import exports exactly what it shows; an unknown import narrows nothing.
            $importedBy = ImportedBy::tryFrom($request->request->getString('import'), $request->request->getString('outcome', 'create'));
            if ($importedBy instanceof ImportedBy && null === $this->imports->fileName($importedBy->importId)) {
                $importedBy = null;
            }
            $ids = $this->parties->matchingIds(trim(mb_substr($request->request->getString('q'), 0, PartyController::MAX_SEARCH)), null, false, $importedBy, $working);
        } else {
            $selected = $this->selectedIds($request);
            $found = [] === $selected ? [] : $this->parties->findMany($selected);
            if ([] === $found) {
                if ([] === $selected) {
                    return $this->redirectToRoute('app_contacts');
                }
                throw new NotFoundHttpException();
            }
            $ids = array_map(static fn (Party $party): string => $party->id(), $found);
        }
        if ([] === $ids) {
            return $this->redirectToRoute('app_contacts');
        }

        $response = new StreamedResponse(function () use ($ids, $format): void {
            $this->export->write(PartyImportTarget::KEY, $ids, $format, 'php://output');
        });
        $response->headers->set('Content-Type', SourceFormat::Xlsx === $format ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'contacts-'.$this->clock->now()->format('Ymd').'.'.$format->value));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @return list<string>
     */
    private function selectedIds(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (\is_string($id) && Id::isValid($id)) {
                $ids[$id] = $id;
            }
        }

        return \array_slice(array_values($ids), 0, PartyController::MAX_BULK);
    }
}
