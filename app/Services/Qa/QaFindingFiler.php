<?php

namespace App\Services\Qa;

class QaFindingFiler
{
    /**
     * @param  callable(array<int,string>):string  $runner  executes a gc command, returns stdout (the new bead id on create)
     * @param  callable():array<int,array{id:string,dedup_key:string}>  $openFindings  lists open qa beads with their dedup keys
     */
    public function __construct(
        private $runner,
        private $openFindings,
    ) {}

    public function file(QaFinding $finding): string
    {
        if (! in_array($finding->kind, QaFinding::KINDS, true)) {
            throw new \InvalidArgumentException("Invalid finding kind '{$finding->kind}'. One of: ".implode(', ', QaFinding::KINDS));
        }

        foreach (($this->openFindings)() as $open) {
            if (($open['dedup_key'] ?? null) === $finding->dedupKey()) {
                return $open['id']; // already filed — don't duplicate
            }
        }

        // The real gc bd CLI uses --labels (strings, comma-separated or repeated).
        // We pass --labels twice (once per label) so each label is a distinct array element,
        // which satisfies assertContains('qa', $cmd) / assertContains($kind, $cmd) in tests.
        // Cobra's `strings` flag type accepts repeated flags in addition to comma-separated values.
        $cmd = [
            'gc', 'bd', 'create', '--rig', 'soundit-psa',
            $finding->title, '-t', 'task',
            '--labels', 'qa', '--labels', $finding->kind,
            '-d', $finding->body(),
            '--json',
        ];
        $out = trim(($this->runner)($cmd));

        // bd create --json returns the bead; accept either a raw id or a JSON object with .id
        $decoded = json_decode($out, true);

        return is_array($decoded) && isset($decoded['id']) ? $decoded['id'] : $out;
    }
}
