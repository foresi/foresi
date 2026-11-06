<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Invoicing\Application\MailSecrets;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use Psr\Clock\ClockInterface;

/**
 * Copies a firm's iDoklad account into the snapshot, list by list and page by
 * page, each page stored as it arrives: a pull that stops keeps what it got
 * and records why it stopped. PDFs are a separate step, one request each.
 */
final readonly class PullIdokladSnapshot
{
    public function __construct(
        private IdokladConnections $connections,
        private MailSecrets $secrets,
        private IdokladClient $client,
        private IdokladSnapshot $snapshot,
        private IdokladFiles $files,
        private TransactionalWork $work,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $changedSince a moment in iDoklad's time, usually the
     *                                  latest change of the last pull: lists
     *                                  that can filter return only what changed after it
     *
     * @throws IdokladFailure after the pull recorded it
     */
    public function pull(LegalEntity $firm, ?string $changedSince = null): IdokladPull
    {
        $pull = new IdokladPull($firm, $this->clock->now(), $changedSince);
        $session = $this->open($firm);
        $this->work->run(fn () => $this->snapshot->add($pull));

        try {
            foreach (IdokladResource::cases() as $resource) {
                $this->pullList($session, $pull, $resource);
            }
        } catch (IdokladFailure $failure) {
            $this->work->run(fn () => $pull->finish($this->clock->now(), $session->requests(), $failure->getMessage()));

            throw $failure;
        }
        $this->work->run(fn () => $pull->finish($this->clock->now(), $session->requests()));

        return $pull;
    }

    /**
     * Downloads the PDFs of up to $limit pulled documents that have none yet.
     *
     * @throws IdokladFailure
     */
    public function downloadPdfs(LegalEntity $firm, int $limit = 100): PdfDownload
    {
        $session = $this->open($firm);
        $downloaded = 0;
        foreach ($this->snapshot->withoutPdf($firm, $limit) as $record) {
            $data = $session->get(\sprintf('/Reports/%s/%d/Pdf', $record->resource()->pdfReport(), $record->externalId()));
            $bytes = \is_string($data) ? base64_decode($data, true) : false;
            if (false === $bytes || !str_starts_with($bytes, '%PDF-')) {
                throw new IdokladFailure('iDoklad returned a document that is not a PDF.');
            }
            $key = $this->files->store($record->tenantId()->toString(), $record->id(), $bytes);
            $this->work->run(fn () => $record->attachPdf($key, hash('sha256', $bytes)));
            ++$downloaded;
        }

        return new PdfDownload($downloaded, $session->requests());
    }

    private function open(LegalEntity $firm): IdokladSession
    {
        $connection = $this->connections->for($firm) ?? throw new IdokladNotConnected('The firm has no iDoklad connection.');

        return $this->client->session($connection->clientId(), $this->secrets->open($connection->sealedSecret()));
    }

    private function pullList(IdokladSession $session, IdokladPull $pull, IdokladResource $resource): void
    {
        $query = [];
        $column = $resource->changeFilter();
        if (null !== $pull->changedSince() && null !== $column) {
            $query['filter'] = \sprintf('(%s~gt~%s)', $column, $pull->changedSince());
        }

        $records = 0;
        foreach ($session->pages($resource->path(), $query) as $items) {
            // Checked before the page is stored, so a bad page fails outside the transaction.
            $byId = [];
            foreach ($items as $item) {
                $id = $item['Id'] ?? null;
                if (!\is_int($id)) {
                    throw new IdokladFailure(\sprintf('iDoklad returned a record of %s without an id.', $resource->value));
                }
                $byId[$id] = $item;
            }
            $this->work->run(function () use ($pull, $resource, $byId): void {
                foreach ($byId as $id => $item) {
                    $pull->noteChange($this->keep($pull->firm(), $resource, $id, $item));
                }
            });
            $records += \count($byId);
        }
        $pull->counted($resource, $records);
    }

    /**
     * @param array<mixed> $item
     *
     * @return string|null the moment iDoklad last changed the record
     */
    private function keep(LegalEntity $firm, IdokladResource $resource, int $id, array $item): ?string
    {
        $now = $this->clock->now();
        $record = $this->snapshot->record($firm, $resource, $id);
        if (!$record instanceof IdokladRecord) {
            $record = new IdokladRecord($firm, $resource, $id, $item, $now);
            $this->snapshot->add($record);
        } else {
            $record->replace($item, $now);
        }

        return $record->changedAt();
    }
}
