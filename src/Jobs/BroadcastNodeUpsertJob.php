<?php

declare(strict_types=1);

namespace AdGo\Cluster\Jobs;

use AdGo\Cluster\Cluster;
use CodeIgniter\Queue\BaseJob;
use RuntimeException;

/**
 * Tells one peer that one node's cluster.nodes entry was added/changed here.
 * Consuming app must map this in app/Config/Queue.php:
 *
 *   public array $jobHandlers = [
 *       'cluster-broadcast-node-upsert' => \AdGo\Cluster\Jobs\BroadcastNodeUpsertJob::class,
 *   ];
 *
 * ($data = ['name' => <node name>, 'entry' => ['baseURL' => ..., 'type' =>
 * ..., 'publicKey' => ...], 'peer' => <receiving node name>], enqueued by
 * Cluster::broadcastNodeUpsert().)
 *
 * Same "throw on failure, let codeigniter4/queue retry" contract as
 * BroadcastInvalidationJob/PushFileJob.
 */
class BroadcastNodeUpsertJob extends BaseJob
{
    protected int $tries = 5;

    protected int $retryAfter = 60;

    public function process(): void
    {
        $name  = (string) ($this->data['name'] ?? '');
        $entry = (array) ($this->data['entry'] ?? []);
        $peer  = (string) ($this->data['peer'] ?? '');

        $cluster = new Cluster();
        $node    = $cluster->node($peer);
        if ($node === null || $name === '') {
            // Peer removed from config between enqueue and now - not a
            // failure worth retrying.
            return;
        }

        $client = $cluster->peerClient($node['baseURL'], 20);

        $response = $client->post('cluster/node-registry', [
            'headers'     => ['Authorization' => $cluster->authHeader()],
            'form_params' => [
                'name'      => $name,
                'baseURL'   => (string) ($entry['baseURL'] ?? ''),
                'type'      => (string) ($entry['type'] ?? 'public'),
                'publicKey' => (string) ($entry['publicKey'] ?? ''),
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("node-registry upsert '$name' on $peer failed: HTTP $status - " . $response->getBody());
        }
    }
}
