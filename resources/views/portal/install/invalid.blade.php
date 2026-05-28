<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Setup — {{ $mspName }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #374151;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .install-header {
            background: #1a365d;
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
            max-width: 560px;
        }
    </style>
</head>
<body>

<header class="install-header">
    <div class="container d-flex align-items-center gap-3">
        @if($mspLogoUrl)
            <img src="{{ $mspLogoUrl }}" alt="{{ $mspName }}">
        @endif
        <h1>{{ $mspName }}</h1>
    </div>
</header>

<main class="container py-5 flex-grow-1 d-flex align-items-center justify-content-center">
    <div class="install-card text-center">
        <div class="mb-3">
            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
        </div>
        <h2 class="h4 mb-3">Setup not available</h2>
        <p class="text-muted mb-4">{{ $message }}</p>

        @if($supportPhone || $supportEmail)
            <hr>
            <p class="mb-1 small text-muted">Need help? Contact {{ $mspName }}</p>
            @if($supportPhone)
                <p class="mb-1"><i class="bi bi-telephone me-1"></i>{{ $supportPhone }}</p>
            @endif
            @if($supportEmail)
                <p class="mb-0"><i class="bi bi-envelope me-1"></i>
                    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                </p>
            @endif
        @endif
    </div>
</main>

</body>
</html>
