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
            var data = dataSource && dataSource.data ? dataSource.data() || {} : {};
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
     * Hit the status endpoint once for a given run id. Returns a jqXHR
     * promise resolving to the parsed JSON envelope.
     */
    function pollOne(statusUrl, runId) {
        return $.ajax({
            url: statusUrl,
            type: 'GET',
            dataType: 'json',
            data: { id: runId },
            cache: false,
            showLoader: false
        });
    }

    /**
     * Single polling tick — check every non-terminal row, reload the
     * grid if any flipped, and stop the loop once everything is
     * terminal.
     */
    function pollTick(statusUrl, startedAt, stopFn) {
        if (Date.now() - startedAt > POLL_MAX_DURATION_MS) {
            stopFn();
            return;
        }

        readVisibleRuns(function (runs) {
            var pending = runs.filter(function (run) {
                return !isTerminal(run.status);
            });

            if (pending.length === 0) {
                stopFn();
                return;
            }

            var requests = pending.map(function (run) {
                return pollOne(statusUrl, run.id).then(function (response) {
                    // Match against the *known* prior status for this row.
                    // If it has flipped, the grid is stale.
                    return response && response.status !== run.status
                        ? { changed: true, response: response }
                        : { changed: false };
                }, function () {
                    // Network / 404: skip silently, the next tick retries.
                    return { changed: false };
                });
            });

            $.when.apply($, requests).done(function () {
                var args = arguments.length === 1 ? [arguments[0]] : Array.prototype.slice.call(arguments);
                var anyChanged = args.some(function (entry) {
                    // $.when wraps a single response differently from many.
                    var result = Array.isArray(entry) ? entry[0] : entry;
                    return result && result.changed;
                });

                if (anyChanged) {
                    reloadListing();
                }
            });
        });
    }

    /**
     * Start the polling loop. Stored on a module-private handle so a
     * second click does not stack timers — the previous loop is
     * cancelled before the new one starts.
     */
    var activeTimer = null;

    function startPolling(statusUrl) {
        if (activeTimer !== null) {
            clearInterval(activeTimer);
            activeTimer = null;
        }

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
     */
    return function runScanNow(runUrl, statusUrl) {
        // `mage/storage` automatically appends `form_key` from
        // window.FORM_KEY for any POST against an admin URL, which is
        // exactly the CSRF token Magento's HttpPostActionInterface
        // expects. No manual form-key wiring required.
        storage.post(runUrl, '{}', false)
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
            });
    };
});
