<?php

declare(strict_types=1);

namespace AdGo\Cluster;

/**
 * The ONE place every "run a capability check" call funnels through,
 * whatever kind of check it is and wherever it's actually executing -
 * this node testing itself, RemoteTestController::testConnection()
 * running a check for a PUBLIC peer that asked over a signed HTTPS call,
 * or PullSync::pullTestRequests() running a check a NAT node claimed
 * during its own pull cycle. All three call sites used to independently
 * hardcode their own `if ($kind === 'database') ... elseif ($kind ===
 * 'node') ...` branch - found live 2026-08-19 doing the actual count: the
 * exact same two-way branch, written three times, in three different
 * files, that would all need editing in lockstep every time a capability
 * was added or changed. This class is that one edit point instead.
 *
 * Every registered checker exposes the SAME `checkParams(array $params):
 * array` shape (see DbConnectionChecker/NodeConnectionChecker's own
 * docblocks) - raw parameters that arrived
 * either from this node's own Settings lookup (SettingsController::
 * runLocalTest()) or literally over the wire from another node
 * (RemoteTestController/PullSync), never a second Settings lookup on the
 * executing side. That's what makes a single untyped `array $params`
 * dispatch safe here: every checker already treats "params" as arbitrary
 * external input, whether they arrived from this process's own Settings
 * or a signed HTTP body.
 *
 * Adding a new capability later ("etc." - more protocols are explicitly
 * expected, see this class's own commit message) means ONE new line in
 * CHECKERS below and a new checker class with a matching checkParams() -
 * nothing in RemoteTestController, PullSync, or SettingsController needs
 * to change to pick it up.
 *
 * $kind can also be 'combined' - runs 'node' and 'database' together and
 * returns both results under one requestId (see runCombined()'s own
 * docblock). Not a registered CHECKERS entry itself since it composes two
 * existing checkers rather than being one.
 */
class CapabilityChecker
{
    private const CHECKERS = [
        'database' => DbConnectionChecker::class,
        'node'     => NodeConnectionChecker::class,
    ];

    /**
     * @param array<string, mixed> $params for 'combined', {node: array,
     *                                      database: array} - each the
     *                                      SAME shape 'node'/'database'
     *                                      already expect on their own;
     *                                      for any other kind, passed
     *                                      straight through to that
     *                                      checker unchanged
     *
     * @return array{ok: bool, ms: float, error?: string}
     */
    public function run(string $kind, array $params): array
    {
        if ($kind === 'combined') {
            return $this->runCombined($params);
        }

        $checkerClass = self::CHECKERS[$kind] ?? null;
        if ($checkerClass === null) {
            return ['ok' => false, 'error' => "Unknown capability kind '{$kind}'.", 'ms' => 0.0];
        }

        return (new $checkerClass())->checkParams($params);
    }

    /**
     * SettingsController's Cluster-table "test" action tests a node's
     * file-sync connection AND its configured database connection
     * together, one click/one requestId/one NAT-queue round trip instead
     * of two - see SettingsController::testNode()'s own docblock. Still
     * routes through run() itself (not the checkers directly) so a
     * future third capability gets picked up here too without another
     * edit.
     *
     * @param array{node?: array, database?: array} $params
     *
     * @return array{ok: bool, ms: float, node: array, database: array}
     */
    private function runCombined(array $params): array
    {
        $node     = $this->run('node', (array) ($params['node'] ?? []));
        $database = $this->run('database', (array) ($params['database'] ?? []));

        return [
            'ok'       => $node['ok'] && $database['ok'],
            'ms'       => (float) ($node['ms'] ?? 0.0) + (float) ($database['ms'] ?? 0.0),
            'node'     => $node,
            'database' => $database,
        ];
    }
}
