{{-- Client Site Notes quick-access card --}}
@if($ticket->client && $ticket->client->has_site_notes)
<div class="card shadow-sm mt-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="small"><i class="bi bi-journal-text me-1"></i>Site Notes</span>
        <a href="{{ route('clients.show', $ticket->client) }}#siteNotes"
           class="btn btn-outline-primary btn-sm py-0" title="View on client page">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    </div>
    <div class="card-body py-2">
        @php
            $plainText = strip_tags($ticket->client->site_notes_html);
            $isLong = strlen($plainText) > 300;
            $preview = Str::limit($plainText, 300);

            // §4.6: when the wiki is on and this client's overview is composed, AI triage
            // reads that overview instead of these notes — tell staff so the two don't drift.
            $overviewComposed = false;
            if (\App\Support\WikiConfig::isEnabled()) {
                $overviewPage = \App\Models\WikiPage::active()
                    ->forClient($ticket->client->id)
                    ->where('kind', \App\Enums\WikiPageKind::Overview->value)
                    ->first();
                $overviewComposed = ! empty($overviewPage?->meta['composed_at']);
            }
        @endphp
        @if($overviewComposed)
            <div class="small text-muted mb-2">
                <i class="bi bi-info-circle me-1"></i>AI triage now reads this client's
                <a href="{{ route('clients.wiki.show', [$ticket->client, 'overview']) }}">wiki overview</a>.
                These site notes remain editable.
            </div>
        @endif
        <div class="small text-muted">{{ $preview }}</div>
        @if($isLong)
            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#fullSiteNotes" role="button"
               aria-expanded="false" aria-controls="fullSiteNotes">
                <i class="bi bi-chevron-down me-1"></i>Show more
            </a>
            <div class="collapse" id="fullSiteNotes">
                <hr class="my-2">
                <div class="small note-body">{!! $ticket->client->site_notes_html !!}</div>
            </div>
        @endif
        @if($ticket->client->site_notes_updated_at)
            <div class="text-muted mt-1" style="font-size: 0.7rem;">
                Updated {{ $ticket->client->site_notes_updated_at->diffForHumans() }}
            </div>
        @endif
    </div>
</div>
@endif
