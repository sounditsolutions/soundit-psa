/**
 * Ticket action buttons: Note, Reply, Ask AI, Change Status
 * Handles toggling panels and per-panel form behavior.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var buttons = document.querySelectorAll('.action-btn');
        var panels = document.querySelectorAll('.action-panel');
        var activeAction = null;

        // Map action names to panel IDs
        var panelMap = {
            'note': 'actionNote',
            'reply': 'actionReply',
            'status': 'actionStatus'
            // 'ask-ai' is handled separately by ticket-ai-chat.js
        };

        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = this.dataset.action;

                // Ask AI is handled by ticket-ai-chat.js
                if (action === 'ask-ai') return;

                if (activeAction === action) {
                    // Toggle off: collapse current panel
                    closeAllPanels();
                    activeAction = null;
                    return;
                }

                // Close any open panel, open the new one
                closeAllPanels();
                var panelId = panelMap[action];
                if (panelId) {
                    document.getElementById(panelId).classList.remove('d-none');
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-secondary');
                    activeAction = action;
                }
            });
        });

        function closeAllPanels() {
            panels.forEach(function(p) { p.classList.add('d-none'); });
            buttons.forEach(function(b) {
                if (b.dataset.action !== 'ask-ai') {
                    b.classList.remove('btn-secondary');
                    b.classList.add('btn-outline-secondary');
                }
            });
        }

        // Note panel: show/hide billable and contract when time entered
        var noteTimeInput = document.getElementById('noteTimeInput');
        if (noteTimeInput) {
            noteTimeInput.addEventListener('input', function() {
                var hasTime = this.value.trim();
                var billable = document.getElementById('noteBillableGroup');
                var contract = document.getElementById('noteContractGroup');
                if (billable) billable.classList.toggle('d-none', !hasTime);
                if (contract) contract.classList.toggle('d-none', !hasTime);
            });
        }

        // Show the resolution input only when "Resolved" is selected, and make it
        // required in that case — a resolved ticket must record what was done.
        // The required attribute is cleared while hidden so it never blocks other
        // actions (a required, display:none control is unfocusable and would stall
        // form submission).
        function bindResolutionToggle(selectId, groupId) {
            var select = document.getElementById(selectId);
            if (!select) return;
            var group = document.getElementById(groupId);
            var input = group ? group.querySelector('input, textarea') : null;
            function sync() {
                var resolving = select.value === 'resolved';
                if (group) group.classList.toggle('d-none', !resolving);
                if (input) {
                    if (resolving) {
                        input.setAttribute('required', 'required');
                    } else {
                        input.removeAttribute('required');
                    }
                }
            }
            select.addEventListener('change', sync);
            sync();
        }

        bindResolutionToggle('noteStatusSelect', 'noteResolutionGroup');   // Note panel
        bindResolutionToggle('replyStatusSelect', 'replyResolutionGroup'); // Reply panel
        bindResolutionToggle('statusOnlySelect', 'statusResolutionGroup'); // Status-only panel

        // Populate contact email datalist
        var ticketClientId = document.body.dataset.clientId;
        if (ticketClientId) {
            fetch('/api/clients/' + ticketClientId + '/contacts')
                .then(function(r) { return r.json(); })
                .then(function(contacts) {
                    var datalist = document.getElementById('contactEmails');
                    if (datalist) {
                        contacts.forEach(function(c) {
                            if (c.email) {
                                var opt = document.createElement('option');
                                opt.value = c.email;
                                opt.textContent = c.name;
                                datalist.appendChild(opt);
                            }
                        });
                    }
                })
                .catch(function() {}); // Datalist is a convenience
        }
    });
})();
