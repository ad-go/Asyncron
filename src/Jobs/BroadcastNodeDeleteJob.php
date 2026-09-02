<?php

declare(strict_types=1);

namespace AdGo\Cluster\Jobs;

use AdGo\Cluster\Cluster;
use CodeIgniter\Queue\BaseJob;
use RuntimeException;

/**
 * Tells one peer that one node's cluster.nodes entry was removed here.
 * Consuming app must map this in app/Config/Queue.php:
 *
 *   public array $jobHandlers = [
 *       'cluster-broadcast-node-delete' => \AdGo\Cluster\Jobs\BroadcastNodeDeleteJob::class,
 *   ];
 *
 * ($data = ['name' => <deleted node name>, 'deletedAt' => <unix ts,
 * captured once by Cluster::broadcastNodeDelete() at detection time - NOT
 * re-derived here, same reasoning as DeleteFileJob's own docblock>, 'peer'
 * => <receiving node name>], enqueued by Cluster::broadcastNodeDelete().)
 *
 * Same "throw on failure, let codeigniter4/queue retry" contract as
 * DeleteFileJob - this job's sibling/counterpart for the node registry
 * instead of file content.
 */
class BroadcastNodeDeleteJob extends BaseJob
{
    protected int $tries = 5;

    protected int $retryAfter = 60;

    public function process(): void
    {
        $name      = (string) ($this->data['name'] ?? '');
        $deletedAt = (int) ($this->data['deletedAt'] ?? 0);
        $peer      = (string) ($this->data['peer'] ?? '');

        $cluster = new Cluster();
        $node    = $cluster->node($peer);
        if ($node === null || $name === '') {
            return;
        }

        $client = $cluster->peerClient($node['baseURL'], 20);

        $response = $client->post('cluster/node-registry-delete', [
            'headers'     => ['Authorization' => $cluster->authHeader()],
            'form_params' => [
                'name'      => $name,
                'deletedAt' => (string) $deletedAt,
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("node-registry delete '$name' on $peer failed: HTTP $status - " . $response->getBody());
        }
    }
}
