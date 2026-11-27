<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Settings;

use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\ReceivedDocuments\Contract\PurchasingSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * The state of reading with AI on Settings → Received documents: not set up, with where to read how, or the
 * provider, the model and when it runs. It always says that a document read by AI is sent to the provider. The
 * key is never shown: only the settings' own answers are read here, never {@see AiReaderSettings::apiKey()}.
 */
final readonly class AiReaderStatus implements SlotProvider
{
    public const string TEMPLATE = '@AiReader/settings/_status.html.twig';
    public const string DOCS = 'https://github.com/foresi/foresi/blob/main/docs/ai-reader.md';

    private const int WEIGHT = 10;

    /** The names the settings show for the providers the module knows. */
    private const array NAMES = [AiReaderSettings::CLAUDE => 'Claude'];

    public function __construct(private AiReaderSettings $settings)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        if (PurchasingSlots::SETTINGS_SECTIONS !== $slot) {
            return [];
        }

        return [new SlotItem(self::TEMPLATE, [
            'configured' => $this->settings->isConfigured(),
            'provider' => self::NAMES[$this->settings->provider()] ?? $this->settings->provider(),
            'model' => $this->settings->model(),
            'automatic' => $this->settings->automatic(),
            'trigger_valid' => $this->settings->triggerIsValid(),
            'docs' => self::DOCS,
        ], self::WEIGHT)];
    }
}
