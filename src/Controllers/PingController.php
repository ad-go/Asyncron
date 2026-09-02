<?php

declare(strict_types=1);

namespace AdGo\Cluster\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Last-resort reachability proof for a peer that has NEITHER SSH NOR FTP/
 * FTPS deploy credentials configured (Settings -> Nodes table) - all that's
 * known about it is its own public `url`. NodeConnectionChecker falls back
 * to this endpoint specifically when the protocol-appropriate host field
 * (sshHost/ftpHost) is blank, so "no deploy transport configured" still
 * gets a real yes/no answer instead of an unconditional failure.
 *
 * Deliberately the simplest possible peer-to-peer endpoint in this
 * package - no payload, no side effect, just "is this node up and does it
 * accept our cluster-auth signature" (see this route's own 'cluster-auth'
 * filter in RouteRegistrar::register()). Every other cluster/* GET/POST
 * endpoint answers a specific sync question; this one answers no question
 * at all on purpose, so a reachability check never has to reason about
 * whether a heavier endpoint's own side effects (or lack of relevant data)
 * could produce a false negative.
 */
class PingController extends Controller
{
    public function ping(): ResponseInterface
    {
        return $this->response->setJSON(['ok' => true, 'at' => time()]);
    }
}
