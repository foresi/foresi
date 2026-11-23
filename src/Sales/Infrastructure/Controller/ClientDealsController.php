<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Controller;

use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\DealValues;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Application\RefusalKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The Deals tab of a contact (ContactTabs): the client's deals, the newest
 * first, each with its stage, status and value and a "follows from …" line
 * under a deal that follows another, as a company's record lists its deals in
 * HubSpot and Pipedrive; "New deal" opens the dialog with the client set. The
 * answer is the tab's Turbo frame; the links in it open whole pages.
 */
final class ClientDealsController extends AbstractController
{
    public const string ROUTE = 'app_sales_client_deals';

    public function __construct(
        private readonly DealReads $reads,
        private readonly SalesStore $store,
        private readonly PartyDirectory $parties,
        private readonly DealScreen $screen,
    ) {
    }

    #[Route('/sales/clients/{id}/deals', name: self::ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function deals(string $id): Response
    {
        $client = $this->parties->find($id) ?? throw new NotFoundHttpException('There is no contact with this id.');
        try {
            $deals = $this->reads->dealsOfClient($id);
        } catch (SalesRefused $refused) {
            throw RefusalKind::Forbidden === $refused->kind() ? new AccessDeniedHttpException() : new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $titles = [];
        foreach ($deals as $deal) {
            $titles[$deal->id()] = $deal;
        }

        return $this->render('@Sales/client_deals.html.twig', [
            'frame' => ContactTabs::FRAME,
            'client' => $client,
            'deals' => array_map(fn (Deal $deal): array => $this->item($deal, $titles), $deals),
        ]);
    }

    /**
     * @param array<string, Deal> $deals the client's deals, by id
     *
     * @return array<string, mixed>
     */
    private function item(Deal $deal, array $deals): array
    {
        $follows = [];
        // A link is of one type, follows_from: the deal it comes from follows the deal it points to.
        foreach ($this->store->linksOf($deal->id()) as $link) {
            $earlier = $deals[$link->toDealId()] ?? null;
            if ($link->fromDealId() === $deal->id() && $earlier instanceof Deal) {
                $follows[] = ['title' => $earlier->title(), 'href' => $this->generateUrl(DealController::DEAL_ROUTE, ['id' => $earlier->id()])];
            }
        }

        return [
            'id' => $deal->id(),
            'title' => $deal->title(),
            'href' => $this->generateUrl(DealController::DEAL_ROUTE, ['id' => $deal->id()]),
            'stage' => $this->screen->stage($this->store->stage($deal->stageId())),
            'status' => $this->screen->status($deal),
            'value' => $this->screen->value(DealValues::of($this->store->linesOf($deal->id()))),
            'follows' => $follows,
        ];
    }
}
