<?php
/**
 * Standalone behavior tests for the S6 region anchors + authenticated preview
 * URL (cinatra-ai/cinatra#2044, plugin half). Runs under plain
 * `php tests/test-preview-anchors.php` — no PHPUnit, no WordPress install.
 * Exit code 0 = all pass, 1 = a failure.
 *
 * Covers the plugin-half acceptance criteria of #2044:
 *   - REGION ANCHORS present: the_title / the_content / the_excerpt emit the
 *     scope-manifest region attributes (title/content/excerpt) DURING a preview
 *     render, keyed to the target post id, idempotently.
 *   - NO visitor-visible change: the same filters are byte-for-byte inert on a
 *     normal front-end request (no preview target set) and for a NON-target post
 *     during a preview — so the public page and unpublished data never leak into
 *     ordinary rendering.
 *   - PREVIEW AUTH enforced: cinatra_preview_authorize() denies (=> WordPress
 *     401) when the site is not host-connected, when the signature headers are
 *     missing, when the signature is forged, when the timestamp is stale, and
 *     when the signature was minted for a DIFFERENT post id; it accepts ONLY a
 *     fresh, correctly-signed request. The signing uses the connect-provisioned
 *     Standard-Webhooks shared secret via cinatra_webhook_sign().
 *   - DRAFT render: the render path returns a full page carrying a draft's
 *     content WITH anchors (proving the host can capture the staged page), and
 *     the callback rejects a missing / non-public post type (no leak of an
 *     unqueryable object).
 */

require __DIR__ . '/wp-stubs.php';

// ---------------------------------------------------------------------------
// Test-local stubs (function_exists-guarded so a shared load is harmless).
// ---------------------------------------------------------------------------
if (!function_exists('absint')) {
    function absint($n) { return abs((int) $n); }
}
if (!function_exists('get_post')) {
    // get_post(null) returns the global post (the anchor filters read it);
    // get_post($id) resolves the fixture registry.
    function get_post($id = null) {
        if (null === $id) {
            return $GLOBALS['post'] ?? null;
        }
        return $GLOBALS['cinatra_test']['posts'][(int) $id] ?? null;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('apply_filters')) {
    // Replay the callbacks the plugin registered via add_filter() (captured in
    // $GLOBALS['cinatra_test']['filter_cbs'] by the wp-stubs add_filter()).
    function apply_filters($hook, $value, ...$args) {
        foreach ($GLOBALS['cinatra_test']['filter_cbs'][$hook] ?? [] as $cb) {
            $value = call_user_func_array($cb, array_merge([$value], $args));
        }
        return $value;
    }
}

require dirname(__DIR__) . '/cinatra.php';

$failures = 0;
function check($label, $cond) {
    global $failures;
    if ($cond) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }
}

// A Standard-Webhooks whsec_ secret (computed, so the assertions do not merely
// test the code against itself).
define('PREVIEW_SECRET', 'whsec_' . base64_encode('preview-fixture-hmac-key-' . str_repeat('z', 12)));

/** Sign the way the HOST would, independent of the plugin's helper. */
function preview_host_sign(string $secret, string $id, int $ts, int $post_id): string {
    $key = base64_decode(substr($secret, strlen('whsec_')), true);
    return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . 'preview.' . $post_id, $key, true));
}

function make_request(array $headers, $id_param) {
    $r = new WP_REST_Request();
    foreach ($headers as $k => $v) {
        $r->set_header($k, $v);
    }
    $r->set_param('id', $id_param);
    return $r;
}

// Baseline fixture: a host-connected site with the shared webhook secret.
$GLOBALS['cinatra_test']['options']['cinatra_webhook_secret'] = PREVIEW_SECRET;
$GLOBALS['cinatra_test']['posts'] = array(
    41 => new WP_Post(array(
        'ID'           => 41,
        'post_type'    => 'post',
        'post_status'  => 'draft',
        'post_title'   => 'Staged headline',
        'post_content' => '<p>Draft body copy.</p>',
        'post_excerpt' => 'Draft excerpt.',
    )),
    99 => new WP_Post(array(
        'ID'           => 99,
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'Some other post',
        'post_content' => '<p>Other body.</p>',
        'post_excerpt' => '',
    )),
);

// ===========================================================================
echo "Test: region anchors present DURING a preview render (scope-manifest fields)\n";
// ===========================================================================
$GLOBALS['post'] = $GLOBALS['cinatra_test']['posts'][41];
$GLOBALS['cinatra_preview_target'] = 41;

$title   = cinatra_anchor_the_title('Staged headline', 41);
$content = cinatra_anchor_the_content('<p>Draft body copy.</p>');
$excerpt = cinatra_anchor_the_excerpt('Draft excerpt.');

check('title anchor present with region=title + post id',
    strpos($title, 'data-cinatra-region="title"') !== false && strpos($title, 'data-cinatra-post="41"') !== false);
check('content anchor present with region=content + post id',
    strpos($content, 'data-cinatra-region="content"') !== false && strpos($content, 'data-cinatra-post="41"') !== false);
check('excerpt anchor present with region=excerpt + post id',
    strpos($excerpt, 'data-cinatra-region="excerpt"') !== false && strpos($excerpt, 'data-cinatra-post="41"') !== false);
check('original title text preserved inside the anchor',
    strpos($title, 'Staged headline') !== false);
check('original content preserved inside the anchor',
    strpos($content, '<p>Draft body copy.</p>') !== false);

// Idempotency: re-running the filter does not double-wrap (deterministic).
check('title anchor is idempotent (no double wrap)',
    cinatra_anchor_the_title($title, 41) === $title);
check('content anchor is idempotent (no double wrap)',
    cinatra_anchor_the_content($content) === $content);

// ===========================================================================
echo "Test: NO visitor-visible change — filters inert on a normal front-end request\n";
// ===========================================================================
$GLOBALS['cinatra_preview_target'] = 0; // normal visitor: no preview render.
$GLOBALS['post'] = $GLOBALS['cinatra_test']['posts'][99];

check('the_title unchanged for a normal visitor',
    cinatra_anchor_the_title('Some other post', 99) === 'Some other post');
check('the_content unchanged for a normal visitor',
    cinatra_anchor_the_content('<p>Other body.</p>') === '<p>Other body.</p>');
check('the_excerpt unchanged for a normal visitor',
    cinatra_anchor_the_excerpt('x') === 'x');

// Guarded to the target: during a preview of post 41, a DIFFERENT post's title
// (e.g. a nav-menu item, adjacent post) is NOT wrapped.
$GLOBALS['cinatra_preview_target'] = 41;
$GLOBALS['post'] = $GLOBALS['cinatra_test']['posts'][41];
check('the_title of a NON-target post id is not wrapped during preview',
    cinatra_anchor_the_title('Menu item label', 99) === 'Menu item label');
check('the_content of a NON-target global post is not wrapped during preview',
    (function () {
        $GLOBALS['post'] = $GLOBALS['cinatra_test']['posts'][99];
        $out = cinatra_anchor_the_content('<p>Other body.</p>');
        $GLOBALS['post'] = $GLOBALS['cinatra_test']['posts'][41];
        return $out === '<p>Other body.</p>';
    })());

// reset render context
$GLOBALS['cinatra_preview_target'] = 0;

// ===========================================================================
echo "Test: preview auth — DENY paths (=> WordPress 401)\n";
// ===========================================================================
$now = time();

// (a) not host-connected: no shared secret.
$GLOBALS['cinatra_test']['options']['cinatra_webhook_secret'] = '';
$sig = preview_host_sign(PREVIEW_SECRET, 'msg_1', $now, 41);
check('deny when the site is not host-connected (no webhook secret)',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $now, 'webhook-signature' => $sig,
    ], 41)) === false);

// restore the secret for the remaining cases
$GLOBALS['cinatra_test']['options']['cinatra_webhook_secret'] = PREVIEW_SECRET;

check('deny when ALL signature headers are missing',
    cinatra_preview_authorize(make_request([], 41)) === false);
check('deny when only some signature headers are present',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $now,
    ], 41)) === false);
check('deny a FORGED signature',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $now, 'webhook-signature' => 'v1,' . base64_encode('not-the-real-mac'),
    ], 41)) === false);
check('deny a STALE timestamp (outside the freshness window)',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1',
        'webhook-timestamp' => (string) ($now - CINATRA_PREVIEW_TS_TOLERANCE - 5),
        'webhook-signature' => preview_host_sign(PREVIEW_SECRET, 'msg_1', $now - CINATRA_PREVIEW_TS_TOLERANCE - 5, 41),
    ], 41)) === false);
check('deny a non-numeric timestamp',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => 'not-a-number', 'webhook-signature' => $sig,
    ], 41)) === false);
// A signature minted for post 41 must NOT authorize a request for post 99.
check('deny a signature minted for a DIFFERENT post id (bound to id)',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $now,
        'webhook-signature' => preview_host_sign(PREVIEW_SECRET, 'msg_1', $now, 41),
    ], 99)) === false);
check('deny a zero / invalid id',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $now, 'webhook-signature' => $sig,
    ], 0)) === false);

// ===========================================================================
echo "Test: preview auth — ACCEPT a fresh, correctly-signed host request\n";
// ===========================================================================
check('accept a valid, fresh signature for the requested post',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_ok', 'webhook-timestamp' => (string) $now,
        'webhook-signature' => preview_host_sign(PREVIEW_SECRET, 'msg_ok', $now, 41),
    ], 41)) === true);
check('accept when the signature is one of a space-separated list (distinct id)',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_ok2', 'webhook-timestamp' => (string) $now,
        'webhook-signature' => 'v1,' . base64_encode('other') . ' ' . preview_host_sign(PREVIEW_SECRET, 'msg_ok2', $now, 41),
    ], 41)) === true);

// SINGLE-USE: replaying the EXACT same signed request (same webhook-id) is
// rejected even inside the freshness window — a captured valid request is not
// reusable.
check('deny a REPLAY of an already-consumed webhook-id (single-use)',
    cinatra_preview_authorize(make_request([
        'webhook-id' => 'msg_ok', 'webhook-timestamp' => (string) $now,
        'webhook-signature' => preview_host_sign(PREVIEW_SECRET, 'msg_ok', $now, 41),
    ], 41)) === false);

// ===========================================================================
echo "Test: preview render — a DRAFT is rendered to a full page WITH anchors\n";
// ===========================================================================
$html = cinatra_preview_render_post($GLOBALS['cinatra_test']['posts'][41]);
check('rendered page carries the draft body',
    strpos($html, 'Draft body copy.') !== false);
check('rendered page carries the title-region anchor',
    strpos($html, 'data-cinatra-region="title"') !== false);
check('rendered page carries the content-region anchor',
    strpos($html, 'data-cinatra-region="content"') !== false);
check('rendered page carries the excerpt-region anchor',
    strpos($html, 'data-cinatra-region="excerpt"') !== false);
check('rendered page marks the preview status (draft) for the host',
    strpos($html, 'data-cinatra-preview-status="draft"') !== false);
check('standalone document is emitted when the theme has no chrome',
    strpos($html, '<!DOCTYPE html>') === 0);
check('render restores the target flag to 0 afterwards (no leak into later renders)',
    cinatra_preview_target() === 0);
check('after the render, a normal the_content call is inert again',
    cinatra_anchor_the_content('<p>Other body.</p>') === '<p>Other body.</p>');

// ===========================================================================
echo "Test: preview callback — non-previewable objects are refused (no leak)\n";
// ===========================================================================
$missing = cinatra_rest_render_preview(make_request([], 4242));
check('missing post => WP_Error 404',
    $missing instanceof WP_Error);

// A private / non-public post type must not be previewable.
$GLOBALS['cinatra_test']['post_types'] = array('post' => ['public' => true], 'secret_cpt' => ['public' => false]);
$GLOBALS['cinatra_test']['posts'][77] = new WP_Post(array(
    'ID' => 77, 'post_type' => 'secret_cpt', 'post_status' => 'draft', 'post_title' => 'hidden',
));
$np = cinatra_rest_render_preview(make_request([], 77));
check('non-public post type => WP_Error (not previewable)',
    $np instanceof WP_Error);

// A valid draft through the full callback returns a response carrying the HTML.
$ok = cinatra_rest_render_preview(make_request([], 41));
check('valid draft => WP_REST_Response with cinatra_preview_html',
    $ok instanceof WP_REST_Response && is_array($ok->get_data()) && isset($ok->get_data()['cinatra_preview_html']));
check('response advertises the three owned regions',
    $ok instanceof WP_REST_Response && $ok->get_data()['regions'] === ['title', 'content', 'excerpt']);

// ---------------------------------------------------------------------------
if ($failures > 0) {
    echo "\nFAILED ($failures)\n";
    exit(1);
}
echo "\nAll preview + anchor tests passed.\n";
exit(0);
