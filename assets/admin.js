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
            html += '<td class="stlm-row-actions">';
            html += '<button type="button" class="button stlm-preview-mapping" data-post-id="' + row.post_id + '">Preview Mapping</button> ';
            html += '<button type="button" class="button stlm-show-migrated-fields" data-post-id="' + row.post_id + '">Show Migrated Fields</button> ';
            html += '<button type="button" class="button stlm-run-single" data-post-id="' + row.post_id + '">Migrate</button>';
            html += "</td>";
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

    function getCurrentPage() {
        var p = parseInt($("#stlm-current-page").val(), 10);
        return isNaN(p) || p < 1 ? 1 : p;
    }

    function getPerPage() {
        var p = parseInt($("#stlm-per-page").val(), 10);
        return isNaN(p) || p < 1 ? 20 : Math.min(100, p);
    }

    function renderPagination(pagination) {
        if (!pagination || !pagination.total_pages) {
            return;
        }
        var page = parseInt(pagination.page, 10) || 1;
        var totalPages = parseInt(pagination.total_pages, 10) || 1;
        var totalRows = parseInt(pagination.total_rows, 10) || 0;
        $("#stlm-current-page").val(page);
        $("#stlm-page-current").text(page);
        $("#stlm-page-total").text(totalPages);
        $("#stlm-total-rows").text(totalRows);
        $("#stlm-pagination .stlm-page-first, #stlm-pagination .stlm-page-prev").prop("disabled", page <= 1);
        $("#stlm-pagination .stlm-page-next, #stlm-pagination .stlm-page-last").prop("disabled", page >= totalPages);
    }

    function refreshDashboard(requestedPage) {
        var page = requestedPage !== undefined ? requestedPage : getCurrentPage();
        var perPage = getPerPage();
        $.post(STLM_DATA.ajax_url, {
            action: "stlm_get_dashboard_data",
            nonce: STLM_DATA.nonce,
            filter: $("#stlm-filter-status").val() || "all",
            page: page,
            per_page: perPage,
            search: $("#stlm-search-query").val() || ""
        }).done(function (resp) {
            if (!resp || !resp.success) {
                showNotice("error", (resp && resp.data && resp.data.message) ? resp.data.message : STLM_DATA.i18n.error);
                return;
            }
            var data = resp.data || {};
            renderStats(data.stats || {});
            renderRows(data.rows || []);
            renderLogs(data.logs || []);
            renderPagination(data.pagination || {});
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
                renderPagination(payloadData.pagination || {});
            } else {
                refreshDashboard();
            }
        }).fail(function () {
            showNotice("error", STLM_DATA.i18n.error);
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function renderFieldDetails(title, data) {
        ensureDetailsPanel();
        var html = '<h3>' + escapeHtml(title) + '</h3>';
        html += '<p><strong>Post ID:</strong> ' + escapeHtml(data.post_id || "") + ' &nbsp; ';
        html += '<strong>Field Count:</strong> ' + escapeHtml(data.field_count || 0) + '</p>';
        html += '<p><strong>Message:</strong> ' + escapeHtml(data.message || "") + '</p>';
        html += '<pre>' + escapeHtml(JSON.stringify(data.fields || {}, null, 2)) + '</pre>';
        $("#stlm-field-details").html(html);
        var panelTop = $("#stlm-field-details").offset();
        if (panelTop && typeof panelTop.top !== "undefined") {
            $("html, body").animate({ scrollTop: Math.max(0, panelTop.top - 80) }, 200);
        }
    }

    function ensureDetailsPanel() {
        if ($("#stlm-field-details").length) {
            return;
        }
        var fallbackHtml = [
            '<h2>Field Details</h2>',
            '<div id="stlm-field-details" class="stlm-field-details">',
            "<p>Select a row action to view mapped/migrated fields.</p>",
            "</div>"
        ].join("");

        if ($(".stlm-log-wrap").length) {
            $(fallbackHtml).insertBefore(".stlm-log-wrap");
        } else if ($(".stlm-wrap").length) {
            $(".stlm-wrap").append(fallbackHtml);
        }
    }

    function loadFieldAction(action, postId, panelTitle) {
        if (!postId) {
            return;
        }
        ensureDetailsPanel();
        $("#stlm-field-details").html("<p>Loading...</p>");
        $.post(STLM_DATA.ajax_url, {
            action: action,
            nonce: STLM_DATA.nonce,
            post_id: postId
        }).done(function (resp) {
            if (!resp || typeof resp !== "object") {
                showNotice("error", "Unexpected response from server.");
                $("#stlm-field-details").html("<pre>" + escapeHtml(String(resp)) + "</pre>");
                return;
            }
            if (!resp.success) {
                showNotice("error", (resp && resp.data && resp.data.message) ? resp.data.message : STLM_DATA.i18n.error);
                $("#stlm-field-details").html("<pre>" + escapeHtml(JSON.stringify(resp.data || {}, null, 2)) + "</pre>");
                return;
            }
            renderFieldDetails(panelTitle, resp.data || {});
        }).fail(function () {
            showNotice("error", STLM_DATA.i18n.error);
            $("#stlm-field-details").html("<p>Failed to load details.</p>");
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

    $(document).on("click", ".stlm-preview-mapping", function () {
        var postId = $(this).data("post-id");
        loadFieldAction("stlm_preview_mapping", postId, "Preview Mapping (Legacy -> Enhanced)");
    });

    $(document).on("click", ".stlm-show-migrated-fields", function () {
        var postId = $(this).data("post-id");
        loadFieldAction("stlm_get_migrated_fields", postId, "Saved Migrated Enhanced Fields");
    });

    $(document).on("change", "#stlm-filter-status", function () {
        $("#stlm-current-page").val(1);
        refreshDashboard(1);
    });

    $(document).on("change", "#stlm-per-page", function () {
        $("#stlm-current-page").val(1);
        refreshDashboard(1);
    });

    $(document).on("keypress", "#stlm-search-query", function (e) {
        if (e.which === 13) {
            $("#stlm-current-page").val(1);
            refreshDashboard(1);
        }
    });

    $(document).on("click", "#stlm-refresh", function () {
        refreshDashboard();
    });

    $(document).on("click", "#stlm-pagination .stlm-page-first", function () {
        if ($(this).prop("disabled")) {
            return;
        }
        refreshDashboard(1);
    });
    $(document).on("click", "#stlm-pagination .stlm-page-prev", function () {
        if ($(this).prop("disabled")) {
            return;
        }
        refreshDashboard(Math.max(1, getCurrentPage() - 1));
    });
    $(document).on("click", "#stlm-pagination .stlm-page-next", function () {
        if ($(this).prop("disabled")) {
            return;
        }
        var totalPages = parseInt($("#stlm-page-total").text(), 10) || 1;
        refreshDashboard(Math.min(totalPages, getCurrentPage() + 1));
    });
    $(document).on("click", "#stlm-pagination .stlm-page-last", function () {
        if ($(this).prop("disabled")) {
            return;
        }
        var totalPages = parseInt($("#stlm-page-total").text(), 10) || 1;
        refreshDashboard(totalPages);
    });
})(jQuery);

