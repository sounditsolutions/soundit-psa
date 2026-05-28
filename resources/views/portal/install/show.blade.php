<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Set up your computer — {{ $package->clientName }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a365d;
            --primary-light: #234179;
            --accent: #fed136;
            --accent-hover: #fdc50c;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #374151;
        }
        .install-header {
            background: var(--primary);
            color: #fff;
            padding: 2rem 0;
        }
        .install-header img { max-height: 48px; }
        .install-header h1 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            font-size: 1.5rem;
        }
        .install-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
        .btn-accent {
            background: var(--accent);
            color: var(--primary);
            border: 0;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
        }
        .btn-accent:hover { background: var(--accent-hover); }
        .download-btn {
            min-width: 220px;
        }
        .key-block {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 1rem;
            font-family: ui-monospace, 'SF Mono', Consolas, monospace;
            font-size: 0.95rem;
            word-break: break-all;
        }
        .script-block {
            background: #1f2937;
            color: #f3f4f6;
            border-radius: 8px;
            padding: 1rem;
            font-family: ui-monospace, 'SF Mono', Consolas, monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .platform-toggle {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .platform-toggle .btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #6b7280;
        }
        .platform-toggle .btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }
        .footer-contact {
            color: #6b7280;
            padding: 2rem 0;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<header class="install-header">
    <div class="container d-flex align-items-center gap-3">
        @if($package->mspLogoUrl)
            <img src="{{ $package->mspLogoUrl }}" alt="{{ $package->mspName }}">
        @endif
        <h1>{{ $package->mspName }}</h1>
    </div>
</header>

<main class="container py-5">
    <div class="install-card mx-auto" style="max-width: 720px;">
        <h2 class="h3 mb-2">Set up your new computer</h2>
        <p class="text-muted mb-4">
            Welcome, {{ $package->clientName }}. This will install the
            <strong>{{ $package->rmmLabel }}</strong> on this computer so our support
            team can help you when you need it.
        </p>

        <div class="mb-3">
            <label class="form-label fw-semibold">Choose your operating system</label>
            <div class="platform-toggle" id="platformToggle">
                @foreach($package->availablePlatforms() as $platform)
                    <button type="button"
                            class="btn"
                            data-platform="{{ $platform }}">
                        @if($platform === 'windows')
                            <i class="bi bi-windows me-1"></i>Windows
                        @elseif($platform === 'mac')
                            <i class="bi bi-apple me-1"></i>macOS
                        @elseif($platform === 'linux')
                            <i class="bi bi-ubuntu me-1"></i>Linux
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        @foreach($package->platforms as $platform => $info)
            <div class="platform-panel" data-platform-panel="{{ $platform }}" style="display: none;">

                @if($info->hasScript())
                    <div class="mb-3">
                        <strong>One-click install</strong>
                        <p class="text-muted small mb-2">
                            Copy the command below, then paste it into an administrator PowerShell window and press Enter.
                        </p>
                        <div class="script-block" id="script-{{ $platform }}">{{ $info->installScript }}</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copyScript('{{ $platform }}')">
                            <i class="bi bi-clipboard me-1"></i>Copy to clipboard
                        </button>
                    </div>
                @endif

                @if($info->hasDownload())
                    <div class="mb-3">
                        @if($info->hasScript())
                            <hr class="my-4">
                            <p class="text-muted small mb-2">Or download and run the installer manually:</p>
                        @endif
                        <a href="{{ route('portal.install.download', ['token' => request()->route('token'), 'platform' => $platform]) }}"
                           class="btn btn-accent download-btn">
                            <i class="bi bi-download me-1"></i>Download installer
                        </a>
                    </div>
                @endif

                @if($info->hasKey() && ! $info->hasScript())
                    <div class="mb-3">
                        <strong>Registration key</strong>
                        <p class="text-muted small mb-2">
                            When the installer asks for a key, copy and paste this:
                        </p>
                        <div class="key-block" id="key-{{ $platform }}">{{ $info->registrationKey }}</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="copyKey('{{ $platform }}')">
                            <i class="bi bi-clipboard me-1"></i>Copy key
                        </button>
                    </div>
                @endif

                @if($info->instructions)
                    <div class="small text-muted mt-3">
                        <i class="bi bi-info-circle me-1"></i>{{ $info->instructions }}
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</main>

<footer class="footer-contact">
    <div class="container text-center">
        <p class="mb-1">Need help? Contact {{ $package->mspName }}</p>
        @if($package->supportPhone)
            <p class="mb-1"><i class="bi bi-telephone me-1"></i>{{ $package->supportPhone }}</p>
        @endif
        @if($package->supportEmail)
            <p class="mb-0"><i class="bi bi-envelope me-1"></i>
                <a href="mailto:{{ $package->supportEmail }}">{{ $package->supportEmail }}</a>
            </p>
        @endif
    </div>
</footer>

<script>
    // Detect OS and pre-select the matching platform panel
    (function () {
        var available = @json($package->availablePlatforms());
        var detected = null;
        var platformString = (navigator.userAgentData?.platform || navigator.platform || '').toLowerCase();
        if (platformString.includes('win')) detected = 'windows';
        else if (platformString.includes('mac')) detected = 'mac';
        else if (platformString.includes('linux')) detected = 'linux';

        var initial = available.includes(detected) ? detected : available[0];
        if (initial) showPlatform(initial);

        document.querySelectorAll('[data-platform]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showPlatform(btn.dataset.platform);
            });
        });
    })();

    function showPlatform(platform) {
        document.querySelectorAll('[data-platform]').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.platform === platform);
        });
        document.querySelectorAll('[data-platform-panel]').forEach(function (panel) {
            panel.style.display = panel.dataset.platformPanel === platform ? 'block' : 'none';
        });
    }

    function copyScript(platform) {
        var el = document.getElementById('script-' + platform);
        if (el) navigator.clipboard.writeText(el.textContent.trim());
    }

    function copyKey(platform) {
        var el = document.getElementById('key-' + platform);
        if (el) navigator.clipboard.writeText(el.textContent.trim());
    }
</script>

</body>
</html>
