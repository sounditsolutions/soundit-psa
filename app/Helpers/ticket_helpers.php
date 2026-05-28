<?php

if (!function_exists('ticketSortUrl')) {
    function ticketSortUrl(string $col, string $currentSort, string $currentDir, array $defaultDirs): string
    {
        if ($currentSort === $col) {
            $dir = $currentDir === 'asc' ? 'desc' : 'asc';
        } else {
            $dir = $defaultDirs[$col] ?? 'asc';
        }
        return request()->fullUrlWithQuery(['sort' => $col, 'direction' => $dir, 'page' => null]);
    }
}

if (!function_exists('ticketSortIcon')) {
    function ticketSortIcon(string $col, string $currentSort, string $currentDir): string
    {
        if ($currentSort !== $col) return 'bi-chevron-expand';
        return $currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down';
    }
}
