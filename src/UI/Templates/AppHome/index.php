<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>" data-bs-theme-primary="<?= esc(setting('Site.themeColor') ?? 'blue') ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($serverName) ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/tabler/tabler.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/tabler/tabler-themes.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/app.css') ?>">
    <script src="<?= base_url('assets/tabler/bootstrap.bundle.min.js') ?>" defer></script>
    <script src="<?= base_url('assets/tabler/tabler.min.js') ?>" defer></script>
</head>
<body class="d-flex flex-column">
<div class="page page-center"><div class="container container-tight py-4">
    <div class="card card-md"><div class="card-body">
        <h2 class="h2 mb-3"><?= esc($serverName) ?></h2>

        <?php if ($user !== null) : ?>
            <?php $identity = $user->getEmailIdentity(); ?>
            <p class="text-secondary"><?= lang('App.connectedAs', [esc($identity?->secret ?? $user->username ?? '')]) ?></p>
            <a href="<?= url_to('dashboard') ?>" class="btn btn-primary w-100 mb-3"><?= lang('App.goToDashboard') ?></a>
        <?php else : ?>
            <p class="text-secondary"><?= lang('App.notConnected') ?></p>
            <a href="<?= url_to('login') ?>" class="btn btn-primary w-100 mb-3"><?= lang('Auth.login') ?></a>
        <?php endif ?>

        <?php if (!empty($servers)) : ?>
        <div class="mt-2">
            <div class="text-secondary mb-2" style="font-size:12px"><?= lang('App.clusterNodes') ?></div>
            <div id="node-list">
                <?php foreach ($servers as $url) : ?>
                    <div class="d-flex justify-content-between align-items-center py-1" data-url="<?= esc($url) ?>" style="font-family:ui-monospace,Consolas,monospace;font-size:13px">
                        <a href="<?= esc(rtrim($url, '/')) ?>/" class="text-reset"><?= esc(preg_replace('#^https?://#', '', $url)) ?></a>
                        <span class="m text-secondary">…</span>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <?php endif ?>
    </div></div>
</div></div>
<script src="<?= base_url('assets/node-picker.js') ?>"></script>
<script>
(function () {
    // Live per-node status only - no auto-redirect here, unlike the
    // standalone C:\ai\server-picker page this ports from. This IS the
    // site's own root now (see Home::index()'s own docblock), not a
    // transient "find any working node then leave" splash - forcibly
    // navigating a visitor away from the page they just landed on would
    // undo the whole point of it being the permanent home. A logged-in
    // visitor switching nodes already has its own deliberate, opt-in path
    // (the "Auto" toggle + cross-node SSO handoff - see AuthController::
    // fastestNodeRedirect()); this list is for manually picking a
    // different one, same restraint as the login page's own banner.
    var SERVERS = <?= json_encode($servers, JSON_UNESCAPED_SLASHES) ?>;
    var UNAVAILABLE = <?= json_encode(lang('App.startUnavailable')) ?>;

    SERVERS.forEach(function (url) {
        window.NodePicker.probe(url, '/healthz', 2500).then(
            function (r) { setStatus(url, 'ok', r.ms); },
            function () { setStatus(url, 'fail'); }
        );
    });

    function setStatus(url, state, ms) {
        var el = document.querySelector('[data-url="' + CSS.escape(url) + '"] .m');
        if (!el) { return; }
        el.textContent = state === 'ok' ? (ms + ' ms') : UNAVAILABLE;
        el.className = 'm ' + (state === 'ok' ? 'text-success' : 'text-danger');
    }
})();
</script>
<script src="<?= base_url('assets/app.js') ?>" defer></script>
</body></html>
