<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Application;

/**
 * What the operator set in the environment for the AI reader: which provider,
 * its key, the model and when the reader runs. Without a provider and a key,
 * or with a trigger it does not know, the reader is inert.
 *
 * The key is never held as text: it is kept masked with a random pad of the
 * instance, so no dump of the object (`var_export`, a cast to an array, a log
 * of its properties) shows it, `__debugInfo()` leaves it out and the object
 * cannot be serialized. It is read through {@see apiKey()} only.
 */
final readonly class AiReaderSettings
{
    public const string CLAUDE = 'claude';
    public const string DEFAULT_MODEL = 'claude-sonnet-5-5';
    public const string ARRIVAL = 'arrival';
    public const string CLICK = 'click';

    private string $provider;
    private string $model;
    private bool $automatic;
    private bool $triggerIsValid;
    private string $masked;
    private string $pad;

    /**
     * @param string $provider `claude`, or empty for none
     * @param string $model    empty for the default model
     * @param string $trigger  `arrival` (or empty): the reader runs when a file arrives; `click`: only when a person asks
     *
     *                         any other trigger leaves the reader off (see {@see triggerIsValid()}) and never throws, so a
     *                         mistake in the environment cannot break a page
     */
    public function __construct(string $provider, #[\SensitiveParameter] string $apiKey, string $model, string $trigger)
    {
        $trigger = strtolower(trim($trigger));
        $key = trim($apiKey);
        $this->provider = strtolower(trim($provider));
        $this->triggerIsValid = self::CLAUDE !== $this->provider || '' === $key || \in_array($trigger, ['', self::ARRIVAL, self::CLICK], true);
        $this->model = '' === trim($model) ? self::DEFAULT_MODEL : trim($model);
        $this->automatic = self::CLICK !== $trigger;
        $this->pad = random_bytes(max(1, \strlen($key)));
        $this->masked = $key ^ $this->pad;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * Whether a provider the module knows is set and has a key.
     */
    public function isConfigured(): bool
    {
        return $this->triggerIsValid && self::CLAUDE === $this->provider && '' !== $this->masked;
    }

    /**
     * False when a provider and a key are set but the trigger is neither `arrival`, `click` nor empty: the reader
     * is then off. Without a provider and a key there is nothing to be wrong, so it is true.
     */
    public function triggerIsValid(): bool
    {
        return $this->triggerIsValid;
    }

    /**
     * Whether the reader runs when a file arrives; otherwise only when a person asks.
     */
    public function automatic(): bool
    {
        return $this->automatic;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * The key, for the adapter of the provider that sends it; it is never shown or logged.
     */
    public function apiKey(): string
    {
        return $this->masked ^ $this->pad;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('The settings of the AI reader are not serialized.');
    }

    /**
     * @return array<string, string|bool>
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'model' => $this->model, 'automatic' => $this->automatic, 'configured' => $this->isConfigured()];
    }
}
