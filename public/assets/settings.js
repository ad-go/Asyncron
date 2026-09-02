(function () {
    'use strict';
    var box = document.getElementById('settings-form');
    if (!box) return;
    var endpoint = box.dataset.endpoint;
    var logoEndpoint = box.dataset.logoEndpoint;
    var logoDeleteEndpoint = box.dataset.logoDeleteEndpoint;
    var savedBadge = document.getElementById('settings-saved');
    var showSaved = function () {
        savedBadge.classList.remove('d-none');
        clearTimeout(showSaved._t);
        showSaved._t = setTimeout(function () { savedBadge.classList.add('d-none'); }, 1500);
    };

    // See app.js's own syncCsrf() for why this exists and why sharing one
    // instance via window is safe. Deliberately NOT captured into a local
    // `var syncCsrf = window.syncCsrf` here (every call below goes through
    // window.syncCsrf directly instead) - found live 2026-08-21: this
    // page's own <script> tag (rendered via Layout/app.php's
    // renderSection('content')) sits BEFORE app.js's own <script> tag in
    // the final HTML, so even though both use `defer` (which runs scripts
    // in DOCUMENT ORDER, not download order), THIS script's own top-level
    // code runs first - window.syncCsrf doesn't exist yet at that point,
    // so capturing it into a local var here would silently bind
    // `undefined`, and every one of this page's bulk actions (Import,
    // Import cluster, Delete, Add node, Delete node - all pre-existing,
    // not just the new reset button) would throw "syncCsrf is not a
    // function" the instant their own response arrived, no matter how
    // long after page load. Referencing window.syncCsrf directly at CALL
    // time instead - well after page load, once app.js has definitely
    // finished - sidesteps the ordering issue entirely rather than
    // depending on fixing script order (fragile - the next added
    // page-specific script could reintroduce the same race).

    // Found live 2026-08-21: this page's many independent autosave fields
    // (every Cluster row) and its one-off bulk actions (Import/Import
    // cluster/Delete/Add node/Delete node) all read window.CI4_CSRF at
    // send time and get a FRESH token back in every successful response
    // (Security::$regenerate - see app/Config/Security.php). Two POSTs
    // firing close together (e.g. a field's own blur-triggered autosave
    // landing right as a bulk action's request goes out) race for that
    // single-use token: whichever completes SECOND submits a hash the
    // server already rotated past, and gets a 403 - even though the
    // OTHER request's change (possibly THIS one's own, if it's what lost
    // the race) already committed. That's exactly the "shows an error,
    // but the change is there after a refresh" report this fixes -
    // refreshCsrf() below re-reads the CURRENT page's own embedded
    // window.CI4_CSRF (a plain GET, doesn't itself touch the CSRF
    // session state the way a POST does) and fetchWithCsrfRetry() retries
    // the ONE failed bulk action once, transparently, against that fresh
    // token - so the user only ever sees a real failure, never a stale-
    // token false one.
    function refreshCsrf() {
        return fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var m = html.match(/CI4_CSRF\s*=\s*\{\s*name:\s*'([^']*)',\s*hash:\s*'([^']*)'/);
                if (m) window.CI4_CSRF = { name: m[1], hash: m[2] };
            })
            .catch(function () { /* best-effort - the retry below just reuses whatever token it already has if this fails */ });
    }

    // buildBody() is called fresh on EVERY attempt (including the retry),
    // so it always picks up whatever window.CI4_CSRF is current at that
    // moment - no separate "patch the token into an already-built body"
    // step needed. Only ONE automatic retry - a second 403 in a row means
    // something is genuinely wrong, not just an unlucky race, and should
    // surface as a real error like it always did.
    function fetchWithCsrfRetry(url, buildBody, fetchOpts) {
        function attempt(isRetry) {
            var opts = Object.assign({ method: 'POST', body: buildBody(), headers: { 'X-Requested-With': 'XMLHttpRequest' } }, fetchOpts || {});
            return fetch(url, opts).then(function (r) {
                if (r.status === 403 && !isRetry) {
                    return refreshCsrf().then(function () { return attempt(true); });
                }
                return r.json().then(function (data) { return { ok: r.ok, data: data }; });
            });
        }

        return attempt(false);
    }

    // importSettings()/importCluster() can return a 'warning' (thisNode
    // couldn't be auto-detected - see SettingsController::
    // configureClusterIdentity()) on an otherwise-successful response, the
    // SAME response whose success also triggers location.reload() below -
    // sessionStorage is what carries it across that reload to somewhere it
    // can actually be shown (see the DOMContentLoaded check further down).
    var CLUSTER_WARNING_KEY = 'settingsImportWarning';
    function stashClusterWarning(warning) {
        if (warning) sessionStorage.setItem(CLUSTER_WARNING_KEY, warning);
    }
    var clusterWarningBox = document.getElementById('settings-cluster-warning');
    if (clusterWarningBox) {
        var storedWarning = sessionStorage.getItem(CLUSTER_WARNING_KEY);
        if (storedWarning) {
            clusterWarningBox.textContent = storedWarning;
            clusterWarningBox.classList.remove('d-none');
            sessionStorage.removeItem(CLUSTER_WARNING_KEY);
        }
    }

    var timers = {};
    box.querySelectorAll('[data-field]').forEach(function (el) {
        var send = function () {
            var body = new URLSearchParams();
            body.set('field', el.dataset.field);
            body.set('value', el.value);
            if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
            fetch(endpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                .then(function (d) { return window.syncCsrf(d); })
                .then(function () { showSaved(); })
                .catch(function () { /* field left unsaved; next successful save will show the badge again */ });
        };
        var evt = el.tagName === 'SELECT' ? 'change' : 'input';
        el.addEventListener(evt, function () {
            clearTimeout(timers[el.dataset.field]);
            timers[el.dataset.field] = setTimeout(send, el.tagName === 'SELECT' ? 0 : 500);
        });
    });

    // Nodes and Databases now share ONE table (#settings-cluster, see
    // app/Views/Settings/index.php) - each node is a two-row block, the
    // Nodes fields on top and the Databases fields directly under it, so
    // TWO independent endpoints/credential-family concepts feed the same
    // table. bindClusterAutosave() attaches ONE autosave listener per
    // field, picking data-node-endpoint vs data-database-endpoint off the
    // table by that field's own data-row ("node" or "database").
    // bindFamilySwap() is called once per swap-select kind (Protocol on
    // the Nodes row, Type on the Databases row) - kept as a SEPARATE pass
    // from the autosave binding (not folded into one combined function
    // like this used to be when Nodes/Databases were separate tables) so
    // calling it twice on the same merged table doesn't double-attach the
    // autosave listener to every field.
    function bindClusterAutosave(table, endpointsByRow) {
        if (!table) return;
        var rowTimers = {};
        table.querySelectorAll('[data-node][data-prop]').forEach(function (el) {
            var endpoint = endpointsByRow[el.dataset.row];
            if (!endpoint) return;
            var key = el.dataset.row + ':' + el.dataset.node + ':' + el.dataset.prop;
            var send = function () {
                var body = new URLSearchParams();
                body.set('node', el.dataset.node);
                body.set('prop', el.dataset.prop);
                body.set('value', el.value);
                if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                fetch(endpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                    .then(function (d) { return window.syncCsrf(d); })
                    .then(function () { showSaved(); })
                    .catch(function () { /* field left unsaved; next successful save will show the badge again */ });
            };
            var evt = el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(evt, function () {
                clearTimeout(rowTimers[key]);
                rowTimers[key] = setTimeout(send, el.tagName === 'SELECT' ? 0 : 500);
            });
        });
    }

    // familyOf(selectValue) maps the swap-select's current value to the
    // data-{family}-{role} attribute prefix used on that row.
    function bindFamilySwap(table, swapSelector, familyOf) {
        if (!table) return;
        table.querySelectorAll(swapSelector).forEach(function (select) {
            select.addEventListener('change', function () {
                var tr = select.closest('tr');
                var family = familyOf(select.value);
                // 'database' is a no-op on the Nodes row (no data-field-role
                //="database" input there, the guard below just skips it) -
                // shared list works for both rows without a second copy.
                ['database', 'host', 'port', 'user', 'pass'].forEach(function (role) {
                    var input = tr.querySelector('[data-field-role="' + role + '"]');
                    if (!input) return;
                    var datasetKey = family + role.charAt(0).toUpperCase() + role.slice(1);
                    input.dataset.prop = datasetKey;
                    input.value = tr.dataset[datasetKey] || '';
                    input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input'));
                });
            });
        });
    }

    var clusterTable = document.getElementById('settings-cluster');
    if (clusterTable) {
        bindClusterAutosave(clusterTable, {
            node: clusterTable.dataset.nodeEndpoint,
            database: clusterTable.dataset.databaseEndpoint
        });
        bindFamilySwap(clusterTable, '[data-protocol-select]', function (value) {
            return (value === 'SSH' || value === 'SCP') ? 'ssh' : 'ftp';
        });
        // Databases row's Type values ARE the family names already (mysql/
        // postgres/sqlite3/oci8/sqlsrv - see SettingsController::
        // DATABASE_TYPES), no mapping needed.
        bindFamilySwap(clusterTable, '[data-type-select]', function (value) {
            return value;
        });
        // Node row's own Type select (distinct from the Database row's
        // [data-type-select] above, hence the plain [data-row="node"]
        // selector) - picking "Local" means there's no real network
        // endpoint to reach this node over, so "LOCAL" is the only
        // sensible Protocol; auto-select it rather than leaving a
        // mismatched FTP/SSH protocol dropdown to correct by hand.
        clusterTable.querySelectorAll('[data-row="node"][data-prop="type"]').forEach(function (typeSelect) {
            typeSelect.addEventListener('change', function () {
                if (typeSelect.value !== 'local') return;
                var protocolSelect = typeSelect.closest('tr').querySelector('[data-protocol-select]');
                if (!protocolSelect || protocolSelect.value === 'LOCAL') return;
                protocolSelect.value = 'LOCAL';
                protocolSelect.dispatchEvent(new Event('change'));
            });
        });
    }

    // ONE modal, opened from a node's name badge in the Cluster table -
    // tests that row's FTP/SSH connection AND its configured database
    // connection TOGETHER in a single request (server side does the actual
    // connecting - see SettingsController::testNode()/CapabilityChecker::
    // runCombined()), same idea as this project's own one-off verification
    // scripts used by hand throughout setup, just permanent and in-app now.
    // The response always carries BOTH sub-results ({node: {...}, database:
    // {...}}, each shaped like their own standalone checker's own result -
    // driver/version for the database one, protocol/detail for the node
    // one) - fillConnSection() below renders either shape identically,
    // just with a different summary formatter per section.
    var connModalEl = document.getElementById('conn-test-modal');
    var connModalTitle = document.getElementById('conn-test-modal-title');
    var connModalElapsed = document.getElementById('conn-test-modal-elapsed');
    var connModalLoading = document.getElementById('conn-test-modal-loading');
    var connModalWaiting = document.getElementById('conn-test-modal-waiting');
    var connModalError = document.getElementById('conn-test-modal-error');
    var connModalResult = document.getElementById('conn-test-modal-result');
    var connModalNodeSection = connModalResult ? connModalResult.querySelector('[data-conn-section="node"]') : null;
    var connModalDatabaseSection = connModalResult ? connModalResult.querySelector('[data-conn-section="database"]') : null;
    // Result endpoint is shared/global (a requestId is already a unique
    // opaque token - see SettingsController::testResult()), read off the
    // modal itself rather than the table's own data-test-endpoint - this
    // is a plain static .js file, not a PHP view, so the URL has to arrive
    // via a data attribute (see index.php), not url_to() inline.
    var connTestResultEndpoint = connModalEl ? connModalEl.dataset.testResultEndpoint : '';
    // Bounds how long a NAT-target poll waits before giving up client-side
    // (see dispatchTest()'s own docblock on why this is async at all) -
    // generous relative to the plain once-a-minute cron cadence, so a
    // normal wait doesn't false-timeout; a node that's actually offline
    // still resolves in a reasonable time rather than spinning forever.
    var CONN_TEST_POLL_MS = 1500;
    var CONN_TEST_TIMEOUT_MS = 90000;
    var connPollTimer = null;
    // Live stopwatch shown in the modal header - see index.php's own
    // comment on conn-test-modal-elapsed for why it lives there rather
    // than inside the loading/result toggle.
    var CONN_ELAPSED_TICK_MS = 47;
    var connElapsedStart = 0;
    var connElapsedTimer = null;

    var CONN_SUMMARY_FN = {
        node: function (data) { return data.protocol + (data.detail ? ' - ' + data.detail : '') + ' (' + data.ms + 'ms)'; },
        database: function (data) { return data.driver + ' - ' + data.version + ' (' + data.ms + 'ms)'; }
    };

    function connModal() {
        return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(connModalEl) : null;
    }

    function stopPolling() {
        if (connPollTimer) {
            clearTimeout(connPollTimer);
            connPollTimer = null;
        }
    }

    function formatElapsed(ms) {
        return (ms / 1000).toFixed(3) + 's';
    }

    function startConnTimer() {
        stopConnTimer();
        connElapsedStart = performance.now();
        connModalElapsed.textContent = formatElapsed(0);
        connElapsedTimer = setInterval(function () {
            connModalElapsed.textContent = formatElapsed(performance.now() - connElapsedStart);
        }, CONN_ELAPSED_TICK_MS);
    }

    // Stops the tick AND freezes the display on the real final elapsed
    // time (not whatever the last tick happened to show) - performance.now()
    // (not Date.now()) so a mid-test system clock adjustment can't skew it.
    // Called both when a result/error lands and when the modal is closed
    // mid-test.
    function stopConnTimer() {
        if (connElapsedTimer) {
            clearInterval(connElapsedTimer);
            connElapsedTimer = null;
            connModalElapsed.textContent = formatElapsed(performance.now() - connElapsedStart);
        }
    }

    function fillConnSection(section, kind, sub, testStrings) {
        if (!section) return;
        var ok = !!(sub && sub.ok);
        var badge = section.querySelector('[data-conn-badge]');
        badge.className = 'badge ' + (ok ? 'bg-green-lt' : 'bg-red-lt');
        badge.textContent = (ok ? testStrings.ok : testStrings.failed) || (ok ? 'OK' : 'FAILED');
        section.querySelector('[data-conn-summary]').textContent = ok ? CONN_SUMMARY_FN[kind](sub) : (sub && sub.ms !== undefined ? sub.ms + 'ms' : '');
        section.querySelector('[data-conn-detail]').textContent = (sub && sub.error) || '';
    }

    // Both sub-results present - the common case, whether the request
    // resolved instantly (local/public target) or after a NAT-relay poll.
    function showConnResult(data, testStrings) {
        stopConnTimer();
        connModalLoading.classList.add('d-none');
        connModalWaiting.classList.add('d-none');
        connModalError.classList.add('d-none');
        connModalResult.classList.remove('d-none');
        fillConnSection(connModalNodeSection, 'node', data.node, testStrings);
        fillConnSection(connModalDatabaseSection, 'database', data.database, testStrings);
    }

    // Whole-request failure - a network/fetch error, or the NAT-relay poll
    // giving up (see pollForResult() below) - there's no per-capability
    // result to show a section for either way, just one message.
    function showConnError(message) {
        stopConnTimer();
        connModalLoading.classList.add('d-none');
        connModalWaiting.classList.add('d-none');
        connModalResult.classList.add('d-none');
        connModalError.textContent = message || '';
        connModalError.classList.remove('d-none');
    }

    function pollForResult(node, requestId, testStrings, deadline) {
        stopPolling();
        connPollTimer = setTimeout(function () {
            fetch(connTestResultEndpoint + '?requestId=' + encodeURIComponent(requestId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { return window.syncCsrf(d); })
                .then(function (data) {
                    if (data.pending) {
                        if (Date.now() >= deadline) {
                            showConnError((testStrings.timeout || 'Timed out.').replace('{0}', node));

                            return;
                        }
                        pollForResult(node, requestId, testStrings, deadline);

                        return;
                    }
                    showConnResult(data, testStrings);
                })
                .catch(function () {
                    showConnError('');
                });
        }, CONN_TEST_POLL_MS);
    }

    function bindTestModal(table, testEndpoint) {
        if (!table || !connModalEl || !testEndpoint) return;
        var testStrings = JSON.parse(table.dataset.testStrings || '{}');

        table.querySelectorAll('[data-test-conn]').forEach(function (badge) {
            badge.addEventListener('click', function () {
                var node = badge.dataset.testConn;
                stopPolling();
                startConnTimer();
                connModalTitle.textContent = node;
                connModalLoading.classList.remove('d-none');
                connModalWaiting.classList.add('d-none');
                connModalError.classList.add('d-none');
                connModalResult.classList.add('d-none');
                var m = connModal();
                if (m) m.show();

                var body = new URLSearchParams();
                body.set('node', node);
                if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                fetch(testEndpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { return window.syncCsrf(d); })
                    .then(function (data) {
                        if (data.pending && data.requestId) {
                            // NAT target, almost always - but this is also
                            // where dispatchTest() falls back to for a node
                            // whose type it couldn't confirm was 'public',
                            // so pick the message off data.nodeType rather
                            // than assuming NAT and claiming a "no direct
                            // connection" reason that might not be true.
                            // No instant result yet; show why (not just a
                            // bare spinner) and start polling.
                            var waitingText = data.nodeType === 'nat' ? testStrings.waitingNat : testStrings.waitingUnknown;
                            connModalWaiting.textContent = (waitingText || 'Waiting for {0}...').replace('{0}', node);
                            connModalWaiting.classList.remove('d-none');
                            pollForResult(node, data.requestId, testStrings, Date.now() + CONN_TEST_TIMEOUT_MS);

                            return;
                        }
                        showConnResult(data, testStrings);
                    })
                    .catch(function () {
                        showConnError('');
                    });
            });
        });
    }

    if (clusterTable) {
        bindTestModal(clusterTable, clusterTable.dataset.nodeTestEndpoint);
    }

    ['conn-test-modal-close', 'conn-test-modal-ok'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function () {
            // Closing mid-test (still loading/polling) shouldn't leave
            // either running invisibly in the background.
            stopPolling();
            stopConnTimer();
            var m = connModal();
            if (m) m.hide();
        });
    });

    // Trash icon under each node's name badge (see index.php) - confirm-
    // modal first (see delete-node-modal's own comment on why this is
    // never a native confirm()), the actual delete only fires from the
    // modal's own Delete button. A full reload on success is what makes
    // the deleted node's whole two-row block - and its rowspan - actually
    // disappear, same reasoning every other bulk change on this page
    // already reloads for.
    var deleteNodeModalEl = document.getElementById('delete-node-modal');
    var deleteNodeError = document.getElementById('delete-node-modal-error');
    var deleteNodeStrings = deleteNodeModalEl ? JSON.parse(deleteNodeModalEl.dataset.deleteStrings || '{}') : {};
    if (clusterTable && deleteNodeModalEl) {
        var deleteNodeEndpoint = clusterTable.dataset.deleteEndpoint;
        var pendingDeleteNode = null;

        function deleteNodeModal() {
            return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(deleteNodeModalEl) : null;
        }

        clusterTable.querySelectorAll('[data-delete-node]').forEach(function (icon) {
            icon.addEventListener('click', function () {
                pendingDeleteNode = icon.dataset.deleteNode;
                deleteNodeError.classList.add('d-none');
                var m = deleteNodeModal();
                if (m) m.show();
            });
        });

        document.getElementById('delete-node-modal-confirm').addEventListener('click', function () {
            if (!pendingDeleteNode) return;
            fetchWithCsrfRetry(deleteNodeEndpoint, function () {
                var body = new URLSearchParams();
                body.set('node', pendingDeleteNode);
                if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                return body;
            })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        location.reload();
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    deleteNodeError.textContent = deleteNodeStrings.failed ? deleteNodeStrings.failed.replace('{0}', msg) : msg;
                    deleteNodeError.classList.remove('d-none');
                })
                .catch(function () {
                    deleteNodeError.textContent = deleteNodeStrings.failed ? deleteNodeStrings.failed.replace('{0}', '') : '';
                    deleteNodeError.classList.remove('d-none');
                });
        });

        ['delete-node-modal-close', 'delete-node-modal-cancel'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('click', function () {
                var m = deleteNodeModal();
                if (m) m.hide();
            });
        });
    }

    // "Delete" button on the Cluster card header (see index.php) -
    // resetCluster(), same confirm-modal-first shape as the per-node
    // delete above, just for this node's whole mesh membership instead of
    // one peer. A full reload on success is what flips the page back to
    // the "no cluster configured yet" empty state (see
    // SettingsController::resetCluster()'s own docblock).
    var resetClusterBtn = document.getElementById('settings-reset-cluster-btn');
    var resetClusterModalEl = document.getElementById('reset-cluster-modal');
    var resetClusterError = document.getElementById('reset-cluster-modal-error');
    var resetClusterStrings = resetClusterModalEl ? JSON.parse(resetClusterModalEl.dataset.resetStrings || '{}') : {};
    var resetClusterBox = document.getElementById('settings-export-import');
    if (resetClusterBtn && resetClusterModalEl && resetClusterBox) {
        var resetClusterEndpoint = resetClusterBox.dataset.resetEndpoint;

        function resetClusterModal() {
            return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(resetClusterModalEl) : null;
        }

        resetClusterBtn.addEventListener('click', function () {
            resetClusterError.classList.add('d-none');
            var m = resetClusterModal();
            if (m) m.show();
        });

        document.getElementById('reset-cluster-modal-confirm').addEventListener('click', function () {
            fetchWithCsrfRetry(resetClusterEndpoint, function () {
                var body = new URLSearchParams();
                if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                return body;
            })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        location.reload();
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    resetClusterError.textContent = resetClusterStrings.failed ? resetClusterStrings.failed.replace('{0}', msg) : msg;
                    resetClusterError.classList.remove('d-none');
                })
                .catch(function () {
                    resetClusterError.textContent = resetClusterStrings.failed ? resetClusterStrings.failed.replace('{0}', '') : '';
                    resetClusterError.classList.remove('d-none');
                });
        });

        ['reset-cluster-modal-close', 'reset-cluster-modal-cancel'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('click', function () {
                var m = resetClusterModal();
                if (m) m.hide();
            });
        });
    }

    var logoDrop = document.getElementById('settings-logo-drop');
    var logoInput = document.getElementById('settings-logo');
    var logoThumbWrap = document.getElementById('settings-logo-thumb-wrap');
    var logoThumb = document.getElementById('settings-logo-thumb');
    var logoDropHint = document.getElementById('settings-logo-drop-hint');

    function uploadLogoFile(file) {
        if (!file) return;
        var fd = new FormData();
        fd.append('logo', file);
        if (window.CI4_CSRF) fd.append(window.CI4_CSRF.name, window.CI4_CSRF.hash);
        fetch(logoEndpoint, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (d) { return window.syncCsrf(d); })
            .then(function (data) {
                showSaved();
                if (data && data.path) {
                    logoThumb.src = '/' + data.path;
                    logoThumbWrap.classList.remove('d-none');
                    if (logoDropHint) logoDropHint.classList.add('d-none');
                }
            })
            .catch(function () {});
    }

    if (logoInput) {
        logoInput.addEventListener('change', function () {
            uploadLogoFile(logoInput.files[0]);
        });
    }

    // Dropzone (see index.php's .settings-logo-drop) - click anywhere in it
    // (except the Delete badge, which has its own handler below and stops
    // this from also firing) proxies to the hidden file input, same as the
    // plain <input type=file> used to work. tabindex on the dropzone is
    // what lets it receive focus - and therefore a clipboard 'paste' event
    // - just by clicking into it, with no separate "click here first" step.
    if (logoDrop && logoInput) {
        logoDrop.addEventListener('click', function () {
            logoInput.click();
        });
        logoDrop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                logoInput.click();
            }
        });
        logoDrop.addEventListener('paste', function (e) {
            var items = (e.clipboardData || window.clipboardData || {}).items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) {
                    e.preventDefault();
                    uploadLogoFile(items[i].getAsFile());

                    return;
                }
            }
        });
    }

    var logoDeleteBtn = document.getElementById('settings-logo-delete-btn');
    if (logoDeleteBtn) {
        logoDeleteBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var body = new URLSearchParams();
            if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
            fetch(logoDeleteEndpoint, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { return window.syncCsrf(d); })
                .then(function (data) {
                    if (data && data.ok) {
                        logoThumbWrap.classList.add('d-none');
                        if (logoDropHint) logoDropHint.classList.remove('d-none');
                        logoInput.value = '';
                    }
                });
        });
    }

    // Shared password prompt (see app/Views/Settings/index.php's
    // #crypto-password-modal) backing BOTH Export (always asks - every
    // export is encrypted, see SettingsController::encryptExportPayload())
    // and Import (asks ONLY when the picked file turns out to already be
    // "encrypted": true - peekEncrypted() below reads that flag straight
    // out of the file client-side before ever uploading it, so a plain
    // unencrypted file - e.g. this project's own asyncron.nodes.json -
    // still imports with zero extra clicks like it always did). One modal,
    // retitled per call, instead of three near-identical copies.
    var pwModalEl = document.getElementById('crypto-password-modal');
    var pwStrings = pwModalEl ? JSON.parse(pwModalEl.dataset.strings || '{}') : {};
    var pwTitleEl = document.getElementById('crypto-password-modal-title');
    var pwHintEl = document.getElementById('crypto-password-modal-hint');
    var pwInput = document.getElementById('crypto-password-input');
    var pwError = document.getElementById('crypto-password-modal-error');
    var pwConfirmBtn = document.getElementById('crypto-password-modal-confirm');
    var pwCancelBtn = document.getElementById('crypto-password-modal-cancel');
    var pwCloseBtn = document.getElementById('crypto-password-modal-close');

    function pwModal() { return window.bootstrap ? bootstrap.Modal.getOrCreateInstance(pwModalEl) : null; }

    // Resolves with the typed password (possibly '' when required is
    // false - see the Export button's own call below), or rejects with an
    // Error whose .message is 'cancelled' when the user backs out -
    // callers treat that one rejection reason as "do nothing", every
    // other one as a real failure worth surfacing.
    function askPassword(title, hint, confirmLabel, required) {
        return new Promise(function (resolve, reject) {
            if (!pwModalEl || !pwInput) { reject(new Error('no-modal')); return; }
            pwTitleEl.textContent = title;
            pwHintEl.textContent = hint;
            pwConfirmBtn.textContent = confirmLabel;
            pwInput.value = '';
            pwError.classList.add('d-none');

            function onConfirm() {
                var val = pwInput.value;
                if (!val && required) {
                    pwError.textContent = pwStrings.required || '';
                    pwError.classList.remove('d-none');
                    return;
                }
                cleanup();
                var m = pwModal();
                if (m) m.hide();
                resolve(val);
            }
            function onCancel() {
                cleanup();
                var m = pwModal();
                if (m) m.hide();
                reject(new Error('cancelled'));
            }
            function onKeydown(e) { if (e.key === 'Enter') onConfirm(); }
            function cleanup() {
                pwConfirmBtn.removeEventListener('click', onConfirm);
                pwCancelBtn.removeEventListener('click', onCancel);
                pwCloseBtn.removeEventListener('click', onCancel);
                pwInput.removeEventListener('keydown', onKeydown);
            }
            pwConfirmBtn.addEventListener('click', onConfirm);
            pwCancelBtn.addEventListener('click', onCancel);
            pwCloseBtn.addEventListener('click', onCancel);
            pwInput.addEventListener('keydown', onKeydown);

            var m = pwModal();
            if (m) m.show();
            setTimeout(function () { pwInput.focus(); }, 300);
        });
    }

    // Reads a picked File client-side (no upload yet) just far enough to
    // know whether it's one of encryptExportPayload()'s envelopes - lets
    // the Import handlers below skip the password prompt entirely for a
    // plain file, and ask BEFORE spending a round trip on a file the
    // server would otherwise reject for lack of a password.
    function peekEncrypted(file) {
        return file.text().then(function (text) {
            try {
                var decoded = JSON.parse(text);
                return !!(decoded && decoded.encrypted === true);
            } catch (e) {
                return false;
            }
        }).catch(function () { return false; });
    }

    // Export's own response is JSON (envelope + filename), not a raw file
    // body with Content-Disposition like the old GET-download link used -
    // a fetch() POST can't trigger a browser "Save As" by itself, so this
    // builds the download client-side via an object URL and a synthetic,
    // never-appended-to-view <a download> click.
    function downloadJson(filename, obj) {
        var blob = new Blob([JSON.stringify(obj, null, 2)], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    // Export button (see app/Views/Settings/index.php - shared toolbar
    // above the Nodes table). Always shows the password prompt, but the
    // field itself is optional (askPassword()'s own 'required' arg is
    // false here) - leaving it blank downloads the plain, unencrypted
    // payload, same as before encryption existed at all; typing one
    // downloads the encrypted envelope instead. Either way, whatever
    // exportSettings() sends back is what gets downloaded as-is.
    var exportBox = document.getElementById('settings-export-import');
    var exportBtn = document.getElementById('settings-export-btn');
    if (exportBox && exportBtn) {
        var exportEndpoint = exportBox.dataset.exportEndpoint;
        var exportError = document.getElementById('settings-import-error');

        exportBtn.addEventListener('click', function () {
            askPassword(pwStrings.exportTitle, pwStrings.exportHint, pwStrings.exportConfirm, false)
                .then(function (password) {
                    return fetchWithCsrfRetry(exportEndpoint, function () {
                        var body = new URLSearchParams();
                        body.set('password', password);
                        if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                        return body;
                    });
                })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        downloadJson(result.data.filename, result.data.envelope);
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    exportError.textContent = msg;
                    exportError.classList.remove('d-none');
                })
                .catch(function (e) {
                    if (e && e.message === 'cancelled') return;
                    exportError.textContent = pwStrings.networkError || '';
                    exportError.classList.remove('d-none');
                });
        });
    }

    // Import button (see app/Views/Settings/index.php - shared toolbar
    // above the Nodes table, covers both Nodes and Databases). The file
    // input's own 'change' event does the picking; clicking the visible
    // button just proxies to the hidden <input type=file> the same way
    // the logo picker above does.
    var importBox = document.getElementById('settings-export-import');
    var importBtn = document.getElementById('settings-import-btn');
    var importInput = document.getElementById('settings-import-file');
    // importBtn/importInput only exist once the cluster is "functional"
    // (see SettingsController::index()'s own 'clusterFunctional' docblock)
    // - below that threshold the toolbar shows the bootstrap "Import
    // cluster" button instead (see clusterImportBox below), not this one.
    if (importBox && importBtn && importInput) {
        var importEndpoint = importBox.dataset.importEndpoint;
        var importStrings  = JSON.parse(importBox.dataset.importStrings || '{}');
        var importError    = document.getElementById('settings-import-error');

        importBtn.addEventListener('click', function () { importInput.click(); });

        importInput.addEventListener('change', function () {
            if (!importInput.files[0]) return;
            importError.classList.add('d-none');
            var pickedFile = importInput.files[0];
            peekEncrypted(pickedFile)
                .then(function (encrypted) {
                    return encrypted
                        ? askPassword(pwStrings.importTitle, pwStrings.importHint, pwStrings.importConfirm, true)
                        : '';
                })
                .then(function (password) {
                    return fetchWithCsrfRetry(importEndpoint, function () {
                        var fd = new FormData();
                        fd.append('file', pickedFile);
                        if (password) fd.append('password', password);
                        if (window.CI4_CSRF) fd.append(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                        return fd;
                    });
                })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        showSaved();
                        stashClusterWarning(result.data.warning);
                        // Nodes/Databases tables were seeded server-side from
                        // Settings on page load - a full reload is the
                        // simplest way to reflect what import just
                        // overwrote, same as any other bulk server-side change.
                        location.reload();
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    importError.textContent = importStrings.failed ? importStrings.failed.replace('{0}', msg) : msg;
                    importError.classList.remove('d-none');
                })
                .catch(function (e) {
                    if (e && e.message === 'cancelled') return;
                    importError.textContent = importStrings.failed ? importStrings.failed.replace('{0}', '') : '';
                    importError.classList.remove('d-none');
                })
                .finally(function () { importInput.value = ''; });
        });
    }

    // "Import cluster" button (see app/Views/Settings/index.php - nested
    // inside the toolbar alongside Export/Import/Delete) - only rendered
    // while the node registry is entirely empty, in place of the regular
    // Import button. Bootstraps cluster.nodes (.env) plus the Nodes/
    // Databases credential tables in one upload, from a file shaped like
    // this project's own asyncron.nodes.json. Same button-proxies-hidden-
    // file-input shape as the Import block above; on success there's no
    // existing table to patch in place (there wasn't one to begin with),
    // so a full reload is what makes the newly-populated Nodes/Databases
    // tables - and this button's own disappearance - actually show up.
    var clusterImportBox = document.getElementById('settings-cluster-import');
    if (clusterImportBox) {
        var clusterImportEndpoint = clusterImportBox.dataset.importEndpoint;
        var clusterImportStrings = JSON.parse(clusterImportBox.dataset.importStrings || '{}');
        var clusterImportBtn = document.getElementById('settings-import-cluster-btn');
        var clusterImportInput = document.getElementById('settings-import-cluster-file');
        var clusterImportError = document.getElementById('settings-import-cluster-error');

        clusterImportBtn.addEventListener('click', function () { clusterImportInput.click(); });

        clusterImportInput.addEventListener('change', function () {
            if (!clusterImportInput.files[0]) return;
            clusterImportError.classList.add('d-none');
            var pickedFile = clusterImportInput.files[0];
            peekEncrypted(pickedFile)
                .then(function (encrypted) {
                    return encrypted
                        ? askPassword(pwStrings.importTitle, pwStrings.importHint, pwStrings.importConfirm, true)
                        : '';
                })
                .then(function (password) {
                    return fetchWithCsrfRetry(clusterImportEndpoint, function () {
                        var fd = new FormData();
                        fd.append('file', pickedFile);
                        if (password) fd.append('password', password);
                        if (window.CI4_CSRF) fd.append(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                        return fd;
                    });
                })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        stashClusterWarning(result.data.warning);
                        location.reload();
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    clusterImportError.textContent = clusterImportStrings.failed ? clusterImportStrings.failed.replace('{0}', msg) : msg;
                    clusterImportError.classList.remove('d-none');
                })
                .catch(function (e) {
                    if (e && e.message === 'cancelled') return;
                    clusterImportError.textContent = clusterImportStrings.failed ? clusterImportStrings.failed.replace('{0}', '') : '';
                    clusterImportError.classList.remove('d-none');
                })
                .finally(function () { clusterImportInput.value = ''; });
        });
    }

    // Blank "add a node" row at the end of the Cluster table (see
    // app/Views/Settings/index.php) - a single create call, not per-field
    // autosave like every other row (there's nothing to autosave against
    // until the node exists in cluster.nodes - see SettingsController::
    // addNode()'s own docblock). Reads every field currently typed into
    // the row and reloads on success, same "no existing row to patch in
    // place" reasoning the Import cluster button above already uses.
    var addNodeRow = document.getElementById('settings-add-node-row');
    if (addNodeRow) {
        var addNodeEndpoint = addNodeRow.dataset.addEndpoint;
        var addNodeStrings = JSON.parse(addNodeRow.dataset.addStrings || '{}');
        var addNodeBtn = document.getElementById('settings-add-node-btn');
        var addNodeError = document.getElementById('settings-add-node-error');
        var addNodeFields = ['name', 'type', 'url', 'protocol', 'host', 'port', 'user', 'pass', 'dbType', 'dbDatabase', 'dbHost', 'dbPort', 'dbUser', 'dbPass'];

        // Same "Local" type -> "LOCAL" protocol auto-select as the
        // existing rows above - submitAddNode() below reads whatever's
        // currently in the Protocol select at submit time, so setting it
        // here is enough.
        var addNodeType = document.getElementById('settings-add-node-type');
        var addNodeProtocol = document.getElementById('settings-add-node-protocol');
        if (addNodeType && addNodeProtocol) {
            addNodeType.addEventListener('change', function () {
                if (addNodeType.value === 'local') addNodeProtocol.value = 'LOCAL';
            });
        }

        function submitAddNode() {
            addNodeError.classList.add('d-none');
            fetchWithCsrfRetry(addNodeEndpoint, function () {
                var body = new URLSearchParams();
                addNodeFields.forEach(function (field) {
                    var el = document.getElementById('settings-add-node-' + field);
                    if (el) body.set(field, el.value);
                });
                if (window.CI4_CSRF) body.set(window.CI4_CSRF.name, window.CI4_CSRF.hash);
                return body;
            })
                .then(function (result) {
                    window.syncCsrf(result.data);
                    if (result.ok && result.data && result.data.ok) {
                        location.reload();
                        return;
                    }
                    var msg = (result.data && result.data.error) ? result.data.error : '';
                    addNodeError.textContent = addNodeStrings.failed ? addNodeStrings.failed.replace('{0}', msg) : msg;
                    addNodeError.classList.remove('d-none');
                })
                .catch(function () {
                    addNodeError.textContent = addNodeStrings.failed ? addNodeStrings.failed.replace('{0}', '') : '';
                    addNodeError.classList.remove('d-none');
                });
        }

        addNodeBtn.addEventListener('click', submitAddNode);
        var addNodeNameInput = document.getElementById('settings-add-node-name');
        if (addNodeNameInput) {
            addNodeNameInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') submitAddNode();
            });
        }
    }
})();
