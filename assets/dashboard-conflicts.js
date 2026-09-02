(function () {
    'use strict';

    // Dashboard::restoreConflict()'s own client - see that method's
    // docblock for the full restore semantics. One shared confirm modal
    // for every row's "Restore" button (see app/Views/dashboard.php's own
    // #restore-conflict-modal), same "one modal, remember which row
    // triggered it" shape Settings' own delete-node-modal already uses.
    var table = document.getElementById('conflicts-table');
    var modalEl = document.getElementById('restore-conflict-modal');
    if (!table || !modalEl) return;

    var restoreEndpoint = table.dataset.restoreEndpoint;
    var strings = JSON.parse(table.dataset.strings || '{}');
    var modalError = document.getElementById('restore-conflict-modal-error');
    var pendingRow = null;

    function modal() { return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null; }

    table.querySelectorAll('[data-restore-conflict]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingRow = btn.closest('tr');
            modalError.classList.add('d-none');
            var m = modal();
            if (m) m.show();
        });
    });

    document.getElementById('restore-conflict-modal-confirm').addEventListener('click', function () {
        if (!pendingRow) return;
        var row = pendingRow;
        var archive = row.dataset.archive;
        var path = row.dataset.path;

        var body = new URLSearchParams();
        body.set('archive', archive);
        body.set('path', path);
        if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);

        fetch(restoreEndpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (window.syncCsrf) window.syncCsrf(data);
                if (!data || !data.ok) {
                    modalError.textContent = (strings.restoreFailed || '{0}').replace('{0}', (data && data.error) || '');
                    modalError.classList.remove('d-none');

                    return;
                }
                var m = modal();
                if (m) m.hide();
                var actionCell = row.querySelector('td:last-child');
                if (actionCell) {
                    actionCell.innerHTML = '<span class="badge bg-secondary-lt">' + (strings.restoredBadge || 'Restored') + '</span>';
                }
                pendingRow = null;
            })
            .catch(function () {
                modalError.textContent = (strings.restoreFailed || '{0}').replace('{0}', '');
                modalError.classList.remove('d-none');
            });
    });

    ['restore-conflict-modal-close', 'restore-conflict-modal-cancel'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function () {
            var m = modal();
            if (m) m.hide();
        });
    });
})();
