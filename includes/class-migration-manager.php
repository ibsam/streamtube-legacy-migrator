<?php

if (! defined('ABSPATH')) {
    exit;
}

class STLM_Migration_Manager
{
    /**
     * @var STLM_Migration_Manager|null
     */
    private static $instance = null;

    /**
     * @var STLM_Legacy_Content_Parser
     */
    private $parser;

    /**
     * @return STLM_Migration_Manager
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->parser = new STLM_Legacy_Content_Parser();
    }

    /**
     * @return array<string,mixed>
     */
    public function get_dashboard_payload($filter = 'all', $page = 1, $per_page = 20)
    {
        $legacy_ids = $this->get_legacy_video_ids();
        $status_map = $this->get_status_map();
        $state = $this->get_state();

        $rows_all = array();
        $eligible_count = 0;
        $migrated_count = 0;
        $failed_count = 0;

        foreach ($legacy_ids as $post_id) {
            $row = $this->build_row_data($post_id, $status_map);
            if ($row['eligible']) {
                $eligible_count++;
            }
            if ($row['status'] === 'migrated') {
                $migrated_count++;
            } elseif ($row['status'] === 'failed') {
                $failed_count++;
            }
            $rows_all[] = $row;
        }

        if ($filter !== 'all') {
            $rows_all = array_values(array_filter($rows_all, function ($row) use ($filter) {
                return $row['status'] === $filter;
            }));
        }

        $page = max(1, (int) $page);
        $per_page = max(1, min(100, (int) $per_page));
        $total_rows = count($rows_all);
        $offset = ($page - 1) * $per_page;
        $rows = array_slice($rows_all, $offset, $per_page);

        $stats = array(
            'total_old' => count($legacy_ids),
            'eligible' => $eligible_count,
            'migrated' => $migrated_count,
            'failed' => $failed_count,
            'remaining' => max(0, count($legacy_ids) - $migrated_count),
            'last_run_at' => isset($state['last_run_at']) ? (string) $state['last_run_at'] : '',
            'last_mode' => isset($state['last_mode']) ? (string) $state['last_mode'] : '',
            'last_processed_post_id' => isset($state['last_processed_post_id']) ? (int) $state['last_processed_post_id'] : 0,
            'run_id' => isset($state['run_id']) ? (string) $state['run_id'] : '',
        );

        return array(
            'stats' => $stats,
            'rows' => $rows,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total_rows' => $total_rows,
                'total_pages' => (int) ceil($total_rows / $per_page),
            ),
            'logs' => $this->get_logs(150),
        );
    }

    /**
     * Preview which fields will be mapped from legacy content.
     *
     * @param int $post_id
     * @return array<string,mixed>
     */
    public function preview_mapping($post_id)
    {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'video') {
            return array(
                'post_id' => $post_id,
                'title' => '',
                'is_legacy' => false,
                'field_count' => 0,
                'fields' => array(),
                'message' => 'Post not found or not video.',
            );
        }

        $content = (string) $post->post_content;
        if (! $this->is_legacy_content($content)) {
            return array(
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'is_legacy' => false,
                'field_count' => 0,
                'fields' => array(),
                'message' => 'Legacy block content not found.',
            );
        }

        $mapped = $this->parser->parse($content);

        $film_title = isset($mapped['enhanced_film_title']) && $mapped['enhanced_film_title'] !== '' ? $mapped['enhanced_film_title'] : get_the_title($post_id);
        $release_date = isset($mapped['enhanced_original_release_date']) ? $mapped['enhanced_original_release_date'] : '';
        $release_year = $release_date !== '' && strtotime($release_date) !== false ? date('Y', strtotime($release_date)) : get_the_date('Y', $post_id);
        $directors = isset($mapped['enhanced_directors']) ? (string) $mapped['enhanced_directors'] : '';

        if (! empty($mapped['enhanced_poster_9_16']) && is_string($mapped['enhanced_poster_9_16'])) {
            $mapped['enhanced_poster_9_16_new_filename'] = STLM_Image_Renamer::build_media_filename_from_parts(
                $film_title,
                $release_year,
                $directors,
                'poster',
                basename(wp_parse_url($mapped['enhanced_poster_9_16'], PHP_URL_PATH))
            );
        }
        if (! empty($mapped['enhanced_poster_title_image_16_9']) && is_string($mapped['enhanced_poster_title_image_16_9'])) {
            $mapped['enhanced_poster_title_image_16_9_new_filename'] = STLM_Image_Renamer::build_media_filename_from_parts(
                $film_title,
                $release_year,
                $directors,
                'title_image',
                basename(wp_parse_url($mapped['enhanced_poster_title_image_16_9'], PHP_URL_PATH))
            );
        }
        if (! empty($mapped['enhanced_stills_gallery']) && is_array($mapped['enhanced_stills_gallery'])) {
            $new_filenames = array();
            $idx = 1;
            foreach ($mapped['enhanced_stills_gallery'] as $url) {
                if (is_string($url) && $url !== '') {
                    $new_filenames[] = STLM_Image_Renamer::build_media_filename_from_parts(
                        $film_title,
                        $release_year,
                        $directors,
                        'STILL_' . $idx,
                        basename(wp_parse_url($url, PHP_URL_PATH))
                    );
                }
                $idx++;
            }
            $mapped['enhanced_stills_gallery_new_filenames'] = $new_filenames;
        }

        return array(
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'is_legacy' => true,
            'field_count' => count($mapped),
            'fields' => $mapped,
            'message' => empty($mapped) ? 'No mapped fields extracted.' : 'Preview generated successfully.',
        );
    }

    /**
     * Return currently saved enhanced fields for a post.
     *
     * @param int $post_id
     * @return array<string,mixed>
     */
    public function get_migrated_fields($post_id)
    {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'video') {
            return array(
                'post_id' => $post_id,
                'title' => '',
                'field_count' => 0,
                'fields' => array(),
                'message' => 'Post not found or not video.',
            );
        }

        $fields_to_check = array(
            'enhanced_directors',
            'enhanced_synopsis',
            'enhanced_writers',
            'enhanced_producers',
            'enhanced_composers',
            'enhanced_duration',
            'enhanced_genres',
            'enhanced_country',
            'enhanced_countries_of_production',
            'enhanced_language',
            'enhanced_aspect_ratio',
            'enhanced_poster_9_16',
            'enhanced_poster_title_image_16_9',
            'enhanced_stills_gallery',
        );

        $saved = array();
        foreach ($fields_to_check as $field) {
            $value = get_post_meta($post_id, '_' . $field, true);
            if (is_array($value) && ! empty($value)) {
                $saved[$field] = $value;
            } elseif (! is_array($value) && $value !== '') {
                $saved[$field] = $value;
            }
        }

        if (! empty($saved['enhanced_poster_9_16']) && is_string($saved['enhanced_poster_9_16'])) {
            $saved['enhanced_poster_9_16_filename'] = basename(wp_parse_url($saved['enhanced_poster_9_16'], PHP_URL_PATH));
        }
        if (! empty($saved['enhanced_poster_title_image_16_9']) && is_string($saved['enhanced_poster_title_image_16_9'])) {
            $saved['enhanced_poster_title_image_16_9_filename'] = basename(wp_parse_url($saved['enhanced_poster_title_image_16_9'], PHP_URL_PATH));
        }
        if (! empty($saved['enhanced_stills_gallery']) && is_array($saved['enhanced_stills_gallery'])) {
            $saved['enhanced_stills_gallery_filenames'] = array();
            foreach ($saved['enhanced_stills_gallery'] as $url) {
                if (is_string($url) && $url !== '') {
                    $saved['enhanced_stills_gallery_filenames'][] = basename(wp_parse_url($url, PHP_URL_PATH));
                }
            }
        }

        return array(
            'post_id' => $post_id,
            'title' => get_the_title($post_id),
            'field_count' => count($saved),
            'fields' => $saved,
            'message' => empty($saved) ? 'No migrated/saved enhanced fields found.' : 'Saved enhanced fields loaded.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function run_bulk($force = false, $dry_run = false)
    {
        @set_time_limit(0);
        $mode = $dry_run ? 'bulk-dry-run' : 'bulk';
        $run_id = $this->start_run($mode);
        $processed = 0;
        $migrated = 0;
        $failed = 0;
        $skipped = 0;
        $dry_run_count = 0;

        $ids = $this->get_legacy_video_ids();
        foreach ($ids as $post_id) {
            $result = $this->migrate_post((int) $post_id, $mode, (bool) $force, $run_id, (bool) $dry_run);
            $processed++;
            if ($result['status'] === 'migrated') {
                $migrated++;
            } elseif ($result['status'] === 'dry-run') {
                $dry_run_count++;
            } elseif ($result['status'] === 'failed') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return $this->finish_run($run_id, $mode, array(
            'processed' => $processed,
            'migrated' => $migrated,
            'failed' => $failed,
            'skipped' => $skipped,
            'dry_run' => $dry_run_count,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function run_chunk($limit = 20, $force = false, $dry_run = false)
    {
        @set_time_limit(0);
        $mode = $dry_run ? 'chunk-dry-run' : 'chunk';
        $run_id = $this->start_run($mode);
        $limit = max(1, min(200, (int) $limit));

        $processed = 0;
        $migrated = 0;
        $failed = 0;
        $skipped = 0;
        $dry_run_count = 0;

        $ids = $this->get_legacy_video_ids();
        $pending_ids = array();
        foreach ($ids as $post_id) {
            $row = $this->build_row_data((int) $post_id, $this->get_status_map());
            if ($row['status'] !== 'migrated') {
                $pending_ids[] = (int) $post_id;
            }
        }

        $batch = array_slice($pending_ids, 0, $limit);
        foreach ($batch as $post_id) {
            $result = $this->migrate_post($post_id, $mode, (bool) $force, $run_id, (bool) $dry_run);
            $processed++;
            if ($result['status'] === 'migrated') {
                $migrated++;
            } elseif ($result['status'] === 'dry-run') {
                $dry_run_count++;
            } elseif ($result['status'] === 'failed') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return $this->finish_run($run_id, $mode, array(
            'processed' => $processed,
            'migrated' => $migrated,
            'failed' => $failed,
            'skipped' => $skipped,
            'dry_run' => $dry_run_count,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function run_single($post_id, $force = false, $dry_run = false)
    {
        $post_id = (int) $post_id;
        $mode = $dry_run ? 'single-dry-run' : 'single';
        $run_id = $this->start_run($mode);
        $result = $this->migrate_post($post_id, $mode, (bool) $force, $run_id, (bool) $dry_run);
        return $this->finish_run($run_id, $mode, array(
            'processed' => 1,
            'migrated' => $result['status'] === 'migrated' ? 1 : 0,
            'failed' => $result['status'] === 'failed' ? 1 : 0,
            'skipped' => $result['status'] === 'skipped' ? 1 : 0,
            'dry_run' => $result['status'] === 'dry-run' ? 1 : 0,
            'result' => $result,
        ));
    }

    /**
     * @param int $post_id
     * @param string $mode
     * @param bool $force
     * @param string $run_id
     * @param bool $dry_run
     * @return array{status:string,message:string}
     */
    public function migrate_post($post_id, $mode = 'single', $force = false, $run_id = '', $dry_run = false)
    {
        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'video') {
            $this->set_post_status($post_id, 'failed', 'Post not found or not video.', $run_id);
            $this->append_log($run_id, $post_id, $mode, 'failed', 'Post not found or not video.', array());
            return array('status' => 'failed', 'message' => 'Post not found or not video.');
        }

        if (! $this->is_legacy_content((string) $post->post_content)) {
            $this->set_post_status($post_id, 'skipped', 'Legacy block content not found.', $run_id);
            $this->append_log($run_id, $post_id, $mode, 'skipped', 'Legacy block content not found.', array());
            return array('status' => 'skipped', 'message' => 'Legacy block content not found.');
        }

        if (! $force && $this->has_enhanced_meta($post_id)) {
            $this->set_post_status($post_id, 'skipped', 'Enhanced meta already present.', $run_id);
            $this->append_log($run_id, $post_id, $mode, 'skipped', 'Enhanced meta already present.', array());
            return array('status' => 'skipped', 'message' => 'Enhanced meta already present.');
        }

        $mapped = $this->parser->parse((string) $post->post_content);
        if (empty($mapped)) {
            $this->set_post_status($post_id, 'failed', 'No mapped fields extracted.', $run_id);
            $this->append_log($run_id, $post_id, $mode, 'failed', 'No mapped fields extracted.', array());
            return array('status' => 'failed', 'message' => 'No mapped fields extracted.');
        }

        if ($dry_run) {
            $field_count = count($mapped);
            $message = sprintf('Dry run successful (%d fields parsed, not saved).', $field_count);
            $this->set_post_status($post_id, 'dry-run', $message, $run_id);
            $this->append_log($run_id, $post_id, $mode, 'dry-run', $message, array_keys($mapped));
            return array('status' => 'dry-run', 'message' => $message);
        }

        // 1) Save all text/array meta first (so filename builder can use film_title, directors, release date)
        if (empty($mapped['enhanced_film_title'])) {
            $mapped['enhanced_film_title'] = get_the_title($post_id);
        }
        if (empty($mapped['enhanced_original_release_date'])) {
            $post_date = get_the_date('Y-m-d', $post_id);
            if ($post_date) {
                $mapped['enhanced_original_release_date'] = $post_date;
            }
        }

        $image_fields = array('enhanced_poster_9_16', 'enhanced_poster_title_image_16_9', 'enhanced_stills_gallery');
        foreach ($mapped as $field => $value) {
            if (in_array($field, $image_fields, true)) {
                continue;
            }
            $meta_key = '_' . $field;
            if (is_array($value)) {
                update_post_meta($post_id, $meta_key, array_values(array_filter($value)));
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        if (! empty($mapped['enhanced_country']) && empty($mapped['enhanced_countries_of_production'])) {
            update_post_meta($post_id, '_enhanced_countries_of_production', array($mapped['enhanced_country']));
        } elseif (! empty($mapped['enhanced_countries_of_production']) && ! is_array($mapped['enhanced_countries_of_production'])) {
            update_post_meta($post_id, '_enhanced_countries_of_production', array($mapped['enhanced_countries_of_production']));
        }

        // 2) Copy and rename images with wizard-style nomenclature; update meta with new URLs
        if (! empty($mapped['enhanced_poster_9_16']) && is_string($mapped['enhanced_poster_9_16'])) {
            $new_url = STLM_Image_Renamer::copy_and_rename($post_id, $mapped['enhanced_poster_9_16'], 'poster');
            if ($new_url !== false) {
                update_post_meta($post_id, '_enhanced_poster_9_16', $new_url);
            } else {
                update_post_meta($post_id, '_enhanced_poster_9_16', $mapped['enhanced_poster_9_16']);
            }
        }
        if (! empty($mapped['enhanced_poster_title_image_16_9']) && is_string($mapped['enhanced_poster_title_image_16_9'])) {
            $new_url = STLM_Image_Renamer::copy_and_rename($post_id, $mapped['enhanced_poster_title_image_16_9'], 'title_image');
            if ($new_url !== false) {
                update_post_meta($post_id, '_enhanced_poster_title_image_16_9', $new_url);
            } else {
                update_post_meta($post_id, '_enhanced_poster_title_image_16_9', $mapped['enhanced_poster_title_image_16_9']);
            }
        }
        if (! empty($mapped['enhanced_stills_gallery']) && is_array($mapped['enhanced_stills_gallery'])) {
            $new_stills = array();
            $idx = 1;
            foreach ($mapped['enhanced_stills_gallery'] as $url) {
                if (! is_string($url) || $url === '') {
                    continue;
                }
                $new_url = STLM_Image_Renamer::copy_and_rename($post_id, $url, 'STILL_' . $idx);
                $new_stills[] = $new_url !== false ? $new_url : $url;
                $idx++;
            }
            update_post_meta($post_id, '_enhanced_stills_gallery', $new_stills);
        }

        $field_count = count($mapped);
        $message = sprintf('Migrated successfully (%d fields).', $field_count);
        $this->set_post_status($post_id, 'migrated', $message, $run_id);
        $this->append_log($run_id, $post_id, $mode, 'success', $message, array_keys($mapped));

        return array('status' => 'migrated', 'message' => $message);
    }

    /**
     * @return array<int,int>
     */
    public function get_legacy_video_ids()
    {
        $ids = get_posts(array(
            'post_type' => 'video',
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ));

        if (! is_array($ids)) {
            return array();
        }

        $legacy_ids = array();
        foreach ($ids as $id) {
            $content = (string) get_post_field('post_content', (int) $id);
            if ($this->is_legacy_content($content)) {
                $legacy_ids[] = (int) $id;
            }
        }
        return $legacy_ids;
    }

    /**
     * @param string $content
     * @return bool
     */
    private function is_legacy_content($content)
    {
        if ($content === '' || strpos($content, '<!-- wp:') === false) {
            return false;
        }
        $markers = array('Directed by', 'Writer(s):', '<!-- wp:gallery', '<!-- wp:image');
        foreach ($markers as $marker) {
            if (stripos($content, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $post_id
     * @return bool
     */
    private function has_enhanced_meta($post_id)
    {
        $keys = array(
            '_enhanced_directors',
            '_enhanced_synopsis',
            '_enhanced_poster_9_16',
            '_enhanced_stills_gallery',
            '_enhanced_writers',
            '_enhanced_producers',
            '_enhanced_duration',
            '_enhanced_genres',
            '_enhanced_country',
            '_enhanced_language',
            '_enhanced_aspect_ratio',
        );
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (is_array($value) && ! empty($value)) {
                return true;
            }
            if (! is_array($value) && $value !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $post_id
     * @param array<string,mixed> $status_map
     * @return array<string,mixed>
     */
    private function build_row_data($post_id, array $status_map)
    {
        $post = get_post($post_id);
        $title = $post ? get_the_title($post_id) : sprintf('Video #%d', $post_id);
        $stored = isset($status_map[$post_id]) && is_array($status_map[$post_id]) ? $status_map[$post_id] : array();

        $status = isset($stored['status']) ? (string) $stored['status'] : 'pending';
        $message = isset($stored['message']) ? (string) $stored['message'] : '';
        $updated_at = isset($stored['updated_at']) ? (string) $stored['updated_at'] : '';

        if ($status === 'pending' && $this->has_enhanced_meta($post_id)) {
            $status = 'migrated';
            $message = $message !== '' ? $message : 'Enhanced meta already exists.';
        }

        return array(
            'post_id' => (int) $post_id,
            'title' => $title !== '' ? $title : sprintf('Video #%d', $post_id),
            'status' => $status,
            'message' => $message,
            'updated_at' => $updated_at,
            'eligible' => ! $this->has_enhanced_meta($post_id),
            'edit_link' => get_edit_post_link($post_id, 'raw'),
        );
    }

    /**
     * @param string $run_id
     * @param string $mode
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function finish_run($run_id, $mode, array $summary)
    {
        $state = $this->get_state();
        $state['run_id'] = $run_id;
        $state['last_mode'] = $mode;
        $state['last_run_at'] = current_time('mysql');

        if (! empty($summary['result']) && isset($summary['result']['status'])) {
            // keep existing last_processed_post_id from migrate_post status setter.
        }

        update_option(STLM_OPTION_STATE, $state, false);
        return array(
            'run_id' => $run_id,
            'mode' => $mode,
            'summary' => $summary,
            'payload' => $this->get_dashboard_payload(),
        );
    }

    /**
     * @param string $mode
     * @return string
     */
    private function start_run($mode)
    {
        $state = $this->get_state();
        $run_id = $mode . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $state['run_id'] = $run_id;
        $state['last_mode'] = $mode;
        update_option(STLM_OPTION_STATE, $state, false);
        return $run_id;
    }

    /**
     * @param int $post_id
     * @param string $status
     * @param string $message
     * @param string $run_id
     */
    private function set_post_status($post_id, $status, $message, $run_id)
    {
        $map = $this->get_status_map();
        $map[(int) $post_id] = array(
            'status' => (string) $status,
            'message' => (string) $message,
            'updated_at' => current_time('mysql'),
            'run_id' => (string) $run_id,
        );
        update_option(STLM_OPTION_STATUS_MAP, $map, false);

        $state = $this->get_state();
        $state['last_processed_post_id'] = (int) $post_id;
        update_option(STLM_OPTION_STATE, $state, false);
    }

    /**
     * @param string $run_id
     * @param int $post_id
     * @param string $mode
     * @param string $status
     * @param string $message
     * @param array<int|string,mixed> $fields
     */
    private function append_log($run_id, $post_id, $mode, $status, $message, array $fields)
    {
        $logs = get_option(STLM_OPTION_LOGS, array());
        if (! is_array($logs)) {
            $logs = array();
        }

        $logs[] = array(
            'run_id' => (string) $run_id,
            'post_id' => (int) $post_id,
            'mode' => (string) $mode,
            'status' => (string) $status,
            'message' => (string) $message,
            'fields' => array_values($fields),
            'timestamp' => current_time('mysql'),
        );

        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }

        update_option(STLM_OPTION_LOGS, $logs, false);
    }

    /**
     * @return array<string,mixed>
     */
    private function get_state()
    {
        $state = get_option(STLM_OPTION_STATE, array());
        if (! is_array($state)) {
            $state = array();
        }
        return wp_parse_args($state, array(
            'run_id' => '',
            'last_run_at' => '',
            'last_mode' => '',
            'last_processed_post_id' => 0,
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_logs($limit = 100)
    {
        $logs = get_option(STLM_OPTION_LOGS, array());
        if (! is_array($logs)) {
            return array();
        }
        $limit = max(1, min(1000, (int) $limit));
        return array_reverse(array_slice($logs, -1 * $limit));
    }

    /**
     * @return array<string,mixed>
     */
    private function get_status_map()
    {
        $map = get_option(STLM_OPTION_STATUS_MAP, array());
        return is_array($map) ? $map : array();
    }
}

