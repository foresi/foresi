<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Column;

use Foresi\Documents\Application\View\DocumentTemplateLists;
use Foresi\Documents\Application\View\DocumentTypeLists;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the two tables on Settings → Documents, those the page always showed: of a
 * type its name, code and signing with the archive; of a template its name, type and version
 * with the archive.
 */
final readonly class DocumentSettingsColumns implements DataSource
{
    public const string TYPES = DocumentTypeLists::KEY;
    public const string TEMPLATES = DocumentTemplateLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        $label = static fn (string $key): string => 'documents.settings.column.'.$key;
        $archived = fn (bool $archived): array => $archived ? [['label' => $this->translator->trans('documents.settings.archived'), 'tone' => 'gray']] : [];

        return match ($list) {
            self::TYPES => [
                new Attribute('name', $label('name'), AttributeType::Text, static fn (DocumentType $type): string => $type->name(), defaultVisible: true, defaultPosition: 10, cell: static fn (DocumentType $type): string => $type->name(), locked: true, truncate: true),
                new Attribute('code', $label('code'), AttributeType::Text, static fn (DocumentType $type): string => $type->code(), defaultVisible: true, defaultPosition: 20, cell: static fn (DocumentType $type): string => $type->code(), phone: false),
                new Attribute('signing', $label('signing'), AttributeType::Text, fn (DocumentType $type): string => $this->signing($type), defaultVisible: true, defaultPosition: 30, cell: fn (DocumentType $type): array => ['badges' => [
                    ['label' => $this->signing($type), 'tone' => $type->needsSigning() ? 'gray' : 'outline'],
                    ...$archived($type->isArchived()),
                ]]),
            ],
            self::TEMPLATES => [
                new Attribute('name', $label('name'), AttributeType::Text, static fn (TemplateRow $row): string => $row->template->name(), defaultVisible: true, defaultPosition: 10, cell: static fn (TemplateRow $row): string => $row->template->name(), locked: true, truncate: true),
                new Attribute('type', $label('type'), AttributeType::Text, static fn (TemplateRow $row): string => $row->type, defaultVisible: true, defaultPosition: 20, cell: static fn (TemplateRow $row): string => $row->type, phone: false),
                new Attribute('version', $label('version'), AttributeType::Text, static fn (TemplateRow $row): string => (string) $row->version?->number(), defaultVisible: true, defaultPosition: 30, cell: function (TemplateRow $row) use ($archived): ?array {
                    $badges = [
                        ...($row->version instanceof TemplateVersion ? [['label' => $this->translator->trans('documents.settings.version', ['number' => $row->version->number()]), 'tone' => $row->version->isFrozen() ? 'green' : 'gray']] : []),
                        ...$archived($row->template->isArchived()),
                    ];

                    // A template made before its first version has none to show.
                    return [] === $badges ? null : ['badges' => $badges];
                }),
            ],
            default => [],
        };
    }

    private function signing(DocumentType $type): string
    {
        return $this->translator->trans($type->needsSigning() ? 'documents.settings.level.'.$type->signatureLevel()->value : 'documents.settings.not_signed');
    }
}
