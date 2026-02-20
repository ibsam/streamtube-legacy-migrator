<?php
if (! defined('ABSPATH')) {
    exit;
}

$stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : array();
$rows = isset($payload['rows']) && is_array($payload['rows']) ? $payload['rows'] : array();
$logs = isset($payload['logs']) && is_array($payload['logs']) ? $payload['logs'] : array();
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
                    <td><button type="button" class="button stlm-run-single" data-post-id="<?php echo esc_attr((string) $row['post_id']); ?>">Migrate</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

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

