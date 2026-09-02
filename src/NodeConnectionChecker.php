<?php

declare(strict_types=1);

namespace AdGo\Cluster;

use CodeIgniter\Settings\Settings;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Real connect-and-prove-usable test against whatever deploy credentials
 * are CURRENTLY saved for a node in cluster-ui's Settings -> Nodes table -
 * whichever protocol (`Nodes.protocol`) is presently selected for that row
 * (FTP, FTPS explicit/AUTH TLS, SSH, or SCP - see SettingsController::
 * NODE_PROTOCOLS in ad-go/cluster-ui), using that protocol's own
 * independent credential set (`Nodes.ftp*` or `Nodes.ssh*`). When the
 * protocol-appropriate host field is blank - no SSH access AND no FTP/
 * FTPS deploy credentials configured for this node at all - falls back to
 * checkApi(): a signed peer-to-peer HTTP GET to the node's own `Nodes.url`
 * (see PingController), the only thing still known about a node with no
 * deploy transport configured. That fallback needs a reachable `url` to
 * mean anything - a 'nat' node with neither deploy credentials nor a
 * publicly reachable URL genuinely has no way to be tested from outside
 * its own LAN, and correctly still fails.
 *
 * checkNode() (local Settings lookup) and checkParams() (raw parameters,
 * no Settings involved) are separate entry points onto the SAME connect
 * logic - checkParams() exists specifically so RemoteTestController can
 * run a check using parameters that arrived OVER THE WIRE from a
 * different node (see that class's own docblock for why credentials must
 * be tested from the TARGET node's own local network position), without
 * duplicating the connect/try/catch logic a second time.
 *
 * Same "on-demand, not a scheduled/queued check" role DbConnectionChecker
 * has for the Databases table - triggered only from cluster-ui's Nodes
 * table node-name badge/modal, not from a Queue job or Tasks schedule.
 * Node connectivity (whichever of SSH/FTP/API a peer actually has
 * configured) IS already covered by a scheduled/queued check elsewhere
 * (see NodeConnectivityChecker, triggered after login and every minute,
 * writing to writable/Cluster/node_connections.json, and itself built on
 * THIS class) - this class deliberately does NOT duplicate that
 * persistent-log side effect; an on-demand modal click proving "does THIS
 * click work right now" has no need for a history file, unlike the
 * standing health check NodeConnectivityChecker already is.
 */
class NodeConnectionChecker
{
    /**
     * @return array{ok: bool, protocol?: string, detail?: string, error?: string, errorCode?: string, errorArgs?: array<int, scalar>, ms: float}
     */
    public function checkNode(string $node, ?Settings $settings = null): array
    {
        $settings = $settings ?? service('settings');
        $protocol = (string) ($settings->get('Nodes.protocol', $node) ?? 'FTP');
        $family   = in_array($protocol, ['SSH', 'SCP'], true) ? 'ssh' : 'ftp';

        return $this->checkParams([
            'protocol' => $protocol,
            'host'     => (string) ($settings->get('Nodes.' . $family . 'Host', $node) ?? ''),
            'port'     => (string) ($settings->get('Nodes.' . $family . 'Port', $node) ?? ''),
            'user'     => (string) ($settings->get('Nodes.' . $family . 'User', $node) ?? ''),
            'pass'     => (string) ($settings->get('Nodes.' . $family . 'Pass', $node) ?? ''),
            'url'      => (string) ($settings->get('Nodes.url', $node) ?? ''),
        ]);
    }

    /**
     * 'error' is always plain English - this package ships no Language
     * files of its own (see NodeConnectionChecker's own class docblock
     * and SsoController::handoffFailedMessage() for the same reasoning),
     * and a remote/NAT check's result crosses the wire from a DIFFERENT
     * node's PHP process, which has no idea what locale the requesting
     * admin's browser session is even in. 'errorCode' is set alongside
     * it for every KNOWN, fixed-message failure (never for a caught
     * Throwable's own ->getMessage(), which is inherently
     * driver/library-specific and not practical to translate) - the
     * app/ layer (cluster-ui's own SettingsController) is the one place
     * that both owns Language files AND knows the viewer's locale, so
     * it looks up 'errorCode' there and re-translates 'error' just
     * before the JSON response reaches the browser, regardless of which
     * node actually ran the check. 'errorArgs' carries the {0}-style
     * placeholder value(s) a code needs (e.g. execFailed's exit status).
     *
     * @param array{protocol?: string, host?: string, port?: string|int, user?: string, pass?: string, url?: string} $params
     *
     * @return array{ok: bool, protocol?: string, detail?: string, error?: string, errorCode?: string, errorArgs?: array<int, scalar>, ms: float}
     */
    public function checkParams(array $params): array
    {
        $protocol = (string) ($params['protocol'] ?? 'FTP');
        $url      = (string) ($params['url'] ?? '');

        return in_array($protocol, ['SSH', 'SCP'], true)
            ? $this->checkSsh($protocol, $params, $url)
            : $this->checkFtp($protocol, $params, $url);
    }

    /**
     * @param array{host?: string, port?: string|int, user?: string, pass?: string} $params
     */
    private function checkSsh(string $protocol, array $params, string $url): array
    {
        $host = trim((string) ($params['host'] ?? ''));
        if ($host === '') {
            if ($url !== '') {
                return $this->checkApi($url);
            }

            return ['ok' => false, 'error' => 'No SSH host configured for this node.', 'errorCode' => 'noSshHost', 'ms' => 0.0];
        }
        $port = (int) ($params['port'] ?: 22);
        $user = (string) ($params['user'] ?? '');
        $pass = (string) ($params['pass'] ?? '');

        $start = microtime(true);
        try {
            $ssh      = new SSH2($host, $port, 10);
            $loggedIn = $ssh->login($user, $pass);
            if (! $loggedIn) {
                return ['ok' => false, 'error' => 'Authentication failed.', 'errorCode' => 'authFailed', 'ms' => $this->msSince($start)];
            }

            // Cheap, side-effect-free proof of usability: confirms the
            // shell actually runs commands, not only that the handshake/
            // auth succeeded.
            $ssh->exec('true');
            $exitStatus = $ssh->getExitStatus();
            $ssh->disconnect();

            if ($exitStatus !== 0) {
                return ['ok' => false, 'error' => "Login succeeded but exec failed (exit $exitStatus).", 'errorCode' => 'execFailed', 'errorArgs' => [$exitStatus], 'ms' => $this->msSince($start)];
            }

            return ['ok' => true, 'protocol' => $protocol, 'detail' => "$user@$host:$port", 'ms' => $this->msSince($start)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'ms' => $this->msSince($start)];
        }
    }

    /**
     * @param array{host?: string, port?: string|int, user?: string, pass?: string} $params
     */
    private function checkFtp(string $protocol, array $params, string $url): array
    {
        $host = trim((string) ($params['host'] ?? ''));
        if ($host === '') {
            if ($url !== '') {
                return $this->checkApi($url);
            }

            return ['ok' => false, 'error' => 'No FTP host configured for this node.', 'errorCode' => 'noFtpHost', 'ms' => 0.0];
        }
        $port = (int) ($params['port'] ?: 21);
        $user = (string) ($params['user'] ?? '');
        $pass = (string) ($params['pass'] ?? '');

        // PHP's bundled ext-ftp, not a shell-out to a system ftp/curl
        // binary - same reasoning checkSsh() above uses phpseclib instead
        // of a system ssh binary for: no assumption about what's actually
        // on the shared hosting box beyond core PHP extensions. Not every
        // build has ext-ftp enabled, so this is checked, not assumed.
        if (! function_exists('ftp_connect')) {
            return ['ok' => false, 'error' => 'PHP ext-ftp is not available on this node.', 'errorCode' => 'noFtpExt', 'ms' => 0.0];
        }

        $start  = microtime(true);
        $useTls = str_starts_with($protocol, 'FTPS');
        // @ - both ftp_connect()/ftp_ssl_connect() emit a PHP warning (not
        // an exception) on failure; the false return is the only reliable
        // signal, same shape as every native ftp_* call below.
        $conn = $useTls ? @ftp_ssl_connect($host, $port, 10) : @ftp_connect($host, $port, 10);
        if ($conn === false) {
            return ['ok' => false, 'error' => 'Could not connect to the FTP host.', 'errorCode' => 'ftpConnectFailed', 'ms' => $this->msSince($start)];
        }

        $loggedIn = @ftp_login($conn, $user, $pass);
        if (! $loggedIn) {
            @ftp_close($conn);

            return ['ok' => false, 'error' => 'FTP authentication failed.', 'errorCode' => 'ftpAuthFailed', 'ms' => $this->msSince($start)];
        }

        // Proof-of-usability, same idea as SSH's 'true' exec above - a
        // real round-trip command, not just an accepted login.
        $pwd = @ftp_pwd($conn);
        // @ - ftp_close() itself can emit a PHP warning closing an FTPS
        // (explicit TLS) control channel on some server/OpenSSL
        // combinations ("SSL_read on shutdown: unexpected eof while
        // reading") - benign, the actual test result above is already
        // captured by this point, found live 2026-08-19 testing against a
        // real FTPS node.
        @ftp_close($conn);

        if ($pwd === false) {
            return ['ok' => false, 'error' => 'Login succeeded but PWD failed.', 'errorCode' => 'ftpPwdFailed', 'ms' => $this->msSince($start)];
        }

        return ['ok' => true, 'protocol' => $protocol, 'detail' => $pwd, 'ms' => $this->msSince($start)];
    }

    /**
     * Last resort: no SSH or FTP/FTPS deploy credentials at all for this
     * node, but its own public `url` is known - proves reachability (and
     * that it's a genuine, currently-trusted peer, via the signed
     * Authorization header) through the one thing every node in the mesh
     * already exposes, rather than reporting an unconditional failure
     * just because no deploy transport was ever configured. See
     * PingController's own docblock for the receiving end.
     */
    private function checkApi(string $url): array
    {
        $start = microtime(true);
        try {
            $cluster  = new Cluster();
            $client   = $cluster->peerClient(rtrim($url, '/'), 10);
            $response = $client->get('cluster/ping', [
                'headers' => ['Authorization' => $cluster->authHeader()],
            ]);
            $decoded = json_decode((string) $response->getBody(), true);

            if (! is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                return ['ok' => false, 'error' => 'Unexpected response from cluster/ping.', 'errorCode' => 'apiPingFailed', 'ms' => $this->msSince($start)];
            }

            return ['ok' => true, 'protocol' => 'API', 'detail' => $url, 'ms' => $this->msSince($start)];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'ms' => $this->msSince($start)];
        }
    }

    private function msSince(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 1);
    }
}
