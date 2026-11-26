<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Foresi\Ui\Avatar as Initials;
use Foresi\Ui\Contract\PersonPhotos;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A person as a circle with the initials of their name on a colour of the brand that "seed",
 * such as the user id, always picks the same (Foresi\Ui\Avatar). A circle is a person and a
 * square is a project or a firm (Atlassian Avatar); the initials stand in for a photo
 * (Polaris Avatar). With "photo" the person's own picture fills the circle instead, cut to it
 * (object-cover), with an empty alt as the name is beside it or on the circle. With "user", the
 * id of the person who uses Foresi, the photo is theirs when they have one (PersonPhotos); a
 * contact who is no user has no photo and keeps the initials.
 *
 * The name is usually written next to it, so the avatar is hidden from assistive tech; with
 * "labelled" it stands alone and is an image named by the full name. Without a name it is the
 * "nobody" placeholder: a dashed circle with a person icon.
 */
#[AsTwigComponent]
final class Avatar
{
    private const array SIZES = [
        'sm' => 'size-6 text-caption',
        'md' => 'size-8 text-caption',
        'lg' => 'size-9 text-small',
        // The person on their own profile (GitHub, Google Account: the large circle at the top).
        'xl' => 'size-24 text-title',
    ];

    private const array ICONS = ['sm' => 'size-3.5', 'md' => 'size-4', 'lg' => 'size-5', 'xl' => 'size-10'];

    /** The full name; empty for nobody. */
    public string $name = '';
    /** What picks the colour; the name when not given. */
    public ?string $seed = null;
    public string $size = 'sm';
    /** True when no name is written beside it: the avatar is then an image named by the name. */
    public bool $labelled = false;
    /** The address of the person's photo, shown in place of the initials; null for none. */
    public ?string $photo = null;
    /** The id of the user the avatar shows, whose photo it takes when "photo" is not given. */
    public ?string $user = null;

    /**
     * @param iterable<PersonPhotos> $photos
     */
    public function __construct(#[AutowireIterator(PersonPhotos::TAG)] private readonly iterable $photos = [])
    {
    }

    #[PostMount]
    public function validate(): void
    {
        if (null === $this->photo && null !== $this->user && '' !== $this->user && !$this->nobody()) {
            foreach ($this->photos as $photos) {
                $this->photo ??= $photos->url($this->user);
            }
        }
        $this->classes();
    }

    public function nobody(): bool
    {
        return '' === trim($this->name);
    }

    public function initials(): string
    {
        return Initials::initials($this->name);
    }

    public function classes(): string
    {
        $size = self::SIZES[$this->size] ?? throw new \InvalidArgumentException(sprintf('Unknown avatar size "%s", expected one of: %s.', $this->size, implode(', ', array_keys(self::SIZES))));
        $look = match (true) {
            null !== $this->photo => 'overflow-hidden bg-frame',
            $this->nobody() => 'border border-dashed border-container bg-white text-muted',
            default => Initials::tone($this->seed ?? $this->name),
        };

        return 'inline-grid shrink-0 place-items-center rounded-avatar font-semibold leading-none select-none '.$size.' '.$look;
    }

    public function iconClasses(): string
    {
        return self::ICONS[$this->size] ?? 'size-4';
    }
}
