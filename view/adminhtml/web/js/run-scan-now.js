/**
 * IronCart_Scan — "Run scan now" button JS driver.
 *
 * Wired from the toolbar button declared by
 * `IronCart\Scan\Ui\Component\Control\RunScanNowButton`. On click:
 *
 *   1. POST to the run controller with the admin form key. Read back
 *      `{ runId, status: "queued" }`.
 *   2. Reload the run-listing data source via uiRegistry so the newly
 *      enqueued row appears in the grid immediately.
 *   3. Start a polling loop: every 2s, hit the status endpoint for
 *      each non-terminal `entity_id` currently visible in the grid.
 *      When a row's status transitions, reload the data source so the
 *      grid re-renders the badge + severity totals. Stop when every
 *      visible row is terminal, or after 5 minutes.
 *
 * Polling is intentional for v1 (see issue #29) — admin UI Components
 * do not have first-class support for SSE / websockets, and this UI
 * is throwaway once the SaaS hosted dashboard ships.
 *
 * ## Throttling shape (issue #77)
 *
 * v1.2.0's #73 fix made `data.items` actually readable, which surfaced
 * a request-storm regression in this loop: a single tick fan-outs one
 * GET per non-terminal row, and an earlier (still-in-flight) tick's
 * AJAX from a previous interval could overlap with the next tick's
 * fresh wave. With N pending rows the wire traffic was ~N requests
 * every 2s, easily several per second in real installs (HotCustard).
 *
 * Throttling rules now enforced here:
 *
 *   - Per-runId de-dup: a runId already in flight is skipped — never
 *     two concurrent GETs for the same row.
 *   - Global concurrency ceiling: `MAX_INFLIGHT` simultaneous GETs
 *     across the whole listing. Any pending row that would push past
 *     the ceiling waits for the next tick.
 *   - Single-tick guard: a new interval tick that lands while the
 *     prior tick's `readVisibleRuns` callback is still queued is a
 *     no-op (Magento's uiRegistry resolves async).
 *   - Click guard: a second `runScanNow()` invocation while the POST
 *     is still in flight is dropped — prevents double-enqueue and
 *     prevents stacking parallel polling chains.
 *
 * Read-only invariant: nothing here issues outbound network calls; the
 * only XHRs are same-origin GETs to the admin status endpoint and the
 * single POST to the admin run endpoint.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */
define([
    'jquery',
    'mage/storage',
    'uiRegistry',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, storage, registry, alert, $t) {
    'use strict';

    var LISTING_NAMESPACE = 'ironcartscan_run_listing';
    var DATA_SOURCE_PATH = LISTING_NAMESPACE + '.' + LISTING_NAMESPACE + '_data_source';
    var POLL_INTERVAL_MS = 2000;
    var POLL_MAX_DURATION_MS = 5 * 60 * 1000; // 5 minutes — issue AC.
    var TERMINAL_STATUSES = ['succeeded', 'failed'];

    /**
     * Hard ceiling on simultaneous status GETs across the listing.
     *
     * Rationale: even with per-runId de-dup, a listing showing 20+
     * pending rows would still produce a 20-wide wave every 2s. The
     * status endpoint is cheap (one PK lookup) but admin servers
     * are not always sized for it. 8 is the AC ceiling from #77.
     */
    var MAX_INFLIGHT = 8;

    /**
     * Resolve the listing data source. Returned async-ish because
     * uiRegistry may not have resolved it yet on first paint.
     */
    function withDataSource(callback) {
        registry.get(DATA_SOURCE_PATH, function (dataSource) {
            callback(dataSource);
        });
    }

    /**
     * Reload the run-listing grid rows in place. Uses
     * `refresh: true` so cached state (filters, paging) is preserved.
     */
    function reloadListing() {
        withDataSource(function (dataSource) {
            if (dataSource && dataSource.reload) {
                dataSource.reload({ refresh: true });
            }
        });
    }

    /**
     * Read the currently rendered grid items from uiRegistry. Used to
     * pick which run ids to poll. The shape comes straight from the
     * ScanRunDataProvider — see Ui/DataProvider/ScanRunDataProvider.php.
     *
     * Returns a list of { id, status } objects.
     */
    function readVisibleRuns(callback) {
        withDataSource(function (dataSource) {
            // `data` on Magento_Ui/js/grid/provider is a plain object
            // ({ items, totalRecords }), not a Knockout observable — see
            // vendor/magento/module-ui .../grid/provider.js setData().
            var data = (dataSource && dataSource.data) || {};
            var rows = data.items || [];
            var snapshot = [];

            rows.forEach(function (row) {
                if (row && row.entity_id) {
                    snapshot.push({
                        id: parseInt(row.entity_id, 10),
                        status: row.status || ''
                    });
                }
            });

            callback(snapshot);
        });
    }

    function isTerminal(status) {
        return TERMINAL_STATUSES.indexOf(status) !== -1;
    }

    /**
     * Module-private state for the polling loop. All cleared on `stop()`
     * so a second `runScanNow()` invocation starts from a clean slate.
     */
    var activeTimer = null;
    var inflightIds = {};   // { runId: true } — de-dup guard, request count = Object.keys().length.
    var tickInProgress = false; // true between readVisibleRuns dispatch and its callback returning.
    var postInFlight = false;   // true between storage.post() and its done/fail settling.

    /**
     * Hit the status endpoint once for a given run id. Returns a jqXHR
     * promise resolving to the parsed JSON envelope.
     *
     * Tracks the runId in `inflightIds` so two concurrent ticks never
     * issue two simultaneous GETs for the same row.
     */
    function pollOne(statusUrl, runId) {
        inflightIds[runId] = true;
        return $.ajax({
            url: statusUrl,
            type: 'GET',
            dataType: 'json',
            data: { id: runId },
            cache: false,
            showLoader: false
        }).always(function () {
            delete inflightIds[runId];
        });
    }

    /**
     * Count currently in-flight status GETs (across all run ids).
     */
    function inflightCount() {
        return Object.keys(inflightIds).length;
    }

    /**
     * Single polling tick — check every non-terminal row (subject to
     * de-dup and the concurrency ceiling), reload the grid if any row
     * flipped status, and stop the loop once everything visible is
     * terminal.
     *
     * Ticks are guarded by `tickInProgress` so a setInterval-fired tick
     * that lands while the previous tick's `readVisibleRuns` callback
     * is still queued in uiRegistry's microtask queue is a no-op.
     */
    function pollTick(statusUrl, startedAt, stopFn) {
        if (Date.now() - startedAt > POLL_MAX_DURATION_MS) {
            stopFn();
            return;
        }

        if (tickInProgress) {
            // Previous tick's readVisibleRuns callback hasn't returned
            // yet — skip this tick rather than queuing a parallel wave.
            return;
        }

        tickInProgress = true;

        readVisibleRuns(function (runs) {
            try {
                var pending = runs.filter(function (run) {
                    return !isTerminal(run.status);
                });

                if (pending.length === 0) {
                    stopFn();
                    return;
                }

                // Filter: skip runIds already being polled, then cap at
                // the global concurrency ceiling. Anything we drop here
                // will be picked up on the next tick.
                var available = MAX_INFLIGHT - inflightCount();
                if (available <= 0) {
                    return;
                }

                var toPoll = [];
                for (var i = 0; i < pending.length && toPoll.length < available; i++) {
                    var run = pending[i];
                    if (!inflightIds[run.id]) {
                        toPoll.push(run);
                    }
                }

                if (toPoll.length === 0) {
                    return;
                }

                var requests = toPoll.map(function (run) {
                    return pollOne(statusUrl, run.id).then(function (response) {
                        // Match against the *known* prior status for this
                        // row. If it has flipped, the grid is stale.
                        return response && response.status !== run.status
                            ? { changed: true, response: response }
                            : { changed: false };
                    }, function () {
                        // Network / 404: skip silently, the next tick retries.
                        return { changed: false };
                    });
                });

                $.when.apply($, requests).done(function () {
                    var args = arguments.length === 1
                        ? [arguments[0]]
                        : Array.prototype.slice.call(arguments);
                    var anyChanged = args.some(function (entry) {
                        // $.when wraps a single response differently from many.
                        var result = Array.isArray(entry) ? entry[0] : entry;
                        return result && result.changed;
                    });

                    if (anyChanged) {
                        reloadListing();
                    }
                });
            } finally {
                tickInProgress = false;
            }
        });
    }

    /**
     * Start the polling loop. Stored on a module-private handle so a
     * second click does not stack timers — the previous loop is
     * cancelled before the new one starts. The `inflightIds` /
     * `tickInProgress` flags are also reset so the new loop starts
     * from a clean state regardless of what the prior loop was doing.
     */
    function startPolling(statusUrl) {
        if (activeTimer !== null) {
            clearInterval(activeTimer);
            activeTimer = null;
        }
        // Reset the de-dup + tick-in-progress flags. In-flight XHRs from
        // a prior loop will still call their `.always()` (clearing their
        // own entries) but a hard reset here means a stuck flag from a
        // cancelled chain doesn't permanently block the new loop.
        inflightIds = {};
        tickInProgress = false;

        var startedAt = Date.now();

        function stop() {
            if (activeTimer !== null) {
                clearInterval(activeTimer);
                activeTimer = null;
            }
        }

        // Fire one tick immediately, then on the interval.
        pollTick(statusUrl, startedAt, stop);
        activeTimer = setInterval(function () {
            pollTick(statusUrl, startedAt, stop);
        }, POLL_INTERVAL_MS);
    }

    /**
     * Entry point invoked from RunScanNowButton::getButtonData()'s
     * on_click. The button renderer hands us the pre-built URLs.
     *
     * A click while the previous POST is still in flight is silently
     * dropped — Magento renders the toolbar button as a regular
     * `<button>` which is *not* disabled during the async POST, so
     * impatient users would otherwise stack parallel enqueues + their
     * own polling loops.
     */
    return function runScanNow(runUrl, statusUrl) {
        if (postInFlight) {
            return;
        }
        postInFlight = true;

        // Magento's admin CSRF guard reads `form_key` from $_POST, so
        // the POST body must be form-encoded and must include the key
        // explicitly — `mage/storage` does not append it on its own.
        storage.post(
            runUrl,
            'form_key=' + encodeURIComponent(window.FORM_KEY),
            false,
            'application/x-www-form-urlencoded'
        )
            .done(function (response) {
                if (!response || typeof response.runId !== 'number') {
                    alert({
                        title: $t('Run scan now'),
                        content: $t('The server did not return a run id.')
                    });
                    return;
                }

                reloadListing();
                startPolling(statusUrl);
            })
            .fail(function (xhr) {
                var message = $t('Could not enqueue scan run.');
                if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                    message = xhr.responseJSON.error;
                }
                alert({
                    title: $t('Run scan now'),
                    content: message
                });
            })
            .always(function () {
                postInFlight = false;
            });
    };
});
