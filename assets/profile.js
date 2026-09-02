(function () {
    'use strict';
    var box = document.getElementById('profile-form');
    if (!box) return;
    var endpoint = box.dataset.endpoint;
    var avatarEndpoint = box.dataset.avatarEndpoint;
    var savedBadge = document.getElementById('profile-saved');
    var showSaved = function () {
        savedBadge.classList.remove('d-none');
        clearTimeout(showSaved._t);
        showSaved._t = setTimeout(function () { savedBadge.classList.add('d-none'); }, 1500);
    };

    // See app.js's own syncCsrf() for why this exists and why sharing one
    // instance via window is safe. Deliberately NOT captured into a local
    // var here - see settings.js's own identical comment: this page's own
    // <script> tag (rendered via Layout/app.php's renderSection('content'))
    // runs before app.js's, so window.syncCsrf doesn't exist yet at
    // capture time - every call below goes through window.syncCsrf
    // directly instead, resolved at call time once app.js has definitely
    // finished.

    var timers = {};
    box.querySelectorAll('[data-field]').forEach(function (el) {
        var send = function () {
            if (el.value === '') return; // blank means "leave unchanged" - see ProfileController::updateField()
            var body = new URLSearchParams();
            body.set('field', el.dataset.field);
            body.set('value', el.value);
            if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
            fetch(endpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (d) { return window.syncCsrf(d); })
                .then(function () {
                    showSaved();
                    // Never let a saved password sit visible/re-sendable in the field.
                    if (el.dataset.field === 'password') el.value = '';
                })
                .catch(function () { /* field left unsaved; next successful save will show the badge again */ });
        };
        el.addEventListener('input', function () {
            clearTimeout(timers[el.dataset.field]);
            timers[el.dataset.field] = setTimeout(send, 500);
        });
    });

    var avatarInput = document.getElementById('profile-avatar');
    var avatarThumbWrap = document.getElementById('avatar-thumb-wrap');
    var avatarThumb = document.getElementById('avatar-thumb');
    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            if (!avatarInput.files[0]) return;
            var fd = new FormData();
            fd.append('avatar', avatarInput.files[0]);
            if (window.CI4_CSRF) fd.append(window.CI4_CSRF.name, window.CI4_CSRF.hash);
            fetch(avatarEndpoint, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (d) { return window.syncCsrf(d); })
                .then(function (data) {
                    showSaved();
                    if (data && data.path) {
                        avatarThumb.src = '/' + data.path;
                        avatarThumbWrap.classList.remove('d-none');
                    }
                })
                .catch(function () {});
        });
    }
})();
