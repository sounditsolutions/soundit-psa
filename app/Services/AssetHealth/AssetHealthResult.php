<?php

namespace App\Services\AssetHealth;

use App\Enums\AssetHealthGrade;

/**
 * Immutable outcome of scoring one asset.
 *
 * `score` is null when there were no monitoring signals to read (the asset
 * grades Unknown). `factors` is an ordered list of the signals considered,
 * each shaped:
 *   [
 *     'key'    => 'connectivity',   // stable identifier
 *     'label'  => 'Connectivity',   // human label
 *     'status' => 'ok'|'warn'|'bad'|'unknown',
 *     'detail' => 'Offline; last seen 3 days ago',
 *     'points' => -30,              // <= 0; contribution to the score
 *   ]
 */
final class AssetHealthResult
{
    /**
     * @param  array<int, array{key:string,label:string,status:string,detail:string,points:int}>  $factors
     */
    public function __construct(
        public readonly ?int $score,
        public readonly AssetHealthGrade $grade,
        public readonly array $factors,
    ) {}

    public function isKnown(): bool
    {
        return $this->score !== null;
    }

    /**
     * Factors that dragged the score down, worst first.
     *
     * @return array<int, array{key:string,label:string,status:string,detail:string,points:int}>
     */
    public function notableFactors(): array
    {
        $notable = array_values(array_filter($this->factors, fn ($f) => $f['points'] < 0));
        usort($notable, fn ($a, $b) => $a['points'] <=> $b['points']);

        return $notable;
    }

    /**
     * Stable keys of the factors that reduced the score. Used to decide whether
     * a cached AI narrative is still valid after a recompute.
     *
     * @return array<int, string>
     */
    public function notableFactorKeys(): array
    {
        $keys = array_map(fn ($f) => $f['key'], $this->notableFactors());
        sort($keys);

        return $keys;
    }
}
