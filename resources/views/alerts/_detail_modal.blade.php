{{-- Alert Detail Modal — include once per page that shows clickable alert titles.
     Works with any element that has class="alert-detail-link" and the standard data-alert-* attributes. --}}
<div class="modal fade" id="alertDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell me-2"></i>Alert Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Severity</div>
                    <div class="col-sm-9" id="adSeverity"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Source</div>
                    <div class="col-sm-9" id="adSource"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Device</div>
                    <div class="col-sm-9" id="adHostname"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Client</div>
                    <div class="col-sm-9" id="adClient"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Status</div>
                    <div class="col-sm-9" id="adStatus"></div>
                </div>
                <div class="row mb-3">
                    <div class="col-sm-3 fw-semibold text-muted">Fired</div>
                    <div class="col-sm-9" id="adFired"></div>
                </div>
                <div class="row mb-3 d-none" id="adAcknowledgedRow">
                    <div class="col-sm-3 fw-semibold text-muted">Acknowledged</div>
                    <div class="col-sm-9" id="adAcknowledged"></div>
                </div>
                <div class="row mb-3 d-none" id="adResolvedRow">
                    <div class="col-sm-3 fw-semibold text-muted">Resolved</div>
                    <div class="col-sm-9" id="adResolved"></div>
                </div>
                <hr>
                <div class="mb-2 fw-semibold">Title</div>
                <div class="mb-3" id="adTitle"></div>
                <div class="mb-2 fw-semibold d-none" id="adMessageLabel">Details</div>
                <pre class="bg-light border rounded p-3 small d-none" id="adMessage" style="white-space: pre-wrap; word-break: break-word; max-height: 400px; overflow-y: auto;"></pre>
            </div>
            <div class="modal-footer">
                <a href="#" class="btn btn-outline-primary btn-sm d-none" id="adSourceLink" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right me-1"></i>View in Source
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.alert-detail-link').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var d = this.dataset;
        document.getElementById('adSeverity').textContent = d.alertSeverity;
        document.getElementById('adSource').textContent = d.alertSource;
        document.getElementById('adHostname').textContent = d.alertHostname;
        document.getElementById('adClient').textContent = d.alertClient;
        document.getElementById('adStatus').textContent = d.alertStatus;
        document.getElementById('adTitle').textContent = d.alertTitle;

        var msg = d.alertMessage || '';
        var msgEl = document.getElementById('adMessage');
        var msgLabel = document.getElementById('adMessageLabel');
        if (msg) {
            msgEl.textContent = msg;
            msgEl.classList.remove('d-none');
            msgLabel.classList.remove('d-none');
        } else {
            msgEl.classList.add('d-none');
            msgLabel.classList.add('d-none');
        }

        var refired = parseInt(d.alertRefired) || 0;
        var firedText = d.alertFired;
        if (refired > 0) firedText += ' (re-fired ' + refired + ' time' + (refired > 1 ? 's' : '') + ')';
        document.getElementById('adFired').textContent = firedText;

        var ackRow = document.getElementById('adAcknowledgedRow');
        if (d.alertAcknowledged) {
            var ackText = d.alertAcknowledged;
            if (d.alertAcknowledgedBy) ackText += ' by ' + d.alertAcknowledgedBy;
            document.getElementById('adAcknowledged').textContent = ackText;
            ackRow.classList.remove('d-none');
        } else {
            ackRow.classList.add('d-none');
        }

        var resRow = document.getElementById('adResolvedRow');
        if (d.alertResolved) {
            document.getElementById('adResolved').textContent = d.alertResolved;
            resRow.classList.remove('d-none');
        } else {
            resRow.classList.add('d-none');
        }

        var srcLink = document.getElementById('adSourceLink');
        if (d.alertSourceUrl) {
            srcLink.href = d.alertSourceUrl;
            srcLink.classList.remove('d-none');
        } else {
            srcLink.classList.add('d-none');
        }

        new bootstrap.Modal(document.getElementById('alertDetailModal')).show();
    });
});
</script>
