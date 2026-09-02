<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Settings\Config\Settings as BaseSettings;
use CodeIgniter\Settings\Handlers\ArrayHandler;
use CodeIgniter\Settings\Handlers\DatabaseHandler;
use CodeIgniter\Settings\Handlers\FileHandler;

class Settings extends BaseSettings
{
    /**
     * The available handlers. The alias must
     * match a public class var here with the
     * settings array containing 'class'.
     *
     * @var list<string>
     */
    public $handlers = ['database'];

    /**
     * Array handler settings.
     */
    public $array = [
        'class'     => ArrayHandler::class,
        'writeable' => true,
    ];

    /**
     * Database handler settings.
     *
     * 'group' => 'cluster', not the framework's own null-means-default -
     * DbSyncSchema::settingsDb() (see that method's own docblock) has
     * connected to the 'cluster' group for the settings table since
     * 2026-08-19, on the understanding that this property was already
     * pointed there too. It wasn't - left at null (-> 'default') here,
     * so every node's REAL settings table lived in database.default while
     * cluster:sync-db's own settingsDb() looked for it in database.cluster,
     * which never had it: "no such table: settings" on every node's
     * cluster:sync-db run, 100% of the time, regardless of network/auth
     * state. A regression from this repo's own merge (the fix DbSyncSchema
     * already documents was never carried over) rather than a new issue.
     * codeigniter4/settings' own migrations (CreateSettingsTable and
     * later ones) read this exact property for their own $DBGroup, so
     * this one-line change is enough to fix BOTH where a fresh install's
     * migration creates the table AND where the running app reads/writes
     * it via service('settings') - no InstallCommand change needed, unlike
     * codeigniter4/queue's migrations (which don't declare a $DBGroup of
     * their own at all, see InstallCommand::runQueueMigrationAgainstClusterGroup()
     * for why THAT package needs a different, heavier fix).
     */
    public $database = [
        'class'       => DatabaseHandler::class,
        'table'       => 'settings',
        'group'       => 'cluster',
        'writeable'   => true,
        'deferWrites' => false,
    ];

    /**
     * File handler settings.
     */
    public $file = [
        'class'       => FileHandler::class,
        'path'        => WRITEPATH . 'settings',
        'writeable'   => true,
        'deferWrites' => false,
    ];
}
