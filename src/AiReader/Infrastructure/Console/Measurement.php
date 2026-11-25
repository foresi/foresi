<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Console;

use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\AiReader\Infrastructure\AiDocumentReader;
use Foresi\AiReader\Infrastructure\Claude\ClaudeProvider;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads each PDF, PNG and JPEG file of a directory with the AI reader, outside the trigger, and says what it
 * read, how long it took and how many tokens it used. It is the instrument behind ai-reader:measure.
 */
final readonly class Measurement
{
    private const array TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public function __construct(private AiReaderSettings $settings, private HttpClientInterface $http)
    {
    }

    /**
     * The files this run reads, sorted by name.
     *
     * @return list<string> full paths
     */
    public function files(string $directory): array
    {
        $files = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isFile() && isset(self::TYPES[strtolower($entry->getExtension())])) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param string $model empty for the model of the settings
     *
     * @return array{file: string, ms: int, input_tokens: int|null, output_tokens: int|null, error: string|null, supplier_name: string|null, supplier_registration_number: string|null, supplier_vat_id: string|null, total_minor: int|null, currency: string|null, issue_date: string|null, vat_recap: list<array<mixed>>|null}
     */
    public function read(string $path, string $model = ''): array
    {
        // The same key and trigger, the model of this run.
        $settings = new AiReaderSettings(
            AiReaderSettings::CLAUDE,
            $this->settings->apiKey(),
            '' === $model ? $this->settings->model() : $model,
            $this->settings->automatic() ? AiReaderSettings::ARRIVAL : AiReaderSettings::CLICK,
        );
        $client = new UsageRecordingClient($this->http);
        $reader = new AiDocumentReader($settings, new ClaudeProvider($settings, $client));
        $file = new DocumentFile(basename($path), self::TYPES[strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? '', (string) file_get_contents($path));

        $reading = null;
        $error = null;
        $started = hrtime(true);
        try {
            if (!$reader->supports($file)) {
                throw new ReaderFailed('unsupported_file');
            }
            $reading = $reader->read($file);
        } catch (ReaderFailed $failed) {
            $error = $failed->getMessage();
        }
        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);
        $usage = $client->usage();

        return [
            'file' => basename($path),
            'ms' => $milliseconds,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'error' => $error,
            'supplier_name' => $this->text($reading, ReadField::SupplierName),
            'supplier_registration_number' => $this->text($reading, ReadField::SupplierRegistrationNumber),
            'supplier_vat_id' => $this->text($reading, ReadField::SupplierVatId),
            'total_minor' => $this->total($reading),
            'currency' => $this->text($reading, ReadField::Currency),
            'issue_date' => $this->text($reading, ReadField::IssueDate),
            'vat_recap' => $this->recap($reading),
        ];
    }

    private function text(?Reading $reading, ReadField $field): ?string
    {
        $value = $reading?->get($field)?->value;

        return \is_string($value) ? $value : null;
    }

    private function total(?Reading $reading): ?int
    {
        $value = $reading?->get(ReadField::Total)?->value;

        return \is_int($value) ? $value : null;
    }

    /**
     * @return list<array<mixed>>|null
     */
    private function recap(?Reading $reading): ?array
    {
        $value = $reading?->get(ReadField::VatRecap)?->value;

        if (!\is_array($value)) {
            return null;
        }
        $rows = [];
        foreach ($value as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
