<?php

declare(strict_types=1);

namespace Tests\Controllers;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\DeletedFiles;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ClusterConfigTestTrait;

/**
 * HTTP coverage of FileReceiverController.
 *
 * receive() (the push-upload endpoint) is only covered for the parts that
 * don't require an actual multipart file arriving: PHP's own
 * is_uploaded_file()/move_uploaded_file() (which UploadedFile::isValid()/
 * move() both call - see Cluster::detectConflict()'s own docblock for the
 * conflict LOGIC, which IS covered directly against Cluster in
 * tests/Cluster/ManifestConcurrencyTest.php's sibling files) only ever
 * return true for a file that arrived through a genuine SAPI upload
 * mechanism, which a PHPUnit-constructed request - built entirely
 * in-process, no real multipart POST - structurally cannot produce.
 * Faking $_FILES via the Superglobals test double gets an UploadedFile
 * instance to exist, but isValid() still returns false for it, so
 * receive()'s success path (the move() call and everything after it) is
 * not exercisable this way without either a real HTTP client hitting a
 * running server, or modifying UploadedFile's own SAPI checks - neither
 * of which belongs in this test suite. deleteFile() has no such gap (no
 * upload involved) and IS covered end-to-end, including its real
 * "file actually removed from disk" success path.
 */
final class FileReceiverControllerHttpTest extends CIUnitTestCase
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

    // ---- receive() ----

    public function testReceiveWithFileSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'fileSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())->post('cluster/files', ['path' => 'share/x.txt']);

        $result->assertStatus(503);
        $result->assertJSONFragment(['error' => 'file sync disabled on this node']);
    }

    public function testReceiveWithNoFileUploadedReturns400(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET]);

        $result = $this->withHeaders($this->authHeader())->post('cluster/files', ['path' => 'share/x.txt']);

        $result->assertStatus(400);
        $result->assertJSONFragment(['error' => 'missing or invalid file upload']);
    }

    public function testReceiveWithoutValidAuthIsForbiddenBeforeReachingTheController(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET]);

        $result = $this->post('cluster/files', ['path' => 'share/x.txt']);

        $result->assertStatus(403);
    }

    // ---- deleteFile() ----

    public function testDeleteFileWithFileSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET, 'fileSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader())
            ->post('cluster/delete-file', ['path' => 'share/x.txt', 'deletedAt' => time()]);

        $result->assertStatus(503);
    }

    public function testDeleteFileRejectsPathTraversal(): void
    {
        $this->withClusterConfig(['secretToken' => self::SECRET]);

        $result = $this->withHeaders($this->authHeader())
            ->post('cluster/delete-file', ['path' => '../../etc/passwd', 'deletedAt' => time()]);

        $result->assertStatus(400);
        $result->assertJSONFragment(['error' => 'path not allowed']);
    }

    public function testDeleteFileRemovesAnExistingFileAndRecordsATombstone(): void
    {
        $config  = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cluster = new Cluster($config);

        $dir = $cluster->syncDirs()[0];
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $absolutePath = $dir . '/gone.txt';
        file_put_contents($absolutePath, 'bye');
        // Known to the manifest with an OLDER mtime than the delete event
        // below, matching applyIncomingDeletion()'s own "known mtime >
        // deletedAt means the delete is stale, don't apply it" rule.
        $cluster->recordKnown('gone.txt', md5_file($absolutePath), time() - 100, 3);

        $deletedAt = time();
        $result = $this->withHeaders($this->authHeader())
            ->post('cluster/delete-file', ['path' => 'gone.txt', 'deletedAt' => $deletedAt]);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertTrue($body['applied']);
        $this->assertSame('deleted', $body['reason']);

        $this->assertFileDoesNotExist($absolutePath);
        $tombstones = (new DeletedFiles($config))->allSince($deletedAt - 10);
        $this->assertSame($deletedAt, $tombstones['gone.txt']);
    }

    public function testDeleteFileDoesNotApplyAStaleDeletionOlderThanTheKnownLocalChange(): void
    {
        $config  = $this->withClusterConfig(['secretToken' => self::SECRET]);
        $cluster = new Cluster($config);

        $dir = $cluster->syncDirs()[0];
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $absolutePath = $dir . '/still-here.txt';
        file_put_contents($absolutePath, 'still here');
        // Local's own manifest entry is NEWER than the incoming deletedAt
        // below - the file was genuinely changed locally AFTER the peer's
        // delete event, so the delete must be rejected as stale.
        $cluster->recordKnown('still-here.txt', md5_file($absolutePath), time() + 100, 10);

        $result = $this->withHeaders($this->authHeader())
            ->post('cluster/delete-file', ['path' => 'still-here.txt', 'deletedAt' => time()]);

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertFalse($body['applied']);
        $this->assertSame('local is newer', $body['reason']);
        $this->assertFileExists($absolutePath);
    }
}
