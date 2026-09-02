<?php

declare(strict_types=1);

namespace Tests\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ClusterConfigTestTrait;

/**
 * End-to-end HTTP coverage of ClusterAuthFilter as it actually runs in
 * front of cluster/ping and cluster/time - real routing (app/Config/
 * Routes.php -> AdGo\Cluster\RouteRegistrar::register()), real filter
 * alias resolution (app/Config/Filters.php's 'cluster-auth' =>
 * ClusterAuthFilter), real controller. Complements
 * tests/Cluster/SignedAuthRetirementTest.php (which calls
 * Cluster::verifyAuthHeader() directly, in isolation) by proving the SAME
 * behavior holds once wired through CI4's actual router/filter pipeline,
 * where the alias/route/response-shape wiring itself could silently
 * diverge from what verifyAuthHeader() alone guarantees - a route
 * registered without the filter, or a typo'd alias, would pass every unit
 * test on Cluster itself and still leave the endpoint wide open.
 */
final class PingTimeAuthHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use ClusterConfigTestTrait;

    protected function tearDown(): void
    {
        $this->cleanUpClusterTestDirs();
        parent::tearDown();
    }

    public function testPingWithNoAuthorizationHeaderIsForbidden(): void
    {
        $this->withClusterConfig(['secretToken' => 'real-secret']);

        $result = $this->get('cluster/ping');

        $result->assertStatus(403);
        $result->assertJSONFragment(['error' => 'Forbidden']);
    }

    public function testPingWithWrongSharedSecretIsForbidden(): void
    {
        $this->withClusterConfig(['secretToken' => 'real-secret']);

        $result = $this->withHeaders(['Authorization' => 'Bearer wrong-secret'])->get('cluster/ping');

        $result->assertStatus(403);
    }

    public function testPingWithCorrectSharedSecretSucceeds(): void
    {
        $this->withClusterConfig(['secretToken' => 'real-secret']);

        $result = $this->withHeaders(['Authorization' => 'Bearer real-secret'])->get('cluster/ping');

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('at', $body);
    }

    public function testTimeWithCorrectSharedSecretSucceeds(): void
    {
        $this->withClusterConfig(['secretToken' => 'real-secret']);

        $result = $this->withHeaders(['Authorization' => 'Bearer real-secret'])->get('cluster/time');

        $result->assertStatus(200);
        $body = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('time', $body);
        $this->assertIsFloat($body['time']);
    }

    /**
     * Config\Cluster::$secretToken defaults to '' - Cluster::
     * verifyAuthHeader()'s own guard ($expected !== '') means a node that
     * has never configured a secret must reject EVERY bare-secret
     * credential outright, never accidentally accept an equally-empty one
     * sent as 'Bearer '.
     */
    public function testPingWithEmptySecretTokenIsAlwaysForbidden(): void
    {
        $this->withClusterConfig(['secretToken' => '']);

        $result = $this->withHeaders(['Authorization' => 'Bearer '])->get('cluster/ping');

        $result->assertStatus(403);
    }

    public function testPingWithValidSignedHeaderSucceeds(): void
    {
        $keypair = $this->generateSigningKeypair();
        if ($keypair === null) {
            $this->markTestSkipped('openssl_pkey_new() has no usable openssl.cnf in this environment.');
        }
        [$privatePem, $publicKeyPem] = $keypair;

        $this->withClusterConfig([
            'nodes' => ['peerA' => ['baseURL' => 'http://a/', 'type' => 'public', 'publicKey' => base64_encode($publicKeyPem)]],
        ]);

        $result = $this->withHeaders(['Authorization' => $this->signedHeader('peerA', $privatePem)])->get('cluster/ping');

        $result->assertStatus(200);
    }

    /**
     * Mirrors verifyAuthHeader()'s own >300s anti-replay window - a signed
     * header with a stale timestamp must be rejected even though the
     * signature itself is genuinely valid for that (node, timestamp) pair.
     */
    public function testPingWithExpiredSignedTimestampIsForbidden(): void
    {
        $keypair = $this->generateSigningKeypair();
        if ($keypair === null) {
            $this->markTestSkipped('openssl_pkey_new() has no usable openssl.cnf in this environment.');
        }
        [$privatePem, $publicKeyPem] = $keypair;

        $this->withClusterConfig([
            'nodes' => ['peerA' => ['baseURL' => 'http://a/', 'type' => 'public', 'publicKey' => base64_encode($publicKeyPem)]],
        ]);

        $result = $this->withHeaders([
            'Authorization' => $this->signedHeader('peerA', $privatePem, time() - 400),
        ])->get('cluster/ping');

        $result->assertStatus(403);
    }

    public function testPingWithSignedHeaderFromUnknownNodeIsForbidden(): void
    {
        $keypair = $this->generateSigningKeypair();
        if ($keypair === null) {
            $this->markTestSkipped('openssl_pkey_new() has no usable openssl.cnf in this environment.');
        }
        [$privatePem] = $keypair;

        // No 'nodes' entry at all for 'peerA' - verifyAuthHeader() must
        // fail closed (no public key to verify against), not fall back to
        // the shared-secret scheme just because the signed form didn't
        // match anything.
        $this->withClusterConfig(['secretToken' => 'real-secret']);

        $result = $this->withHeaders(['Authorization' => $this->signedHeader('peerA', $privatePem)])->get('cluster/ping');

        $result->assertStatus(403);
    }
}
