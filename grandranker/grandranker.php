<?php
/**
 * Plugin Name: Grandranker
 * Plugin URI:  https://grandranker.com
 * Description: Automatically publish and update SEO-optimized articles from Grandranker — the AI content platform that helps you rank higher on Google and AI search engines.
 * Version:     1.6.1
 * Author:      Grandranker
 * License:     MIT
 */

defined('ABSPATH') || exit;

const GRANDRANKER_VERSION       = '1.6.1';
const GRANDRANKER_API_BASE      = 'https://app.grandranker.com';
// Body images on these hosts are copied into the Media Library (task 934).
const GRANDRANKER_IMAGE_HOSTS   = ['cdn.grandranker.com'];
// One request downloads at most this many new images, and starts none after
// this many seconds, so a post with many images answers inside Grandranker's
// 60 s timeout. The rest keep their CDN URL until the next push or
// mirror-images call; images already copied are reused, never downloaded again.
const GRANDRANKER_MAX_DOWNLOADS_PER_REQUEST = 8;
const GRANDRANKER_DOWNLOAD_SECONDS_PER_REQUEST = 30;
const GRANDRANKER_DOWNLOAD_TIMEOUT = 20;
const GRANDRANKER_OPT_TOKEN  = 'grandranker_api_token';
const GRANDRANKER_OPT_SECRET = 'grandranker_signing_secret';
const GRANDRANKER_OPT_SITE_URL = 'grandranker_site_url';
const GRANDRANKER_OPT_DEFAULT_AUTHOR = 'grandranker_default_author';
const GRANDRANKER_OPT_POST_STATUS    = 'grandranker_post_status';
const GRANDRANKER_OPT_SEO_PLUGIN     = 'grandranker_seo_plugin';
const GRANDRANKER_OPT_INDEXNOW_KEY   = 'grandranker_indexnow_key';

// ---------------------------------------------------------------------------
// IndexNow — Grandranker sends the site's key with each article, and this
// plugin serves it as /{key}.txt so Bing & co. can verify ownership before
// crawling newly published articles.
// ---------------------------------------------------------------------------

function grandranker_store_indexnow_key($payload) {
    $key = $payload['data']['indexnow_key'] ?? null;
    if ($key && preg_match('/^[a-f0-9]{8,128}$/i', $key)) {
        update_option(GRANDRANKER_OPT_INDEXNOW_KEY, $key);
    }
}

add_action('init', function () {
    $key = get_option(GRANDRANKER_OPT_INDEXNOW_KEY);
    if (empty($key)) return;

    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($path !== $key . '.txt') return;

    // Stray output from another plugin would corrupt the key file's contents.
    if (headers_sent()) return;

    header('Content-Type: text/plain; charset=utf-8');
    echo $key;
    exit;
});

// ---------------------------------------------------------------------------
// Connect — exchange the API token for an HMAC signing secret.
// ---------------------------------------------------------------------------

function grandranker_connect($api_token) {
    update_option(GRANDRANKER_OPT_TOKEN, $api_token);
    update_option(GRANDRANKER_OPT_SITE_URL, home_url());

    $response = wp_remote_post(GRANDRANKER_API_BASE . '/api/v1/plugin/connect', [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body' => wp_json_encode(['site_url' => home_url()]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('grandranker_connect_failed', $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || empty($body['data']['signing_secret'])) {
        return new WP_Error(
            'grandranker_connect_failed',
            $body['error'] ?? "Connect failed (HTTP {$code})"
        );
    }

    update_option(GRANDRANKER_OPT_SECRET, $body['data']['signing_secret']);
    return true;
}

function grandranker_get_default_author() {
    $author_id = (int) get_option(GRANDRANKER_OPT_DEFAULT_AUTHOR, 0);
    if ($author_id && get_userdata($author_id)) {
        return $author_id;
    }
    return 0;
}

// Signed deliveries run with no logged-in user, so an unset author left posts
// with post_author 0 and no byline. Fall back to the user whose display name
// matches the article's author, then to the first administrator.
function grandranker_resolve_author($article) {
    $default_author = grandranker_get_default_author();
    if ($default_author) {
        return $default_author;
    }

    $name = trim((string) ($article['author_name'] ?? ''));
    if ($name !== '') {
        $match = get_users(['search' => $name, 'search_columns' => ['display_name'], 'number' => 1, 'fields' => 'ID']);
        if (!empty($match)) {
            return (int) $match[0];
        }
    }

    $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID']);
    return empty($admins) ? 0 : (int) $admins[0];
}

// ---------------------------------------------------------------------------
// REST endpoints exposed to Grandranker.
// ---------------------------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('grandranker/v1', '/articles', [
        'methods'             => 'POST',
        'callback'            => 'grandranker_receive_article',
        'permission_callback' => 'grandranker_verify_signature',
    ]);

    register_rest_route('grandranker/v1', '/articles/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'grandranker_unpublish_article',
        'permission_callback' => 'grandranker_verify_signature',
    ]);

    register_rest_route('grandranker/v1', '/articles/(?P<id>\d+)/schema', [
        'methods'             => 'POST',
        'callback'            => 'grandranker_update_schema',
        'permission_callback' => 'grandranker_verify_signature',
    ]);

    register_rest_route('grandranker/v1', '/articles/(?P<id>\d+)/mirror-images', [
        'methods'             => 'POST',
        'callback'            => 'grandranker_mirror_images',
        'permission_callback' => 'grandranker_verify_signature',
    ]);

    register_rest_route('grandranker/v1', '/media/dedupe', [
        'methods'             => 'POST',
        'callback'            => 'grandranker_dedupe_media',
        'permission_callback' => 'grandranker_verify_signature',
    ]);

    register_rest_route('grandranker/v1', '/ping', [
        'methods'             => 'POST',
        'callback'            => 'grandranker_ping',
        'permission_callback' => 'grandranker_verify_signature',
    ]);
});

function grandranker_ping() {
    return ['ok' => true, 'version' => GRANDRANKER_VERSION];
}

function grandranker_verify_signature(WP_REST_Request $request) {
    $secret = get_option(GRANDRANKER_OPT_SECRET);
    if (empty($secret)) {
        return new WP_Error('grandranker_not_connected', 'Plugin is not connected.', ['status' => 401]);
    }

    $provided = $request->get_header('x-grandranker-signature');
    if (empty($provided)) {
        return new WP_Error('grandranker_missing_signature', 'Missing signature header.', ['status' => 401]);
    }

    $body = $request->get_body();
    $expected = hash_hmac('sha256', $body, $secret);

    if (!hash_equals($expected, $provided)) {
        return new WP_Error('grandranker_invalid_signature', 'Invalid signature.', ['status' => 401]);
    }

    return true;
}

/**
 * wp_insert_post() gives an existing post today's date unless the date is
 * passed back in, so every update moved a live post to the top of the blog.
 * A draft is left alone: it should get its date when it goes live.
 */
function grandranker_kept_post_date($post_id): array {
    if (!in_array(get_post_status($post_id), ['publish', 'future'], true)) {
        return [];
    }

    return [
        'post_date'     => get_post_field('post_date', $post_id),
        'post_date_gmt' => get_post_field('post_date_gmt', $post_id),
    ];
}

function grandranker_receive_article(WP_REST_Request $request) {
    $payload = $request->get_json_params();
    $article = $payload['data']['articles'][0] ?? null;

    if (!$article || empty($article['title'])) {
        return new WP_Error('grandranker_bad_payload', 'No article in payload.', ['status' => 400]);
    }

    grandranker_store_indexnow_key($payload);

    $grandranker_id = $article['id'] ?? null;
    $existing_id = null;

    if ($grandranker_id) {
        $existing = get_posts([
            'meta_key'    => '_grandranker_id',
            'meta_value'  => $grandranker_id,
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if (!empty($existing)) {
            $existing_id = $existing[0];
        }
    }

    $post_data = [
        'post_title'   => wp_strip_all_tags($article['title']),
        'post_name'    => sanitize_title($article['slug'] ?? $article['title']),
        'post_content' => grandranker_clean_content_html($article['content_html'] ?? ''),
        'post_excerpt' => $article['meta_description'] ?? '',
        'post_status'  => grandranker_get_post_status(),
        'post_type'    => 'post',
        'meta_input'   => grandranker_build_meta_input($article),
    ];

    if ($existing_id) {
        $post_data['ID'] = $existing_id;
        $post_data += grandranker_kept_post_date($existing_id);
    }

    // Updates keep the post's current author unless a Default author is set.
    $author = $existing_id ? grandranker_get_default_author() : grandranker_resolve_author($article);
    if ($author) {
        $post_data['post_author'] = $author;
    }

    if (!empty($article['category'])) {
        $cat_id = grandranker_ensure_category($article['category']);
        if ($cat_id) $post_data['post_category'] = [$cat_id];
    }

    // wp_insert_post() expects slashed data and strips one level of slashes,
    // which corrupts backslashes in the JSON-LD schema meta (\" and \uXXXX).
    $post_id = wp_insert_post(wp_slash($post_data), true);

    if (is_wp_error($post_id)) {
        return new WP_Error('grandranker_insert_failed', $post_id->get_error_message(), ['status' => 500]);
    }

    if (!empty($article['tags']) && is_array($article['tags'])) {
        wp_set_post_tags($post_id, $article['tags']);
    }

    $media = grandranker_attach_media($post_id, $article);

    $status = $existing_id ? 200 : 201;
    return new WP_REST_Response([
        'post_id'        => $post_id,
        'url'            => get_permalink($post_id),
        'updated'        => (bool) $existing_id,
        'featured_image' => $media['featured_image'],
        'content_images' => $media['content_images'],
    ], $status);
}

function grandranker_clean_content_html($html) {
    $html = preg_replace('/<script\s+type=["\']application\/ld\+json["\']>.*?<\/script>\s*/s', '', $html);
    $html = preg_replace('/<link\s+rel=["\']alternate["\']\s+hreflang=["\'][^"\']*["\']\s+href=["\'][^"\']*["\']\s*\/?>\s*/i', '', $html);
    return ltrim($html);
}

function grandranker_get_post_status() {
    $status = get_option(GRANDRANKER_OPT_POST_STATUS, 'publish');
    $allowed = ['publish', 'draft', 'pending'];
    return in_array($status, $allowed, true) ? $status : 'publish';
}

function grandranker_detect_seo_plugin() {
    $setting = get_option(GRANDRANKER_OPT_SEO_PLUGIN, 'auto');

    if ($setting !== 'auto') {
        return $setting;
    }

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (is_plugin_active('wordpress-seo/wp-seo.php')) return 'yoast';
    if (is_plugin_active('seo-by-rank-math/rank-math.php')) return 'rankmath';
    if (is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')) return 'aioseo';

    return 'yoast';
}

function grandranker_build_meta_input($article) {
    $meta = [
        '_grandranker_id' => $article['id'] ?? null,
    ];

    $seo = grandranker_detect_seo_plugin();

    $meta_title = $article['meta_title'] ?? '';
    // Kept on our own key too: with no SEO plugin active nothing reads the
    // Yoast/Rank Math/AIOSEO keys, so the <title> filter below uses this one.
    if ($meta_title) {
        $meta['_grandranker_meta_title'] = $meta_title;
    }
    if ($meta_title && $seo !== 'none') {
        switch ($seo) {
            case 'rankmath':
                $meta['rank_math_title'] = $meta_title;
                break;
            case 'aioseo':
                $meta['_aioseo_title'] = $meta_title;
                break;
            default:
                $meta['_yoast_wpseo_title'] = $meta_title;
                break;
        }
    }

    $description = $article['meta_description'] ?? '';
    // Own key for the same reason as the SEO title: the wp_head tags below read it.
    if ($description) {
        $meta['_grandranker_meta_description'] = $description;
    }
    if ($description && $seo !== 'none') {
        switch ($seo) {
            case 'rankmath':
                $meta['rank_math_description'] = $description;
                break;
            case 'aioseo':
                $meta['_aioseo_description'] = $description;
                break;
            default:
                $meta['_yoast_wpseo_metadesc'] = $description;
                break;
        }
    }

    // Focus keyword — without it the SEO plugin's Focus Keyword field is empty
    // and every keyword check fails, even when the content is optimized.
    // (AIOSEO stores keyphrases in its own table, not post meta, so skip it.)
    $focus_keyword = $article['focus_keyword'] ?? '';
    if ($focus_keyword && $seo !== 'none') {
        switch ($seo) {
            case 'rankmath':
                $meta['rank_math_focus_keyword'] = $focus_keyword;
                break;
            case 'yoast':
                $meta['_yoast_wpseo_focuskw'] = $focus_keyword;
                break;
        }
    }

    if (!empty($article['json_ld_schema'])) {
        $meta['_grandranker_schema'] = wp_json_encode($article['json_ld_schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $meta;
}

add_action('wp_head', function () {
    if (!is_singular('post')) return;

    $schema = get_post_meta(get_the_ID(), '_grandranker_schema', true);
    if (empty($schema)) return;

    // Schema saved by plugin versions < 1.5.3 may have had its backslashes
    // stripped on save; never output JSON that no longer parses.
    $decoded_schema = json_decode($schema, true);
    if ($decoded_schema === null) return;

    $safe_schema = wp_json_encode(
        $decoded_schema,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Re-encoded JSON must not be HTML-escaped or the schema becomes invalid.
    echo '<script type="application/ld+json">' . "\n" . $safe_schema . "\n" . '</script>' . "\n";
}, 1);

// Task 866: with no SEO plugin nothing prints a meta description or Open Graph
// tags, so a description fixed in GrandRanker never reached the page and the
// Site Audit flagged it again after every republish.
add_action('wp_head', function () {
    if (!is_singular('post') || grandranker_active_seo_plugin() !== null) return;

    $post_id = get_the_ID();
    $title = get_post_meta($post_id, '_grandranker_meta_title', true) ?: get_the_title($post_id);
    $description = get_post_meta($post_id, '_grandranker_meta_description', true);

    if ($description !== '') {
        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }

    $og = [
        'og:type' => 'article',
        'og:title' => $title,
        'og:description' => $description,
        'og:url' => get_permalink($post_id),
        'og:image' => get_the_post_thumbnail_url($post_id, 'full'),
    ];
    foreach ($og as $property => $content) {
        if ($content) {
            echo '<meta property="' . $property . '" content="' . esc_attr($content) . '" />' . "\n";
        }
    }
}, 1);

// Ticket 2678: without an SEO plugin WordPress builds <title> from the post
// title, so an SEO title fixed in GrandRanker (Apply Fix) never reached the page.
add_filter('pre_get_document_title', function ($title) {
    if (!is_singular('post') || grandranker_active_seo_plugin() !== null) return $title;

    $meta_title = get_post_meta(get_the_ID(), '_grandranker_meta_title', true);

    return $meta_title !== '' ? $meta_title : $title;
});

function grandranker_active_seo_plugin() {
    // An explicit choice in the plugin settings wins over detection, the same
    // way grandranker_detect_seo_plugin() treats it.
    $setting = get_option(GRANDRANKER_OPT_SEO_PLUGIN, 'auto');
    if ($setting !== 'auto') {
        return $setting === 'none' ? null : $setting;
    }

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (is_plugin_active('wordpress-seo/wp-seo.php')) return 'yoast';
    if (is_plugin_active('seo-by-rank-math/rank-math.php')) return 'rankmath';
    if (is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php')) return 'aioseo';

    return null;
}

function grandranker_unpublish_article(WP_REST_Request $request) {
    $post_id = (int) $request['id'];

    $refusal = grandranker_unpublish_refusal($post_id, $request['grandranker_id']);
    if ($refusal) {
        return ['deleted' => false, 'error' => $refusal];
    }

    $result = wp_delete_post($post_id, false);
    return ['deleted' => (bool) $result];
}

/**
 * Why Grandranker must not delete $post_id, or null when nothing stops it.
 *
 * Grandranker unpublishes by the post id it stored at publish. After a site
 * moves (staging to live, a rebuild, an import, a restore that reuses ids) that
 * id can name the site's own content: on holidaylightstore.com three stored ids
 * were media files, and wp_delete_post() deletes an attachment for good,
 * skipping the trash (ticket 3333). So only a post carrying the _grandranker_id
 * this plugin writes on create may go, and when Grandranker sends the
 * article's uuid, only that article's post.
 *
 * A missing id is not refused: there is nothing to delete, and Grandranker
 * reads the plain `deleted: false` reply as already gone, so an article whose
 * post was removed by hand can still be unpublished.
 */
function grandranker_unpublish_refusal($post_id, $grandranker_id = null) {
    $post = $post_id > 0 ? get_post($post_id) : null;
    if (!$post) {
        return null;
    }

    if ($post->post_type !== 'post') {
        return sprintf('Not deleted: item %d on this site is not a post (type "%s"), so Grandranker did not publish it.', $post_id, $post->post_type);
    }

    $owner = (string) get_post_meta($post_id, '_grandranker_id', true);
    if ($owner === '') {
        return sprintf('Not deleted: post %d on this site was not published by Grandranker.', $post_id);
    }

    if (!empty($grandranker_id) && $owner !== (string) $grandranker_id) {
        return sprintf('Not deleted: post %d on this site belongs to a different Grandranker article.', $post_id);
    }

    return null;
}

function grandranker_ensure_category($category_name) {
    $name = str_replace(['-', '_'], ' ', $category_name);
    $name = ucwords(trim($name));
    if (empty($name)) return null;

    $term = term_exists($name, 'category');
    if ($term) return (int) $term['term_id'];

    $result = wp_insert_term($name, 'category', ['slug' => sanitize_title($category_name)]);
    if (is_wp_error($result)) return null;

    return (int) $result['term_id'];
}

// ---------------------------------------------------------------------------
// Media — every image Grandranker sends is stored once (task 955) and body
// images are copied off our CDN (task 934). An attachment remembers the URL it
// came from in _grandranker_source_url, which is what makes it reusable.
// ---------------------------------------------------------------------------

/**
 * Featured image and body images for a post Grandranker just wrote.
 */
function grandranker_attach_media($post_id, $article) {
    grandranker_start_download_budget();

    return [
        'featured_image' => empty($article['image_url']) ? null : grandranker_attach_featured_image($post_id, $article['image_url']),
        'content_images' => grandranker_mirror_content_images($post_id),
    ];
}

/**
 * The attachment holding $url, sideloaded under $post_id only when no
 * attachment came from that URL yet. $reused tells the caller which happened.
 *
 * @return int|WP_Error
 */
function grandranker_sideload_once($url, $post_id, &$reused = null) {
    $existing = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'meta_key'    => '_grandranker_source_url',
        'meta_value'  => $url,
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);
    $reused = !empty($existing);
    if ($reused) {
        return (int) $existing[0];
    }

    if (!grandranker_take_download()) {
        return new WP_Error('grandranker_deferred', 'download deferred: per-request limit reached');
    }

    if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!function_exists('media_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/media.php';
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url, GRANDRANKER_DOWNLOAD_TIMEOUT);
    if (is_wp_error($tmp)) {
        return new WP_Error('grandranker_download_failed', 'download: ' . $tmp->get_error_message());
    }

    $file = ['name' => basename((string) wp_parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
    $attachment_id = media_handle_sideload($file, $post_id);

    if (is_wp_error($attachment_id)) {
        wp_delete_file($tmp);
        return new WP_Error('grandranker_sideload_failed', 'sideload: ' . $attachment_id->get_error_message());
    }

    update_post_meta($attachment_id, '_grandranker_source_url', $url);
    return (int) $attachment_id;
}

/**
 * Start this request's download allowance (GRANDRANKER_MAX_DOWNLOADS_PER_REQUEST
 * new images, none started after GRANDRANKER_DOWNLOAD_SECONDS_PER_REQUEST).
 */
function grandranker_start_download_budget() {
    $GLOBALS['grandranker_download_budget'] = [
        'left'  => GRANDRANKER_MAX_DOWNLOADS_PER_REQUEST,
        'until' => microtime(true) + GRANDRANKER_DOWNLOAD_SECONDS_PER_REQUEST,
    ];
}

/**
 * Whether one more download may start, counting it when it may. Without a
 * started allowance every download may.
 */
function grandranker_take_download() {
    $budget = $GLOBALS['grandranker_download_budget'] ?? null;
    if ($budget === null) {
        return true;
    }

    if ($budget['left'] <= 0 || microtime(true) >= $budget['until']) {
        return false;
    }

    $GLOBALS['grandranker_download_budget']['left']--;
    return true;
}

/**
 * Set $image_url as the post's featured image, downloading it only when the
 * site has no copy yet. Returns what happened, so a failure reaches
 * Grandranker instead of leaving a post without its image unnoticed (882).
 */
function grandranker_attach_featured_image($post_id, $image_url) {
    $current = (int) get_post_thumbnail_id($post_id);
    if ($current && grandranker_thumbnail_is_image($current, $image_url)) {
        return ['status' => 'unchanged', 'attachment_id' => $current, 'error' => null];
    }

    $attachment_id = grandranker_sideload_once($image_url, $post_id, $reused);
    if (is_wp_error($attachment_id)) {
        return ['status' => 'failed', 'attachment_id' => null, 'error' => $attachment_id->get_error_message()];
    }

    if (!set_post_thumbnail($post_id, $attachment_id)) {
        return ['status' => 'failed', 'attachment_id' => $attachment_id, 'error' => 'set_post_thumbnail returned false'];
    }

    return ['status' => $reused ? 'reused' : 'attached', 'attachment_id' => $attachment_id, 'error' => null];
}

/**
 * Whether the post's current thumbnail is already $image_url.
 *
 * Plugin 1.5.10 and older stored no source URL, so a thumbnail without one is
 * adopted when its file is that image or a "-N" copy WordPress named after it
 * (beste-dag-1791502402-1.jpg for beste-dag-1791502402.jpg). Adopting writes
 * the source URL, so the next push matches it directly.
 */
function grandranker_thumbnail_is_image($attachment_id, $image_url) {
    $source = (string) get_post_meta($attachment_id, '_grandranker_source_url', true);
    if ($source !== '') {
        return $source === $image_url;
    }

    $url_stem = pathinfo((string) wp_parse_url($image_url, PHP_URL_PATH), PATHINFO_FILENAME);
    $file_stem = pathinfo((string) get_attached_file($attachment_id), PATHINFO_FILENAME);
    if ($url_stem === '' || ($file_stem !== $url_stem && preg_replace('/-\d+$/', '', $file_stem) !== $url_stem)) {
        return false;
    }

    update_post_meta($attachment_id, '_grandranker_source_url', $image_url);
    return true;
}

/**
 * Copy every body <img> served from GRANDRANKER_IMAGE_HOSTS into the Media
 * Library and point its src at the copy. Works on the post's current content,
 * so edits made in WordPress survive. An image that cannot be copied keeps its
 * CDN URL: never a broken src. So does one past this request's download
 * allowance (counted as deferred); the next push or mirror-images call copies it.
 */
function grandranker_mirror_content_images($post_id) {
    $counts = ['mirrored' => 0, 'reused' => 0, 'failed' => 0, 'deferred' => 0];
    $content = (string) get_post_field('post_content', $post_id);

    $replacements = [];
    foreach (grandranker_cdn_images_in($content) as $src) {
        $attachment_id = grandranker_sideload_once($src, $post_id, $reused);
        if (is_wp_error($attachment_id) && $attachment_id->get_error_code() === 'grandranker_deferred') {
            $counts['deferred']++;
            continue;
        }

        $local = is_wp_error($attachment_id) ? false : wp_get_attachment_url($attachment_id);
        if (!$local) {
            $counts['failed']++;
            continue;
        }

        $counts[$reused ? 'reused' : 'mirrored']++;
        $replacements[$src] = $local;
    }

    if ($replacements && !grandranker_replace_post_content($post_id, strtr($content, $replacements))) {
        $counts['failed'] += $counts['mirrored'] + $counts['reused'];
        $counts['mirrored'] = $counts['reused'] = 0;
    }

    return $counts;
}

/**
 * The distinct body image URLs served from GRANDRANKER_IMAGE_HOSTS, in order
 * of appearance: <img> src values, then every candidate of an <img> or
 * <source> srcset, so no width variant keeps loading from our CDN.
 *
 * @return string[]
 */
function grandranker_cdn_images_in($content) {
    $content = (string) $content;
    $urls = [];

    if (preg_match_all('/<img\b[^>]*?\ssrc=(["\'])(.*?)\1/i', $content, $matches)) {
        $urls = $matches[2];
    }

    if (preg_match_all('/<(?:img|source)\b[^>]*?\ssrcset=(["\'])(.*?)\1/is', $content, $matches)) {
        foreach ($matches[2] as $srcset) {
            foreach (explode(',', $srcset) as $candidate) {
                $url = strtok(trim($candidate), " \t\n\r");
                if ($url !== false && $url !== '') $urls[] = $url;
            }
        }
    }

    return array_values(array_filter(array_unique($urls), function ($src) {
        return in_array(strtolower((string) wp_parse_url($src, PHP_URL_HOST)), GRANDRANKER_IMAGE_HOSTS, true);
    }));
}

/**
 * Save content whose only change is image src values. kses is held off for
 * this one save: these requests run with no user, and on a post the site owner
 * edited it would strip markup they were allowed to add.
 */
function grandranker_replace_post_content($post_id, $content) {
    $kses = has_filter('content_save_pre', 'wp_filter_post_kses');
    if ($kses !== false) remove_filter('content_save_pre', 'wp_filter_post_kses', $kses);

    $result = wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $content] + grandranker_kept_post_date($post_id)), true);

    if ($kses !== false) add_filter('content_save_pre', 'wp_filter_post_kses', $kses);

    return $result && !is_wp_error($result);
}

// ---------------------------------------------------------------------------
// Maintenance endpoints, each driven by an artisan command after a human has
// reviewed its dry run.
// ---------------------------------------------------------------------------

/**
 * A reply refusing to touch $post_id, or null when it is $grandranker_id's
 * own post. Same rule as an unpublish (grandranker_unpublish_refusal).
 */
function grandranker_ownership_refusal($post_id, $grandranker_id) {
    $post = $post_id > 0 ? get_post($post_id) : null;
    if (!$post || $post->post_type !== 'post') {
        return new WP_REST_Response(['error' => 'post not found'], 404);
    }

    if (empty($grandranker_id) || grandranker_unpublish_refusal($post_id, $grandranker_id)) {
        return new WP_REST_Response(['error' => 'post belongs to another article'], 409);
    }

    return null;
}

/**
 * Replace only the post's JSON-LD (task 873). The post itself is not saved, so
 * its content and modified date stay exactly as they are.
 */
function grandranker_update_schema(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $params = (array) $request->get_json_params();
    $schema = $params['json_ld_schema'] ?? null;

    if (!is_array($schema) || $schema === [] || array_keys($schema) === range(0, count($schema) - 1)) {
        return new WP_REST_Response(['error' => 'json_ld_schema must be an object'], 400);
    }

    $refusal = grandranker_ownership_refusal($post_id, $params['grandranker_id'] ?? null);
    if ($refusal) {
        return $refusal;
    }

    // update_post_meta() strips one level of slashes, like wp_insert_post().
    update_post_meta($post_id, '_grandranker_schema', wp_slash(wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    return new WP_REST_Response(['post_id' => $post_id, 'updated' => true], 200);
}

/**
 * Copy an existing post's CDN body images into the Media Library without
 * Grandranker resending the post. Dry run unless dry_run is false (1.6.1): the
 * dry run lists the CDN images the post shows and whether a copy already
 * exists, and downloads and saves nothing.
 */
function grandranker_mirror_images(WP_REST_Request $request) {
    $post_id = (int) $request['id'];
    $params = (array) $request->get_json_params();
    $dry_run = !array_key_exists('dry_run', $params) || !empty($params['dry_run']);

    $refusal = grandranker_ownership_refusal($post_id, $params['grandranker_id'] ?? null);
    if ($refusal) {
        return $refusal;
    }

    if (!$dry_run) {
        grandranker_start_download_budget();
        return new WP_REST_Response(['post_id' => $post_id, 'dry_run' => false, 'content_images' => grandranker_mirror_content_images($post_id)], 200);
    }

    $images = [];
    foreach (grandranker_cdn_images_in(get_post_field('post_content', $post_id)) as $src) {
        $existing = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => '_grandranker_source_url',
            'meta_value'  => $src,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        $images[] = ['src' => $src, 'action' => empty($existing) ? 'mirror' : 'reuse'];
    }

    return new WP_REST_Response(['post_id' => $post_id, 'dry_run' => true, 'images' => $images], 200);
}

/**
 * Remove the copies of featured images that plugin 1.5.10 and older made on
 * every push. Dry run unless dry_run is false; deletes at most `limit` per call.
 *
 * With `after_post_id` (1.6.1) only one page is scanned: the next `post_limit`
 * Grandranker posts (default 50) with a higher id, so no single request scans
 * and hashes a whole large site. `next_post_id` is where the next page starts,
 * null once the last post was scanned. Without `after_post_id` every post is
 * scanned, as before.
 */
function grandranker_dedupe_media(WP_REST_Request $request) {
    $params = (array) $request->get_json_params();
    $dry_run = !array_key_exists('dry_run', $params) || !empty($params['dry_run']);
    $limit = max(1, (int) ($params['limit'] ?? 200));

    $posts = grandranker_own_post_ids();
    $next_post_id = null;
    if (array_key_exists('after_post_id', $params)) {
        $after = max(0, (int) $params['after_post_id']);
        $post_limit = min(500, max(1, (int) ($params['post_limit'] ?? 50)));
        $posts = array_values(array_filter($posts, function ($id) use ($after) {
            return $id > $after;
        }));
        if (count($posts) > $post_limit) {
            $posts = array_slice($posts, 0, $post_limit);
            $next_post_id = end($posts);
        }
    }

    $duplicates = grandranker_duplicate_attachments($posts);
    $deleted = [];

    if (!$dry_run) {
        foreach (array_slice(array_keys($duplicates), 0, $limit) as $attachment_id) {
            if (wp_delete_attachment($attachment_id, true)) {
                $deleted[] = $attachment_id;
            }
        }
    }

    return new WP_REST_Response([
        'duplicates' => array_keys($duplicates),
        'deleted'    => $deleted,
        'bytes'      => array_sum($duplicates),
        'remaining'  => count($duplicates) - count($deleted),
        'scanned_posts' => count($posts),
        'next_post_id'  => $next_post_id,
    ], 200);
}

/**
 * Ids of every post Grandranker wrote, ascending.
 *
 * @return int[]
 */
function grandranker_own_post_ids() {
    $ids = array_map('intval', get_posts([
        'post_type'   => 'post',
        'post_status' => 'any',
        'meta_key'    => '_grandranker_id',
        'numberposts' => -1,
        'fields'      => 'ids',
        'orderby'     => 'ID',
        'order'       => 'ASC',
    ]));
    sort($ids);

    return $ids;
}

/**
 * Attachments safe to delete as copies, as [attachment id => bytes].
 *
 * Only attachments of a Grandranker post count: files with no parent post
 * (WordPress's "Unattached") are never looked at. Within one post, files whose
 * name is another file's name plus "-N" form a group with it (as do two "-N"
 * files of the same name once the original is gone). The group keeps the
 * post's thumbnail, else its lowest (oldest) id. Any other member is a copy
 * only when all of these hold (1.6.1, SEO safety):
 * - Grandranker made it: it carries _grandranker_source_url, or the group's
 *   name ends in the Unix timestamp every Grandranker image file name ends in
 *   (beste-dag-1791502402.jpg), which plugins before 1.6.0 kept on download;
 * - its bytes match the kept file;
 * - it is in use nowhere: no post's thumbnail, and no post content, post meta
 *   or site option shows its id or its file name, original or any size
 *   (grandranker_is_referenced). A deleted file that something still showed
 *   would be a broken image on a live page and a 404 in image search.
 *
 * Looks only under $posts (Grandranker post ids; default all of them). Sizes
 * are compared before any file is hashed, and the kept file is hashed only
 * when a member of its size exists.
 */
function grandranker_duplicate_attachments($posts = null) {
    global $wpdb;

    $thumbnails = array_flip(array_map('intval', $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'")));
    $posts = $posts ?? grandranker_own_post_ids();

    $duplicates = [];
    foreach ($posts as $post_id) {
        $children = get_posts([
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        if (count($children) < 2) continue;

        $files = [];
        $stems = [];
        foreach ($children as $attachment_id) {
            $files[(int) $attachment_id] = (string) get_attached_file($attachment_id);
            $stems[(int) $attachment_id] = pathinfo($files[(int) $attachment_id], PATHINFO_FILENAME);
        }

        // x-1.jpg, x-2.jpg are copies of x.jpg, and stay one group once x.jpg is gone.
        $bases = [];
        foreach ($stems as $attachment_id => $stem) {
            $base = preg_replace('/-\d+$/', '', $stem);
            if ($base !== $stem) $bases[$attachment_id] = $base;
        }
        $copies_per_base = array_count_values($bases);

        $groups = [];
        foreach ($stems as $attachment_id => $stem) {
            $base = $bases[$attachment_id] ?? null;
            $is_copy = $base !== null && (in_array($base, $stems, true) || $copies_per_base[$base] > 1);
            $groups[$is_copy ? $base : $stem][] = $attachment_id;
        }

        $thumbnail = (int) get_post_thumbnail_id($post_id);
        foreach ($groups as $base => $ids) {
            if (count($ids) < 2) continue;

            $keep = in_array($thumbnail, $ids, true) ? $thumbnail : min($ids);
            if (!is_file($files[$keep])) continue;
            $keep_size = filesize($files[$keep]);
            $keep_md5 = null;

            $grandranker_name = preg_match('/-\d{10}$/', (string) $base) === 1;
            $texts = null;
            foreach ($ids as $attachment_id) {
                if ($attachment_id === $keep || isset($thumbnails[$attachment_id])) continue;
                if (!$grandranker_name && (string) get_post_meta($attachment_id, '_grandranker_source_url', true) === '') continue;
                if (!is_file($files[$attachment_id]) || filesize($files[$attachment_id]) !== $keep_size) continue;
                $keep_md5 = $keep_md5 ?? md5_file($files[$keep]);
                if (!$keep_md5 || md5_file($files[$attachment_id]) !== $keep_md5) continue;

                $texts = $texts ?? grandranker_texts_mentioning((string) $base, $ids);
                $siblings = array_values(array_diff_key(array_intersect_key($stems, array_flip($ids)), [$attachment_id => true]));
                if (grandranker_is_referenced($attachment_id, grandranker_attachment_stems($attachment_id, $files[$attachment_id]), $texts, $siblings)) continue;

                $duplicates[$attachment_id] = (int) filesize($files[$attachment_id]);
            }
        }
    }

    ksort($duplicates);
    return $duplicates;
}

/**
 * Every text on the site that may show a file named $stem* or one of $ids:
 * post content (pages, products, templates… anything but revisions and
 * attachments), post meta (page builders, galleries, SEO plugins' Open Graph
 * images) and site options (theme settings, site icon). Meta of the group's
 * own attachments (their file name, sizes, source URL) is no use and left out,
 * as is the file meta of every other attachment (_wp_attached_file and its
 * sizes name that attachment's own file, not a use of ours).
 * A coarse SQL filter; grandranker_is_referenced() checks exactly.
 *
 * @return string[]
 */
function grandranker_texts_mentioning($stem, $ids) {
    global $wpdb;

    $ids = array_map('intval', $ids);
    $like = '%' . $wpdb->esc_like($stem) . '%';
    $id_regexp = '(^|[^0-9A-Za-z])(' . implode('|', $ids) . ')([^0-9A-Za-z]|$)';
    $own = implode(',', $ids);

    $contents = $wpdb->get_col($wpdb->prepare(
        "SELECT post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'attachment') AND (post_content LIKE %s OR post_content REGEXP %s)",
        $like,
        $id_regexp
    ));
    $meta = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id NOT IN ($own) AND meta_key NOT IN ('_edit_lock', '_edit_last', '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND (meta_value LIKE %s OR meta_value REGEXP %s)",
        $like,
        $id_regexp
    ));
    $options = $wpdb->get_col($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE %s OR ((option_name = 'site_icon' OR option_name LIKE %s) AND option_value REGEXP %s)",
        $like,
        $wpdb->esc_like('theme_mods_') . '%',
        $id_regexp
    ));

    return array_merge($contents, $meta, $options);
}

/**
 * File name stems an attachment is served under: its file, the original of a
 * scaled or edited image, and every generated size.
 *
 * @return string[]
 */
function grandranker_attachment_stems($attachment_id, $file) {
    $stems = [pathinfo($file, PATHINFO_FILENAME)];
    $metadata = wp_get_attachment_metadata($attachment_id);

    if (is_array($metadata)) {
        foreach (['file', 'original_image'] as $key) {
            if (!empty($metadata[$key])) $stems[] = pathinfo((string) $metadata[$key], PATHINFO_FILENAME);
        }
        foreach ((array) ($metadata['sizes'] ?? []) as $size) {
            if (!empty($size['file'])) $stems[] = pathinfo((string) $size['file'], PATHINFO_FILENAME);
        }
    }

    return array_values(array_unique(array_filter($stems, 'strlen')));
}

/**
 * Whether any of $texts shows the attachment: by id (wp-image-<id>, a gallery
 * or page-builder id, a serialized or JSON id), or by a file name built on one
 * of its $stems, in any size or format (name-300x200.jpg, name.jpg.webp).
 * Errs on the side of keeping a file. A name that is really another group
 * member's ($sibling_stems: x-3.jpg is copy 3 of x.jpg, not a size of x) does
 * not count for this one.
 */
function grandranker_is_referenced($attachment_id, $stems, $texts, $sibling_stems = []) {
    $suffix = '(-[A-Za-z0-9]+)*\.[A-Za-z0-9]';
    $id_pattern = '/(?<![0-9A-Za-z])' . (int) $attachment_id . '(?![0-9A-Za-z])/';
    $file_patterns = array_map(function ($stem) use ($suffix) {
        return '#(?<![A-Za-z0-9_.-])' . preg_quote($stem, '#') . $suffix . '#i';
    }, $stems);

    // Group members whose name starts with one of ours plus "-".
    $sibling_patterns = [];
    foreach ($sibling_stems as $sibling) {
        foreach ($stems as $stem) {
            if (stripos((string) $sibling, $stem . '-') === 0) {
                $sibling_patterns[] = '#^' . preg_quote((string) $sibling, '#') . $suffix . '#i';
                break;
            }
        }
    }

    foreach ($texts as $text) {
        $text = (string) $text;
        if (preg_match($id_pattern, $text)) {
            return true;
        }
        foreach ($file_patterns as $pattern) {
            if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) continue;
            foreach ($matches[0] as $match) {
                $rest = substr($text, $match[1]);
                $siblings_name = false;
                foreach ($sibling_patterns as $sibling_pattern) {
                    if (preg_match($sibling_pattern, $rest)) {
                        $siblings_name = true;
                        break;
                    }
                }
                if (!$siblings_name) {
                    return true;
                }
            }
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// Admin settings page — wp-admin → Grandranker (top-level menu item).
// ---------------------------------------------------------------------------

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift(
        $links,
        '<a href="' . esc_url(admin_url('admin.php?page=grandranker')) . '">Settings</a>'
    );

    return $links;
});

add_action('admin_menu', function () {
    $icon = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MDAwIDUwMDAiIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCI+CjxnIHRyYW5zZm9ybT0idHJhbnNsYXRlKDAsNTAwMCkgc2NhbGUoMSwtMSkiIGZpbGw9IndoaXRlIiBzdHJva2U9Im5vbmUiPgo8cGF0aCBkPSJNMzgzNSA0MzM5IGMtNDQxIC0xMzQgLTEwMjEgLTQ1NyAtMTQ3MyAtODIyIGwtODEgLTY2IC0xNzggLTIxCmMtMzcxIC00MyAtNzc1IC0xMTMgLTk3MiAtMTY2IC0xOTIgLTUyIC0yNjEgLTg4IC0yODcgLTE1MCAtNDAgLTk2IDYgLTUxMgoxMjAgLTEwODYgNTEgLTI1NiAxNDggLTY4OSAxNTMgLTY4NCAyIDIgMTQgNzcgMjggMTY3IDgxIDU0OSAyMDMgOTk1IDMxMQoxMTM2IDc1IDk5IDMzNyAxNTkgODA0IDE4MyBsMjE1IDEyIDEyMCAxMTYgYzE4MiAxNzcgMzYyIDMwNSA1MjYgMzcyIDkyIDM4Cjk5IDM4IDk0IC0yIC0zMSAtMjU1IC03NSAtMzgyIC0yNTUgLTczOSBsLTQ4IC05NSAyOCAtMTI1IGMxMDAgLTQzNSAxNDMgLTgzMwoxMDEgLTkyNCAtMzcgLTgyIC0yMzMgLTIzMyAtNTA2IC0zOTAgLTk3IC01NiAtNDM5IC0yMjggLTU4MCAtMjkyIGwtNzAgLTMyCjE1NSA1IGM3NTEgMjQgMTM1MSA4MCAxNTE1IDE0MSA0MSAxNSA1NCAyNiA3MyA2MyAyNCA0NSAyNCA0NiAxOCAzMTUgLTMgMTYwCi0xNCAzNDkgLTI2IDQ2NSAtMjggMjY5IC02MCA1OTIgLTY3IDY3MCAtNSA2MSAtMiA3MyA0NyAxOTQgMjA2IDUxNSAzMTggMTAwMAozNTAgMTUyMyAxMyAyMjUgMTMgMjYzIC0yIDI2MiAtNyAwIC01OCAtMTQgLTExMyAtMzB6Ii8+CjwvZz4KPC9zdmc+';

    add_menu_page(
        'Grandranker',
        'Grandranker',
        'manage_options',
        'grandranker',
        'grandranker_render_settings_page',
        $icon,
        80
    );
});

add_action('admin_post_grandranker_save', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('grandranker_save');

    $connection_key = sanitize_text_field(wp_unslash($_POST['grandranker_connection_key'] ?? ''));

    if (empty($connection_key)) {
        $result = new WP_Error('grandranker_invalid_key', 'Please enter a connection key.');
    } else {
        $result = grandranker_connect($connection_key);
    }

    $args = ['page' => 'grandranker'];
    if (is_wp_error($result)) {
        $args['grandranker_error'] = rawurlencode($result->get_error_message());
    } else {
        $args['grandranker_connected'] = '1';
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
});

add_action('admin_post_grandranker_save_settings', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('grandranker_save_settings');

    $author_id = absint(wp_unslash($_POST['grandranker_default_author'] ?? 0));
    if ($author_id && !get_userdata($author_id)) {
        $author_id = 0;
    }
    update_option(GRANDRANKER_OPT_DEFAULT_AUTHOR, $author_id);

    $post_status = sanitize_text_field(wp_unslash($_POST['grandranker_post_status'] ?? 'publish'));
    $allowed_statuses = ['publish', 'draft', 'pending'];
    update_option(GRANDRANKER_OPT_POST_STATUS, in_array($post_status, $allowed_statuses, true) ? $post_status : 'publish');

    $seo_plugin = sanitize_text_field(wp_unslash($_POST['grandranker_seo_plugin'] ?? 'auto'));
    $allowed_seo = ['auto', 'yoast', 'rankmath', 'aioseo', 'none'];
    update_option(GRANDRANKER_OPT_SEO_PLUGIN, in_array($seo_plugin, $allowed_seo, true) ? $seo_plugin : 'auto');

    wp_safe_redirect(add_query_arg([
        'page' => 'grandranker',
        'grandranker_settings_saved' => '1',
    ], admin_url('admin.php')));
    exit;
});

function grandranker_render_settings_page() {
    $connected      = !empty(get_option(GRANDRANKER_OPT_SECRET));
    $connection_key = (string) get_option(GRANDRANKER_OPT_TOKEN, '');
    $error          = isset($_GET['grandranker_error'])
        ? sanitize_text_field(rawurldecode(wp_unslash($_GET['grandranker_error'])))
        : '';
    $success        = !empty($_GET['grandranker_connected']);
    $settings_saved = !empty($_GET['grandranker_settings_saved']);
    $disconnected   = !empty($_GET['grandranker_disconnected']);
    ?>
    <div class="wrap">
        <style>
            .grandranker-status {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-left: 4px;
                padding: 3px 9px;
                border-radius: 999px;
                font-size: 12px;
                line-height: 1.4;
                font-weight: 600;
                vertical-align: middle;
            }
            .grandranker-status-connected {
                color: #005c12;
                background: #edfaef;
                border: 1px solid #68de7c;
            }
        </style>
        <h1>Grandranker</h1>
        <?php if ($disconnected): ?>
            <div class="notice notice-info"><p>Disconnected from Grandranker.</p></div>
        <?php elseif ($settings_saved): ?>
            <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php elseif ($success): ?>
            <div class="notice notice-success"><p>Connected to Grandranker.</p></div>
        <?php elseif ($error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <p>
            Status:
            <?php if ($connected): ?>
                <span class="grandranker-status grandranker-status-connected"><span aria-hidden="true">●</span> Connected</span>
            <?php else: ?>
                <strong>Not connected</strong>
            <?php endif; ?>
        </p>

        <?php if (empty($connection_key)): ?>
            <div class="notice notice-warning inline">
                <p>
                    A connection key is required. Open the
                    <a href="<?php echo esc_url(GRANDRANKER_API_BASE . '/integrations/create/wp-connect'); ?>" target="_blank" rel="noopener noreferrer">Grandranker WordPress Plugin page</a>,
                    copy your connection key, and paste it below.
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="grandranker_save" />
            <?php wp_nonce_field('grandranker_save'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="grandranker_connection_key">Connection key</label></th>
                    <td>
                        <input type="password" id="grandranker_connection_key" name="grandranker_connection_key" value="<?php echo esc_attr($connection_key); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                        <p class="description">Paste the connection key from your Grandranker integrations page.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save & Connect'); ?>
        </form>

        <?php if ($connected): ?>
        <hr />
        <h2>Publishing Settings</h2>
        <?php
            $current_author = (int) get_option(GRANDRANKER_OPT_DEFAULT_AUTHOR, 0);
            $current_status = get_option(GRANDRANKER_OPT_POST_STATUS, 'publish');
            $current_seo    = get_option(GRANDRANKER_OPT_SEO_PLUGIN, 'auto');
            $detected_seo   = grandranker_detect_seo_plugin();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="grandranker_save_settings" />
            <?php wp_nonce_field('grandranker_save_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="grandranker_default_author">Default author</label></th>
                    <td>
                        <?php wp_dropdown_users([
                            'name'             => 'grandranker_default_author',
                            'id'               => 'grandranker_default_author',
                            'selected'         => $current_author,
                            'show_option_none' => '— WordPress default —',
                            'option_none_value' => 0,
                            'capability'       => ['edit_posts'],
                        ]); ?>
                        <p class="description">Author assigned to articles published by Grandranker.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="grandranker_post_status">Post status</label></th>
                    <td>
                        <select name="grandranker_post_status" id="grandranker_post_status">
                            <option value="publish" <?php selected($current_status, 'publish'); ?>>Published</option>
                            <option value="draft" <?php selected($current_status, 'draft'); ?>>Draft</option>
                            <option value="pending" <?php selected($current_status, 'pending'); ?>>Pending Review</option>
                        </select>
                        <p class="description">Status assigned to new articles. Use Draft or Pending to review before publishing.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="grandranker_seo_plugin">SEO plugin</label></th>
                    <td>
                        <select name="grandranker_seo_plugin" id="grandranker_seo_plugin">
                            <option value="auto" <?php selected($current_seo, 'auto'); ?>>Auto-detect (<?php echo esc_html($detected_seo); ?>)</option>
                            <option value="yoast" <?php selected($current_seo, 'yoast'); ?>>Yoast SEO</option>
                            <option value="rankmath" <?php selected($current_seo, 'rankmath'); ?>>Rank Math</option>
                            <option value="aioseo" <?php selected($current_seo, 'aioseo'); ?>>All in One SEO</option>
                            <option value="none" <?php selected($current_seo, 'none'); ?>>None</option>
                        </select>
                        <p class="description">Which SEO plugin to write meta descriptions to. Auto-detect works for most sites.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>

        <hr />
        <h2>Disconnect</h2>
        <p>Remove all Grandranker data from this site and stop polling for articles.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:4px;">
            <input type="hidden" name="action" value="grandranker_disconnect" />
            <?php wp_nonce_field('grandranker_disconnect'); ?>
            <?php submit_button('Disconnect', 'delete'); ?>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

add_action('admin_post_grandranker_disconnect', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('grandranker_disconnect');

    delete_option(GRANDRANKER_OPT_TOKEN);
    delete_option(GRANDRANKER_OPT_SECRET);
    delete_option(GRANDRANKER_OPT_SITE_URL);
    delete_option(GRANDRANKER_OPT_DEFAULT_AUTHOR);
    delete_option(GRANDRANKER_OPT_POST_STATUS);
    delete_option(GRANDRANKER_OPT_SEO_PLUGIN);
    // Stop serving the ownership-proof key file for a site that revoked us.
    delete_option(GRANDRANKER_OPT_INDEXNOW_KEY);
    wp_clear_scheduled_hook('grandranker_poll_deliveries');

    wp_safe_redirect(add_query_arg([
        'page' => 'grandranker',
        'grandranker_disconnected' => '1',
    ], admin_url('admin.php')));
    exit;
});

// ---------------------------------------------------------------------------
// Pull-based delivery polling — hybrid push/pull system.
// Push is the fast path; if it fails (WAF, timeout, etc.), Grandranker
// queues the article and this plugin pulls it on the next poll cycle.
// ---------------------------------------------------------------------------

add_filter('cron_schedules', function ($schedules) {
    $schedules['grandranker_one_minute'] = [
        'interval' => 60,
        'display'  => 'Every minute (Grandranker)',
    ];
    return $schedules;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('grandranker_poll_deliveries')) {
        wp_schedule_event(time(), 'grandranker_one_minute', 'grandranker_poll_deliveries');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('grandranker_poll_deliveries');
});

add_action('init', function () {
    if (!wp_next_scheduled('grandranker_poll_deliveries')) {
        wp_schedule_event(time(), 'grandranker_one_minute', 'grandranker_poll_deliveries');
    }
});

add_action('admin_init', function () {
    if (!get_option(GRANDRANKER_OPT_TOKEN)) return;
    if (get_transient('grandranker_admin_poll_lock')) return;
    set_transient('grandranker_admin_poll_lock', 1, 30);
    grandranker_poll_deliveries();
});

add_action('grandranker_poll_deliveries', 'grandranker_poll_deliveries');

add_filter('heartbeat_received', function ($response) {
    if (!get_option(GRANDRANKER_OPT_TOKEN)) return $response;
    if (get_transient('grandranker_heartbeat_lock')) return $response;
    set_transient('grandranker_heartbeat_lock', 1, 14);
    grandranker_poll_deliveries();
    $response['grandranker_poll'] = 'ok';
    return $response;
}, 10, 1);

function grandranker_poll_deliveries() {
    $token = get_option(GRANDRANKER_OPT_TOKEN);
    if (empty($token)) return;

    if (get_transient('grandranker_poll_lock')) return;
    set_transient('grandranker_poll_lock', 1, 60);

    $response = wp_remote_get(GRANDRANKER_API_BASE . '/api/v1/plugin/deliveries', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) return;
    if (wp_remote_retrieve_response_code($response) !== 200) return;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $deliveries = $body['data'] ?? [];

    foreach ($deliveries as $delivery) {
        grandranker_process_delivery($delivery, $token);
    }
}

function grandranker_process_delivery($delivery, $token) {
    $id     = $delivery['id'] ?? null;
    $action = $delivery['action'] ?? null;
    if (!$id || !$action) return;

    $result = ['post_id' => null, 'url' => null, 'error' => null];

    if ($action === 'publish') {
        $result = grandranker_process_publish_delivery($delivery);
    } elseif ($action === 'unpublish') {
        $result = grandranker_process_unpublish_delivery($delivery);
    }

    wp_remote_post(GRANDRANKER_API_BASE . '/api/v1/plugin/deliveries/' . $id . '/confirm', [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body' => wp_json_encode($result),
    ]);
}

function grandranker_process_publish_delivery($delivery) {
    $article = $delivery['payload']['data']['articles'][0] ?? null;
    if (!$article || empty($article['title'])) {
        return ['error' => 'No article in payload'];
    }

    grandranker_store_indexnow_key($delivery['payload']);

    $grandranker_id = $article['id'] ?? null;
    $existing_id = null;

    if ($grandranker_id) {
        $existing = get_posts([
            'meta_key'    => '_grandranker_id',
            'meta_value'  => $grandranker_id,
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if (!empty($existing)) {
            $existing_id = $existing[0];
        }
    }

    $post_data = [
        'post_title'   => wp_strip_all_tags($article['title']),
        'post_name'    => sanitize_title($article['slug'] ?? $article['title']),
        'post_content' => grandranker_clean_content_html($article['content_html'] ?? ''),
        'post_excerpt' => $article['meta_description'] ?? '',
        'post_status'  => grandranker_get_post_status(),
        'post_type'    => 'post',
        'meta_input'   => grandranker_build_meta_input($article),
    ];

    if ($existing_id) {
        $post_data['ID'] = $existing_id;
        $post_data += grandranker_kept_post_date($existing_id);
    }

    // Updates keep the post's current author unless a Default author is set.
    $author = $existing_id ? grandranker_get_default_author() : grandranker_resolve_author($article);
    if ($author) {
        $post_data['post_author'] = $author;
    }

    if (!empty($article['category'])) {
        $cat_id = grandranker_ensure_category($article['category']);
        if ($cat_id) $post_data['post_category'] = [$cat_id];
    }

    // wp_insert_post() expects slashed data — see grandranker_publish_article().
    $post_id = wp_insert_post(wp_slash($post_data), true);

    if (is_wp_error($post_id)) {
        return ['error' => $post_id->get_error_message()];
    }

    if (!empty($article['tags']) && is_array($article['tags'])) {
        wp_set_post_tags($post_id, $article['tags']);
    }

    $media = grandranker_attach_media($post_id, $article);

    // A missing featured image is reported, not raised as `error`: the post
    // exists, so the delivery must be confirmed rather than retried.
    return [
        'post_id'              => $post_id,
        'url'                  => get_permalink($post_id),
        'updated'              => (bool) $existing_id,
        'error'                => null,
        'featured_image'       => $media['featured_image'],
        'content_images'       => $media['content_images'],
        'featured_image_error' => $media['featured_image']['error'] ?? null,
    ];
}

function grandranker_process_unpublish_delivery($delivery) {
    $external_id = $delivery['payload']['external_platform_id'] ?? null;
    if (!$external_id) {
        return ['error' => 'No post ID to unpublish'];
    }

    $refusal = grandranker_unpublish_refusal((int) $external_id, $delivery['payload']['grandranker_id'] ?? null);
    if ($refusal) {
        return ['post_id' => (int) $external_id, 'url' => null, 'error' => $refusal];
    }

    $result = wp_delete_post((int) $external_id, false);
    return ['post_id' => (int) $external_id, 'url' => null, 'error' => $result ? null : 'Failed to trash post'];
}
