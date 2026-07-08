<?php

namespace App\Console\Commands;

use App\Services\Qa\QaRunReport;
use Illuminate\Console\Command;

class QaReport extends Command
{
    protected $signature = 'qa:report
        {resultsJsonPath : Path to the JSON results file produced by the QA runner}
        {--mail= : Email address to send the report to (optional; save-only if omitted)}';

    protected $description = 'Render a QA run report from a results JSON file and save it to storage/qa-reports/';

    public function handle(): int
    {
        $path = $this->argument('resultsJsonPath');

        if (! file_exists($path)) {
            $this->error("Results file not found: {$path}");

            return self::FAILURE;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            $this->error('Results file is not valid JSON.');

            return self::FAILURE;
        }

        $runLabel = $data['run_label'] ?? basename($path, '.json');
        $results = $data['results'] ?? [];
        $findings = $data['findings'] ?? [];

        $report = new QaRunReport($runLabel, $results, $findings);
        $markdown = $report->toMarkdown();

        $dir = storage_path('qa-reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($runLabel));
        $filename = now()->format('Ymd-His').'-'.$slug.'.md';
        $outPath = $dir.'/'.$filename;

        file_put_contents($outPath, $markdown);
        $this->info("Report saved: {$outPath}");

        // Mailing: v1 is save-only. No app\Mail classes exist yet.
        // When --mail is provided, warn that mailing is not yet implemented.
        if ($this->option('mail')) {
            $this->warn('--mail option noted but mailing is not yet implemented (v1 is save-only). Report saved to disk.');
        }

        return self::SUCCESS;
    }
}
