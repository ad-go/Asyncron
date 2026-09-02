"use strict";

// Ports C:\ai\server-picker's own standalone racing logic into this app,
// shared by the /start landing page (full-page, auto-redirecting) and the
// login page's own "a faster node exists" banner (non-intrusive, no
// redirect) - one implementation instead of the two drifting apart.
//
// Deliberately 'no-cors': works against every node with zero server-side
// CORS configuration, at the cost of only ever knowing "answered" vs
// "didn't", never a real HTTP status - identical tradeoff C:\ai\server-picker
// itself made, for the same reason (a network-level yes/no is all a picker
// actually needs; a bad status past that point isn't reachability's job).
window.NodePicker = (function () {
    function probe(url, healthPath, timeoutMs) {
        var start = performance.now();
        var ctrl = new AbortController();
        var timer = setTimeout(function () { ctrl.abort(); }, timeoutMs);

        return fetch(url.replace(/\/$/, "") + healthPath, {
            mode: "no-cors",
            cache: "no-store",
            signal: ctrl.signal,
        }).then(
            function () {
                clearTimeout(timer);
                return { url: url, ms: Math.round(performance.now() - start) };
            },
            function (err) {
                clearTimeout(timer);
                throw err;
            }
        );
    }

    // Resolves with the FIRST server to answer (not necessarily the
    // lowest-latency one overall - same "first past the post" contract
    // the standalone picker uses, since waiting for every straggler to
    // time out before deciding defeats the point of racing at all).
    // onResult(url, "ok"|"fail", ms?) fires as each one settles, for a
    // caller that wants to render live per-server status.
    function race(servers, healthPath, timeoutMs, onResult) {
        return new Promise(function (resolve, reject) {
            var settled = 0;
            var winnerTaken = false;

            servers.forEach(function (url) {
                probe(url, healthPath, timeoutMs).then(
                    function (result) {
                        if (onResult) { onResult(url, "ok", result.ms); }
                        settled++;
                        if (!winnerTaken) {
                            winnerTaken = true;
                            resolve(result);
                        }
                    },
                    function () {
                        if (onResult) { onResult(url, "fail"); }
                        settled++;
                        if (settled === servers.length && !winnerTaken) {
                            reject(new Error("every server failed to answer"));
                        }
                    }
                );
            });
        });
    }

    return { probe: probe, race: race };
})();
