<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Acknowledged — {{ config('app.name') }}</title>
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
        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
        <h1 class="h4 mt-3 mb-2">Got it — thanks.</h1>
        <p class="text-muted mb-0">
            @if($ticketId)
                You're now on ticket #{{ $ticketId }}. We'll stop paging until someone touches it.
            @else
                Your acknowledgement was recorded.
            @endif
        </p>
    </div>
</main>
</body>
</html>
