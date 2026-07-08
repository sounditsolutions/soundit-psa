<footer class="site-footer mt-auto py-3 text-center small">
    &copy; {{ date('Y') }} {{ config('app.name') }}
    @php $__version = \Illuminate\Support\Facades\Cache::get('psa_version_current'); @endphp
    @if($__version)
        <span class="ms-2 text-muted">
            <a href="{{ route('about') }}" class="text-reset" style="text-decoration: none;">v{{ $__version['commit_short'] }}</a>
        </span>
    @endif
</footer>
