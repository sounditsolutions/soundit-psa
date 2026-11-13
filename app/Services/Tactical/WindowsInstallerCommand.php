<?php

namespace App\Services\Tactical;

/** Compose PowerShell from a strictly recognized upstream manual response, never eval cmd.exe text. */
final class WindowsInstallerCommand
{
    /** @return array{filename: string, script: string}|null */
    public static function build(string $command, mixed $url, string $arch): ?array
    {
        if (! is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)
            || ! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }

        // Immutable upstream 1e786d37 agents/views.py manual Windows grammar.
        // Reject drift rather than interpolating unknown vendor shell syntax.
        $pattern = '~\A(tacticalagent-v[0-9.]+-windows-(amd64|386)\.exe) /VERYSILENT /SUPPRESSMSGBOXES'
            .' && ping 127\.0\.0\.1 -n 7 && "C:\\\\Program Files\\\\TacticalAgent\\\\tacticalrmm\.exe" -m install'
            .' --api (https://[a-zA-Z0-9.:-]+(?:/[a-zA-Z0-9._/-]*)?) --client-id ([0-9]+) --site-id ([0-9]+)'
            .' --agent-type (workstation|server) --auth ([a-zA-Z0-9_-]+)((?: --(?:rdp|ping|power))*)\z~';
        if (preg_match($pattern, $command, $m) !== 1 || $m[2] !== $arch
            || ! filter_var($m[3], FILTER_VALIDATE_URL)) {
            return null;
        }
        $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
        $arguments = ['-m', 'install', '--api', $m[3], '--client-id', $m[4], '--site-id', $m[5], '--agent-type', $m[6], '--auth', $m[7]];
        if ($m[8] !== '') {
            array_push($arguments, ...explode(' ', trim($m[8])));
        }
        $args = implode(',', array_map($quote, $arguments));
        $filename = $quote($m[1]);
        $download = $quote($url);
        // A fresh directory prevents reusing a stale executable after a failed download.
        // Fixed catch text deliberately suppresses credential-bearing transport/process errors.
        $script = '& { $ErrorActionPreference = \'Stop\'; $ProgressPreference = \'SilentlyContinue\'; $dir = $null; $file = $null; try { '
            .'if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw \'Administrator required\' }; '
            .'$dir = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString()); '
            .'New-Item -ItemType Directory -Path $dir -ErrorAction Stop | Out-Null; '
            ."\$file = Join-Path \$dir {$filename}; "
            ."Invoke-WebRequest -UseBasicParsing -Uri {$download} -OutFile \$file -ErrorAction Stop; "
            .'if ((Get-Item -LiteralPath $file).Length -lt 1024) { throw \'Invalid installer\' }; '
            .'$stream = [IO.File]::OpenRead($file); try { if ($stream.ReadByte() -ne 77 -or $stream.ReadByte() -ne 90) { throw \'Invalid installer\' } } finally { $stream.Dispose() }; '
            .'$process = Start-Process -FilePath $file -ArgumentList \'/VERYSILENT\',\'/SUPPRESSMSGBOXES\' -Wait -PassThru -ErrorAction Stop; '
            .'if ($process.ExitCode -ne 0) { throw \'Installation failed\' }; '
            .'Start-Sleep -Seconds 6; '
            .'$agent = Join-Path $env:SystemDrive \'Program Files\\TacticalAgent\\tacticalrmm.exe\'; '
            .'if (-not (Test-Path -LiteralPath $agent -PathType Leaf)) { throw \'Agent missing\' }; '
            ."& \$agent @({$args}) *> \$null; "
            .'if ($LASTEXITCODE -ne 0) { throw \'Enrollment failed\' }; '
            .'Write-Host \'Setup finished. Ask us to verify this computer and intended-site check-in.\' '
            .'} catch { Write-Host \'Setup stopped. Do not rerun or share this command. Contact us for help; download, installation or enrollment could not be completed.\' '
            .'} finally { if ($file -and (Test-Path -LiteralPath $file)) { Remove-Item -LiteralPath $file -Force -ErrorAction SilentlyContinue }; '
            .'if ($dir -and (Test-Path -LiteralPath $dir)) { Remove-Item -LiteralPath $dir -ErrorAction SilentlyContinue } } }';

        return ['filename' => $m[1], 'script' => $script];
    }
}
