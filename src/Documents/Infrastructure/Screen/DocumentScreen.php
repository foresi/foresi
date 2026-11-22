<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Screen;

use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\DocumentsStore;
use Foresi\Documents\Application\LinkTargets;
use Foresi\Documents\Application\LinkView;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Domain\Document;
use Foresi\Documents\Domain\DocumentLink;
use Foresi\Documents\Domain\DocumentStatus;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\Signer;
use Foresi\Documents\Domain\SignerStatus;
use Foresi\Documents\Infrastructure\Controller\DocumentController;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Ui\Contract\Opens;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Documents as their screens show them: the rows of a list, the status badge,
 * the details and links of one document, its signers grouped by order as
 * DocuSign shows a routing order, the choices of the forms, and a refusal as
 * a sentence of the person's language.
 */
final readonly class DocumentScreen
{
    /** The tone of each status's badge. */
    private const array TONES = [
        'draft' => 'gray',
        'ready' => 'amber',
        'out_for_signature' => 'amber',
        'completed' => 'green',
        'declined' => 'red',
        'cancelled' => 'gray',
        'expired' => 'red',
    ];

    public function __construct(
        private DocumentsStore $store,
        private LinkTargets $targets,
        private InstanceClock $clock,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the columns of Ui:DataTable
     */
    public function columns(bool $withLinks = true): array
    {
        return array_values(array_filter([
            ['key' => 'title', 'label' => $this->trans('documents.column.title'), 'truncate' => true],
            ['key' => 'type', 'label' => $this->trans('documents.column.type'), 'phone' => false],
            ['key' => 'status', 'label' => $this->trans('documents.column.status')],
            $withLinks ? ['key' => 'links', 'label' => $this->trans('documents.column.links'), 'truncate' => true, 'phone' => false, 'wide' => true] : null,
            ['key' => 'created', 'label' => $this->trans('documents.column.created'), 'phone' => false],
        ]));
    }

    /**
     * @param list<Document> $documents
     *
     * @return list<array<string, mixed>> the rows of Ui:DataTable, newest first
     */
    public function rows(array $documents, bool $withLinks = true): array
    {
        $types = $this->typeNames();

        return array_map(function (Document $document) use ($types, $withLinks): array {
            $status = $this->status($document->status());
            $cells = [
                'title' => $document->title(),
                'type' => $types[$document->typeId()] ?? '',
                'status' => ['badges' => [$status]],
                'created' => $this->day($document->createdAt()),
            ];
            if ($withLinks) {
                $labels = array_map(fn (LinkView $link): string => $link->available ? (string) $link->label : $this->trans('documents.link.unavailable_short'), $this->links($document));
                $cells['links'] = [] === $labels ? ['empty' => $this->trans('documents.none.links')] : implode(', ', $labels);
            }

            return [
                'id' => $document->id(),
                // A document is a medium record: it opens in the side sheet over the list (docs/design/overlays.md).
                'href' => $this->urls->generate(DocumentController::SHOW_ROUTE, ['id' => $document->id()]),
                'opens' => Opens::SHEET,
                'label' => $document->title(),
                'secondary' => $status['label'],
                'cells' => $cells,
            ];
        }, $documents);
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function status(DocumentStatus $status): array
    {
        return ['label' => $this->trans('documents.status.'.$status->value), 'tone' => self::TONES[$status->value]];
    }

    /**
     * @return list<array<string, mixed>> the details of Ui:DescriptionList
     */
    public function details(Document $document): array
    {
        $type = $this->store->type($document->typeId());
        $template = $this->store->template($document->templateId());
        $version = null === $document->templateVersionId() ? null : $this->store->version($document->templateVersionId());

        return [
            ['label' => $this->trans('documents.field.type'), 'value' => $type?->name()],
            ['label' => $this->trans('documents.field.template'), 'value' => $template instanceof DocumentTemplate ? $version instanceof \Foresi\Documents\Domain\TemplateVersion ? $this->trans('documents.detail.template_version', ['name' => $template->name(), 'number' => $version->number()]) : $template->name() : (null)],
            ['label' => $this->trans('documents.detail.created'), 'value' => $this->day($document->createdAt(), true)],
            ['label' => $this->trans('documents.detail.generated'), 'value' => $document->generatedAt() instanceof \DateTimeImmutable ? $this->day($document->generatedAt(), true) : null, 'empty' => $this->trans('documents.none.generated')],
            ['label' => $this->trans('documents.detail.sent'), 'value' => $document->sentAt() instanceof \DateTimeImmutable ? $this->day($document->sentAt(), true) : null, 'empty' => $this->trans('documents.none.sent')],
            ['label' => $this->trans('documents.detail.completed'), 'value' => $document->completedAt() instanceof \DateTimeImmutable ? $this->day($document->completedAt(), true) : null, 'empty' => $this->trans('documents.none.completed')],
        ];
    }

    /**
     * What each link shows: the record's name and screen, or unavailable when it is gone.
     *
     * @return list<LinkView>
     */
    public function links(Document $document): array
    {
        return array_map(fn (DocumentLink $link): LinkView => $this->targets->describe(new RecordLink($link->kind(), $link->targetId())), $this->store->links($document));
    }

    /**
     * The signers by their signing order, ascending: signers of one order sign
     * at the same time, as DocuSign's routing order puts them.
     *
     * @return list<array{order: int, parallel: bool, signers: list<array<string, mixed>>}>
     */
    public function signerGroups(Document $document): array
    {
        $groups = [];
        foreach ($this->store->signers($document) as $signer) {
            $groups[$signer->order()][] = [
                'id' => $signer->id(),
                'name' => $signer->name(),
                'email' => $signer->email(),
                'side' => $this->trans('documents.signer.side.'.$signer->side()->value),
                'status' => $signer->status()->value,
                'badge' => $this->signerStatus($signer),
                'pending' => SignerStatus::Pending === $signer->status(),
            ];
        }
        ksort($groups);
        $list = [];
        foreach ($groups as $order => $signers) {
            $list[] = ['order' => $order, 'parallel' => \count($signers) > 1, 'signers' => $signers];
        }

        return $list;
    }

    /**
     * @return list<Signer> the signers who have not signed or declined yet
     */
    public function pendingSigners(Document $document): array
    {
        return array_values(array_filter($this->store->signers($document), static fn (Signer $signer): bool => SignerStatus::Pending === $signer->status()));
    }

    /**
     * @return array<string, string> the active types by id, for a select
     */
    public function typeOptions(): array
    {
        $options = [];
        foreach ($this->store->types(false) as $type) {
            $options[$type->id()] = $type->name();
        }

        return $options;
    }

    /**
     * The active templates grouped under the name of their type, so the
     * template of the type chosen is the one picked (Ui:Select groups).
     *
     * @return array<string, array<string, string>>
     */
    public function templateOptions(): array
    {
        $options = [];
        foreach ($this->store->types(false) as $type) {
            foreach ($this->store->templates($type->id(), false) as $template) {
                $options[$type->name()][$template->id()] = $template->name();
            }
        }

        return $options;
    }

    /**
     * @return array<string, string> the statuses, for the filter
     */
    public function statusOptions(): array
    {
        $options = ['' => $this->trans('documents.filter.any_status')];
        foreach (DocumentStatus::cases() as $status) {
            if (DocumentStatus::Expired !== $status) {
                $options[$status->value] = $this->trans('documents.status.'.$status->value);
            }
        }

        return $options;
    }

    /**
     * @return array<string, string> the types, archived ones too, for the filter
     */
    public function typeFilterOptions(): array
    {
        return ['' => $this->trans('documents.filter.any_type')] + $this->typeNames();
    }

    /**
     * The first active template of a type, for a new document of that type.
     */
    public function firstTemplate(?string $typeId): ?DocumentTemplate
    {
        if (null === $typeId) {
            return null;
        }

        return $this->store->templates($typeId, false)[0] ?? null;
    }

    public function type(string $id): ?DocumentType
    {
        return $this->store->type($id);
    }

    /**
     * A refusal as a sentence of the person's language: the text of its
     * reason and field, of its reason, else the use case's own message. The
     * variables a template misses are named after it.
     */
    public function refusal(DocumentsRefused $refused): string
    {
        $key = 'documents.error.'.$refused->reason();
        $parameters = array_filter($refused->details(), static fn (mixed $value): bool => \is_string($value) || \is_int($value));
        $text = $this->translated($key.'.'.$refused->field(), $parameters) ?? $this->translated($key, $parameters) ?? $refused->getMessage();
        $missing = $refused->details()['missing'] ?? null;
        if (\is_array($missing) && [] !== $missing) {
            $text .= ' '.implode(', ', $missing);
        }

        return $text;
    }

    /**
     * "3 Dec", or with the year "3 Dec 2026", as the instance's locale writes it.
     */
    public function day(\DateTimeImmutable $day, bool $year = false): string
    {
        $locale = $this->locales->getLocale();
        $day = $day->setTimezone($this->clock->timezone());
        $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern($year ? 'dMMMy' : 'dMMM');
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, false === $pattern ? 'd MMM' : $pattern);
        $text = $formatter->format($day);

        return false === $text ? $day->format('Y-m-d') : $text;
    }

    /**
     * @return array<string, string> the names of all types, archived ones too, by id
     */
    private function typeNames(): array
    {
        $names = [];
        foreach ($this->store->types(true) as $type) {
            $names[$type->id()] = $type->name();
        }

        return $names;
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function signerStatus(Signer $signer): array
    {
        return match ($signer->status()) {
            SignerStatus::Pending => ['label' => $this->trans('documents.signer.status.pending'), 'tone' => 'gray'],
            SignerStatus::Signed => ['label' => $signer->signedAt() instanceof \DateTimeImmutable ? $this->trans('documents.signer.status.signed_on', ['day' => $this->day($signer->signedAt(), true)]) : $this->trans('documents.signer.status.signed'), 'tone' => 'green'],
            SignerStatus::Declined => ['label' => $this->trans('documents.signer.status.declined'), 'tone' => 'red'],
        };
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function translated(string $key, array $parameters): ?string
    {
        $text = $this->translator->trans($key, $parameters);

        return $text === $key ? null : $text;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
