<?php
if (! defined('ABSPATH')) {
    exit;
}

$stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : array();
$rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
$logs = isset($payload['logs']) && is_array($payload['logs']) ? $payload['logs'] : array();
$pagination = isset($payload['pagination']) && is_array($payload['pagination']) ? $payload['pagination'] : array();
$current_page = isset($pagination['page']) ? (int) $pagination['page'] : 1;
$total_pages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;
$total_rows = isset($pagination['total_rows']) ? (int) $pagination['total_rows'] : 0;
$per_page = isset($pagination['per_page']) ? (int) $pagination['per_page'] : 20;
?>
<div class="wrap stlm-wrap">
    <h1><?php esc_html_e('Standalone Legacy Migration Plugin', 'streamtube-legacy-migrator'); ?></h1>
    <p><?php esc_html_e('Migrate old video post_content data into enhanced wizard meta fields.', 'streamtube-legacy-migrator'); ?></p>

    <div id="stlm-notice-area"></div>

    <div class="stlm-cards">
        <div class="stlm-card"><span class="stlm-label">Total Old Videos</span><strong id="stlm-total-old"><?php echo esc_html((string) ($stats['total_old'] ?? 0)); ?></strong></div>
        <div class="stlm-card"><span class="stlm-label">Eligible</span><strong id="stlm-eligible"><?php echo esc_html((string) ($stats['eligible'] ?? 0)); ?></strong></div>
        <div class="stlm-card"><span class="stlm-label">Migrated</span><strong id="stlm-migrated"><?php echo esc_html((string) ($stats['migrated'] ?? 0)); ?></strong></div>
        <div class="stlm-card"><span class="stlm-label">Failed</span><strong id="stlm-failed"><?php echo esc_html((string) ($stats['failed'] ?? 0)); ?></strong></div>
        <div class="stlm-card"><span class="stlm-label">Remaining</span><strong id="stlm-remaining"><?php echo esc_html((string) ($stats['remaining'] ?? 0)); ?></strong></div>
    </div>

    <div class="stlm-meta-line">
        <span><strong>Run ID:</strong> <code id="stlm-run-id"><?php echo esc_html((string) ($stats['run_id'] ?? '')); ?></code></span>
        <span><strong>Last Mode:</strong> <span id="stlm-last-mode"><?php echo esc_html((string) ($stats['last_mode'] ?? '')); ?></span></span>
        <span><strong>Last Run:</strong> <span id="stlm-last-run"><?php echo esc_html((string) ($stats['last_run_at'] ?? '')); ?></span></span>
        <span><strong>Last Post ID:</strong> <span id="stlm-last-post-id"><?php echo esc_html((string) ($stats['last_processed_post_id'] ?? 0)); ?></span></span>
    </div>

    <div class="stlm-actions">
        <button type="button" class="button button-primary" id="stlm-run-bulk">Run Bulk Migration</button>
        <label for="stlm-chunk-limit">Chunk size</label>
        <input type="number" id="stlm-chunk-limit" min="1" max="200" step="1" value="20" />
        <button type="button" class="button" id="stlm-run-chunk">Run Chunk Migration</button>
        <label class="stlm-force-label">
            <input type="checkbox" id="stlm-force-remap" value="1" />
            Force remap existing meta
        </label>
        <label class="stlm-force-label">
            <input type="checkbox" id="stlm-dry-run" value="1" />
            Dry run (no save)
        </label>
        <button type="button" class="button" id="stlm-refresh">Refresh</button>
    </div>

    <h2>Single Video Migration</h2>
    <div class="stlm-table-controls">
        <label for="stlm-filter-status">Filter status</label>
        <select id="stlm-filter-status">
            <option value="all">All</option>
            <option value="pending">Pending</option>
            <option value="migrated">Migrated</option>
            <option value="failed">Failed</option>
            <option value="skipped">Skipped</option>
            <option value="dry-run">Dry-run</option>
        </select>
        <label for="stlm-search-query">Search</label>
        <input type="text" id="stlm-search-query" placeholder="Search by ID or title" />
        <label for="stlm-per-page">Per page</label>
        <select id="stlm-per-page">
            <option value="20" <?php selected($per_page, 20); ?>>20</option>
            <option value="50" <?php selected($per_page, 50); ?>>50</option>
            <option value="100" <?php selected($per_page, 100); ?>>100</option>
        </select>
    </div>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Status</th>
                <th>Message</th>
                <th>Updated</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="stlm-rows">
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['post_id']); ?></td>
                    <td>
                        <?php if (! empty($row['edit_link'])) : ?>
                            <a href="<?php echo esc_url($row['edit_link']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $row['title']); ?></a>
                        <?php else : ?>
                            <?php echo esc_html((string) $row['title']); ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="stlm-status stlm-status-<?php echo esc_attr((string) $row['status']); ?>"><?php echo esc_html((string) $row['status']); ?></span></td>
                    <td><?php echo esc_html((string) $row['message']); ?></td>
                    <td><?php echo esc_html((string) $row['updated_at']); ?></td>
                    <td class="stlm-row-actions">
                        <button type="button" class="button stlm-preview-mapping" data-post-id="<?php echo esc_attr((string) $row['post_id']); ?>">Preview Mapping</button>
                        <button type="button" class="button stlm-show-migrated-fields" data-post-id="<?php echo esc_attr((string) $row['post_id']); ?>">Show Migrated Fields</button>
                        <button type="button" class="button stlm-run-single" data-post-id="<?php echo esc_attr((string) $row['post_id']); ?>">Migrate</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="stlm-pagination" id="stlm-pagination">
        <input type="hidden" id="stlm-current-page" value="<?php echo (int) $current_page; ?>" />
        <span class="stlm-pagination-info">Page <strong id="stlm-page-current"><?php echo (int) $current_page; ?></strong> of <strong id="stlm-page-total"><?php echo (int) $total_pages; ?></strong> (<strong id="stlm-total-rows"><?php echo (int) $total_rows; ?></strong> records)</span>
        <span class="stlm-pagination-links">
            <button type="button" class="button stlm-page-first">First</button>
            <button type="button" class="button stlm-page-prev">Previous</button>
            <button type="button" class="button stlm-page-next">Next</button>
            <button type="button" class="button stlm-page-last">Last</button>
        </span>
    </div>

    <h2>Field Details</h2>
    <div id="stlm-field-details" class="stlm-field-details">
        <p>Select a row action to view mapped/migrated fields.</p>
    </div>

    <h2>Migration Logs</h2>
    <div class="stlm-log-wrap">
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Run ID</th>
                    <th>Post ID</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="stlm-logs">
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($log['timestamp'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($log['run_id'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($log['post_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($log['mode'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($log['status'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($log['message'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

