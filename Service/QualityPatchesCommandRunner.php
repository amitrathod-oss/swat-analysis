<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Service;

use Symfony\Component\Process\Process;

class QualityPatchesCommandRunner
{
    /** @return array<string, int|string> */
    public function status(string $executable, string $workingDirectory, int $timeout = 60): array
    {
        $process = new Process([$executable, 'status', '--format=json', '--no-interaction', '--no-ansi'], $workingDirectory, [], null, $timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
        ];
    }
}
