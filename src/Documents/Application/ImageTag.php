<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Twig\Markup;

/**
 * The <img> an image variable prints. It fits the image into 52 by 16 mm, the
 * logo box of an invoice; a template sets other bounds with both width and
 * height in its CSS. The PDF reads the local file; the preview in the browser
 * gets the file's bytes inline, as it cannot read the server's disk.
 */
final class ImageTag
{
    /** The most the image takes, in millimetres. */
    public const array BOX_MM = [52.0, 16.0];
    private const float PX_PER_MM = 96 / 25.4;

    /**
     * @return Markup|null null when the file is no image
     */
    public static function forPdf(string $file, string $alt): ?Markup
    {
        $size = self::fitted($file);
        if (null === $size) {
            return null;
        }

        return self::tag($file, $alt, \sprintf('%.2Fmm', $size[0]), \sprintf('%.2Fmm', $size[1]));
    }

    /**
     * @return Markup|null null when the file is no image
     */
    public static function forPreview(string $file, string $alt): ?Markup
    {
        $size = self::fitted($file);
        if (null === $size) {
            return null;
        }

        return self::tag('data:'.$size[2].';base64,'.base64_encode((string) file_get_contents($file)), $alt, (string) round($size[0] * self::PX_PER_MM), (string) round($size[1] * self::PX_PER_MM));
    }

    /**
     * What the preview shows for an image it has no file for, such as the example of the template settings.
     */
    public static function placeholder(string $label): Markup
    {
        return new Markup(\sprintf(
            '<span style="display:inline-flex;align-items:center;justify-content:center;width:%smm;height:%smm;border:1px dashed #9ca3af;color:#6b7280;font-size:9pt;vertical-align:middle">%s</span>',
            self::BOX_MM[0],
            self::BOX_MM[1],
            htmlspecialchars($label),
        ), 'UTF-8');
    }

    /**
     * @return array{float, float, string}|null the width and height in millimetres, and the MIME type
     */
    private static function fitted(string $file): ?array
    {
        $size = is_file($file) ? getimagesize($file) : false;
        if (false === $size || !\in_array($size['mime'], ['image/png', 'image/jpeg'], true)) {
            return null;
        }
        $scale = min(self::BOX_MM[0] / $size[0], self::BOX_MM[1] / $size[1]);

        return [$size[0] * $scale, $size[1] * $scale, $size['mime']];
    }

    private static function tag(string $src, string $alt, string $width, string $height): Markup
    {
        return new Markup(\sprintf('<img src="%s" alt="%s" width="%s" height="%s">', htmlspecialchars($src), htmlspecialchars($alt), $width, $height), 'UTF-8');
    }
}
