<?= $this->extend('\AdGo\Cluster\UI\Views\Layout\app') ?>
<?= $this->section('content') ?>
<?php if ($userSummary !== null) : ?>
<div class="row row-cards">
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0"><?= lang('App.userDashFilesCard') ?></h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <div class="h1 mb-0 text-success"><?= (int) $userSummary['syncedFiles'] ?></div>
                        <div class="text-secondary"><?= lang('App.userDashSynced') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="h1 mb-0 text-warning"><?= (int) $userSummary['pendingFiles'] ?></div>
                        <div class="text-secondary"><?= lang('App.userDashPending') ?></div>
                    </div>
                </div>
                <div class="mt-3 text-secondary">
                    <?= lang('App.userDashRecentTransfer') ?>: <?= esc($userSummary['recentBytesHuman']) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0"><?= lang('App.userDashDbCard') ?></h3>
            </div>
            <div class="card-body">
                <div class="h1 mb-0"><?= (int) $userSummary['dbTotalSynced'] ?></div>
                <div class="text-secondary mb-3"><?= lang('App.userDashDbSynced') ?></div>
                <div class="row">
                    <div class="col-6">
                        <div class="h3 mb-0 text-success"><?= (int) $userSummary['dbRecentOk'] ?></div>
                        <div class="text-secondary"><?= lang('App.userDashDbOk') ?></div>
                    </div>
                    <div class="col-6">
                        <div class="h3 mb-0 text-danger"><?= (int) $userSummary['dbRecentErrors'] ?></div>
                        <div class="text-secondary"><?= lang('App.userDashDbErrors') ?></div>
                    </div>
                </div>
                <div class="mt-3 text-secondary">
                    <?= lang('App.userDashLastActivity') ?>: <?= esc($userSummary['dbLastActivityAgo'] ?? lang('App.userDashNever')) ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else : ?>
<div class="alert alert-secondary"><?= lang('App.netNotInstalled') ?></div>
<?php endif ?>
<?= $this->endSection() ?>
