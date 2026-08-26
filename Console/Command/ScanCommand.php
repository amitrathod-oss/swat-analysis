<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Console\Command;

use Sigma\HealthCheck\Model\ScanRunner;
use Sigma\HealthCheck\Report\HtmlReportGenerator;
use Sigma\HealthCheck\Report\JsonReportGenerator;
use Sigma\HealthCheck\Report\PdfReportGenerator;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScanCommand extends Command
{
    public const COMMAND_NAME = 'health:scan';

    private ScanRunner $scanRunner;
    private JsonReportGenerator $jsonReportGenerator;
    private HtmlReportGenerator $htmlReportGenerator;
    private PdfReportGenerator $pdfReportGenerator;

    public function __construct(
        ScanRunner $scanRunner,
        JsonReportGenerator $jsonReportGenerator,
        HtmlReportGenerator $htmlReportGenerator,
        PdfReportGenerator $pdfReportGenerator,
        ?string $name = null
    ) {
        $this->scanRunner = $scanRunner;
        $this->jsonReportGenerator = $jsonReportGenerator;
        $this->htmlReportGenerator = $htmlReportGenerator;
        $this->pdfReportGenerator = $pdfReportGenerator;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME);
        $this->setDescription('Run the read-only Magento Open Source health scan.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: json, html, or pdf.', 'json');
        $this->addOption('magento-root', null, InputOption::VALUE_REQUIRED, 'Magento root used for filesystem checks.', defined('BP') ? BP : getcwd());
        $this->addOption('base-url', null, InputOption::VALUE_OPTIONAL, 'Base URL used for HTTP checks.');
        $this->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output file or directory for the requested report.');
        $this->addOption('only', null, InputOption::VALUE_OPTIONAL, 'Comma-separated collector codes to run.');
        $this->addOption('skip', null, InputOption::VALUE_OPTIONAL, 'Comma-separated collector codes to skip.');
        $this->addOption('no-history', null, InputOption::VALUE_NONE, 'Do not write a history snapshot.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string)$input->getOption('format');
        if (!in_array($format, ['json', 'html', 'pdf'], true)) {
            $output->writeln('<error>Supported report formats are json, html, and pdf.</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $context = [
                'magento_root' => (string)$input->getOption('magento-root'),
                'base_url' => (string)($input->getOption('base-url') ?? ''),
                'only' => $this->csvOption($input->getOption('only')),
                'skip' => $this->csvOption($input->getOption('skip')),
                'no_history' => (bool)$input->getOption('no-history'),
            ];
            $scanResult = $this->scanRunner->run($context);
            $outputPath = (string)($input->getOption('output') ?? '');
            $target = $outputPath === '' ? null : $this->targetPath($outputPath, $format);
            $reportPath = match ($format) {
                'html' => $target === null ? $this->htmlReportGenerator->write($scanResult) : $this->htmlReportGenerator->writeTo($scanResult, $target),
                'pdf' => $target === null ? $this->pdfReportGenerator->write($scanResult) : $this->pdfReportGenerator->writeTo($scanResult, $target),
                default => $target === null ? $this->jsonReportGenerator->write($scanResult) : $this->jsonReportGenerator->writeTo($scanResult, $target),
            };
            $output->writeln(sprintf('<info>Health scan completed. %s report: %s</info>', strtoupper($format), $reportPath));
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $exception) {
            $output->writeln('<error>Health scan could not generate its report.</error>');
            if ($output->isVerbose()) {
                $output->writeln('<comment>' . $this->escapeCliError($exception->getMessage()) . '</comment>');
            }
            return Cli::RETURN_FAILURE;
        }
    }

    /** @return array<int, string> */
    private function csvOption($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function targetPath(string $path, string $format): string
    {
        $extension = '.' . $format;
        return str_ends_with(strtolower($path), $extension) ? $path : rtrim($path, '/') . '/health-report' . $extension;
    }

    private function escapeCliError(string $message): string
    {
        return htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
