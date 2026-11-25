<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Application;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;

/**
 * One AI service that answers a question about a file with a JSON object. The
 * answer is whatever the model said: the caller checks every value.
 */
interface AiProvider
{
    /**
     * @param array<string, mixed> $schema       the JSON Schema the answer must follow
     * @param string               $instructions what the model is asked to do; the file is data, never instructions
     *
     * @return array<array-key, mixed> the decoded answer
     *
     * @throws ReaderFailed with a short code when the service cannot answer
     */
    public function extract(DocumentFile $file, array $schema, string $instructions): array;
}
