{{-- Tab persistence: URL hash + sessionStorage (survives redirects after form saves) --}}
{{-- Usage: @include('components._tab-persistence', ['tabListId' => 'clientTabs', 'storageKey' => 'client-show-tab']) --}}
<script>
(function() {
    var tabListId = @json($tabListId);
    var storageKey = @json($storageKey);
    var hash = window.location.hash.replace('#', '');
    var saved = sessionStorage.getItem(storageKey);
    var target = hash || saved;
    if (target) {
        var tab = document.querySelector('#' + tabListId + ' button[data-bs-target="#' + target + '"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
    document.querySelectorAll('#' + tabListId + ' button[data-bs-toggle="tab"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function(e) {
            var id = e.target.dataset.bsTarget.replace('#', '');
            history.replaceState(null, null, '#' + id);
            sessionStorage.setItem(storageKey, id);
        });
    });
})();
</script>
