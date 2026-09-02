<?php

declare(strict_types=1);

namespace Tests\Controllers;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\DeletedFiles;
use AdGo\Cluster\SessionInvalidation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ClusterConfigTestTrait;

/**
 * HTTP coverage of PullController - the server side of cluster:pull (see
 * that command's own docblock: a NAT peer connects OUT to these GET
 * endpoints since nothing can push INTO it directly). All four endpoints
 * are exercised as real HTTP requests through the actual route table and
 * ClusterAuthFilter, not by calling the controller's methods directly -
 * file() in particular is only meaningful tested this way, since its own
 * behavior (streaming bytes back via CI4's download() response, with
 * custom headers) is exactly the HTTP-layer contract PullCommand's own
 * downloadFile() depends on.
 */
final class PullControllerHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use ClusterConfigTestTrait;

    private const SECRET = 'shared-secret';

    protected function tearDown(): void
    {
        $this->cleanUpClusterTestDirs();
        parent::tearDown();
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer ' . self::SECRET];
    }

    // ---- invalidations ----

    public function testInvalidationsWithSessionSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'sessionSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-invalidations');

        $result->assertStatus(503);
    }

    public function testInvalidationsReturnsEntriesRecordedAfterSince(): void
    {
        $config = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cutoff = time() - 1;
        (new SessionInvalidation($config))->recordInvalidation('admin@local.host', time());

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-invalidations?since=' . $cutoff);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('admin@local.host', $body['invalidations']);
    }

    public function testInvalidationsExcludesEntriesRecordedBeforeSince(): void
    {
        $config = $this->withClusterConfig(['secretToken' => self::SECRET]);
        (new SessionInvalidation($config))->recordInvalidation('admin@local.host', time());
        $cutoffAfterwards = time() + 60;

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-invalidations?since=' . $cutoffAfterwards);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertArrayNotHasKey('admin@local.host', $body['invalidations']);
    }

    // ---- files (manifest) ----

    public function testFilesWithFileSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'fileSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-files');

        $result->assertStatus(503);
    }

    public function testFilesReturnsManifestEntriesRecordedAfterSince(): void
    {
        $config = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cutoff = time() - 1;
        (new Cluster($config))->recordKnown('share/report.csv', 'abc123', time(), 42);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-files?since=' . $cutoff);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('share/report.csv', $body['files']);
        $this->assertSame('abc123', $body['files']['share/report.csv']['hash']);
    }

    // ---- deletedFiles ----

    public function testDeletedFilesWithFileSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'fileSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-deleted-files');

        $result->assertStatus(503);
    }

    public function testDeletedFilesReturnsTombstonesRecordedAfterSince(): void
    {
        $config = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cutoff = time() - 1;
        $deletedAt = time();
        (new DeletedFiles($config))->record('share/old.txt', $deletedAt);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-deleted-files?since=' . $cutoff);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertSame($deletedAt, $body['deletions']['share/old.txt']);
    }

    // ---- file (download) ----

    public function testFileWithFileSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'fileSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-file?path=share/report.csv');

        $result->assertStatus(503);
    }

    public function testFileRejectsPathTraversalWithNotFoundRatherThanServingAnything(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET]);

        $result = $this->withHeaders($this->authHeader())
            ->get('cluster/pull-file?' . http_build_query(['path' => '../../../../etc/passwd']));

        $result->assertStatus(404);
    }

    public function testFileReturns404ForAPathThatIsNotOnDiskYet(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET]);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-file?path=share/does-not-exist.txt');

        $result->assertStatus(404);
    }

    public function testFileServesAnExistingFileWithMtimeAndHashHeaders(): void
    {
        $config = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cluster = new Cluster($config);

        $dir = $cluster->syncDirs()[0];
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $absolutePath = $dir . '/report.csv';
        file_put_contents($absolutePath, 'a,b,c');
        $expectedMtime = filemtime($absolutePath);
        $expectedHash  = md5_file($absolutePath);

        $result = $this->withHeaders($this->authHeader())->get('cluster/pull-file?path=report.csv');

        $result->assertStatus(200);
        $result->assertHeader('X-Cluster-Mtime', (string) $expectedMtime);
        $result->assertHeader('X-Cluster-Hash', $expectedHash);
    }
}
