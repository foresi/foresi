<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Mail\RecentMail;
use Foresi\Purchasing\Application\View\CaptureMailLists;
use Foresi\Purchasing\Domain\MailIntakeOutcome;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the recent e-mails of the capture address, those the page always showed: when
 * a mail came, its sender and subject, what came of it and the parts left out. The sender, the
 * subject and the names of the parts are the sender's text: Twig escapes them.
 */
final readonly class CaptureMailColumns implements DataSource
{
    public const string LIST = CaptureMailLists::KEY;

    public function __construct(private TranslatorInterface $translator, private InstanceClock $clock)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'purchasing.capture.recent.column.'.$key;
        $gone = fn (): array => ['empty' => $this->translator->trans('purchasing.capture.recent.gone')];
        $received = fn (RecentMail $mail): string => $mail->receivedAt->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime'));
        $outcome = fn (RecentMail $mail): array => match ($mail->outcome) {
            MailIntakeOutcome::Filed => ['text' => $this->translator->trans('purchasing.capture.recent.filed', ['count' => $mail->drafts]), 'tone' => 'green'],
            MailIntakeOutcome::NothingAccepted => ['text' => $this->translator->trans('purchasing.capture.recent.nothing_accepted'), 'tone' => 'gray'],
            MailIntakeOutcome::Failed => ['text' => $this->translator->trans('purchasing.capture.recent.failed'), 'tone' => 'red'],
            MailIntakeOutcome::Queued => ['text' => $this->translator->trans('purchasing.capture.recent.queued'), 'tone' => 'amber'],
        };
        $skipped = function (RecentMail $mail): string {
            $parts = array_map(fn (array $part): string => $this->translator->trans('purchasing.skipped.'.$part['reason'], ['name' => $part['name']]), $mail->skipped);
            $more = $mail->skippedCount - \count($mail->skipped);
            if ($more > 0) {
                $parts[] = $this->translator->trans('purchasing.skipped.more', ['count' => $more]);
            }

            return implode('; ', $parts);
        };

        return [
            new Attribute('received', $label('received'), AttributeType::Text, $received, defaultVisible: true, defaultPosition: 10, cell: $received, locked: true),
            new Attribute('from', $label('from'), AttributeType::Text, static fn (RecentMail $mail): ?string => $mail->from, defaultVisible: true, defaultPosition: 20, cell: fn (RecentMail $mail): array|string => $mail->from ?? $gone(), truncate: true),
            new Attribute('subject', $label('subject'), AttributeType::Text, static fn (RecentMail $mail): ?string => $mail->subject, defaultVisible: true, defaultPosition: 30, cell: fn (RecentMail $mail): array|string => $mail->subject ?? $gone(), phone: false, truncate: true),
            new Attribute('outcome', $label('outcome'), AttributeType::Text, fn (RecentMail $mail): string => $outcome($mail)['text'], defaultVisible: true, defaultPosition: 40, cell: $outcome),
            new Attribute('skipped', $label('skipped'), AttributeType::Text, $skipped, defaultVisible: true, defaultPosition: 50, cell: fn (RecentMail $mail): array|string => '' === $skipped($mail) ? ['empty' => $this->translator->trans('purchasing.capture.recent.none_skipped')] : $skipped($mail), phone: false, truncate: true),
        ];
    }
}
