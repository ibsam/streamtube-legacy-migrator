<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Standalone helper: copy legacy images into uploads with wizard-style filenames.
 * Uses only post/meta (film_title, release_year, directors) — no wizard plugin dependency.
 */
class STLM_Image_Renamer
{
    /**
     * Build filename base: {film_title}_{release_year}_{directors}_{SUFFIX}.ext
     * Parts from post title and meta (same pattern as wizard).
     *
     * @param int $post_id
     * @param string $suffix e.g. poster, title_image, STILL_1
     * @param string $original_name optional original filename for extension
     * @return string
     */
    public static function build_media_filename($post_id, $suffix, $original_name = '')
    {
        $parts = array();

        $film_title = get_post_meta($post_id, '_enhanced_film_title', true);
        if ($film_title === '' || $film_title === false) {
            $film_title = get_the_title($post_id);
        }
        if (! empty($film_title)) {
            $parts[] = self::slug_component((string) $film_title);
        }

        $release_date = get_post_meta($post_id, '_enhanced_original_release_date', true);
        if (! empty($release_date)) {
            $ts = strtotime((string) $release_date);
            if ($ts !== false) {
                $parts[] = self::slug_component(date('Y', $ts));
            }
        } else {
            $post_date = get_the_date('Y', $post_id);
            if (! empty($post_date)) {
                $parts[] = self::slug_component($post_date);
            }
        }

        $directors = get_post_meta($post_id, '_enhanced_directors', true);
        if (! empty($directors)) {
            $directors_clean = str_replace(',', ' and ', (string) $directors);
            $parts[] = self::slug_component($directors_clean);
        }

        if (empty($parts)) {
            $parts[] = 'movie';
        }

        $normalized_suffix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $suffix));
        $normalized_suffix = trim(preg_replace('/_+/', '_', $normalized_suffix), '_');
        $parts[] = $normalized_suffix;

        $base = implode('_', array_filter($parts));

        if ($original_name !== '') {
            $ext = pathinfo((string) $original_name, PATHINFO_EXTENSION);
            if (! empty($ext)) {
                $base .= '.' . strtolower($ext);
            }
        }

        return $base;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function slug_component($value)
    {
        $slug = sanitize_title($value);
        $slug = str_replace('-', '_', $slug);
        $slug = preg_replace('/_+/', '_', $slug);
        return trim($slug, '_');
    }

    /**
     * Copy image from source URL to uploads with new name; create attachment; return new URL.
     *
     * @param int $post_id Video post ID (for attachment parent and filename parts).
     * @param string $source_url Existing image URL (on this site).
     * @param string $suffix e.g. poster, title_image, STILL_1
     * @return string|false New attachment URL or false on failure
     */
    public static function copy_and_rename($post_id, $source_url, $suffix)
    {
        $source_url = esc_url_raw(trim((string) $source_url));
        if ($source_url === '') {
            return false;
        }

        $upload_dir = wp_upload_dir();
        if (! empty($upload_dir['error'])) {
            return false;
        }

        $baseurl = untrailingslashit($upload_dir['baseurl']);
        $basedir = untrailingslashit($upload_dir['basedir']);

        if (strpos($source_url, $baseurl) !== 0) {
            return false;
        }

        $rel_path = substr($source_url, strlen($baseurl));
        $rel_path = ltrim($rel_path, '/');
        $rel_path = str_replace('/', DIRECTORY_SEPARATOR, $rel_path);
        $source_path = $basedir . DIRECTORY_SEPARATOR . $rel_path;

        if (! is_readable($source_path) || ! is_file($source_path)) {
            return false;
        }

        $original_name = basename($source_path);
        $new_filename = self::build_media_filename($post_id, $suffix, $original_name);
        if ($new_filename === '') {
            return false;
        }

        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $ext = 'jpg';
        }
        $new_filename = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $new_filename) . '.' . strtolower($ext);

        $subdir = trim(dirname($rel_path), DIRECTORY_SEPARATOR);
        if ($subdir === '.' || $subdir === '') {
            $subdir = date('Y') . '/' . date('m');
        }
        $dest_dir = $basedir . DIRECTORY_SEPARATOR . $subdir;
        if (! is_dir($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        if (! is_dir($dest_dir) || ! is_writable($dest_dir)) {
            return false;
        }

        $dest_path = $dest_dir . DIRECTORY_SEPARATOR . $new_filename;
        $unique_name = wp_unique_filename($dest_dir, $new_filename);
        if ($unique_name === '') {
            return false;
        }
        $dest_path = $dest_dir . DIRECTORY_SEPARATOR . $unique_name;

        if (@copy($source_path, $dest_path) !== true) {
            return false;
        }

        $file_type = wp_check_filetype($dest_path, null);
        $attachment = array(
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name(pathinfo($dest_path, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment($attachment, $dest_path, $post_id);
        if (is_wp_error($attach_id) || $attach_id === 0) {
            @unlink($dest_path);
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $dest_path));

        $new_url = wp_get_attachment_url($attach_id);
        return $new_url ? $new_url : false;
    }
}
