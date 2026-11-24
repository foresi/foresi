<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * Reads an inbound mail setting as `%env(mail_inbound:NAME)%`: the value of
 * MAIL_INBOUND_<NAME>, or, while that is empty, of the old BANK_INBOX_<NAME>.
 *
 * The old names are read for one more release, so an installation that has not
 * renamed its settings keeps receiving mail. Each old name in use is logged once
 * per process as a deprecation, by name only: never its value. When neither is
 * set, the setting has its default, if it has one.
 */
final class LegacyMailSettings implements EnvVarProcessorInterface
{
    public const PREFIX = 'mail_inbound';

    /** What a setting is when neither name is set. */
    private const DEFAULTS = [
        'PORT' => '993',
        'ENCRYPTION' => 'ssl',
        'FOLDER' => 'INBOX',
    ];

    /** @var array<string, true> the old names already warned about */
    private array $warned = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $new = $this->read($getEnv, 'MAIL_INBOUND_'.$name);
        if ('' !== $new) {
            return $new;
        }

        $oldName = 'BANK_INBOX_'.$name;
        $old = $this->read($getEnv, $oldName);
        if ('' !== $old) {
            if (!isset($this->warned[$oldName])) {
                $this->warned[$oldName] = true;
                $this->logger->warning('The setting {old} is deprecated and will stop working in the next release; rename it to {new}.', [
                    'old' => $oldName,
                    'new' => 'MAIL_INBOUND_'.$name,
                ]);
            }

            return $old;
        }

        return self::DEFAULTS[$name] ?? '';
    }

    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }

    private function read(\Closure $getEnv, string $variable): string
    {
        try {
            $value = $getEnv($variable);
        } catch (EnvNotFoundException) {
            return '';
        }

        return \is_string($value) ? trim($value) : '';
    }
}
