<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

/**
 * A file offered to the readers: its name, its media type as received and its bytes.
 */
final readonly class DocumentFile
{
    public function __construct(
        public string $fileName,
        public string $mediaType,
        public string $bytes,
    ) {
    }

    public function isPdf(): bool
    {
        return $this->is(['application/pdf'], ['pdf']);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->type(), 'image/') || $this->is([], ['png', 'jpg', 'jpeg', 'gif', 'webp', 'tif', 'tiff', 'bmp']);
    }

    public function isXml(): bool
    {
        return $this->is(['application/xml', 'text/xml'], ['xml', 'isdoc']);
    }

    /**
     * @param list<string> $mediaTypes
     * @param list<string> $extensions
     */
    private function is(array $mediaTypes, array $extensions): bool
    {
        if (\in_array($this->type(), $mediaTypes, true)) {
            return true;
        }

        return \in_array(strtolower(pathinfo($this->fileName, \PATHINFO_EXTENSION)), $extensions, true);
    }

    private function type(): string
    {
        return strtolower(trim(explode(';', $this->mediaType)[0]));
    }
}
