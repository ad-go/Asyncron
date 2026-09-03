<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use CodeIgniter\Database\ConnectionInterface;

/**
 * The one place table-specific knowledge lives for DB sync (see this
 * package's README "How it works" - Database sync, for the short
 * version). No schema changes
 * anywhere - every table here uses a node-local autoincrement `id` that
 * differs per node for the SAME logical record, so each table's own
 * already-existing natural/business key (email; class+key+context) is
 * what identifies a row across nodes, exactly like session invalidation
 * and SSO already key everything by email rather than Shield's numeric
 * user ID.
 *
 * A "user" snapshot is exported/applied as ONE unit spanning users +
 * auth_identities + auth_groups_users + auth_permissions_users +
 * user_profiles - not one row at a time - because every one of those
 * tables' foreign keys (`user_id`) is node-local and meaningless outside
 * the node that assigned it; the snapshot resolves that FK exactly once
 * (by email) rather than needing a separate remap step per table.
 *
 * Hard-excluded from Config\Cluster::$dbSyncGroup's whole-database
 * discovery, regardless of $dbExcludeTables (see HARD_EXCLUDED_TABLES
 * below), and not handled here at all: migrations/sqlite_sequence
 * (node-local deployment bookkeeping, never portable), queue_jobs/
 * queue_jobs_failed (relocated to their own connection - see
 * Config\Database's $cluster group), auth_logins/auth_token_logins/
 * auth_remember_tokens (audit logs and per-device tokens with no
 * cross-node sync value).
 */
class DbSyncSchema
{
    /**
     * @return array{email: string, users: array, identities: list<array>, groups: list<string>, permissions: list<string>, profile: array|null}|null
     */
    public static function exportUser(ConnectionInterface $db, string $email): ?array
    {
        $email = strtolower(trim($email));

        $identityRow = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();
        if ($identityRow === null) {
            return null;
        }
        $userId = (int) $identityRow['user_id'];

        $user = $db->table('users')->where('id', $userId)->get()->getRowArray();
        if ($user === null) {
            return null;
        }
        unset($user['id']);

        $identities = $db->table('auth_identities')->where('user_id', $userId)->get()->getResultArray();
        foreach ($identities as &$identity) {
            unset($identity['id'], $identity['user_id']);
        }
        unset($identity);

        $groups = array_values(array_column(
            $db->table('auth_groups_users')->where('user_id', $userId)->get()->getResultArray(),
            'group'
        ));
        $permissions = array_values(array_column(
            $db->table('auth_permissions_users')->where('user_id', $userId)->get()->getResultArray(),
            'permission'
        ));

        $profile = $db->table('user_profiles')->where('user_id', $userId)->get()->getRowArray();
        if ($profile !== null) {
            unset($profile['id'], $profile['user_id']);
        }

        return [
            'email'       => $email,
            'users'       => $user,
            'identities'  => $identities,
            'groups'      => $groups,
            'permissions' => $permissions,
            'profile'     => $profile,
        ];
    }

    /**
     * @return list<string> every distinct email currently known to this node
     */
    public static function exportAllUserEmails(ConnectionInterface $db): array
    {
        $rows = $db->table('auth_identities')
            ->select('secret')
            ->where('type', 'email_password')
            ->get()->getResultArray();

        return array_values(array_unique(array_map(
            static fn (array $row): string => strtolower(trim((string) $row['secret'])),
            $rows
        )));
    }

    /**
     * Insert-or-update a user's full snapshot. Brand new email -> a new
     * `users` row via Shield's own UserModel (respects its own defaults/
     * validation, same as how a real signup or CI4install.php's
     * installSuperadminHere() creates one). Existing email -> mutable
     * fields updated in place, but `created_at` is NEVER overwritten from
     * an incoming snapshot - that's this node's own record of when it
     * first learned about the account, not portable state.
     *
     * `secret2` (the bcrypt hash) is written VERBATIM from the payload -
     * it is already hashed by the originating node; re-hashing it here
     * would break login. This is the one place password data crosses
     * nodes, over the same Bearer-HTTPS channel every other peer-to-peer
     * call already uses - intentional, since "the same login works
     * everywhere" is the actual point of this feature.
     */
    /**
     * @param list<string> $queryLog appended with the real SQL text of
     *                               every write this call makes - see
     *                               applyIncomingCommand()'s own docblock,
     *                               this is what the Dashboard's "Database
     *                               synchronization" card shows as
     *                               "recent SQL commands"
     */
    public static function applyUserSnapshot(ConnectionInterface $db, array $snapshot, array &$queryLog = []): void
    {
        $email = strtolower(trim((string) ($snapshot['email'] ?? '')));
        if ($email === '') {
            return;
        }

        $existingIdentity = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();

        if ($existingIdentity === null) {
            $userModel = new \CodeIgniter\Shield\Models\UserModel();
            $userEntity = new \CodeIgniter\Shield\Entities\User((array) ($snapshot['users'] ?? []));
            $userModel->save($userEntity);
            $userId = (int) $userModel->getInsertID();
            $queryLog[] = $db->showLastQuery();
        } else {
            $userId = (int) $existingIdentity['user_id'];
            $fields = (array) ($snapshot['users'] ?? []);
            unset($fields['created_at']);
            if ($fields !== []) {
                $db->table('users')->where('id', $userId)->update($fields);
                $queryLog[] = $db->showLastQuery();
            }
        }

        foreach ((array) ($snapshot['identities'] ?? []) as $incoming) {
            $type = (string) ($incoming['type'] ?? '');
            $name = (string) ($incoming['name'] ?? '');
            if ($type === '') {
                continue;
            }
            $secret = (string) ($incoming['secret'] ?? '');
            // Match by (type, secret) when there IS a secret - that's the
            // ACTUAL unique constraint Shield enforces on auth_identities
            // (found live 2026-08-18: matching by (user_id, type, name)
            // alone let a concurrent delivery race hit "UNIQUE constraint
            // failed: auth_identities.type, auth_identities.secret" -
            // this is the real key, (user_id, type, name) was only ever a
            // proxy for it). Falls back to (user_id, type, name) for
            // identity types with no meaningful secret to match on.
            $existing = $secret !== ''
                ? $db->table('auth_identities')->where('type', $type)->where('secret', $secret)->get()->getRowArray()
                : $db->table('auth_identities')->where('user_id', $userId)->where('type', $type)->where('name', $name)->get()->getRowArray();
            $data = $incoming;
            $data['user_id'] = $userId;
            if ($existing !== null) {
                unset($data['created_at']);
                $db->table('auth_identities')->where('id', $existing['id'])->update($data);
            } else {
                $db->table('auth_identities')->insert($data);
            }
            $queryLog[] = $db->showLastQuery();
        }

        self::replaceMembership($db, 'auth_groups_users', 'group', $userId, (array) ($snapshot['groups'] ?? []), $queryLog);
        self::replaceMembership($db, 'auth_permissions_users', 'permission', $userId, (array) ($snapshot['permissions'] ?? []), $queryLog);

        $profile = $snapshot['profile'] ?? null;
        if (is_array($profile) && $profile !== []) {
            $existingProfile = $db->table('user_profiles')->where('user_id', $userId)->get()->getRowArray();
            $data = $profile;
            $data['user_id'] = $userId;
            if ($existingProfile !== null) {
                unset($data['created_at']);
                $db->table('user_profiles')->where('id', $existingProfile['id'])->update($data);
            } else {
                $db->table('user_profiles')->insert($data);
            }
            $queryLog[] = $db->showLastQuery();
        }
    }

    /**
     * Full diff-based replace (add missing, REMOVE stale) - unlike
     * generic file deletion (explicitly out of scope elsewhere), a
     * snapshot's group/permission list is a complete declaration of
     * current membership, so reconciling removals here is correct and
     * security-relevant (revoking superadmin on one node should revoke
     * it everywhere).
     *
     * @param list<string> $wanted
     * @param list<string> $queryLog
     */
    private static function replaceMembership(ConnectionInterface $db, string $table, string $column, int $userId, array $wanted, array &$queryLog): void
    {
        $current = array_values(array_column(
            $db->table($table)->where('user_id', $userId)->get()->getResultArray(),
            $column
        ));

        foreach (array_diff($wanted, $current) as $add) {
            $db->table($table)->insert([
                'user_id'  => $userId,
                $column    => $add,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $queryLog[] = $db->showLastQuery();
        }
        foreach (array_diff($current, $wanted) as $remove) {
            $db->table($table)->where('user_id', $userId)->where($column, $remove)->delete();
            $queryLog[] = $db->showLastQuery();
        }
    }

    /**
     * Stable across identical content regardless of array order - groups/
     * permissions/identities are sorted before hashing so re-exporting
     * unchanged data (just enumerated in a different order) never looks
     * like a change.
     *
     * Also strips `last_active` (users) and `last_used_at` (each identity)
     * before hashing - found live 2026-08-18: these drift on their own
     * (Shield touches them on ordinary authenticated activity, unrelated
     * to any account-data change worth syncing), so leaving them in the
     * hash made every account look "changed" on nearly every cron tick -
     * a constant, unnecessary re-broadcast storm, not a one-off. The
     * actual DB columns are still synced as part of the payload (no
     * information lost) - only the CHANGE-DETECTION hash ignores them.
     *
     * Also strips `created_at`/`updated_at` from users/identities/profile -
     * found live 2026-08-21, the same storm resurfacing on a different
     * field: admin@local.host's `users.created_at` reads 07:44:20 on h1q
     * but 07:44:02 on bak/res (each node's own account row came from a
     * separate local provisioning step, not purely through cluster sync),
     * so this account could never hash equal across nodes despite every
     * OTHER field matching exactly - see hashSettingSnapshot()'s own
     * docblock for the identical reasoning applied there the same day.
     */
    public static function hashUserSnapshot(array $snapshot): string
    {
        $normalized               = $snapshot;
        $normalized['groups']     = self::sorted($normalized['groups'] ?? []);
        $normalized['permissions'] = self::sorted($normalized['permissions'] ?? []);
        unset($normalized['users']['last_active'], $normalized['users']['created_at'], $normalized['users']['updated_at']);

        // `foreach ($normalized['identities'] ?? [] as &$identity)` looks
        // like it strips these fields in place, but a reference taken
        // through a `??` expression binds to a THROWAWAY temporary, not
        // the original array slot - the unset()s below were silently
        // discarded, and $normalized['identities'] (what actually gets
        // hashed) never lost last_used_at/created_at/updated_at at all.
        // Found live 2026-08-21 chasing a users:admin@local.host relay
        // that wouldn't die even after this method's own dedup fields
        // were added: h1q and bak's identity rows differed only in
        // created_at (07:44:21 vs 07:44:02) yet still hashed differently,
        // because that field was never actually leaving the payload
        // being hashed. This bug predates today - the ORIGINAL
        // 2026-08-18 fix for last_used_at had the exact same silent
        // no-op. Assigning through a real local variable first, then
        // writing it back, is what actually makes the mutation stick.
        $identities = $normalized['identities'] ?? [];
        foreach ($identities as &$identity) {
            unset($identity['last_used_at'], $identity['created_at'], $identity['updated_at']);
        }
        unset($identity);
        usort($identities, static fn (array $a, array $b): int => (($a['type'] ?? '') . ($a['name'] ?? '')) <=> (($b['type'] ?? '') . ($b['name'] ?? '')));
        $normalized['identities'] = $identities;

        if (is_array($normalized['profile'] ?? null)) {
            unset($normalized['profile']['created_at'], $normalized['profile']['updated_at']);
        }

        return md5((string) json_encode($normalized));
    }

    /**
     * The Settings package's own table has lived in the 'cluster'
     * connection group since 2026-08-19 (see app/Config/Settings.php's
     * 'group' and CI4install.php's patchSettingsClusterGroup() /
     * runSettingsMigrationAgainstClusterGroup() /cluster.md's own
     * writeup) - never in whatever connection a caller happens to pass
     * around for OTHER tables (users, generic app tables, both still on
     * 'default'). exportSetting()/exportAllSettingIds()/
     * applySettingSnapshot() below all deliberately ignore their own
     * $db parameter and connect here instead - found live 2026-08-19 as
     * "no such table: settings" on every node's cluster:sync-db cron the
     * very first minute after that migration landed, since every call
     * site here still passed db_connect('default').
     */
    private static function settingsDb(): ConnectionInterface
    {
        return db_connect('cluster');
    }

    /**
     * Per-node on/off switch for the 'settings' table's own sync
     * specifically - NOT $fileSyncEnabled/$sessionSyncEnabled's kind of
     * .env-only flag (see Config\Cluster's own docblocks on why those
     * stay .env-only: both are checked on every single web request,
     * where a per-request Settings-table DB read would be real overhead).
     * This one is only ever checked from cron-driven cluster:sync-db and
     * the (infrequent, never per-page-load) incoming-command handlers, so
     * a live Settings-panel checkbox is fine here - see
     * SettingsController's own "Settings sync" toggle.
     *
     * Stored via the SAME Settings-package mechanism (and the SAME
     * 'settings' table) the rest of this class already scans/syncs -
     * scoped by $context = this node's own name (Config\Cluster::
     * $thisNode), same convention Nodes.* / Databases.* already use, so
     * each node's own on/off choice is independent and never collides
     * with another node's. This one row DOES still sync normally like
     * any other 'settings' row (harmless, even informative - a peer
     * seeing "node X's own settingsSyncEnabled = false" under X's own
     * context costs nothing and isn't acted on by anyone but X) - only
     * the check below, always scoped to THIS node's own context, ever
     * gates behavior.
     *
     * Unset (a fresh install, or a node that's never touched the
     * checkbox) defaults to enabled - an opt-OUT, not opt-in, so normal
     * behavior needs no action.
     */
    public static function settingsSyncEnabled(): bool
    {
        $thisNode = (string) config('Cluster')->thisNode;
        $value    = service('settings')->get('Cluster.settingsSyncEnabled', $thisNode);

        return $value === null || $value === '' || $value === '1';
    }

    /**
     * Per-node on/off switch for Config\Cluster::$dbSyncGroup's whole
     * generic-table sync (genericTables()'s own discovery) - same
     * mechanism/storage as settingsSyncEnabled() above, just opposite
     * default: unset (a fresh install, or a node whose $dbSyncGroup points
     * at real application/business data it hasn't explicitly opted into
     * sharing yet) defaults to DISABLED, an opt-IN - unlike the 'settings'
     * table (this package's own admin bookkeeping, safe by construction),
     * $dbSyncGroup can point at an arbitrary pre-existing database a node
     * already had before this package was ever installed; syncing it
     * cluster-wide must be a deliberate choice, never a silent side effect
     * of just configuring the group name.
     */
    public static function productionSyncEnabled(): bool
    {
        $thisNode = (string) config('Cluster')->thisNode;
        $value    = service('settings')->get('Cluster.productionSyncEnabled', $thisNode);

        return $value === '1';
    }

    /**
     * @return array{class: string, key: string, value: string, type: string, context: string, created_at?: string, updated_at?: string}|null
     */
    public static function exportSetting(ConnectionInterface $db, string $class, string $key, string $context): ?array
    {
        // Global settings (no per-node group, e.g. Site.footer) store a
        // genuine SQL NULL in `context`, not an empty string - `WHERE
        // context = ''` never matches NULL, so every global setting was
        // silently invisible to sync (found live 2026-08-21: Site.footer
        // never propagated past its origin node). CI4's query builder
        // turns a null value into `IS NULL` automatically.
        $row = self::settingsDb()->table('settings')
            ->where('class', $class)->where('key', $key)->where('context', $context === '' ? null : $context)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        unset($row['id']);

        return $row;
    }

    /**
     * @return list<array{class: string, key: string, context: string}>
     */
    public static function exportAllSettingIds(ConnectionInterface $db): array
    {
        return self::settingsDb()->table('settings')->select('class, key, context')->get()->getResultArray();
    }

    /**
     * @param list<string> $queryLog
     */
    public static function applySettingSnapshot(ConnectionInterface $db, array $snapshot, array &$queryLog = []): void
    {
        $settingsDb = self::settingsDb();
        // Same NULL-vs-empty-string gap as exportSetting() above - without
        // this, applying an incoming global-setting snapshot never finds
        // its own existing row and inserts a duplicate on every apply
        // instead of updating in place.
        $context    = (string) ($snapshot['context'] ?? '');
        $existing   = $settingsDb->table('settings')
            ->where('class', $snapshot['class'] ?? '')
            ->where('key', $snapshot['key'] ?? '')
            ->where('context', $context === '' ? null : $context)
            ->get()->getRowArray();

        $data = $snapshot;
        if ($existing !== null) {
            unset($data['created_at']);
            $settingsDb->table('settings')->where('id', $existing['id'])->update($data);
        } else {
            $settingsDb->table('settings')->insert($data);
        }
        $queryLog[] = $settingsDb->showLastQuery();
    }

    /**
     * Strips `created_at`/`updated_at` before hashing - same reasoning as
     * hashUserSnapshot()'s own docblock (last_active/last_used_at), just
     * found later (live 2026-08-21, chasing a Nodes/Databases-driven
     * relay storm): these two rows can hold the IDENTICAL `value` on
     * every node yet have genuinely different created_at/updated_at
     * (e.g. each node's copy was populated by its own independent
     * Settings import rather than purely through cluster sync), so
     * leaving them in the hash meant no two nodes' copies of the SAME
     * unchanged setting could ever hash equal to each other - a
     * perpetual cross-node re-relay with no natural end, not a one-off.
     * LWW ordering is unaffected: the `timestamp` used for staleness
     * comparison is passed separately at every call site (sourced from
     * `updated_at`, same as before) - only the CHANGE-DETECTION hash
     * ignores these two columns. The actual DB columns are still synced
     * as part of the payload either way - no information lost.
     */
    public static function hashSettingSnapshot(array $snapshot): string
    {
        unset($snapshot['created_at'], $snapshot['updated_at']);

        return md5((string) json_encode($snapshot));
    }

    /**
     * Every table hardcoded elsewhere in this class (users' own five
     * tables, settings, and this package's own relocated queue tables) or
     * otherwise never a sensible wholesale-sync candidate (node-local
     * deployment bookkeeping) - excluded from $dbSyncGroup's discovery
     * below regardless of $dbExcludeTables, so a group pointed at the SAME
     * physical database users/settings/queue already live in (a valid,
     * if slightly redundant, config) can never double-handle any of them.
     * See this class's own top-of-file docblock for the fuller reasoning
     * on why each of these is out of scope for generic handling.
     */
    private const HARD_EXCLUDED_TABLES = [
        'migrations', 'sqlite_sequence',
        'users', 'auth_identities', 'auth_logins', 'auth_token_logins',
        'auth_remember_tokens', 'auth_groups_users', 'auth_permissions_users', 'user_profiles',
        'settings',
        'queue_jobs', 'queue_jobs_failed',
    ];

    /**
     * @var array<string, string>|null per-process cache - see the docblock
     *                                  below on why this is safe to keep
     *                                  for the life of one request/CLI run
     */
    private static ?array $genericTablesCache = null;

    private static ?string $genericTablesCacheGroup = null;

    /**
     * Discovers every table Config\Cluster::$dbSyncGroup's connection
     * group actually has, minus HARD_EXCLUDED_TABLES and
     * Config\Cluster::$dbExcludeTables, keeping only tables that pass BOTH
     * of $dbSyncGroup's own documented requirements (single-column,
     * non-integer-typed primary key; a real `updated_at` column) - see
     * that property's own docblock in Config\Cluster for the full
     * reasoning. A table failing either check is skipped and logged via
     * error_log(), not included with a wrong/guessed key and not a fatal
     * error for the whole sync run.
     *
     * Driver-agnostic by construction (listTables()/getFieldData()/
     * getIndexData() are all public CI4\BaseConnection methods, not
     * MySQL-specific raw SQL) - same "whatever driver a node's .env sets"
     * philosophy Config\Database's own $cluster group docblock already
     * commits to elsewhere in this project. One real MySQL-specific gap:
     * CI4's getFieldData() exposes MySQL's PRIMARY/not-PRIMARY flag but
     * not its own "is this AUTO_INCREMENT" Extra flag on any driver, so
     * this can only reject an integer-typed PK by column TYPE, not confirm
     * whether it truly auto-increments - a manually-assigned, never-
     * shared integer id would also get skipped here as a false negative.
     * That's the safe direction to be wrong in for a wholesale, no-per-
     * table-config feature: skipping a genuinely-fine table just means it
     * needs `id` renamed/retyped or its data reachable a different way,
     * not that a table with node-local ids about to collide across nodes
     * gets silently synced as if they were the same id.
     *
     * Cached per-process (a `static` property naturally resets every
     * fresh PHP-FPM/CLI process, so this never goes stale ACROSS
     * requests) because this is called several times per sync-db run
     * (SyncDbCommand's own three loops, plus DbSyncController's several
     * table-membership checks within the same request) and a schema
     * rarely changes mid-run - repeating six SHOW-COLUMNS/SHOW-INDEX-
     * style round trips per table on every single call would be pure
     * waste for data that cannot have changed since the first call this
     * same process already made.
     *
     * @return array<string, string> table => natural-key (primary key)
     *                                column
     */
    public static function genericTables(): array
    {
        $group = trim(config('Cluster')->dbSyncGroup);

        if ($group === '') {
            return [];
        }

        if (self::$genericTablesCache !== null && self::$genericTablesCacheGroup === $group) {
            return self::$genericTablesCache;
        }

        $exclude = array_merge(self::HARD_EXCLUDED_TABLES, config('Cluster')->dbExcludeTables);

        $tables = [];
        try {
            $db = db_connect($group);
            foreach ($db->listTables() as $tableName) {
                if (in_array($tableName, $exclude, true)) {
                    continue;
                }

                $keyColumn = self::discoverNaturalKey($db, $tableName);
                if ($keyColumn === null) {
                    continue; // reason already logged by discoverNaturalKey()
                }

                $tables[$tableName] = $keyColumn;
            }
        } catch (\Throwable $e) {
            error_log("DbSyncSchema::genericTables(): could not connect to/inspect group '$group' - $e");

            return [];
        }

        self::$genericTablesCache      = $tables;
        self::$genericTablesCacheGroup = $group;

        return $tables;
    }

    /**
     * @return string|null the table's primary-key column name, or null
     *                      (logged) if it doesn't meet $dbSyncGroup's
     *                      requirements - see genericTables()'s own
     *                      docblock
     */
    private static function discoverNaturalKey(ConnectionInterface $db, string $table): ?string
    {
        $info = self::inspectTableKey($db, $table);

        if ($info['keyColumn'] === null) {
            error_log("DbSyncSchema: skipping table '$table' - no single-column primary key (dbSyncGroup requires exactly one).");

            return null;
        }

        if ($info['keyIsInteger']) {
            error_log("DbSyncSchema: skipping table '$table' - primary key '{$info['keyColumn']}' is an integer type ({$info['keyType']}), almost certainly a node-local autoincrement id, not a portable natural key.");

            return null;
        }

        if (! $info['hasUpdatedAt']) {
            error_log("DbSyncSchema: skipping table '$table' - no `updated_at` column (dbSyncGroup requires one for Last-Write-Wins, same as every other synced table).");

            return null;
        }

        return $info['keyColumn'];
    }

    /**
     * One getIndexData()/getFieldData() scan per table, shared by
     * discoverNaturalKey() above (which only cares whether the result
     * passes ALL three checks) and Dashboard::productionInfo() below
     * (which wants each flag individually, for every table - not just the
     * ones that qualify - so an admin can see WHY a table isn't syncing,
     * not just that it isn't).
     *
     * @return array{keyColumn: string|null, keyType: string, keyIsInteger: bool, hasUpdatedAt: bool}
     */
    private static function inspectTableKey(ConnectionInterface $db, string $table): array
    {
        $indexes = $db->getIndexData($table);
        $primary = null;
        foreach ($indexes as $index) {
            if (($index->type ?? '') === 'PRIMARY') {
                $primary = $index;
                break;
            }
        }
        $keyColumn = ($primary !== null && count($primary->fields ?? []) === 1) ? $primary->fields[0] : null;

        $fields       = $db->getFieldData($table);
        $keyType      = '';
        $hasUpdatedAt = false;
        foreach ($fields as $field) {
            if ($keyColumn !== null && ($field->name ?? '') === $keyColumn) {
                $keyType = strtolower((string) ($field->type ?? ''));
            }
            if (($field->name ?? '') === 'updated_at') {
                $hasUpdatedAt = true;
            }
        }

        $keyIsInteger = false;
        foreach (['int', 'bigint', 'mediumint', 'smallint', 'tinyint'] as $intType) {
            if (str_contains($keyType, $intType)) {
                $keyIsInteger = true;
                break;
            }
        }

        return ['keyColumn' => $keyColumn, 'keyType' => $keyType, 'keyIsInteger' => $keyIsInteger, 'hasUpdatedAt' => $hasUpdatedAt];
    }

    /**
     * Read-only inventory of Config\Cluster::$dbSyncGroup's real database -
     * EVERY table (not just genericTables()' sync-eligible subset), each
     * with enough detail for an admin to see both what's syncing and why
     * anything isn't. Feeds the Dashboard's own "Production" card - see
     * Dashboard::productionInfo()'s own docblock for why it's gated on
     * productionSyncEnabled() rather than always shown.
     *
     * @return array{database: string, sizeBytes: int|null, tables: array<string, array{records: int, sizeBytes: int|null, hasAutoIncrementKey: bool, hasUpdatedAt: bool, syncEligible: bool}>}|null
     */
    public static function productionDatabaseInfo(): ?array
    {
        $group = trim(config('Cluster')->dbSyncGroup);
        if ($group === '') {
            return null;
        }

        $eligible  = self::genericTables();
        $tables    = [];
        $totalSize = 0;
        $sizeKnown = true;

        try {
            // db_connect() itself is lazy - MySQLi doesn't actually dial
            // out until the first real call, so bad credentials (found
            // live 2026-09-04 on upz: D10usr access denied) surface at
            // getDatabase()/listTables() below, not at db_connect() -
            // both need to be inside this same try, not just the query
            // loop's own per-table guards further down.
            $db       = db_connect($group);
            $database = $db->getDatabase();
            $tableList = $db->listTables();
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($tableList as $table) {
            try {
                $records = (int) $db->table($table)->countAllResults();
            } catch (\Throwable $e) {
                continue; // an unreadable/system table - skip rather than fail the whole inventory
            }

            try {
                $keyInfo = self::inspectTableKey($db, $table);
            } catch (\Throwable $e) {
                $keyInfo = ['keyColumn' => null, 'keyType' => '', 'keyIsInteger' => false, 'hasUpdatedAt' => false];
            }

            $sizeBytes = null;
            if ($db->DBDriver === 'MySQLi') {
                try {
                    $row = $db->query(
                        'SELECT (data_length + index_length) AS sz FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                        [$table]
                    )->getRowArray();
                    $sizeBytes = $row !== null ? (int) $row['sz'] : null;
                } catch (\Throwable $e) {
                    $sizeKnown = false;
                }
            } else {
                $sizeKnown = false; // best-effort - only MySQL's information_schema is wired up here
            }

            if ($sizeBytes !== null) {
                $totalSize += $sizeBytes;
            }

            $tables[$table] = [
                'records'             => $records,
                'sizeBytes'           => $sizeBytes,
                'hasAutoIncrementKey' => $keyInfo['keyColumn'] !== null && $keyInfo['keyIsInteger'],
                'hasUpdatedAt'        => $keyInfo['hasUpdatedAt'],
                'syncEligible'        => array_key_exists($table, $eligible),
            ];
        }

        return ['database' => $database, 'sizeBytes' => $sizeKnown ? $totalSize : null, 'tables' => $tables];
    }

    /**
     * The connection $dbSyncGroup's own discovered tables actually live
     * in - deliberately ignores whatever $db a caller passes to
     * exportAllGenericKeys()/exportGenericRow()/applyGenericSnapshot()
     * below and connects here instead, same reasoning and same pattern
     * settingsDb() above already established for the 'settings' table:
     * every caller up the stack (SyncDbCommand, DbSyncController,
     * PullSync) still passes db_connect('default') for historical/
     * users-table reasons, and making every one of those call sites aware
     * of a second, table-dependent connection would be a much larger and
     * more error-prone change than centralizing the redirect in the one
     * place that already knows which table needs which connection.
     */
    private static function genericDb(): ConnectionInterface
    {
        return db_connect(config('Cluster')->dbSyncGroup);
    }

    /**
     * @return list<string> every distinct natural-key value currently in
     *                       this table
     */
    public static function exportAllGenericKeys(ConnectionInterface $db, string $table, string $keyColumn): array
    {
        $rows = self::genericDb()->table($table)->select($keyColumn)->get()->getResultArray();

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row[$keyColumn],
            $rows
        )));
    }

    public static function exportGenericRow(ConnectionInterface $db, string $table, string $keyColumn, string $keyValue): ?array
    {
        $row = self::genericDb()->table($table)->where($keyColumn, $keyValue)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        // The physical id (if this table has a plain autoincrement PK
        // alongside its own real natural key) is node-local, same
        // reasoning as every other table here - never portable, and
        // applyGenericSnapshot() below always upserts by $keyColumn, never
        // by it.
        unset($row['id']);

        return $row;
    }

    /**
     * @param list<string> $queryLog
     */
    public static function applyGenericSnapshot(ConnectionInterface $db, string $table, string $keyColumn, array $snapshot, array &$queryLog = []): void
    {
        $db = self::genericDb();

        $keyValue = $snapshot[$keyColumn] ?? null;
        if ($keyValue === null) {
            return;
        }

        $existing = $db->table($table)->where($keyColumn, $keyValue)->get()->getRowArray();

        $data = $snapshot;
        if ($existing !== null) {
            // created_at, like every other table here, is this node's own
            // record of when it first learned about the row - never
            // overwritten from an incoming snapshot (same rule
            // applyUserSnapshot()/applySettingSnapshot() already follow).
            unset($data['created_at']);
            $db->table($table)->where($keyColumn, $keyValue)->update($data);
        } else {
            $db->table($table)->insert($data);
        }
        $queryLog[] = $db->showLastQuery();
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * Number of buckets a table's natural keys are split into for bulk
     * catch-up (see computeBlockHashes()/entitiesInBlock()) - a brand-new
     * node's first sync, or periodic self-healing, compares one hash per
     * block against a peer instead of transferring every row, and only
     * fetches the blocks that actually differ (rsync/Merkle-tree style).
     * Fixed and small on purpose: this project's actual row counts are in
     * the tens, not millions - the win is avoiding a full transfer on a
     * mostly-already-synced catch-up, not sub-linear scan cost.
     */
    public const BLOCK_COUNT = 16;

    public static function blockIndexForKey(string $naturalKey): int
    {
        return crc32($naturalKey) % self::BLOCK_COUNT;
    }

    /**
     * @return array<int, string> blockIndex => hash, one entry per block, always BLOCK_COUNT entries
     */
    public static function computeBlockHashes(ConnectionInterface $db, string $table): array
    {
        $perBlock = self::bucketEntityHashes($db, $table);

        $hashes = [];
        for ($block = 0; $block < self::BLOCK_COUNT; $block++) {
            $pairs = $perBlock[$block] ?? [];
            ksort($pairs);
            $parts = [];
            foreach ($pairs as $naturalKey => $hash) {
                $parts[] = "$naturalKey:$hash";
            }
            $hashes[$block] = md5(implode('|', $parts));
        }

        return $hashes;
    }

    /**
     * Shared by DbSyncController::pullRows() and LongPollController::poll()
     * - every manifest entry recorded (locally or via relay - see
     * DbManifest::record()'s own docblock) strictly after $since, with its
     * current snapshot attached, in the exact wire shape
     * pullDbRows()/applyIncomingCommand() already expect. Filters by
     * `recordedAt` (this node's own "when did I learn this"), not
     * `timestamp` (the row's original updated_at) - same reasoning as
     * Cluster::manifestSince()'s own docblock.
     *
     * @return list<array{table: string, naturalKey: string, timestamp: int, payload: array}>
     */
    public static function collectRowsSince(ConnectionInterface $db, DbManifest $manifest, int $since): array
    {
        $rows = [];
        foreach ($manifest->all() as $manifestKey => $entry) {
            if ((int) ($entry['recordedAt'] ?? $entry['timestamp'] ?? 0) < $since) {
                continue;
            }
            [$table, $naturalKey] = array_pad(explode(':', $manifestKey, 2), 2, '');
            if ($table === '' || $naturalKey === '') {
                continue;
            }

            $exported = self::exportEntity($db, $table, $naturalKey);
            if ($exported === null) {
                continue;
            }

            $rows[] = [
                'table'      => $table,
                'naturalKey' => $naturalKey,
                'timestamp'  => (int) $entry['timestamp'],
                'payload'    => $exported['payload'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{payload: array, timestamp: int}|null the entity's current snapshot plus its own row's updated_at (falls back to now if absent), for block-catch-up transfer
     */
    public static function exportEntity(ConnectionInterface $db, string $table, string $naturalKey): ?array
    {
        if ($table === 'users') {
            $snapshot = self::exportUser($db, $naturalKey);
        } elseif ($table === 'settings') {
            [$class, $key, $context] = array_pad(explode(':', $naturalKey, 3), 3, '');
            $snapshot = self::exportSetting($db, $class, $key, $context);
        } elseif (array_key_exists($table, self::genericTables())) {
            $snapshot = self::exportGenericRow($db, $table, self::genericTables()[$table], $naturalKey);
        } else {
            return null;
        }

        if ($snapshot === null) {
            return null;
        }

        $updatedAt = $snapshot['users']['updated_at'] ?? $snapshot['updated_at'] ?? null;
        $timestamp = $updatedAt !== null ? strtotime((string) $updatedAt) : false;

        return ['payload' => $snapshot, 'timestamp' => $timestamp !== false ? $timestamp : time()];
    }

    /**
     * @return list<string> natural keys currently in the given block
     */
    public static function naturalKeysInBlock(ConnectionInterface $db, string $table, int $block): array
    {
        $perBlock = self::bucketEntityHashes($db, $table);

        return array_keys($perBlock[$block] ?? []);
    }

    /**
     * @return array<int, array<string, string>> blockIndex => [naturalKey => entityHash]
     */
    private static function bucketEntityHashes(ConnectionInterface $db, string $table): array
    {
        $perBlock = [];

        if ($table === 'users') {
            foreach (self::exportAllUserEmails($db) as $email) {
                $snapshot = self::exportUser($db, $email);
                if ($snapshot === null) {
                    continue;
                }
                $perBlock[self::blockIndexForKey($email)][$email] = self::hashUserSnapshot($snapshot);
            }
        } elseif ($table === 'settings') {
            foreach (self::exportAllSettingIds($db) as $id) {
                $key      = $id['class'] . ':' . $id['key'] . ':' . $id['context'];
                $snapshot = self::exportSetting($db, (string) $id['class'], (string) $id['key'], (string) $id['context']);
                if ($snapshot === null) {
                    continue;
                }
                $perBlock[self::blockIndexForKey($key)][$key] = self::hashSettingSnapshot($snapshot);
            }
        } elseif (array_key_exists($table, self::genericTables())) {
            $keyColumn = self::genericTables()[$table];
            foreach (self::exportAllGenericKeys($db, $table, $keyColumn) as $keyValue) {
                $snapshot = self::exportGenericRow($db, $table, $keyColumn, $keyValue);
                if ($snapshot === null) {
                    continue;
                }
                $perBlock[self::blockIndexForKey($keyValue)][$keyValue] = self::hashSettingSnapshot($snapshot);
            }
        }

        return $perBlock;
    }

    /**
     * Applies one incoming write command (from either the push receiver
     * or a pull) - shared by both, so there is exactly one place row-level
     * LWW is decided. Positive timestamps only; ties go to incoming (same
     * convention as file conflict resolution's own tie-break).
     *
     * @param array{table: string, naturalKey: string, operation: string, payload: array, timestamp: int} $command
     *
     * @return array{applied: bool, reason?: string}
     */
    /**
     * @param string $direction 'push-in' (Controllers\DbSyncController::receive()),
     *                          'pull' (Commands\PullCommand::pullDbRows()/
     *                          Commands\SyncDbCommand's --bootstrap) - just a
     *                          label for the Dashboard's activity log, no
     *                          behavioral difference between them here
     */
    public static function applyIncomingCommand(ConnectionInterface $db, DbManifest $manifest, array $command, string $direction = 'push-in', string $peer = ''): array
    {
        $table      = (string) ($command['table'] ?? '');
        $naturalKey = (string) ($command['naturalKey'] ?? '');
        $timestamp  = (int) ($command['timestamp'] ?? 0);
        $payload    = (array) ($command['payload'] ?? []);
        $operation  = (string) ($command['operation'] ?? 'upsert');

        if ($table === '' || $naturalKey === '') {
            return ['applied' => false, 'reason' => 'missing table/naturalKey'];
        }

        // Single choke point for BOTH incoming directions (push-in via
        // Controllers\DbSyncController::receive(), pull-in via
        // PullSync::applyDbRows()/SyncDbCommand::bootstrap()) - see
        // settingsSyncEnabled()'s own docblock. Checked here, not per
        // caller, so there's exactly one place this can ever be wrong.
        if ($table === 'settings' && ! self::settingsSyncEnabled()) {
            return ['applied' => false, 'reason' => 'settings sync disabled locally'];
        }
        // Same choke point, for $dbSyncGroup's generic tables - see
        // productionSyncEnabled()'s own docblock. Checked against the real
        // discovered table list (not just "isn't users/settings") so an
        // actually-unknown table still falls through to the normal
        // 'unknown table' rejection below instead of a misleading reason.
        if (array_key_exists($table, self::genericTables()) && ! self::productionSyncEnabled()) {
            return ['applied' => false, 'reason' => 'production sync disabled locally'];
        }

        $manifestKey = "$table:$naturalKey";
        $known       = $manifest->get($manifestKey);

        if ($known !== null && $timestamp < (int) $known['timestamp']) {
            return ['applied' => false, 'reason' => 'stale'];
        }

        $generic = self::genericTables();
        if ($table === 'users') {
            $hash = self::hashUserSnapshot($payload);
        } elseif ($table === 'settings' || array_key_exists($table, $generic)) {
            $hash = self::hashSettingSnapshot($payload);
        } else {
            return ['applied' => false, 'reason' => 'unknown table'];
        }

        // Content-identical re-relay - the same origin write bouncing back
        // through the mesh (peer P re-broadcasts everything it can see
        // changed within its own rolling pullLookbackSeconds window, not
        // just what genuinely changed FOR IT) - found live 2026-08-21: a
        // NAT node was seeing the SAME ~187 rows on every single
        // long-poll round, forever. Applying (a harmless no-op write) is
        // cheap; the real damage was record() BELOW unconditionally
        // stamping recordedAt=now even when nothing changed, which kept
        // re-extending this exact row's own relay window on THIS node
        // and every node downstream of it - a self-sustaining loop with
        // no natural end. Skipping both the write and the recordedAt
        // refresh here, whenever the incoming content hashes identical to
        // what's already known, breaks that loop while still applying
        // (and refreshing the window for) anything genuinely new.
        if ($known !== null && $known['hash'] === $hash) {
            return ['applied' => false, 'reason' => 'unchanged'];
        }

        $queryLog = [];

        try {
            if ($table === 'users') {
                self::applyUserSnapshot($db, $payload, $queryLog);
            } elseif ($table === 'settings') {
                self::applySettingSnapshot($db, $payload, $queryLog);
            } else {
                self::applyGenericSnapshot($db, $table, $generic[$table], $payload, $queryLog);
            }
        } catch (\Throwable $e) {
            (new DbSyncLog())->record([
                'time'       => time(),
                'direction'  => $direction,
                'peer'       => $peer,
                'table'      => $table,
                'naturalKey' => $naturalKey,
                'operation'  => $operation,
                'ok'         => false,
                'error'      => $e->getMessage(),
                'queries'    => $queryLog,
            ]);

            throw $e; // unchanged behavior - the caller's own retry logic (queue, or a 500 to the pusher) still applies
        }

        $manifest->record($manifestKey, ['hash' => $hash, 'timestamp' => $timestamp]);

        (new DbSyncLog())->record([
            'time'       => time(),
            'direction'  => $direction,
            'peer'       => $peer,
            'table'      => $table,
            'naturalKey' => $naturalKey,
            'operation'  => $operation,
            'ok'         => true,
            'error'      => null,
            'queries'    => $queryLog,
        ]);

        return ['applied' => true];
    }
}
