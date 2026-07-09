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
check('server exposes both abilities as tools', ($srv[9] ?? null) === array('cinatra/post-get', 'cinatra/post-update'));
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

// ---------------------------------------------------------------------------
echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed.\n";
    exit(1);
}
echo "OK: all checks passed.\n";
exit(0);
