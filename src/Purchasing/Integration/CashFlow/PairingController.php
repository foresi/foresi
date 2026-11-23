<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\CashFlow;

use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\PairableDocument;
use Foresi\CashFlow\Contract\PairingRefused;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a person does with the pairing of a received document from its Plan panel: confirm an occurrence Cash
 * flow proposes or pick another one in a dialog, and unpair. Each action returns to the document.
 */
final class PairingController extends AbstractController
{
    public const string TOKEN = 'purchasing_pairing';

    /** The refusals that have words of their own; any other is told in general. */
    private const array REASONS = ['not_found', 'direction_mismatch', 'not_an_occurrence', 'date_invalid'];

    public function __construct(
        private readonly PurchasingAccess $access,
        private readonly ReceivedDocuments $documents,
        private readonly ReceivedDocumentPairables $pairables,
        private readonly DocumentPairings $pairings,
        private readonly ReceivedDocumentScreen $screen,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The dialog that picks an item and the day of its occurrence, from those around the day of the document.
     */
    #[Route('/purchasing/{id}/pairing/choose', name: 'app_purchasing_pairing_choose', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function choose(string $id): Response
    {
        [$document, $pairable] = $this->recorded($id);
        try {
            $choices = $this->pairings->choices($pairable->direction, $pairable->on->modify('-2 months'), $pairable->on->modify('+2 months'));
        } catch (PairingRefused $refused) {
            throw $this->denied($refused);
        }
        $format = $this->translator->trans('format.date');
        $options = [];
        foreach ($choices as $choice) {
            $options[$choice->name] = [];
            foreach ($choice->occurrences as $day) {
                $options[$choice->name][$choice->plannedItemId.'|'.$day->format('Y-m-d')] = $day->format($format);
            }
        }

        return $this->render('@Purchasing/cash_flow/choose.html.twig', [
            'document' => $document,
            'title' => $this->screen->title($document),
            'options' => $options,
        ]);
    }

    #[Route('/purchasing/{id}/pairing', name: 'app_purchasing_pairing_pair', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function pair(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        [, $pairable] = $this->recorded($id);
        [$itemId, $day] = array_pad(explode('|', $request->request->getString('choice'), 2), 2, '');
        $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if ('' === $itemId || false === $on || $on->format('Y-m-d') !== $day) {
            $this->addFlash('error', $this->translator->trans('purchasing.pairing.error.choice'));

            return $this->toDocument($id);
        }

        return $this->act($id, fn () => $this->pairings->pair($pairable->subject, $itemId, $on), 'purchasing.pairing.flash.paired');
    }

    #[Route('/purchasing/{id}/pairing/unpair', name: 'app_purchasing_pairing_unpair', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function unpair(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        [, $pairable] = $this->recorded($id);

        return $this->act($id, fn () => $this->pairings->unpair($pairable->subject), 'purchasing.pairing.flash.unpaired');
    }

    /**
     * @return array{ReceivedDocument, PairableDocument}
     */
    private function recorded(string $id): array
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($id) ?? throw new UnknownReceivedDocument();
        $pairable = $this->pairables->one(ReceivedDocumentPairables::TYPE, $id) ?? throw $this->createNotFoundException();

        return [$document, $pairable];
    }

    private function act(string $id, \Closure $work, string $message): RedirectResponse
    {
        try {
            $work();
            $this->addFlash('success', $this->translator->trans($message));
        } catch (PairingRefused $refused) {
            if ($refused->forbidden) {
                throw $this->denied($refused);
            }
            $this->addFlash('error', $this->translator->trans(\in_array($refused->reason, self::REASONS, true) ? 'purchasing.pairing.error.'.$refused->reason : 'purchasing.pairing.error.other'));
        }

        return $this->toDocument($id);
    }

    private function denied(PairingRefused $refused): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException($refused->getMessage(), $refused);
    }

    private function toDocument(string $id): RedirectResponse
    {
        return $this->redirectToRoute('app_purchasing_document', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
