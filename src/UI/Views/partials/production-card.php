<!-- "Production" card - see Dashboard::productionInfo()'s own docblock
     for why this is gated on DbSyncSchema::productionSyncEnabled() and
     shown on BOTH the superadmin and 'user' dashboards (dashboard.php/
     dashboard-user.php both include this same partial rather than each
     carrying their own copy). Tree root = the real database name/size
     Config\Cluster::$dbSyncGroup points at; each leaf is one table, colored
     by its own sync mode - green "merge" (genericTables()' own natural-
     key/updated_at eligible tables, synced bidirectionally), blue "source-
     only" (genericIdBasedTables()' autoincrement-keyed/no-updated_at
     tables, synced one way FROM whichever peer has "Source node" on), or
     grey "none" - so an admin can see both what's syncing and how, at a
     glance. The table below repeats the same per-table facts in a form
     that doesn't need a hover to read. -->
<div class="row row-cards mt-4">
    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header">
                <h3 class="card-title mb-0"><?= lang('App.prodTitle') ?></h3>
                <div class="card-actions ms-auto">
                    <span class="badge bg-blue-lt"><?= esc($productionInfo['database']) ?></span>
                </div>
            </div>
            <div class="card-body">
                <div id="production-tree"
                    style="height:450px;"
                    data-database="<?= esc($productionInfo['database'], 'attr') ?>"
                    data-size-human="<?= esc($productionInfo['sizeHuman'] ?? '', 'attr') ?>"
                    data-tables="<?= esc(json_encode($productionInfo['tables']), 'attr') ?>"
                    data-strings="<?= esc(json_encode([
                        'database'      => lang('App.prodDatabase'),
                        'size'          => lang('App.prodSize'),
                        'records'       => lang('App.prodRecords'),
                        'autoIncrement' => lang('App.prodAutoIncrement'),
                        'updatedAt'     => lang('App.prodUpdatedAt'),
                        'syncEligible'  => lang('App.prodSyncEligible'),
                        'yes'           => lang('App.prodYes'),
                        'no'            => lang('App.prodNo'),
                        'unknown'       => lang('App.prodUnknown'),
                        'noTables'      => lang('App.prodNoTables'),
                        'modeMerge'      => lang('App.prodSyncModeMerge'),
                        'modeSourceOnly' => lang('App.prodSyncModeSourceOnly'),
                        'modeNone'       => lang('App.prodSyncModeNone'),
                    ]), 'attr') ?>"
                ></div>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-2 text-secondary" style="font-size:.75rem;">
                    <span><span class="legend-dot" style="background:#2fb344"></span> <?= lang('App.prodSyncModeMerge') ?></span>
                    <span><span class="legend-dot" style="background:#4299e1"></span> <?= lang('App.prodSyncModeSourceOnly') ?></span>
                    <span><span class="legend-dot" style="background:#adb5bd"></span> <?= lang('App.prodSyncModeNone') ?></span>
                </div>
                <?php if ($productionInfo['tables'] === []) : ?>
                    <div class="text-secondary text-center mt-2" style="font-size:.75rem;"><?= lang('App.prodNoTables') ?></div>
                <?php else : ?>
                    <hr class="my-3">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr>
                                <th><?= lang('App.prodTablesColumn') ?></th>
                                <th class="text-end"><?= lang('App.prodRecords') ?></th>
                                <th class="text-end"><?= lang('App.prodSize') ?></th>
                                <th class="text-center"><?= lang('App.prodAutoIncrement') ?></th>
                                <th class="text-center"><?= lang('App.prodUpdatedAt') ?></th>
                                <th class="text-center"><?= lang('App.prodSyncEligible') ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($productionInfo['tables'] as $name => $row) : ?>
                                    <tr>
                                        <td><?= esc($name) ?></td>
                                        <td class="text-end"><?= (int) $row['records'] ?></td>
                                        <td class="text-end"><?= esc($row['sizeHuman'] ?? lang('App.prodUnknown')) ?></td>
                                        <td class="text-center"><?= $row['hasAutoIncrementKey'] ? '<span class="text-danger">'.esc(lang('App.prodYes')).'</span>' : '<span class="text-secondary">'.esc(lang('App.prodNo')).'</span>' ?></td>
                                        <td class="text-center"><?= $row['hasUpdatedAt'] ? '<span class="text-success">'.esc(lang('App.prodYes')).'</span>' : '<span class="text-secondary">'.esc(lang('App.prodNo')).'</span>' ?></td>
                                        <td class="text-center"><?php
                                            $modeBadge = [
                                                'merge'       => ['bg-green-lt', 'App.prodSyncModeMerge'],
                                                'source-only' => ['bg-blue-lt', 'App.prodSyncModeSourceOnly'],
                                                'none'        => ['bg-secondary-lt', 'App.prodSyncModeNone'],
                                            ][$row['syncMode'] ?? 'none'];
                                            echo '<span class="badge ' . $modeBadge[0] . '">' . esc(lang($modeBadge[1])) . '</span>';
                                        ?></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif ?>
            </div>
        </div>
    </div>
</div>
