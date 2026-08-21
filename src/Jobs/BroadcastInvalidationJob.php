<?php

declare(strict_types=1);

namespace AdGo\Cluster\Jobs;

use AdGo\Cluster\Cluster;
use CodeIgniter\Queue\BaseJob;
use RuntimeException;

/**
 * Tells one peer "sessions for this email issued before $changedAt are no
 * longer trusted" - the cross-node half of password-change invalidation.
 * Consuming app must map this in app/Config/Queue.php:
 *
 *   public array $jobHandlers = [
 *       'cluster-broadcast-invalidation' => \AdGo\Cluster\Jobs\BroadcastInvalidationJob::class,
 *   ];
 *
 * ($data = ['email' => ..., 'changedAt' => <unix timestamp>, 'peer' => <node name>],
 * enqueued by Cluster::broadcastPasswordChange().)
 *
 * Throwing on any failure is deliberate, not an oversight - codeigniter4/
 * queue's worker (queue:work) catches it, applies BaseJob::$tries/
 * $retryAfter, and re-queues automatically, same reasoning as PushFileJob.
 * A stale session on a peer staying alive because this silently failed
 * once is exactly the kind of thing worth retrying for.
 */
class BroadcastInvalidationJob extends BaseJob
{
    protected int $tries = 5;

    protected int $retryAfter = 60;

    public function process(): void
    {
        $email     = (string) ($this->data['email'] ?? '');
        $changedAt = (int) ($this->data['changedAt'] ?? 0);
        $peerName  = (string) ($this->data['peer'] ?? '');

        if ($email === '' || $changedAt <= 0) {
            return;
        }

        $cluster = new Cluster();
        $node    = $cluster->node($peerName);
        if ($node === null) {
            // Peer removed from config between enqueue and now - not a
            // failure worth retrying.
            return;
        }

        $client = $cluster->peerClient($node['baseURL'], 15);

        $response = $client->post('cluster/invalidate', [
            'headers' => [
                'Authorization' => $cluster->authHeader(),
            ],
            'form_params' => [
                'email'     => $email,
                'changedAt' => (string) $changedAt,
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("invalidation broadcast for $email to $peerName failed: HTTP $status - " . $response->getBody());
        }
    }
}
