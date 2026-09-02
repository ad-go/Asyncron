<?php

declare(strict_types=1);

namespace Tests\Support;

use AdGo\Cluster\Config\Cluster as ClusterConfig;
use CodeIgniter\Config\Factories;

/**
 * Shared plumbing for the Controllers/UI HTTP test classes under
 * tests/Controllers and tests/UI: an isolated AdGo\Cluster\Config\Cluster
 * instance injected as the shared 'Cluster' config (so every `new
 * Cluster()`/`new SessionInvalidation()`/`new DeletedFiles()` a controller
 * constructs internally - with no way for a test to pass one in - picks up
 * the exact same config object a test configured), plus the RSA
 * signed-header helper SignedAuthRetirementTest already uses for the same
 * purpose at the unit level.
 *
 * Each stateDir/syncPaths value is namespaced per test (uniqid()) and
 * cleaned up in tearDown() - same isolation approach ManifestConcurrencyTest/
 * DbManifestTest/DeletedFilesTest already use, just shared here instead of
 * copy-pasted per HTTP test class too.
 */
trait ClusterConfigTestTrait
{
    /** @var list<string> absolute directories to remove in tearDown() */
    private array $clusterTestDirs = [];

    protected function withClusterConfig(array $overrides = []): ClusterConfig
    {
        $config           = new ClusterConfig();
        $config->stateDir = 'Cluster-http-test-' . bin2hex(random_bytes(6));

        foreach ($overrides as $key => $value) {
            $config->{$key} = $value;
        }

        Factories::injectMock('config', 'Cluster', $config);

        $this->clusterTestDirs[] = rtrim(WRITEPATH, '/\\') . '/' . trim($config->stateDir, '/\\');
        foreach ($config->syncPaths as $syncPath) {
            $this->clusterTestDirs[] = rtrim(WRITEPATH, '/\\') . '/' . trim($syncPath, '/\\');
        }

        return $config;
    }

    protected function cleanUpClusterTestDirs(): void
    {
        foreach ($this->clusterTestDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        $this->clusterTestDirs = [];

        Factories::reset('config');
    }

    private function removeDirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Builds a valid signed Authorization header the same way
     * Cluster::authHeader() itself does - see SignedAuthRetirementTest's
     * own identical helper for why (mirrors production signing exactly,
     * not a simplified stand-in for it).
     */
    private function signedHeader(string $nodeName, string $privatePem, ?int $timestamp = null): string
    {
        $message = $nodeName . '.' . ($timestamp ?? time());
        openssl_sign($message, $signature, $privatePem, OPENSSL_ALGO_SHA256);

        return 'Bearer ' . $message . '.' . base64_encode($signature);
    }

    /**
     * @return array{0: string, 1: string} [privatePem, publicKeyPem]
     */
    private function generateSigningKeypair(): ?array
    {
        $resource = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            return null;
        }
        openssl_pkey_export($resource, $privatePem);
        $publicKeyPem = openssl_pkey_get_details($resource)['key'];

        return [$privatePem, $publicKeyPem];
    }
}
