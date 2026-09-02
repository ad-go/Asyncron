<?php

declare(strict_types=1);

namespace AdGo\Cluster;

/**
 * Writes cluster.* keys into .env from the admin-UI side (SettingsController's
 * node CRUD, cluster handshake, import/reset flows) - the counterpart to
 * Cluster::writeNodeRegistryEnv(), which does the same job for the
 * PEER-TO-PEER incoming-sync side (applyIncomingNodeUpsert()/
 * applyIncomingNodeDelete()). Kept as two call paths, not one shared
 * caller, because the admin side legitimately needs to write several
 * different cluster.* keys in one pass (thisNode/secretToken/
 * signingPrivateKey together, say) where the peer-sync side only ever
 * touches cluster.nodes alone.
 *
 * Extracted out of SettingsController (2026-08-31) - part of breaking up
 * that 2000+ line controller one cohesive, independently-testable piece
 * at a time (see SettingsExportCrypto's own docblock for the first such
 * extraction).
 */
final class ClusterEnvWriter
{
    /**
     * Writes/replaces one or more plain "key = value" lines in .env in a
     * SINGLE read-modify-write pass - able to touch several keys at once
     * (one file round trip instead of one per key). $value is used AS-IS
     * - already fully formatted/quoted by the caller if it needs to be;
     * this has no opinion on quoting, only on finding-or-appending the
     * right line per key.
     *
     * flock()'d - found live 2026-08-22: two concurrent requests writing
     * cluster.nodes (a browser-triggered handshake and an incoming peer's
     * own clusterHandshake() call, say - both real, both legitimate,
     * nothing wrong with either on its own) raced on a bare
     * file_get_contents()/file_put_contents() pair with no lock between
     * them - whichever finished LAST silently won, discarding the other's
     * write in full (not just its own key - the WHOLE file content, since
     * both read stale copies of each other). Every OTHER state file in
     * this project (src/*.php - Stats, SyncState, the manifest, ...)
     * already uses fopen()+flock(LOCK_EX) for exactly this reason.
     */
    public static function writeLines(array $lines): bool
    {
        $envPath = ROOTPATH . '.env';
        $handle  = @fopen($envPath, 'r+');
        if ($handle === false) {
            return false;
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return false;
        }

        $env = stream_get_contents($handle);
        if ($env === false) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }

        foreach ($lines as $key => $value) {
            $line    = $key . ' = ' . $value;
            $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=.*$/m';
            $env     = preg_match($pattern, $env) === 1
                ? preg_replace($pattern, $line, $env, 1)
                : rtrim($env) . "\n" . $line . "\n";
        }

        rewind($handle);
        ftruncate($handle, 0);
        $ok = fwrite($handle, $env) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $ok;
    }

    /**
     * Builds the single cluster.nodes line (see writeLines() above for the
     * actual file write) - format matches Config\Cluster::nodesFromEnv()'s
     * own parser exactly: name|baseURL|type[|publicKey], comma-separated.
     * Preserves each entry's 'publicKey' when present - a caller carrying
     * the FULL existing registry through here alongside one changed entry
     * must never drop an already-configured peer's signing key, which
     * would silently downgrade it back to the legacy shared-secret scheme
     * (see Config\Cluster::$signingPrivateKey's own docblock on why
     * that's a real regression, not a cosmetic one).
     */
    public static function writeNodes(array $entries): bool
    {
        $line = '"' . implode(',', array_map(
            static function (string $name, array $entry): string {
                $publicKey = (string) ($entry['publicKey'] ?? '');

                return $name . '|' . $entry['baseURL'] . '|' . $entry['type'] . ($publicKey !== '' ? '|' . $publicKey : '');
            },
            array_keys($entries),
            $entries
        )) . '"';

        if (! self::writeLines(['cluster.nodes' => $line])) {
            return false;
        }

        // config('Cluster') is a shared singleton (CI4 Factories cache) -
        // already loaded from .env once, earlier in THIS same request,
        // before this write happened. Without refreshing it here, a
        // caller later in the SAME request (configureClusterIdentity()'s
        // own allNodes() read, right after an import writes brand-new
        // entries) would still see the pre-write registry, not what .env
        // now actually holds - a fresh process picks up the real file
        // fine, but nothing forces one before this request finishes.
        if (class_exists(Cluster::class)) {
            config('Cluster')->nodes = array_map(
                static fn (array $entry): array => [
                    'baseURL'   => rtrim((string) $entry['baseURL'], '/') . '/',
                    'type'      => (string) $entry['type'],
                    'publicKey' => (string) ($entry['publicKey'] ?? ''),
                ],
                $entries
            );
        }

        return true;
    }
}
