<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A module may depend on Party's contract alone, and the contract is enough to
 * read contacts and add a contact tab: Deptrac passes a fixture module that
 * uses only Party\Contract and fails one that reaches into the module.
 */
final class PartyContractDeptracTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/deptrac-party-contract-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir.'/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAFixtureModuleThatUsesOnlyThePartyContractPasses(): void
    {
        $process = $this->analyse('Good');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function testAFixtureModuleThatReachesIntoPartyFails(): void
    {
        $process = $this->analyse('ReachingIn');

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('ContactDetails', $process->getOutput());
    }

    private function analyse(string $fixture): Process
    {
        $root = \dirname(__DIR__, 3);
        $config = $this->dir.'/deptrac.yaml';
        file_put_contents($config, <<<YAML
            deptrac:
              paths:
                - {$root}/src/Party
                - {$root}/tests/Fixtures/PartyConsumer/{$fixture}
              layers:
                - name: consumer
                  collectors:
                    - type: classLike
                      value: ^Foresi\\\\Tests\\\\Fixtures\\\\PartyConsumer\\\\.*
                - name: party
                  collectors:
                    - type: classLike
                      value: ^Foresi\\\\Party\\\\(?!Contract\\\\).*
                - name: party_contract
                  collectors:
                    - type: classLike
                      value: ^Foresi\\\\Party\\\\Contract\\\\.*
              ruleset:
                consumer:
                  - party_contract
                party:
                  - party_contract
            YAML);
        $process = new Process([\PHP_BINARY, $root.'/vendor/bin/deptrac', 'analyse', '--config-file='.$config, '--cache-file='.$this->dir.'/deptrac.cache', '--no-progress', '--no-interaction'], $root);
        $process->run();

        return $process;
    }
}
