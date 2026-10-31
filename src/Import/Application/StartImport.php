<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Message\ReadImportFile;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Domain\ImportProfile;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Registers an uploaded file as a new import of the active tenant and has it
 * read in the background. Takes plain values, so the screens, the API and MCP
 * share it.
 */
final readonly class StartImport
{
    public function __construct(
        private TargetRegistry $targets,
        private ImportFiles $files,
        private Imports $imports,
        private ProfileRepository $profiles,
        private ImportJobs $jobs,
        private TenantContext $tenants,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string   $tmpPath      the uploaded file; it is copied, the caller removes it
     * @param string   $originalName as the client sent it; it is normalised (ImportFileName)
     * @param int|null $profileId    a profile of the target to rerun, with its mapping and options
     *
     * @return int the id of the new import
     */
    public function start(string $targetKey, string $tmpPath, string $originalName, int $actorUserId, ?int $profileId = null): int
    {
        $target = $this->targets->get($targetKey);
        $profile = null;
        if (null !== $profileId) {
            $profile = $this->profiles->find($profileId);
            if (!$profile instanceof ImportProfile || $profile->targetKey() !== $target->key()) {
                throw new ImportRefused('import.refused.unknown_profile');
            }
        }

        $originalName = ImportFileName::normalise($originalName);
        // The reader detects the format from the content; the name only gives a first guess.
        $format = 'xlsx' === strtolower(pathinfo($originalName, \PATHINFO_EXTENSION)) ? SourceFormat::Xlsx : SourceFormat::Csv;
        $fileKey = $this->files->store($this->tenants->current(), $tmpPath, $originalName);
        try {
            $import = new Import($target->key(), $fileKey, $originalName, new SourceSettings($format)->toArray(), $actorUserId, \DateTimeImmutable::createFromInterface($this->clock->now()));
            if ($profile instanceof ImportProfile) {
                $import->useMapping($profile->mapping(), $profile->options(), $profile);
            }
            $this->imports->add($import);
        } catch (\Throwable $failure) {
            // No import refers to the stored file, so the purge would never remove it.
            $this->files->delete($fileKey);

            throw $failure;
        }
        $id = $import->id() ?? throw new \LogicException('A stored import has an id.');
        $this->jobs->dispatch(new ReadImportFile($id));

        return $id;
    }
}
