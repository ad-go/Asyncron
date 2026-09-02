<?php

declare(strict_types=1);

namespace AdGo\Cluster\Controllers;

use AdGo\Cluster\Cluster;
use AdGo\Cluster\NodeRegistryState;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Receives cluster.nodes registry changes from a peer (BroadcastNodeUpsertJob/
 * BroadcastNodeDeleteJob on the sending side), and serves this node's own
 * since-filtered changes to a puller (cluster:pull / cluster:long-poll) -
 * see NodeRegistryState's own docblock for why one node/change is enough to
 * eventually reach the whole mesh without any node explicitly relaying.
 *
 * Auth is the ClusterAuthFilter (Bearer token), not Shield - this is
 * node-to-node traffic, never a logged-in user's browser request. The two
 * POST routes are excluded from CSRF (see app/Config/Filters.php's csrf
 * except list) - a Bearer-token API has no session to forge. No
 * sessionSyncEnabled/fileSyncEnabled gate anywhere here, deliberately - see
 * Cluster::broadcastNodeUpsert()'s own docblock on why gating the registry
 * itself on either flag would be circular.
 */
class NodeRegistryController extends Controller
{
    public function receive(): ResponseInterface
    {
        $name = (string) $this->request->getPost('name');
        $entry = [
            'baseURL'   => (string) $this->request->getPost('baseURL'),
            'type'      => (string) $this->request->getPost('type'),
            'publicKey' => (string) $this->request->getPost('publicKey'),
        ];

        if ($name === '' || $entry['baseURL'] === '') {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'missing name or baseURL']);
        }

        $result = (new Cluster())->applyIncomingNodeUpsert($name, $entry);

        return $this->response->setJSON(['ok' => true] + $result);
    }

    public function deleteNode(): ResponseInterface
    {
        $name      = (string) $this->request->getPost('name');
        $deletedAt = (int) $this->request->getPost('deletedAt');

        if ($name === '' || $deletedAt <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'missing name or deletedAt']);
        }

        $result = (new Cluster())->applyIncomingNodeDelete($name, $deletedAt);

        return $this->response->setJSON(['ok' => true] + $result);
    }

    /**
     * @return ResponseInterface {"changes": {"name": {"baseURL":..,"type":..,"publicKey":..}, ...}, "deletions": {"name": 1723900000, ...}}
     */
    public function pullRegistry(): ResponseInterface
    {
        $since = (int) $this->request->getGet('since');
        $state = new NodeRegistryState();

        return $this->response->setJSON([
            'changes'   => $state->changesSince($since),
            'deletions' => $state->deletionsSince($since),
        ]);
    }
}
