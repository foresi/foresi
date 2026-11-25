<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Console;

use Foresi\AiReader\Application\AiReaderSettings;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A one-off instrument for the operator: reads every PDF, PNG and JPEG file of a directory with the AI reader,
 * whatever the trigger, and writes one JSON line per file. The files are the operator's own and stay where they
 * are; the key never reaches the output.
 */
#[AsCommand(name: 'ai-reader:measure', description: 'Read the files of a directory with the AI reader and write one JSON line per file')]
final readonly class MeasureCommand
{
    public function __construct(private AiReaderSettings $settings, private Measurement $measurement)
    {
    }

    public function __invoke(
        OutputInterface $out,
        #[Argument(description: 'The directory with the PDF, PNG and JPEG files to read')]
        string $directory,
        #[Option(description: 'Read with this model instead of the one in AI_READER_MODEL')]
        string $model = '',
        #[Option(description: 'Write the lines to this file instead of the standard output', name: 'output')]
        string $file = '',
    ): int {
        $errors = $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
        if (!$this->settings->isConfigured()) {
            $errors->writeln('The AI reader is not set up: set AI_READER_PROVIDER and AI_READER_API_KEY.', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }
        if (!is_dir($directory)) {
            $errors->writeln('The directory does not exist.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }
        $target = '' === $file ? null : @fopen($file, 'wb');
        if ('' !== $file && false === $target) {
            $errors->writeln('The output file cannot be written.', OutputInterface::OUTPUT_RAW);

            return Command::INVALID;
        }

        foreach ($this->measurement->files($directory) as $path) {
            $line = json_encode($this->measurement->read($path, $model), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            if (\is_resource($target)) {
                fwrite($target, $line."\n");
            } else {
                $out->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }
        if (\is_resource($target)) {
            fclose($target);
        }

        return Command::SUCCESS;
    }
}
