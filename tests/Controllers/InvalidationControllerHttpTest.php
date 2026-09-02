<?php

declare(strict_types=1);

namespace Tests\Controllers;

use AdGo\Cluster\SessionInvalidation;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ClusterConfigTestTrait;

/**
 * HTTP coverage of InvalidationController::receive() - the peer-to-peer
 * receiver for a password-change/logout broadcast (see
 * Cluster::broadcastInvalidation()). Chosen as a full round-trip HTTP
 * target (unlike FileReceiverController) because it has no multipart file
 * upload involved: PHP's own is_uploaded_file()/move_uploaded_file() only
 * ever return true for a file that arrived through a genuine SAPI file
 * upload, which a PHPUnit-constructed request can't produce, so a fully
 * successful cluster/files POST can't be exercised end-to-end here (see
 * this test suite's own README note in FileReceiverControllerHttpTest).
 * This controller has no such gap, so its success path is covered for
 * real: HTTP in, JSON out, AND the actual state file on disk verified
 * afterward - not just the response shape.
 */
final class InvalidationControllerHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use ClusterConfigTestTrait;

    protected function tearDown(): void
    {
        $this->cleanUpClusterTestDirs();
        parent::tearDown();
    }

    private function authHeader(string $secret): array
    {
        return ['Authorization' => 'Bearer ' . $secret];
    }

    public function testReceiveWithSessionSyncDisabledReturns503(): void
    {
        $this->withClusterConfig(['secretToken' => 'shared-secret', 'sessionSyncEnabled' => false]);

        $result = $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['email' => 'a@b.com', 'changedAt' => time()]);

        $result->assertStatus(503);
        $result->assertJSONFragment(['error' => 'session sync disabled on this node']);
    }

    public function testReceiveWithMissingEmailReturns400(): void
    {
        $this->withClusterConfig(['secretToken' => 'shared-secret']);

        $result = $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['changedAt' => time()]);

        $result->assertStatus(400);
    }

    public function testReceiveWithMissingChangedAtReturns400(): void
    {
        $this->withClusterConfig(['secretToken' => 'shared-secret']);

        $result = $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['email' => 'a@b.com']);

        $result->assertStatus(400);
    }

    public function testReceiveWithoutValidAuthIsForbiddenBeforeReachingTheController(): void
    {
        $this->withClusterConfig(['secretToken' => 'shared-secret']);

        $result = $this->post('cluster/invalidate', ['email' => 'a@b.com', 'changedAt' => time()]);

        $result->assertStatus(403);
    }

    public function testReceiveRecordsAnInvalidationThatAllSinceThenReturns(): void
    {
        $config = $this->withClusterConfig(['secretToken' => 'shared-secret']);
        $before = time() - 10;
        $changedAt = time();

        $result = $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['email' => 'admin@local.host', 'changedAt' => $changedAt]);

        $result->assertStatus(200);
        $result->assertJSONFragment(['ok' => true]);

        // Verifies the REAL side effect on disk, not just the response
        // shape - the same file SessionInvalidationFilter reads on every
        // subsequent request to decide whether to kill a session.
        $entries = (new SessionInvalidation($config))->allSince($before);
        $this->assertArrayHasKey('admin@local.host', $entries);
        $this->assertSame($changedAt, $entries['admin@local.host']);
    }

    /**
     * recordInvalidation() only ever moves a user's timestamp FORWARD (see
     * that method's own docblock) - an out-of-order delivery must never
     * un-invalidate a session a more recent change already caught. Proven
     * here over real HTTP: an earlier changedAt arriving AFTER a later one
     * must not overwrite it.
     */
    public function testReceiveWithAnOlderChangedAtNeverMovesTheTimestampBackward(): void
    {
        $config = $this->withClusterConfig(['secretToken' => 'shared-secret']);
        $before = time() - 10;

        $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['email' => 'admin@local.host', 'changedAt' => time()]);

        $olderResult = $this->withHeaders($this->authHeader('shared-secret'))
            ->post('cluster/invalidate', ['email' => 'admin@local.host', 'changedAt' => time() - 100]);

        $olderResult->assertStatus(200);

        $entries = (new SessionInvalidation($config))->allSince($before);
        // Still the LATER value - the older delivery was accepted (200,
        // same as any other) but did not move changedAt backward.
        $this->assertGreaterThan(time() - 100, $entries['admin@local.host']);
    }
}
