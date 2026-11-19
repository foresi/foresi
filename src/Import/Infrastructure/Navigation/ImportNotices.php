<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Infrastructure\Navigation;

use Foresi\Audit\Application\AuditActor;
use Foresi\Import\Application\Imports;
use Foresi\Import\Domain\ImportState;
use Foresi\Ui\Contract\Notice\ShellNotice;
use Foresi\Ui\Contract\Notice\ShellNoticeProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells the user on every page that an import of theirs ended, until they open
 * its summary. A new import starts from the pages of the modules it fills, and
 * its history is under Settings.
 */
final readonly class ImportNotices implements ShellNoticeProvider
{
    private const int LIMIT = 3;

    public function __construct(
        private Imports $imports,
        private AuditActor $actor,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function notices(): iterable
    {
        $user = $this->actor->userId();
        if (null === $user) {
            return;
        }
        foreach ($this->imports->endedUnseen($user, self::LIMIT) as $import) {
            $state = $import->state();
            yield new ShellNotice(
                match ($state) {
                    ImportState::Finished, ImportState::Undone => 'success',
                    ImportState::FinishedWithErrors => 'warning',
                    ImportState::Stopped, ImportState::Failed => 'error',
                    default => 'info',
                },
                $this->translator->trans('import.notice.'.$state->value, ['name' => $import->originalName()]),
                $this->translator->trans('import.notice.link'),
                $this->urls->generate('app_import_show', ['id' => $import->id()]),
            );
        }
    }
}
