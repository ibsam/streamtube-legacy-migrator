<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parses legacy Gutenberg post_content and maps fields for enhanced wizard meta.
 */
class STLM_Legacy_Content_Parser
{
    /**
     * Parse legacy post content.
     *
     * @param string $content Post content.
     * @return array<string,mixed>
     */
    public function parse($content)
    {
        $content = (string) $content;
        if ($content === '' || strpos($content, '<!-- wp:') === false) {
            return array();
        }

        $images = array();
        $gallery_images = array();
        $paragraphs = array();
        $tables = array();

        if (function_exists('parse_blocks')) {
            $blocks = parse_blocks($content);
            $this->walk_blocks($blocks, $images, $gallery_images, $paragraphs, $tables);
        } else {
            $this->fallback_extract($content, $images, $gallery_images, $paragraphs);
        }

        $images = array_values(array_unique(array_filter($images)));
        $gallery_images = array_values(array_unique(array_filter($gallery_images)));
        $paragraphs = array_values(array_filter($paragraphs));

        $mapped = array();

        if (! empty($images[0])) {
            $mapped['enhanced_poster_9_16'] = esc_url_raw($images[0]);
            // Old layout usually has one key-art image. Keep both populated.
            $mapped['enhanced_poster_title_image_16_9'] = esc_url_raw($images[0]);
        }

        if (! empty($gallery_images)) {
            $mapped['enhanced_stills_gallery'] = array_map('esc_url_raw', $gallery_images);
        }

        $director_index = -1;
        foreach ($paragraphs as $idx => $paragraph_html) {
            $text = $this->clean_text($paragraph_html);
            if ($text !== '' && stripos($text, 'Directed by') !== false) {
                $director_value = preg_replace('/^.*?Directed by\s*/i', '', $text);
                $director_value = trim($director_value, " \t\n\r\0\x0B,");
                if ($director_value !== '') {
                    $mapped['enhanced_directors'] = $director_value;
                    $director_index = $idx;
                }
                break;
            }
        }

        if ($director_index >= 0) {
            for ($i = $director_index + 1; $i < count($paragraphs); $i++) {
                $candidate = $this->clean_text($paragraphs[$i]);
                if ($candidate === '') {
                    continue;
                }
                if ($this->contains_detail_label($candidate) || stripos($candidate, 'Directed by') !== false) {
                    continue;
                }
                $mapped['enhanced_synopsis'] = $candidate;
                break;
            }
        }

        foreach ($paragraphs as $paragraph_html) {
            if ($this->contains_detail_label($paragraph_html)) {
                $details = $this->extract_details($paragraph_html);
                if (! empty($details)) {
                    $mapped = array_merge($mapped, $details);
                    break;
                }
            }
        }

        // Director bio and photo (from table, reusable block, or paragraph).
        $director_name = isset($mapped['enhanced_directors']) ? (string) $mapped['enhanced_directors'] : '';
        if ($director_name !== '' && ! empty($tables)) {
            foreach ($tables as $table_html) {
                $plain = $this->clean_text($table_html);
                if ($plain === '') {
                    continue;
                }
                // Match if table contains director name; bio = text after first occurrence.
                if (stripos($plain, $director_name) === false) {
                    continue;
                }
                $pos = stripos($plain, $director_name);
                $after = substr($plain, $pos + strlen($director_name));
                $bio = trim(preg_replace('/^\s*\n+/', "\n", (string) $after));
                $bio = trim($bio, " \t\n\r\0\x0B-");
                if ($bio !== '' && strlen($bio) > 5 && ! $this->contains_detail_label($bio)) {
                    $mapped['enhanced_director_bio'] = $bio;
                    break;
                }
                // Fallback: first line = name, rest = bio.
                $lines = preg_split("/\n+/", $plain);
                if (is_array($lines) && count($lines) > 1) {
                    $first_line = trim((string) $lines[0]);
                    if ($first_line !== '' && stripos($first_line, $director_name) !== false) {
                        array_shift($lines);
                        $bio_lines = array();
                        foreach ($lines as $line) {
                            $line = trim((string) $line);
                            if ($line !== '') {
                                $bio_lines[] = $line;
                            }
                        }
                        $bio = trim(implode("\n", $bio_lines));
                        if ($bio !== '' && ! $this->contains_detail_label($bio)) {
                            $mapped['enhanced_director_bio'] = $bio;
                            break;
                        }
                    }
                }
            }
        }

        // Fallback: director bio in a paragraph (e.g. director name + bio in same block).
        if ($director_name !== '' && empty($mapped['enhanced_director_bio'])) {
            foreach ($paragraphs as $paragraph_html) {
                $plain = $this->clean_text($paragraph_html);
                if ($plain === '' || stripos($plain, $director_name) === false) {
                    continue;
                }
                if ($this->contains_detail_label($plain) || stripos($plain, 'Directed by') !== false) {
                    continue;
                }
                $pos = stripos($plain, $director_name);
                $after = substr($plain, $pos + strlen($director_name));
                $bio = trim(preg_replace('/^\s*\n+/', "\n", (string) $after));
                $bio = trim($bio, " \t\n\r\0\x0B-");
                if ($bio !== '' && strlen($bio) > 5 && ! $this->contains_detail_label($bio)) {
                    $mapped['enhanced_director_bio'] = $bio;
                    break;
                }
            }
        }

        // Director photo: last image in block order is usually the director headshot.
        // Set when we have a director and more than one image (no bio required).
        if ($director_name !== '' && count($images) > 1) {
            $last_image = end($images);
            if ($last_image) {
                $mapped['enhanced_director_photo'] = esc_url_raw((string) $last_image);
            }
            reset($images);
        }

        return $mapped;
    }

    /**
     * Recursively walk parsed blocks.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,string> $images
     * @param array<int,string> $gallery_images
     * @param array<int,string> $paragraphs
     * @param array<int,string> $tables
     */
    private function walk_blocks(array $blocks, array &$images, array &$gallery_images, array &$paragraphs, array &$tables)
    {
        foreach ($blocks as $block) {
            $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();

            if ($name === 'core/image') {
                $url = $this->extract_image_url_from_attrs($attrs);
                if ($url !== '') {
                    $images[] = $url;
                }
            } elseif ($name === 'core/gallery') {
                if (isset($attrs['ids']) && is_array($attrs['ids'])) {
                    foreach ($attrs['ids'] as $id) {
                        $url = wp_get_attachment_url((int) $id);
                        if ($url) {
                            $gallery_images[] = $url;
                        }
                    }
                }
            } elseif ($name === 'core/paragraph') {
                $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
                if ($html !== '') {
                    $paragraphs[] = $html;
                }
            } elseif ($name === 'core/table') {
                $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
                if ($html !== '') {
                    $tables[] = $html;
                }
            } elseif ($name === 'core/block' && ! empty($attrs['ref']) && function_exists('parse_blocks')) {
                // Reusable block: director bio/photo often live inside a ref'd block.
                $ref_id = (int) $attrs['ref'];
                if ($ref_id > 0) {
                    $ref_post = get_post($ref_id);
                    if ($ref_post && $ref_post->post_type === 'wp_block' && (string) $ref_post->post_content !== '') {
                        $ref_blocks = parse_blocks($ref_post->post_content);
                        if (! empty($ref_blocks)) {
                            $this->walk_blocks($ref_blocks, $images, $gallery_images, $paragraphs, $tables);
                        }
                    }
                }
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && ! empty($block['innerBlocks'])) {
                $this->walk_blocks($block['innerBlocks'], $images, $gallery_images, $paragraphs, $tables);
            }
        }

        // If gallery not resolved from ids, collect trailing images as stills.
        if (empty($gallery_images) && count($images) > 1) {
            for ($i = 1; $i < count($images); $i++) {
                $gallery_images[] = $images[$i];
            }
        }
    }

    /**
     * Fallback extraction for environments without parse_blocks.
     */
    private function fallback_extract($content, array &$images, array &$gallery_images, array &$paragraphs)
    {
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $img_matches)) {
            foreach ($img_matches[1] as $src) {
                $images[] = esc_url_raw($src);
            }
        }

        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $p_matches)) {
            foreach ($p_matches[1] as $p_html) {
                $paragraphs[] = (string) $p_html;
            }
        }

        if (count($images) > 1) {
            for ($i = 1; $i < count($images); $i++) {
                $gallery_images[] = $images[$i];
            }
        }
    }

    /**
     * @param array<string,mixed> $attrs
     * @return string
     */
    private function extract_image_url_from_attrs(array $attrs)
    {
        if (! empty($attrs['id'])) {
            $url = wp_get_attachment_url((int) $attrs['id']);
            if ($url) {
                return esc_url_raw($url);
            }
        }

        if (! empty($attrs['url'])) {
            return esc_url_raw((string) $attrs['url']);
        }

        return '';
    }

    /**
     * @param string $input
     * @return string
     */
    private function clean_text($input)
    {
        $value = str_replace(array('<br>', '<br/>', '<br />'), "\n", (string) $input);
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\x{00A0}/u', ' ', $value);
        $value = preg_replace("/[ \t]+/", ' ', $value);
        $value = preg_replace("/\n+/", "\n", $value);
        return trim((string) $value);
    }

    /**
     * @param string $text
     * @return bool
     */
    private function contains_detail_label($text)
    {
        $labels = array('Writer', 'Producer', 'Composer', 'Duration', 'Genres', 'Country', 'Language', 'Aspect Ratio');
        foreach ($labels as $label) {
            if (stripos((string) $text, $label . ':') !== false || stripos((string) $text, $label . '(s):') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $paragraph_html
     * @return array<string,string>
     */
    private function extract_details($paragraph_html)
    {
        $plain = $this->clean_text($paragraph_html);
        if ($plain === '') {
            return array();
        }

        $mapped = array();

        $writer = $this->extract_label_value($plain, 'Writer(?:\\(s\\))?');
        if ($writer !== '') {
            $mapped['enhanced_writers'] = $writer;
        }

        $producer = $this->extract_label_value($plain, 'Producer(?:\\(s\\))?');
        if ($producer !== '') {
            $mapped['enhanced_producers'] = $producer;
        }

        $composer = $this->extract_label_value($plain, 'Composer(?:\\(s\\))?');
        if ($composer !== '') {
            $mapped['enhanced_composers'] = $composer;
        }

        $duration = $this->extract_label_value($plain, 'Duration');
        if ($duration !== '') {
            $mapped['enhanced_duration'] = $duration;
        }

        $genres = $this->extract_label_value($plain, 'Genres?');
        if ($genres !== '') {
            $mapped['enhanced_genres'] = $genres;
        }

        $country = $this->extract_label_value($plain, 'Country');
        if ($country !== '') {
            $mapped['enhanced_country'] = $country;
            $mapped['enhanced_countries_of_production'] = $country;
        }

        $language = $this->extract_label_value($plain, 'Language');
        if ($language !== '') {
            $mapped['enhanced_language'] = $language;
        }

        $aspect = $this->extract_label_value($plain, 'Aspect\\s*Ratio');
        if ($aspect !== '') {
            $normalized_aspect = $this->normalize_aspect_ratio($aspect);
            if ($normalized_aspect !== '') {
                $mapped['enhanced_aspect_ratio'] = $normalized_aspect;
            }
        }

        return $mapped;
    }

    /**
     * @param string $plain
     * @param string $label_regex
     * @return string
     */
    private function extract_label_value($plain, $label_regex)
    {
        $pattern = '/(?:^|\n)\s*' . $label_regex . ':\s*(.+?)(?=\n[\w\s\(\)]+:\s|$)/i';
        if (preg_match($pattern, $plain, $matches)) {
            return trim((string) $matches[1], " \t\n\r\0\x0B,");
        }
        return '';
    }

    /**
     * @param string $ratio
     * @return string
     */
    private function normalize_aspect_ratio($ratio)
    {
        $value = strtolower(trim((string) $ratio));
        $value = str_replace(' ', '', $value);

        if ($value === '16:9' || strpos($value, '1.78') !== false) {
            return '1.78';
        }
        if ($value === '4:3' || strpos($value, '1.33') !== false) {
            return '1.33';
        }
        if (strpos($value, '2.35') !== false) {
            return '2.35';
        }
        if (strpos($value, '2.39') !== false || strpos($value, '2.40') !== false) {
            return '2.39';
        }

        return '';
    }
}

