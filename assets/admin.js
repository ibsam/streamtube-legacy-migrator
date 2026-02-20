(function ($) {
    "use strict";

    function boolFlag(selector) {
        return $(selector).is(":checked") ? "1" : "0";
    }

    function isDryRun() {
        return boolFlag("#stlm-dry-run") === "1";
    }

    function showNotice(type, message) {
        var cssClass = type === "error" ? "notice notice-error" : "notice notice-success";
        $("#stlm-notice-area").html('<div class="' + cssClass + ' is-dismissible"><p>' + message + "</p></div>");
    }

    function renderStats(stats) {
        $("#stlm-total-old").text(stats.total_old || 0);
        $("#stlm-eligible").text(stats.eligible || 0);
        $("#stlm-migrated").text(stats.migrated || 0);
        $("#stlm-failed").text(stats.failed || 0);
        $("#stlm-remaining").text(stats.remaining || 0);
        $("#stlm-run-id").text(stats.run_id || "");
        $("#stlm-last-mode").text(stats.last_mode || "");
        $("#stlm-last-run").text(stats.last_run_at || "");
        $("#stlm-last-post-id").text(stats.last_processed_post_id || 0);
    }

    function renderRows(rows) {
        var html = "";
        rows.forEach(function (row) {
            var editTitle = row.title || ("Video #" + row.post_id);
            var linkedTitle = row.edit_link ? '<a href="' + row.edit_link + '" target="_blank" rel="noopener">' + editTitle + "</a>" : editTitle;
            html += "<tr>";
            html += "<td>" + row.post_id + "</td>";
            html += "<td>" + linkedTitle + "</td>";
            html += '<td><span class="stlm-status stlm-status-' + row.status + '">' + row.status + "</span></td>";
            html += "<td>" + (row.message || "") + "</td>";
            html += "<td>" + (row.updated_at || "") + "</td>";
            html += '<td><button type="button" class="button stlm-run-single" data-post-id="' + row.post_id + '">Migrate</button></td>';
            html += "</tr>";
        });
        $("#stlm-rows").html(html);
    }

    function renderLogs(logs) {
        var html = "";
        logs.forEach(function (log) {
            html += "<tr>";
            html += "<td>" + (log.timestamp || "") + "</td>";
            html += "<td><code>" + (log.run_id || "") + "</code></td>";
            html += "<td>" + (log.post_id || "") + "</td>";
            html += "<td>" + (log.mode || "") + "</td>";
            html += "<td>" + (log.status || "") + "</td>";
            html += "<td>" + (log.message || "") + "</td>";
            html += "</tr>";
        });
        $("#stlm-logs").html(html);
    }

    function refreshDashboard() {
        $.post(STLM_DATA.ajax_url, {
            action: "stlm_get_dashboard_data",
            nonce: STLM_DATA.nonce,
            filter: $("#stlm-filter-status").val() || "all",
            page: 1,
            per_page: STLM_DATA.per_page || 20
        }).done(function (resp) {
            if (!resp || !resp.success) {
                showNotice("error", (resp && resp.data && resp.data.message) ? resp.data.message : STLM_DATA.i18n.error);
                return;
            }
            var data = resp.data || {};
            renderStats(data.stats || {});
            renderRows(data.rows || []);
            renderLogs(data.logs || []);
        }).fail(function () {
            showNotice("error", STLM_DATA.i18n.error);
        });
    }

    function runAction(action, payload, doneMessage) {
        showNotice("success", STLM_DATA.i18n.running);
        payload = payload || {};
        payload.action = action;
        payload.nonce = STLM_DATA.nonce;

        $.post(STLM_DATA.ajax_url, payload).done(function (resp) {
            if (!resp || !resp.success) {
                showNotice("error", (resp && resp.data && resp.data.message) ? resp.data.message : STLM_DATA.i18n.error);
                return;
            }
            showNotice("success", doneMessage || STLM_DATA.i18n.done);
            var payloadData = resp.data && resp.data.payload ? resp.data.payload : null;
            if (payloadData) {
                renderStats(payloadData.stats || {});
                renderRows(payloadData.rows || []);
                renderLogs(payloadData.logs || []);
            } else {
                refreshDashboard();
            }
        }).fail(function () {
            showNotice("error", STLM_DATA.i18n.error);
        });
    }

    $(document).on("click", "#stlm-run-bulk", function () {
        var confirmationMessage = isDryRun() ? STLM_DATA.i18n.confirm_bulk_dry_run : STLM_DATA.i18n.confirm_bulk;
        if (!window.confirm(confirmationMessage)) {
            return;
        }
        runAction("stlm_run_bulk_migration", {
            force: boolFlag("#stlm-force-remap"),
            dry_run: boolFlag("#stlm-dry-run")
        }, isDryRun() ? "Bulk dry run completed." : "Bulk migration completed.");
    });

    $(document).on("click", "#stlm-run-chunk", function () {
        runAction("stlm_run_chunk_migration", {
            force: boolFlag("#stlm-force-remap"),
            dry_run: boolFlag("#stlm-dry-run"),
            limit: $("#stlm-chunk-limit").val() || 20
        }, isDryRun() ? "Chunk dry run completed." : "Chunk migration completed.");
    });

    $(document).on("click", ".stlm-run-single", function () {
        var postId = $(this).data("post-id");
        if (!postId) {
            return;
        }
        runAction("stlm_run_single_migration", {
            post_id: postId,
            force: boolFlag("#stlm-force-remap"),
            dry_run: boolFlag("#stlm-dry-run")
        }, isDryRun() ? "Single video dry run completed." : "Single video migration completed.");
    });

    $(document).on("change", "#stlm-filter-status", function () {
        refreshDashboard();
    });

    $(document).on("click", "#stlm-refresh", function () {
        refreshDashboard();
    });
})(jQuery);

