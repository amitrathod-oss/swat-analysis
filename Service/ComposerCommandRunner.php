<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Service;

use Symfony\Component\Process\Process;

class ComposerCommandRunner
{
    /**
     * @return array<string, int|string>
     */
    public function audit(string $workingDirectory, int $timeout): array
    {
        return $this->run(['composer', 'audit', '--format=json', '--no-interaction', '--no-ansi'], $workingDirectory, $timeout);
    }

    /**
     * @return array<string, int|string>
     */
    public function version(string $workingDirectory, int $timeout): array
    {
        return $this->run(['composer', '--version', '--no-interaction', '--no-ansi'], $workingDirectory, $timeout);
    }

    /**
     * Run only fixed Composer commands; no user input is interpolated into the process command.
     *
     * @param string[] $command
     * @return array<string, int|string>
     */
    private function run(array $command, string $workingDirectory, int $timeout): array
    {
        if ($command[0] !== 'composer') {
            throw new \InvalidArgumentException('Only Composer commands are allowed.');
        }

        $process = new Process($command, $workingDirectory, ['COMPOSER_DISABLE_XDEBUG_WARN' => '1'], null, $timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ];
    }
}
