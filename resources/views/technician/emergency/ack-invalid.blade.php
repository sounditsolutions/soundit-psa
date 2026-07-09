<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Link no longer valid — {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #374151;
        }
        .ack-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            max-width: 480px;
        }
    </style>
</head>
<body>
<main class="container py-5 d-flex justify-content-center">
    <div class="ack-card text-center">
        <i class="bi bi-clock-history" style="font-size: 3rem; color: #d97706;" aria-hidden="true"></i>
        <h1 class="h4 mt-3 mb-2">This link is no longer valid</h1>
        <p class="text-muted mb-2">
            One-tap acknowledgement links expire quickly and stop working once used
            or forwarded. Your tap was <strong>not</strong> recorded.
        </p>
        <p class="text-muted mb-4">
            Nothing has been dropped — the on-call system is still tracking this
            emergency and will keep paging until someone picks up the ticket.
        </p>
        <a href="{{ url('/') }}" class="btn btn-primary">
            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Open {{ config('app.name') }}
        </a>
        <p class="text-muted small mt-3 mb-0">
            Sign in to view and acknowledge the ticket, or contact your on-call coordinator.
        </p>
    </div>
</main>
</body>
</html>
