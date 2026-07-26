<?php
/**
 * Standalone behavior tests for the Cinatra content abilities + dedicated MCP
 * server (wp#1214 / S0). Runs under plain `php tests/test-abilities.php` — no
 * PHPUnit, no WordPress install. Exit code 0 = all pass, 1 = a failure.
 *
 * Covers:
 *   - cinatra/post-get + cinatra/post-update register on wp_abilities_api_init
 *     with the cinatra category, id-required schema, execute + permission
 *     callbacks, and meta.mcp.public (so the Adapter execute-ability fallback
 *     can run them too).
 *   - the cinatra ability category registers on wp_abilities_api_categories_init
 *     (idempotently).
 *   - post-get returns the raw post payload; a missing/typemismatched post is a
 *     WP_Error.
 *   - post-update applies only supplied fields (partial), preserves the
 *     demote-then-edit flow (status:"draft"), propagates a wp_update_post
 *     WP_Error, and rejects a no-field update.
 *   - page routing via postType.
 *   - the shared permission gate enforces current_user_can('edit_post', id).
 *   - the dedicated MCP server registers on mcp_adapter_init with the right
 *     id / namespace / route / tools / transport permission gate; a bad adapter
 *     object no-ops; the server is withheld until the abilities are registered.
 *
 * The "plugin still activates on a WordPress WITHOUT the Abilities API / MCP
 * Adapter" guarantee is proven by the php-lint + plugin-check CI jobs (they load
 * and activate the plugin in a stock WordPress with neither dependency present);
 * the function_exists()/is_object()/method_exists() guards below are the code
 * that makes that safe, and the adapter-side guards are exercised here directly.
 */

require __DIR__ . '/wp-stubs.php';

// ---------------------------------------------------------------------------
// Abilities API + post stubs specific to this test (capture into globals).
// ---------------------------------------------------------------------------
$GLOBALS['cinatra_test']['abilities']             = array(); // name => args
$GLOBALS['cinatra_test']['ability_categories']    = array(); // slug => args
$GLOBALS['cinatra_test']['posts']                 = array(); // id => WP_Post
$GLOBALS['cinatra_test']['wp_update_post_calls']  = array(); // captured $postarr
$GLOBALS['cinatra_test']['wp_update_post_return'] = null;    // override return (WP_Error)
$GLOBALS['cinatra_test']['mcp_servers']           = array(); // id => create_server args
$GLOBALS['cinatra_test']['wp_get_ability_force_null'] = false;

if (!function_exists('absint')) {
    function absint($n) { return abs((int) $n); }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        return $GLOBALS['cinatra_test']['posts'][(int) $id] ?? null;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($postarr, $wp_error = false) {
        $GLOBALS['cinatra_test']['wp_update_post_calls'][] = $postarr;
        $ret = $GLOBALS['cinatra_test']['wp_update_post_return'];
        if ($ret instanceof WP_Error) {
            return $wp_error ? $ret : 0;
        }
        // Apply to the fixture post so a follow-up get_post reflects the change.
        $id   = (int) ($postarr['ID'] ?? 0);
        $post = $GLOBALS['cinatra_test']['posts'][$id] ?? null;
        if ($post instanceof WP_Post) {
            if (array_key_exists('post_title', $postarr))   { $post->post_title = $postarr['post_title']; }
            if (array_key_exists('post_content', $postarr)) { $post->post_content = $postarr['post_content']; }
            if (array_key_exists('post_excerpt', $postarr)) { $post->post_excerpt = $postarr['post_excerpt']; }
            if (array_key_exists('post_status', $postarr))  { $post->post_status = $postarr['post_status']; }
        }
        return $id;
    }
}
if (!function_exists('wp_register_ability_category')) {
    function wp_register_ability_category($slug, $args) {
        $GLOBALS['cinatra_test']['ability_categories'][$slug] = $args;
        return (object) array('slug' => $slug);
    }
}
if (!function_exists('wp_has_ability_category')) {
    function wp_has_ability_category($slug) {
        return array_key_exists($slug, $GLOBALS['cinatra_test']['ability_categories']);
    }
}
if (!function_exists('wp_register_ability')) {
    function wp_register_ability($name, $args) {
        $GLOBALS['cinatra_test']['abilities'][$name] = $args;
        return (object) array('name' => $name);
    }
}
if (!function_exists('wp_get_ability')) {
    function wp_get_ability($name) {
        // Test override: simulate "abilities not registered yet" at server-reg time.
        if (!empty($GLOBALS['cinatra_test']['wp_get_ability_force_null'])) {
            return null;
        }
        return array_key_exists($name, $GLOBALS['cinatra_test']['abilities'])
            ? (object) array('name' => $name)
            : null;
    }
}

// ---------------------------------------------------------------------------
// wordpress-plugin#82 stubs: status / list / delete / media / draft / meta.
// The remaining in-admin primitives call these WordPress functions in-process;
// stub them so their behavior is exercised without a WordPress install.
// ---------------------------------------------------------------------------
if (!class_exists('WP_Query')) {
    class WP_Query {
        public $posts = array();
        public $found_posts = 0;
        public function __construct($args = array()) {
            $GLOBALS['cinatra_test']['wp_query_args'][] = $args;
            $type   = $args['post_type'] ?? 'post';
            $status = $args['post_status'] ?? 'publish';
            $all    = array();
            foreach (($GLOBALS['cinatra_test']['posts'] ?? array()) as $p) {
                if (!$p instanceof WP_Post) { continue; }
                if ($p->post_type === $type && $p->post_status === $status) { $all[] = $p; }
            }
            $this->found_posts = count($all);
            $offset            = (int) ($args['offset'] ?? 0);
            $limit             = (int) ($args['posts_per_page'] ?? 10);
            $this->posts       = array_slice($all, $offset, $limit);
        }
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post($id, $force = false) {
        $id      = (int) $id;
        $existed = isset($GLOBALS['cinatra_test']['posts'][$id]);
        $GLOBALS['cinatra_test']['wp_delete_post_calls'][] = array('id' => $id, 'force' => (bool) $force);
        $override = $GLOBALS['cinatra_test']['wp_delete_post_return'] ?? null;
        if (null !== $override) { return $override; }
        if (!$existed) { return false; }
        unset($GLOBALS['cinatra_test']['posts'][$id]);
        return (object) array('ID' => $id);
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr, $wp_error = false) {
        $GLOBALS['cinatra_test']['wp_insert_post_calls'][] = $postarr;
        $ret = $GLOBALS['cinatra_test']['wp_insert_post_return'] ?? null;
        if ($ret instanceof WP_Error) { return $wp_error ? $ret : 0; }
        $id = (int) ($GLOBALS['cinatra_test']['next_insert_id'] ?? 101);
        $GLOBALS['cinatra_test']['next_insert_id'] = $id + 1;
        $GLOBALS['cinatra_test']['posts'][$id] = new WP_Post(array(
            'ID'           => $id,
            'post_type'    => $postarr['post_type'] ?? 'post',
            'post_status'  => $postarr['post_status'] ?? 'draft',
            'post_title'   => $postarr['post_title'] ?? '',
            'post_content' => $postarr['post_content'] ?? '',
            'post_excerpt' => $postarr['post_excerpt'] ?? '',
            'permalink'    => 'https://blog.example/?p=' . $id,
        ));
        return $id;
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        $GLOBALS['cinatra_test']['post_meta'][(int) $id][$key] = $value;
        $GLOBALS['cinatra_test']['update_post_meta_calls'][]   = array('id' => (int) $id, 'key' => $key, 'value' => $value);
        return true;
    }
}
if (!function_exists('wp_upload_bits')) {
    function wp_upload_bits($name, $deprecated, $bits) {
        $GLOBALS['cinatra_test']['wp_upload_bits_calls'][] = array('name' => $name, 'bytes' => strlen((string) $bits));
        $override = $GLOBALS['cinatra_test']['wp_upload_bits_return'] ?? null;
        if (is_array($override)) { return $override; }
        return array(
            'file'  => '/tmp/uploads/' . $name,
            'url'   => 'https://blog.example/wp-content/uploads/' . $name,
            'error' => false,
        );
    }
}
if (!function_exists('wp_insert_attachment')) {
    function wp_insert_attachment($args, $file = false, $parent = 0, $wp_error = false) {
        $GLOBALS['cinatra_test']['wp_insert_attachment_calls'][] = array('args' => $args, 'file' => $file);
        $ret = $GLOBALS['cinatra_test']['wp_insert_attachment_return'] ?? null;
        if ($ret instanceof WP_Error) { return $ret; }
        return (int) (null !== $ret ? $ret : 555);
    }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id) {
        return 'https://blog.example/wp-content/uploads/attachment-' . (int) $id . '.png';
    }
}
if (!function_exists('wp_get_mime_types')) {
    function wp_get_mime_types() {
        return array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'gif'          => 'image/gif',
        );
    }
}
if (!function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata($id, $file) { return array('width' => 1, 'height' => 1); }
}
if (!function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata($id, $data) {
        $GLOBALS['cinatra_test']['attachment_metadata'][(int) $id] = $data;
        return true;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9]+/', '-', $title);
        return trim($title, '-');
    }
}

require dirname(__DIR__) . '/cinatra.php';

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
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

function reset_posts() {
    $GLOBALS['cinatra_test']['posts'] = array(
        4 => new WP_Post(array(
            'ID'           => 4,
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => 'Original title',
            'post_content' => 'Original body',
            'post_excerpt' => 'Original excerpt',
            'post_name'    => 'original-title',
            'permalink'    => 'https://blog.example/?p=4',
        )),
        7 => new WP_Post(array(
            'ID'           => 7,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'About',
            'post_content' => 'Page body',
            'post_name'    => 'about',
            'permalink'    => 'https://blog.example/about/',
        )),
    );
    $GLOBALS['cinatra_test']['wp_update_post_calls']     = array();
    $GLOBALS['cinatra_test']['wp_update_post_return']    = null;
    $GLOBALS['cinatra_test']['wp_get_ability_force_null'] = false;
    $GLOBALS['cinatra_test']['current_user_can']         = true;
    $GLOBALS['cinatra_test']['caps']                     = null;
    // wordpress-plugin#82 capture/override state.
    $GLOBALS['cinatra_test']['wp_delete_post_calls']       = array();
    $GLOBALS['cinatra_test']['wp_delete_post_return']      = null;
    $GLOBALS['cinatra_test']['wp_insert_post_calls']       = array();
    $GLOBALS['cinatra_test']['wp_insert_post_return']      = null;
    $GLOBALS['cinatra_test']['next_insert_id']             = 101;
    $GLOBALS['cinatra_test']['update_post_meta_calls']     = array();
    $GLOBALS['cinatra_test']['post_meta']                  = array();
    $GLOBALS['cinatra_test']['wp_upload_bits_calls']       = array();
    $GLOBALS['cinatra_test']['wp_upload_bits_return']      = null;
    $GLOBALS['cinatra_test']['wp_insert_attachment_calls'] = array();
    $GLOBALS['cinatra_test']['wp_insert_attachment_return'] = null;
    $GLOBALS['cinatra_test']['wp_query_args']              = array();
}

// ---------------------------------------------------------------------------
// A. Ability category registration (wp_abilities_api_categories_init).
// ---------------------------------------------------------------------------
echo "Ability category\n";
$GLOBALS['cinatra_test']['ability_categories'] = array();
cinatra_test_do_action('wp_abilities_api_categories_init');
check('cinatra category registered', isset($GLOBALS['cinatra_test']['ability_categories']['cinatra']));
check('cinatra category has a non-empty description', !empty($GLOBALS['cinatra_test']['ability_categories']['cinatra']['description'] ?? ''));
// Idempotency: re-firing the hook must not double-register or error.
cinatra_test_do_action('wp_abilities_api_categories_init');
check('cinatra category still present after re-fire', wp_has_ability_category('cinatra'));

// ---------------------------------------------------------------------------
// B. Ability registration (wp_abilities_api_init).
// ---------------------------------------------------------------------------
echo "Ability registration\n";
$GLOBALS['cinatra_test']['abilities'] = array();
cinatra_test_do_action('wp_abilities_api_init');
$ab  = $GLOBALS['cinatra_test']['abilities'];
$get = $ab['cinatra/post-get'] ?? array();
$upd = $ab['cinatra/post-update'] ?? array();
check('cinatra/post-get registered', isset($ab['cinatra/post-get']));
check('cinatra/post-update registered', isset($ab['cinatra/post-update']));
check('post-get category is cinatra', ($get['category'] ?? null) === 'cinatra');
check('post-get execute_callback wired', ($get['execute_callback'] ?? null) === 'cinatra_ability_post_get');
check('post-get permission_callback wired', ($get['permission_callback'] ?? null) === 'cinatra_ability_can_edit');
check('post-get requires id', in_array('id', $get['input_schema']['required'] ?? array(), true));
check('post-get is mcp.public (execute-ability fallback)', ($get['meta']['mcp']['public'] ?? false) === true);
check('post-get readonly annotation', ($get['meta']['annotations']['readonly'] ?? null) === true);
check('post-get show_in_rest', ($get['meta']['show_in_rest'] ?? null) === true);
check('post-update execute_callback wired', ($upd['execute_callback'] ?? null) === 'cinatra_ability_post_update');
check('post-update permission_callback wired', ($upd['permission_callback'] ?? null) === 'cinatra_ability_can_edit');
check('post-update category is cinatra', ($upd['category'] ?? null) === 'cinatra');
check('post-update accepts status draft (demote-then-edit)', in_array('draft', $upd['input_schema']['properties']['status']['enum'] ?? array(), true));
check('post-update is not readonly', ($upd['meta']['annotations']['readonly'] ?? null) === false);
check('post-update is mcp.public', ($upd['meta']['mcp']['public'] ?? false) === true);
check('post-update postType enum is post/page', ($upd['input_schema']['properties']['postType']['enum'] ?? null) === array('post', 'page'));

// wordpress-plugin#82: the six rehomed primitives register with the right
// callbacks + OPERATION-SPECIFIC permission gate (not a blanket edit/publish cap).
$st  = $ab['cinatra/post-status'] ?? array();
$ls  = $ab['cinatra/posts-list'] ?? array();
$del = $ab['cinatra/post-delete'] ?? array();
$med = $ab['cinatra/media-upload'] ?? array();
$dft = $ab['cinatra/post-create-draft'] ?? array();
$mta = $ab['cinatra/post-update-meta'] ?? array();
check('cinatra/post-status registered', isset($ab['cinatra/post-status']));
check('cinatra/posts-list registered', isset($ab['cinatra/posts-list']));
check('cinatra/post-delete registered', isset($ab['cinatra/post-delete']));
check('cinatra/media-upload registered', isset($ab['cinatra/media-upload']));
check('cinatra/post-create-draft registered', isset($ab['cinatra/post-create-draft']));
check('cinatra/post-update-meta registered', isset($ab['cinatra/post-update-meta']));
check('post-status execute wired', ($st['execute_callback'] ?? null) === 'cinatra_ability_post_status');
check('post-status gate is per-object status-read', ($st['permission_callback'] ?? null) === 'cinatra_ability_can_read_status');
check('post-status is readonly', ($st['meta']['annotations']['readonly'] ?? null) === true);
check('posts-list execute wired', ($ls['execute_callback'] ?? null) === 'cinatra_ability_posts_list');
check('posts-list gate is type-level edit', ($ls['permission_callback'] ?? null) === 'cinatra_ability_can_edit_type');
check('posts-list postType enum is post/page', ($ls['input_schema']['properties']['postType']['enum'] ?? null) === array('post', 'page'));
check('post-delete execute wired', ($del['execute_callback'] ?? null) === 'cinatra_ability_post_delete');
check('post-delete gate is per-object delete', ($del['permission_callback'] ?? null) === 'cinatra_ability_can_delete');
check('post-delete annotation is destructive', ($del['meta']['annotations']['destructive'] ?? null) === true);
check('media-upload execute wired', ($med['execute_callback'] ?? null) === 'cinatra_ability_media_upload');
check('media-upload gate is upload_files', ($med['permission_callback'] ?? null) === 'cinatra_ability_can_upload');
check('media-upload requires image fields', in_array('imageBase64', $med['input_schema']['required'] ?? array(), true));
check('post-create-draft execute wired', ($dft['execute_callback'] ?? null) === 'cinatra_ability_post_create_draft');
check('post-create-draft gate is type-level edit', ($dft['permission_callback'] ?? null) === 'cinatra_ability_can_edit_type');
check('post-update-meta execute wired', ($mta['execute_callback'] ?? null) === 'cinatra_ability_post_update_meta');
check('post-update-meta gate is per-object edit', ($mta['permission_callback'] ?? null) === 'cinatra_ability_can_edit');
check('post-update-meta requires meta', in_array('meta', $mta['input_schema']['required'] ?? array(), true));
foreach (array('cinatra/post-status', 'cinatra/posts-list', 'cinatra/post-delete', 'cinatra/media-upload', 'cinatra/post-create-draft', 'cinatra/post-update-meta') as $__id) {
    check("$__id is mcp.public", (($ab[$__id]['meta']['mcp']['public'] ?? false) === true));
    check("$__id category is cinatra", (($ab[$__id]['category'] ?? null) === 'cinatra'));
}

// ---------------------------------------------------------------------------
// C. post-get behavior.
// ---------------------------------------------------------------------------
echo "post-get\n";
reset_posts();
$r = cinatra_ability_post_get(array('id' => 4));
check('post-get returns an array', is_array($r));
check('post-get id', ($r['id'] ?? null) === 4);
check('post-get postType', ($r['postType'] ?? null) === 'post');
check('post-get title', ($r['title'] ?? null) === 'Original title');
check('post-get carries raw content before-value', ($r['content'] ?? null) === 'Original body');
check('post-get status', ($r['status'] ?? null) === 'publish');
check('post-get missing id -> WP_Error', cinatra_ability_post_get(array('id' => 999)) instanceof WP_Error);
check('post-get no id -> WP_Error', cinatra_ability_post_get(array()) instanceof WP_Error);

// ---------------------------------------------------------------------------
// D. Page routing via postType.
// ---------------------------------------------------------------------------
echo "postType routing\n";
$pg = cinatra_ability_post_get(array('id' => 7, 'postType' => 'page'));
check('post-get resolves a page via postType', is_array($pg) && ($pg['postType'] ?? null) === 'page');
check('post-get type mismatch (page id as post) -> WP_Error', cinatra_ability_post_get(array('id' => 7, 'postType' => 'post')) instanceof WP_Error);

// ---------------------------------------------------------------------------
// E. post-update demote-then-edit.
// ---------------------------------------------------------------------------
echo "post-update demote-then-edit\n";
reset_posts();
$r = cinatra_ability_post_update(array('id' => 4, 'title' => 'New title', 'content' => 'New body', 'status' => 'draft'));
$calls = $GLOBALS['cinatra_test']['wp_update_post_calls'];
$call  = $calls[0] ?? array();
check('post-update returns an array', is_array($r));
check('wp_update_post called exactly once', count($calls) === 1);
check('update targets ID 4', ($call['ID'] ?? null) === 4);
check('update demotes to draft', ($call['post_status'] ?? null) === 'draft');
check('update sets post_title', ($call['post_title'] ?? null) === 'New title');
check('update sets post_content', ($call['post_content'] ?? null) === 'New body');
check('post-update reflects new status', ($r['status'] ?? null) === 'draft');
check('post-update reflects new title', ($r['title'] ?? null) === 'New title');

// ---------------------------------------------------------------------------
// F. Partial update (only content supplied).
// ---------------------------------------------------------------------------
echo "post-update partial\n";
reset_posts();
cinatra_ability_post_update(array('id' => 4, 'content' => 'Only body changed'));
$call = $GLOBALS['cinatra_test']['wp_update_post_calls'][0] ?? array();
check('partial update carries content', ($call['post_content'] ?? null) === 'Only body changed');
check('partial update omits post_title', !array_key_exists('post_title', $call));
check('partial update omits post_status', !array_key_exists('post_status', $call));

// ---------------------------------------------------------------------------
// G. No-field update is rejected.
// ---------------------------------------------------------------------------
echo "post-update guards\n";
reset_posts();
check('no-field update -> WP_Error', cinatra_ability_post_update(array('id' => 4)) instanceof WP_Error);
check('no-field update did not call wp_update_post', count($GLOBALS['cinatra_test']['wp_update_post_calls']) === 0);

// H. wp_update_post failure propagates.
reset_posts();
$GLOBALS['cinatra_test']['wp_update_post_return'] = new WP_Error('db_fail', 'boom');
check('wp_update_post WP_Error propagates', cinatra_ability_post_update(array('id' => 4, 'title' => 'x')) instanceof WP_Error);

// I. Update of a missing post -> WP_Error, no wp_update_post call.
reset_posts();
check('post-update missing id -> WP_Error', cinatra_ability_post_update(array('id' => 999, 'title' => 'x')) instanceof WP_Error);
check('post-update missing id did not call wp_update_post', count($GLOBALS['cinatra_test']['wp_update_post_calls']) === 0);

// ---------------------------------------------------------------------------
// I2. Publish-capability gate (privilege-escalation guard). status publish /
// private requires the post type's publish capability — NOT merely edit_post —
// mirroring WP_REST_Posts_Controller::handle_status_param. Demotion to draft /
// pending needs only edit ( which the adapter has already gated on ).
// ---------------------------------------------------------------------------
echo "post-update publish-cap gate\n";
reset_posts();
$GLOBALS['cinatra_test']['caps'] = array( 'publish_posts' => false, 'unfiltered_html' => true );
check('publish without publish_posts -> WP_Error', cinatra_ability_post_update(array('id' => 4, 'status' => 'publish')) instanceof WP_Error);
check('blocked publish did not call wp_update_post', count($GLOBALS['cinatra_test']['wp_update_post_calls']) === 0);
check('private without publish_posts -> WP_Error', cinatra_ability_post_update(array('id' => 4, 'status' => 'private')) instanceof WP_Error);

reset_posts();
$GLOBALS['cinatra_test']['caps'] = array( 'publish_posts' => false, 'unfiltered_html' => true );
$r = cinatra_ability_post_update(array('id' => 4, 'status' => 'draft', 'title' => 'Demoted'));
check('demote to draft allowed without publish cap', is_array($r) && ($r['status'] ?? null) === 'draft');
check('set pending allowed without publish cap', !(cinatra_ability_post_update(array('id' => 4, 'status' => 'pending')) instanceof WP_Error));

reset_posts();
$GLOBALS['cinatra_test']['caps'] = array( 'publish_posts' => true, 'unfiltered_html' => true );
$r = cinatra_ability_post_update(array('id' => 4, 'status' => 'publish'));
check('publish allowed with publish_posts', is_array($r) && ($r['status'] ?? null) === 'publish');
reset_posts();

// ---------------------------------------------------------------------------
// J. Permission gate.
// ---------------------------------------------------------------------------
echo "permission gate\n";
reset_posts();
$GLOBALS['cinatra_test']['current_user_can'] = true;
check('can_edit true when user can edit_post', cinatra_ability_can_edit(array('id' => 4)) === true);
$GLOBALS['cinatra_test']['current_user_can'] = false;
check('can_edit false when user cannot', cinatra_ability_can_edit(array('id' => 4)) === false);
$GLOBALS['cinatra_test']['current_user_can'] = true;
check('can_edit false for missing id', cinatra_ability_can_edit(array()) === false);
check('can_edit false for id 0', cinatra_ability_can_edit(array('id' => 0)) === false);
check('can_edit tolerates non-array input', cinatra_ability_can_edit('nope') === false);

// ---------------------------------------------------------------------------
// K. Dedicated MCP server registration (mcp_adapter_init).
// ---------------------------------------------------------------------------
echo "MCP server\n";
reset_posts();
$GLOBALS['cinatra_test']['abilities'] = array();
cinatra_test_do_action('wp_abilities_api_init'); // so wp_get_ability finds them
$GLOBALS['cinatra_test']['mcp_servers'] = array();
$adapter = new class {
    // Variadic capture of the positional create_server() arguments.
    public function create_server(...$args) {
        $GLOBALS['cinatra_test']['mcp_servers'][$args[0]] = $args;
        return true;
    }
};
cinatra_register_mcp_content_server($adapter);
$srv = $GLOBALS['cinatra_test']['mcp_servers']['cinatra-content-server'] ?? null;
check('MCP server registered', is_array($srv));
check('server namespace is mcp (-> /wp-json/mcp/...)', ($srv[1] ?? null) === 'mcp');
check('server route is cinatra-content-server', ($srv[2] ?? null) === 'cinatra-content-server');
check('server exposes all content abilities as tools', ($srv[9] ?? null) === array(
    'cinatra/post-get',
    'cinatra/post-update',
    'cinatra/post-status',
    'cinatra/posts-list',
    'cinatra/post-delete',
    'cinatra/media-upload',
    'cinatra/post-create-draft',
    'cinatra/post-update-meta',
));
check('content-server ability-id list matches the tools array', cinatra_content_server_ability_ids() === ($srv[9] ?? null));
check('server transport permission gate wired', ($srv[12] ?? null) === 'cinatra_mcp_content_server_permission');
check('server passes a non-empty transports array', is_array($srv[6] ?? null) && count($srv[6]) === 1);

// Guards.
$GLOBALS['cinatra_test']['mcp_servers'] = array();
cinatra_register_mcp_content_server('not-an-object');
check('non-object adapter no-ops', count($GLOBALS['cinatra_test']['mcp_servers']) === 0);
cinatra_register_mcp_content_server(new stdClass());
check('adapter without create_server no-ops', count($GLOBALS['cinatra_test']['mcp_servers']) === 0);
$GLOBALS['cinatra_test']['wp_get_ability_force_null'] = true;
cinatra_register_mcp_content_server($adapter);
check('server withheld until abilities are registered', count($GLOBALS['cinatra_test']['mcp_servers']) === 0);
$GLOBALS['cinatra_test']['wp_get_ability_force_null'] = false;

// Server withheld when only ONE ability is registered (partial-registration guard).
$GLOBALS['cinatra_test']['mcp_servers'] = array();
$GLOBALS['cinatra_test']['abilities']   = array( 'cinatra/post-get' => array() );
cinatra_register_mcp_content_server($adapter);
check('server withheld when the post-update ability is missing', count($GLOBALS['cinatra_test']['mcp_servers']) === 0);

// L. Transport permission callback.
$GLOBALS['cinatra_test']['current_user_can'] = true;
check('server permission true when user can edit_posts', cinatra_mcp_content_server_permission(null) === true);
$GLOBALS['cinatra_test']['current_user_can'] = false;
check('server permission false otherwise', cinatra_mcp_content_server_permission(null) === false);

// ===========================================================================
// wordpress-plugin#82 behavior: status / list / delete / media / draft / meta.
// ===========================================================================

// M. post-status.
echo "post-status\n";
reset_posts();
$s = cinatra_ability_post_status(array('id' => 4));
check('post-status returns array', is_array($s));
check('post-status id', ($s['id'] ?? null) === 4);
check('post-status status', ($s['status'] ?? null) === 'publish');
check('post-status link populated for published post', !empty($s['link'] ?? ''));
$sp = cinatra_ability_post_status(array('id' => 7, 'postType' => 'page'));
check('post-status resolves a page', ($sp['postType'] ?? null) === 'page');
$sd = cinatra_ability_post_status(array('id' => 999));
check('post-status of a missing post -> deleted sentinel', ($sd['status'] ?? null) === 'deleted');
check('post-status deleted sentinel carries the id', ($sd['id'] ?? null) === 999);

// N. posts-list.
echo "posts-list\n";
reset_posts();
// Seed several published posts + one draft (must be excluded) + a page.
$GLOBALS['cinatra_test']['posts'] = array();
for ($i = 1; $i <= 3; $i++) {
    $GLOBALS['cinatra_test']['posts'][$i] = new WP_Post(array(
        'ID' => $i, 'post_type' => 'post', 'post_status' => 'publish',
        'post_title' => "Post $i", 'permalink' => "https://blog.example/?p=$i",
        'post_date_gmt' => "2026-06-0$i 00:00:00",
    ));
}
$GLOBALS['cinatra_test']['posts'][9] = new WP_Post(array(
    'ID' => 9, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Hidden draft',
));
$GLOBALS['cinatra_test']['posts'][20] = new WP_Post(array(
    'ID' => 20, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'A page',
));
$l = cinatra_ability_posts_list(array('perPage' => 10));
check('posts-list returns items+total', is_array($l) && isset($l['items'], $l['total']));
check('posts-list counts only published posts (excludes draft + page)', ($l['total'] ?? null) === 3);
check('posts-list items are published posts only', count($l['items']) === 3);
check('posts-list item carries id/title/status/date/url', isset($l['items'][0]['id'], $l['items'][0]['title'], $l['items'][0]['status'], $l['items'][0]['date'], $l['items'][0]['url']));
// Offset pagination.
$lp = cinatra_ability_posts_list(array('perPage' => 2, 'offset' => 2));
check('posts-list honors offset+perPage', count($lp['items']) === 1 && ($lp['total'] ?? null) === 3);
// postType routing to pages.
$lpg = cinatra_ability_posts_list(array('postType' => 'page'));
check('posts-list postType=page lists pages', ($lpg['total'] ?? null) === 1 && ($lpg['items'][0]['id'] ?? null) === 20);

// O. post-delete.
echo "post-delete\n";
reset_posts();
$d = cinatra_ability_post_delete(array('id' => 4));
check('post-delete returns deleted:true', is_array($d) && ($d['deleted'] ?? null) === true);
check('post-delete reports previousStatus', ($d['previousStatus'] ?? null) === 'publish');
check('post-delete called wp_delete_post with force=false by default', ($GLOBALS['cinatra_test']['wp_delete_post_calls'][0]['force'] ?? null) === false);
reset_posts();
cinatra_ability_post_delete(array('id' => 4, 'force' => true));
check('post-delete force=true passes force to wp_delete_post', ($GLOBALS['cinatra_test']['wp_delete_post_calls'][0]['force'] ?? null) === true);
reset_posts();
check('post-delete missing post -> WP_Error', cinatra_ability_post_delete(array('id' => 999)) instanceof WP_Error);
check('post-delete missing post did not call wp_delete_post', count($GLOBALS['cinatra_test']['wp_delete_post_calls']) === 0);
reset_posts();
$GLOBALS['cinatra_test']['wp_delete_post_return'] = false;
check('post-delete wp_delete_post false -> WP_Error', cinatra_ability_post_delete(array('id' => 4)) instanceof WP_Error);

// P. media-upload. Use a REAL 1x1 PNG so the content sniff (getimagesizefromstring)
// recognizes it — the allowlist + sniff reject anything that is not a real image.
echo "media-upload\n";
reset_posts();
$png_bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$png = base64_encode($png_bytes);
$m = cinatra_ability_media_upload(array('imageBase64' => $png, 'imageMimeType' => 'image/png', 'title' => 'My Featured Image'));
check('media-upload returns mediaId', is_array($m) && ($m['mediaId'] ?? null) === 555);
check('media-upload returns a sourceUrl', !empty($m['sourceUrl'] ?? ''));
check('media-upload wrote the decoded bytes via wp_upload_bits', ($GLOBALS['cinatra_test']['wp_upload_bits_calls'][0]['bytes'] ?? null) === strlen($png_bytes));
check('media-upload derived a .png filename', str_ends_with((string) ($GLOBALS['cinatra_test']['wp_upload_bits_calls'][0]['name'] ?? ''), '.png'));
check('media-upload missing base64 -> WP_Error', cinatra_ability_media_upload(array('imageMimeType' => 'image/png', 'title' => 'x')) instanceof WP_Error);
check('media-upload bad base64 -> WP_Error', cinatra_ability_media_upload(array('imageBase64' => '!!!not-base64!!!', 'imageMimeType' => 'image/png', 'title' => 'x')) instanceof WP_Error);
check('media-upload unsupported declared mime -> WP_Error', cinatra_ability_media_upload(array('imageBase64' => $png, 'imageMimeType' => 'application/x-msdownload', 'title' => 'x')) instanceof WP_Error);
// Content sniff: image MIME declared, but the bytes are NOT an image -> refused
// (a mislabeled script/HTML/exe payload cannot pass the upload_files gate).
reset_posts();
$not_image = base64_encode('<?php echo "not an image"; ?>');
check('media-upload non-image bytes with image mime -> WP_Error (content sniff)', cinatra_ability_media_upload(array('imageBase64' => $not_image, 'imageMimeType' => 'image/png', 'title' => 'x')) instanceof WP_Error);
check('media-upload rejected non-image never wrote a file', count($GLOBALS['cinatra_test']['wp_upload_bits_calls']) === 0);

// Q. post-create-draft.
echo "post-create-draft\n";
reset_posts();
$c = cinatra_ability_post_create_draft(array('title' => 'A new draft', 'content' => '<p>Body</p>'));
check('create-draft returns id', is_array($c) && (int) ($c['id'] ?? 0) > 0);
check('create-draft status is draft', ($c['status'] ?? null) === 'draft');
$call = $GLOBALS['cinatra_test']['wp_insert_post_calls'][0] ?? array();
check('create-draft pins post_status to draft', ($call['post_status'] ?? null) === 'draft');
check('create-draft sets the title', ($call['post_title'] ?? null) === 'A new draft');
reset_posts();
check('create-draft empty title+content -> WP_Error', cinatra_ability_post_create_draft(array('title' => '', 'content' => '')) instanceof WP_Error);
check('create-draft empty input did not insert', count($GLOBALS['cinatra_test']['wp_insert_post_calls']) === 0);
reset_posts();
$GLOBALS['cinatra_test']['wp_insert_post_return'] = new WP_Error('db_fail', 'boom');
check('create-draft insert WP_Error propagates', cinatra_ability_post_create_draft(array('title' => 'x', 'content' => 'y')) instanceof WP_Error);

// R. post-update-meta (protected-meta guard).
echo "post-update-meta\n";
reset_posts();
$r = cinatra_ability_post_update_meta(array('id' => 4, 'meta' => array('my_key' => 'v1', 'other' => 'v2')));
check('update-meta returns id+updated', is_array($r) && ($r['id'] ?? null) === 4);
check('update-meta reports both updated keys', ($r['updated'] ?? null) === array('my_key', 'other'));
check('update-meta wrote via update_post_meta twice', count($GLOBALS['cinatra_test']['update_post_meta_calls']) === 2);
reset_posts();
check('update-meta empty meta -> WP_Error', cinatra_ability_post_update_meta(array('id' => 4, 'meta' => array())) instanceof WP_Error);
check('update-meta missing post -> WP_Error', cinatra_ability_post_update_meta(array('id' => 999, 'meta' => array('k' => 'v'))) instanceof WP_Error);
// Protected-meta guard: a key the user cannot write (edit_post_meta denied) aborts
// the WHOLE call with NO partial write.
reset_posts();
$GLOBALS['cinatra_test']['caps'] = array('edit_post_meta' => false);
$forbidden = cinatra_ability_post_update_meta(array('id' => 4, 'meta' => array('_protected' => 'x', 'ok' => 'y')));
check('update-meta protected key -> WP_Error (no privilege escalation)', $forbidden instanceof WP_Error);
check('update-meta protected key wrote NOTHING (no partial write)', count($GLOBALS['cinatra_test']['update_post_meta_calls']) === 0);

// S. Operation-specific permission gates.
echo "operation-specific gates\n";
reset_posts();
$GLOBALS['cinatra_test']['caps'] = array('edit_posts' => true);
check('can_edit_type true with edit_posts', cinatra_ability_can_edit_type(array('postType' => 'post')) === true);
$GLOBALS['cinatra_test']['caps'] = array('edit_posts' => false);
check('can_edit_type false without edit_posts', cinatra_ability_can_edit_type(array('postType' => 'post')) === false);
$GLOBALS['cinatra_test']['caps'] = array('delete_post' => true);
check('can_delete true with delete_post', cinatra_ability_can_delete(array('id' => 4)) === true);
$GLOBALS['cinatra_test']['caps'] = array('delete_post' => false);
check('can_delete false without delete_post', cinatra_ability_can_delete(array('id' => 4)) === false);
check('can_delete false for missing id', cinatra_ability_can_delete(array()) === false);
$GLOBALS['cinatra_test']['caps'] = array('upload_files' => true);
check('can_upload true with upload_files', cinatra_ability_can_upload(array()) === true);
$GLOBALS['cinatra_test']['caps'] = array('upload_files' => false);
check('can_upload false without upload_files', cinatra_ability_can_upload(array()) === false);
$GLOBALS['cinatra_test']['caps'] = null;

// post-status gate: per-object edit_post on an EXISTING post (no status leak);
// deleted/missing falls back to the type-level edit gate (deleted-sentinel path).
reset_posts();
$GLOBALS['cinatra_test']['caps'] = array('edit_post' => true, 'edit_posts' => true);
check('can_read_status true when user can edit the existing post', cinatra_ability_can_read_status(array('id' => 4)) === true);
$GLOBALS['cinatra_test']['caps'] = array('edit_post' => false, 'edit_posts' => true);
check('can_read_status false for an existing post the user cannot edit (no leak)', cinatra_ability_can_read_status(array('id' => 4)) === false);
// Missing post: falls back to type-level edit (so a legit editor still sees the sentinel).
$GLOBALS['cinatra_test']['caps'] = array('edit_post' => false, 'edit_posts' => true);
check('can_read_status of a missing post falls back to type-level edit (allow)', cinatra_ability_can_read_status(array('id' => 999)) === true);
$GLOBALS['cinatra_test']['caps'] = array('edit_post' => true, 'edit_posts' => false);
check('can_read_status of a missing post denied without type-level edit', cinatra_ability_can_read_status(array('id' => 999)) === false);
check('can_read_status false for missing id', cinatra_ability_can_read_status(array()) === false);
$GLOBALS['cinatra_test']['caps'] = null;

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed.\n";
    exit(1);
}
echo "OK: all checks passed.\n";
exit(0);
