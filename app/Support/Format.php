<?php

namespace App\Support;

class Format
{
    /**
     * Format a byte count into a human-readable string (KB, MB, GB, TB).
     */
    public static function bytes(?int $bytes, int $precision = 2): string
    {
        if ($bytes === null || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision).' '.$units[$power];
    }
}
