<?php

namespace App\Services\Portal;

/**
 * Describes how to install a specific RMM agent on one platform.
 *
 * Three shapes are supported:
 *   1. Direct download  — set $downloadUrl only. Button triggers a redirect to the URL.
 *   2. Download + key   — set $downloadUrl and $registrationKey. Landing page shows
 *                         both, user copies the key and pastes it during install.
 *   3. One-liner script — set $installScript. Landing page shows a copy-to-clipboard
 *                         block; the user runs the script in PowerShell/bash.
 *
 * $instructions is optional free-form text shown below the install controls.
 */
final class InstallerInfo
{
    public function __construct(
        public readonly ?string $downloadUrl = null,
        public readonly ?string $registrationKey = null,
        public readonly ?string $installScript = null,
        public readonly ?string $instructions = null,
    ) {}

    public function hasScript(): bool
    {
        return ! empty($this->installScript);
    }

    public function hasKey(): bool
    {
        return ! empty($this->registrationKey);
    }

    public function hasDownload(): bool
    {
        return ! empty($this->downloadUrl);
    }
}
