<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output whose stdout and stderr are kept apart in memory.
 */
final class SplitOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errors;

    public function __construct()
    {
        parent::__construct($this->memory());
        $this->errors = new StreamOutput($this->memory());
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errors = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('Not needed in the tests.');
    }

    public function written(): string
    {
        return $this->read($this);
    }

    public function errors(): string
    {
        \assert($this->errors instanceof StreamOutput);

        return $this->read($this->errors);
    }

    private function read(StreamOutput $output): string
    {
        rewind($output->getStream());

        return (string) stream_get_contents($output->getStream());
    }

    /**
     * @return resource
     */
    private function memory()
    {
        $stream = fopen('php://memory', 'w+');
        \assert(\is_resource($stream));

        return $stream;
    }
}
