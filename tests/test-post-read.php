<?php
/**
 * Standalone behavior tests for the content ability cinatra/post-read
 * (cinatra-ai/cinatra#3745). Runs under plain `php tests/test-post-read.php`:
 * no PHPUnit, no WordPress install, no database. Exit code 0 = all pass.
 *
 * Covers: the raw stored values straight from the posts table (not the object
 * cache); the row token over every column and its canonical encoding; the
 * permalink computed from the row read; the same not_found for a missing
 * post, a post of another type and a post the caller may not edit; db_error
 * for a failed read; the registration.
 */

require __DIR__ . '/wp-stubs.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Recording stand-in for $wpdb: SELECT * FROM wp_posts WHERE ID = n. */
class wpdb {
    public $posts = 'wp_posts';
    public $rows = [];
    public $log = [];
    public $last_error = '';
    public $fail = false;
    public function prepare($query, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    public function get_row($sql, $output = null) {
        $this->log[] = $sql;
        $this->last_error = '';
        if ($this->fail) {
            $this->last_error = 'forced read failure';
            return null;
        }
        if (!preg_match('/^SELECT \* FROM wp_posts WHERE ID = (\d+)$/', $sql, $m)) {
            return null;
        }
        return $this->rows[(int) $m[1]] ?? null;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return 0 < (int) ($GLOBALS['cinatra_test']['current_user_id'] ?? 0); }
}
if (!function_exists('sanitize_post')) {
    /** Core's sanitize_post( $post, 'raw' ) for an object: the values unchanged, filter "raw". */
    function sanitize_post($post, $context = 'display') {
        $post = (object) $post;
        $post->filter = $context;
        return $post;
    }
}
if (!function_exists('get_post')) {
    /** As core: the object cache first; on a miss the row from the database, cached. */
    function get_post($id = null) {
        global $wpdb;
        $id = (int) $id;
        if (!isset($GLOBALS['cinatra_test']['post_cache'][$id])) {
            if (!isset($wpdb->rows[$id])) {
                return null;
            }
            $GLOBALS['cinatra_test']['post_cache'][$id] = $wpdb->rows[$id];
        }
        return new WP_Post($GLOBALS['cinatra_test']['post_cache'][$id]);
    }
}
if (!function_exists('wp_cache_delete')) {
    /** The posts group of the object cache: drops one entry, nothing else. */
    function wp_cache_delete($key, $group = '') {
        $GLOBALS['cinatra_test']['cache_deletes'][] = [$key, $group];
        if ('posts' === $group) {
            unset($GLOBALS['cinatra_test']['post_cache'][(int) $key]);
        }
        return true;
    }
}
if (!function_exists('clean_post_cache')) {
    /** Core's broad clean-up ( post, meta, terms, queries, hooks ): recorded so a test can assert it never runs. */
    function clean_post_cache($id) {
        $GLOBALS['cinatra_test']['clean_post_cache'][] = (int) $id;
        unset($GLOBALS['cinatra_test']['post_cache'][(int) $id]);
    }
}
if (!function_exists('wp_register_ability')) {
    function wp_register_ability($id, $args) {
        $GLOBALS['cinatra_test_abilities'][$id] = $args;
        return true;
    }
}
if (!function_exists('wp_register_ability_category')) {
    function wp_register_ability_category($id, $args) {
        $GLOBALS['cinatra_test_categories'][$id] = $args;
        return true;
    }
}
if (!function_exists('wp_has_ability_category')) {
    function wp_has_ability_category($id) {
        return isset($GLOBALS['cinatra_test_categories'][$id]);
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

const T_ID      = 42;
const T_POST_ID = 43;

/** A full posts row, every column as wpdb returns it ( strings, one NULL-able ). */
function t_row(int $id, string $type, array $over = []): array {
    return array_merge([
        'ID'                    => (string) $id,
        'post_author'           => '3',
        'post_date'             => '2026-01-01 10:00:00',
        'post_date_gmt'         => '2026-01-01 09:00:00',
        'post_content'          => '<p>Body with "quotes" and a back\\slash</p>',
        'post_title'            => 'About "us"',
        'post_excerpt'          => 'Old excerpt',
        'post_status'           => 'publish',
        'comment_status'        => 'closed',
        'ping_status'           => 'closed',
        'post_password'         => '',
        'post_name'             => 'about',
        'to_ping'               => '',
        'pinged'                => '',
        'post_modified'         => '2026-11-25 11:00:00',
        'post_modified_gmt'     => '2026-11-25 10:00:00',
        'post_content_filtered' => '',
        'post_parent'           => '0',
        'guid'                  => 'https://blog.example/?p=' . $id,
        'menu_order'            => '0',
        'post_type'             => $type,
        'post_mime_type'        => '',
        'comment_count'         => '0',
    ], $over);
}

function reset_fixture(array $overrides = []) {
    global $wpdb;
    $wpdb = new wpdb();
    $wpdb->rows = [
        T_ID      => t_row(T_ID, 'page'),
        T_POST_ID => t_row(T_POST_ID, 'post', ['post_name' => 'news']),
        7         => t_row(7, 'attachment', ['post_status' => 'inherit']),
    ];
    $GLOBALS['cinatra_test'] = array_merge([
        'current_user_id' => 3,
        'permalink_base'  => 'https://blog.example/',
        'post_cache'      => [],
        // edit_post as map_meta_cap decides it: on the post get_post() returns
        // ( the object cache first ), the current user must be its author.
        'caps_fn'         => function ($cap, ...$args) {
            $post = get_post((int) ($args[0] ?? 0));
            return 'edit_post' === $cap && null !== $post && (int) $GLOBALS['cinatra_test']['current_user_id'] === (int) $post->post_author;
        },
    ], $overrides);
}

echo "Read: raw stored values, fresh from the posts table\n";
reset_fixture(['post_cache' => [T_ID => t_row(T_ID, 'page', ['post_title' => 'stale cached title'])]]);
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('success with the raw stored title, content ( quotes, backslash ), excerpt, status, type, modified', true === $r['success'] && 'About "us"' === $r['post_title'] && '<p>Body with "quotes" and a back\\slash</p>' === $r['post_content'] && 'Old excerpt' === $r['post_excerpt'] && 'publish' === $r['post_status'] && 'page' === $r['post_type'] && '2026-11-25 10:00:00' === $r['post_modified_gmt'] && T_ID === $r['post_id']);
check('the values come from the database, not from a stale object-cache copy', 'About "us"' === $r['post_title']);
check('exactly one statement: SELECT * by ID, no lock, no transaction', ['SELECT * FROM wp_posts WHERE ID = 42'] === $wpdb->log);
check('output keys', ['success', 'post_id', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_modified_gmt', 'permalink', 'row_token'] === array_keys($r));
check('the read accepts a numeric-string post_id', true === cinatra_ability_post_read(['post_id' => '42'])['success']);

echo "Row token\n";
$row = $wpdb->rows[T_ID];
check('row_token is 64 lower-case hex', 1 === preg_match('/^[0-9a-f]{64}$/', $r['row_token']));
check('row_token is the token of the row read', cinatra_content_row_token($row) === $r['row_token']);
// The canonical encoding, written out independently.
$sorted = $row;
ksort($sorted, SORT_STRING);
$enc = 'cinatra-post-row-v1';
foreach ($sorted as $k => $v) {
    $enc .= pack('N', strlen($k)) . $k . (null === $v ? "\x00" : "\x01" . pack('N', strlen((string) $v)) . $v);
}
check('the encoding is the documented one ( prefix, byte-ordered columns, 4-byte big-endian lengths, NULL marker )', hash('sha256', $enc) === $r['row_token']);
check('column order of the read does not matter', cinatra_content_row_token(array_reverse($row, true)) === $r['row_token']);
foreach (['post_name', 'post_author', 'post_parent', 'menu_order', 'post_modified_gmt'] as $col) {
    $changed = $row;
    $changed[$col] .= 'x';
    check("a change of $col changes the token", cinatra_content_row_token($changed) !== $r['row_token']);
}
$null = $row;
$null['post_password'] = null;
check('NULL and the empty string give different tokens', cinatra_content_row_token($null) !== $r['row_token']);
$a = ['a' => 'bc', 'ab' => 'c'];
$b = ['a' => 'b', 'ab' => 'cc'];
check('no byte can move between columns unseen ( length prefixes )', cinatra_content_row_token($a) !== cinatra_content_row_token($b));

echo "Permalink of the row read\n";
check('the permalink is built from the row read ( its slug )', 'https://blog.example/about/' === $r['permalink']);
reset_fixture(['post_cache' => [T_ID => t_row(T_ID, 'page', ['post_name' => 'old-slug'])]]);
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a stale cached copy with another slug does not change the permalink', 'https://blog.example/about/' === $r['permalink']);

echo "Same not_found for missing, other type and not permitted\n";
reset_fixture();
$missing = cinatra_ability_post_read(['post_id' => 999]);
$attachment = cinatra_ability_post_read(['post_id' => 7]);
reset_fixture(['current_user_id' => 5]);
$forbidden = cinatra_ability_post_read(['post_id' => T_ID]);
check('missing post -> not_found', false === $missing['success'] && 'not_found' === $missing['code']);
check('a post of another type ( attachment ) -> the same not_found', json_encode($missing) === json_encode($attachment));
check('a post the caller may not edit -> the same not_found, no content', json_encode($missing) === json_encode($forbidden) && !isset($forbidden['post_content']));
check('refusal shape: success, code, message, data; no "error" key', ['success', 'code', 'message', 'data'] === array_keys($missing));
foreach ([0, -1, '0', 'abc', '1e3', null, 4.2] as $bad) {
    check('post_id ' . var_export($bad, true) . ' -> not_found, no statement', 'not_found' === cinatra_ability_post_read(['post_id' => $bad])['code']);
}
check('not an object -> not_found', 'not_found' === cinatra_ability_post_read('42')['code']);

echo "Permission decided on the row read, not a stale cache copy\n";
reset_fixture(['post_cache' => [T_ID => t_row(T_ID, 'page', ['post_author' => '5'])]]);
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a stale cached copy with another author does not deny the current author', true === ($r['success'] ?? null));
reset_fixture(['post_cache' => [T_ID => t_row(T_ID, 'page')]]);
$wpdb->rows[T_ID]['post_author'] = '5';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a stale cached copy naming the caller as author does not allow a row the caller may not edit: not_found, no content', 'not_found' === ($r['code'] ?? null) && !isset($r['post_content']));
check('the denied call performs no broad cache clean-up: only this post\'s posts-group entry is dropped', [] === ($GLOBALS['cinatra_test']['clean_post_cache'] ?? []) && [[T_ID, 'posts']] === $GLOBALS['cinatra_test']['cache_deletes']);
check('a refusal\'s data serialises as a JSON object', '{}' === json_encode($r['data']) && 1 === preg_match('/"data":\{\}/', json_encode($r)));

echo "Failed read\n";
reset_fixture();
$wpdb->fail = true;
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a failed read -> db_error, never not_found, no content', false === $r['success'] && 'db_error' === $r['code'] && !isset($r['post_content']));

echo "Permission callback and registration\n";
reset_fixture();
check('logged in -> the permission callback allows ( edit_post is decided on the row )', true === cinatra_content_precheck(['post_id' => T_ID]));
reset_fixture(['current_user_id' => 0]);
check('logged out -> the permission callback refuses', false === cinatra_content_precheck(['post_id' => T_ID]));
$GLOBALS['cinatra_test_abilities'] = [];
$GLOBALS['cinatra_test_categories'] = [];
cinatra_register_ability_category();
cinatra_register_content_read_ability();
$read = $GLOBALS['cinatra_test_abilities']['cinatra/post-read'] ?? null;
check('category registered', isset($GLOBALS['cinatra_test_categories']['cinatra']));
check('cinatra/post-read registered, read-only, public to MCP', null !== $read && true === $read['meta']['mcp']['public'] && true === $read['meta']['annotations']['readonly']);
check('callbacks and input schema', 'cinatra_ability_post_read' === $read['execute_callback'] && 'cinatra_content_precheck' === $read['permission_callback'] && ['post_id'] === $read['input_schema']['required']);
check('output schema declares row_token and the refusal codes', isset($read['output_schema']['properties']['row_token']) && ['not_found', 'db_error'] === $read['output_schema']['properties']['code']['enum']);
check('registers on the Abilities API hooks', in_array('cinatra_register_content_read_ability', $GLOBALS['cinatra_test_actions']['wp_abilities_api_init'] ?? [], true) && in_array('cinatra_register_ability_category', $GLOBALS['cinatra_test_actions']['wp_abilities_api_categories_init'] ?? [], true));

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "All checks passed.\n";
exit(0);
