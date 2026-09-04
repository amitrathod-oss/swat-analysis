<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Report;

use Mha\HealthCheck\Model\ScanResult;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Mpdf\Mpdf;

class PdfReportGenerator
{
    private const REPORT_DIRECTORY = 'health-reports';
    private const LATEST_REPORT = self::REPORT_DIRECTORY . '/latest.pdf';

    private WriteInterface $varDirectory;
    private HtmlReportGenerator $htmlReportGenerator;

    public function __construct(Filesystem $filesystem, HtmlReportGenerator $htmlReportGenerator)
    {
        $this->varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->htmlReportGenerator = $htmlReportGenerator;
    }

    public function generate(ScanResult $scanResult): string
    {
        ini_set('pcre.backtrack_limit', '10000000');
        ini_set('pcre.recursion_limit', '10000000');

        $html = $this->htmlReportGenerator->generate($scanResult);
        // mPDF does not support browser grid/layout rules consistently. Keep
        // the report content and tables, but use its stable flow layout.
        $html = str_replace(
            ['display:grid;', 'break-inside:avoid;', 'break-after:page;'],
            ['', '', 'page-break-after:always;'],
            $html
        );
        $html = (string)preg_replace(
            '/<style>.*?<\/style>/s',
            '<style>' . $this->pdfStyles() . '</style>',
            $html
        );
        $pdf = new Mpdf([
            'format' => 'Letter',
            'tempDir' => sys_get_temp_dir(),
        ]);

        $chunks = explode('<article class="finding">', $html);
        $pdf->WriteHTML($chunks[0]);
        for ($i = 1; $i < count($chunks); $i++) {
            $pdf->WriteHTML('<article class="finding">' . $chunks[$i]);
        }

        return $pdf->Output('', 'S');
    }

    private function pdfStyles(): string
    {
        return 'body{font-family:Arial,sans-serif;font-size:10pt;color:#27313b;margin:0}'
            . 'h1{font-size:28pt;color:#173f66}h2{font-size:18pt;color:#e8751a;border-bottom:1px solid #e8751a;padding-bottom:5px}'
            . 'h3{font-size:13pt;color:#173f66}p{margin:0 0 10px}'
            . '.page-break{page-break-after:always}.cover{page-break-after:always}'
            . '.cover-copy{padding-top:45mm}.brand{color:#173f66;font-weight:bold;text-align:right}'
            . '.brand span{display:block;color:#e8751a;font-size:8pt}.eyebrow{color:#e8751a;font-weight:bold;text-transform:uppercase;font-size:8pt}'
            . '.cover-line{width:28mm;border-top:3px solid #e8751a;margin:25px 0}.customer{font-size:18pt;color:#173f66}'
            . '.dashboard-header{width:100%;border-bottom:1px solid #d7e0e7;padding-bottom:5px}.dashboard-header>div:first-child{width:67%;float:left}.dashboard-header:after{content:"";display:block;clear:both}'
            . '.dashboard-score{float:right;width:25%;background:#edf5fa;border-left:4px solid #2676a8;padding:10px;margin:0 0 10px;text-align:center}'
            . '.dashboard-score span{display:block;color:#687786;font-size:8pt;text-transform:uppercase}.dashboard-score strong{display:block;font-size:22pt;color:#173f66}'
            . '.dashboard-score small{font-size:11pt;color:#687786}.dashboard-card-grid{width:100%;border-collapse:separate;border-spacing:5px;margin:14px 0}'
            . '.dashboard-score.score-red{background:#fff1f1;border-color:#c93636}.dashboard-score.score-red strong{color:#b42318}'
            . '.dashboard-score.score-yellow{background:#fff8e1;border-color:#d99a00}.dashboard-score.score-yellow strong{color:#9a6700}'
            . '.dashboard-score.score-green{background:#ecfdf3;border-color:#24a148}.dashboard-score.score-green strong{color:#087443}'
            . '.dashboard-card-grid td.dashboard-card{width:33.33%;box-sizing:border-box;background:#f5f8fb;border:0;border-top:4px solid #2676a8;padding:10px;font-weight:normal}'
            . '.dashboard-card span{display:block;color:#687786;font-size:8pt}.dashboard-card strong{display:block;color:#173f66;font-size:18pt;padding-top:5px}'
            . '.dashboard-card.orange{border-color:#e8751a!important}.dashboard-card.red{border-color:#c64b4b!important}.dashboard-card.purple{border-color:#635dcc!important}'
            . '.dashboard-card.teal{border-color:#28a6a6!important}.dashboard-card.yellow{border-color:#d2a900!important}.dashboard-card.green{border-color:#3a9565!important}'
            . '.dashboard-detail-grid{width:100%;border-collapse:separate;border-spacing:8px;margin:8px 0}.dashboard-detail-grid>tbody>tr>td{width:50%;border:0;padding:0;vertical-align:top;font-weight:normal}'
            . '.dashboard-detail-grid h3{margin:10px 0 6px;font-size:12pt}.dashboard-detail-grid table{margin:0 0 10px}.dashboard-detail-grid th,.dashboard-detail-grid td{font-size:8pt;padding:4px}'
            . '.score{font-size:36pt;color:#173f66}.score span{font-size:14pt;color:#687786}'
            . 'table{border-collapse:collapse;width:100%;margin:8px 0 14px}th,td{border:1px solid #c5ced6;padding:5px;text-align:left;vertical-align:top}'
            . 'th{background:#edf1f4;color:#425466;font-size:8pt;text-transform:uppercase}td{font-size:9pt}td:first-child{font-weight:bold}'
            . '.finding{border-top:1px solid #ccd6df;padding:12px 0}.finding h3{color:#173f66}.finding h4{color:#e8751a}'
            . '.recommendation-list,.metric-list{padding-left:18px}.metric-list{margin:0}.metric-list li{margin:2px 0}'
            . 'dl{margin:0}dt{font-weight:bold}dd{margin:0 0 6px}pre{white-space:pre-wrap;word-break:break-word;font-size:8pt;margin:0}';
    }

    public function write(ScanResult $scanResult): string
    {
        $this->varDirectory->create(self::REPORT_DIRECTORY);
        $this->varDirectory->writeFile(self::LATEST_REPORT, $this->generate($scanResult));

        return 'var/' . self::LATEST_REPORT;
    }

    public function writeTo(ScanResult $scanResult, string $path): string
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create report directory "%s".', $directory));
        }
        if (file_put_contents($path, $this->generate($scanResult)) === false) {
            throw new \RuntimeException(sprintf('Unable to write report "%s".', $path));
        }
        return $path;
    }
}
