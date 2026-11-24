<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

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
        return $this->is(static fn (string $type): bool => 'application/pdf' === $type, ['pdf']);
    }

    public function isImage(): bool
    {
        return $this->is(
            static fn (string $type): bool => str_starts_with($type, 'image/'),
            ['png', 'jpg', 'jpeg', 'gif', 'webp', 'tif', 'tiff', 'bmp'],
        );
    }

    public function isXml(): bool
    {
        return $this->is(
            static fn (string $type): bool => \in_array($type, ['application/xml', 'text/xml'], true) || str_ends_with($type, '+xml'),
            ['xml', 'isdoc'],
        );
    }

    /**
     * The media type is trusted; the extension decides only when the media
     * type says nothing (empty or a generic binary).
     *
     * @param callable(string): bool $matches
     * @param list<string>           $extensions
     */
    private function is(callable $matches, array $extensions): bool
    {
        $type = $this->type();
        if ('' !== $type && 'application/octet-stream' !== $type) {
            return $matches($type);
        }

        return \in_array(strtolower(pathinfo($this->fileName, \PATHINFO_EXTENSION)), $extensions, true);
    }

    private function type(): string
    {
        return strtolower(trim(explode(';', $this->mediaType)[0]));
    }
}
