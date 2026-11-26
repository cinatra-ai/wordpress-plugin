<?php
/**
 * Standalone behavior tests for the content ability
 * cinatra/post-update-if-unchanged (cinatra-ai/cinatra#3745). Runs under plain
 * `php tests/test-post-update-if-unchanged.php`: no PHPUnit, no WordPress
 * install, no database. Exit code 0 = all pass.
 *
 * The stub $wpdb answers the plain read and runs the ability's one conditional
 * UPDATE as the database does: the row changes only when every column
 * condition holds, and the result is the number of CHANGED rows.
 *
 * Covers: the input contract; the checks against the row read; the one
 * conditional statement ( stored, or post_changed when the row changed between
 * the read and the write ); only the written columns and the two modification
 * times change; kses for a user without unfiltered_html and sanitized_fields;
 * the after-save hooks in core's order with the posts before and after; no
 * hook on a refusal; the answer from the stored row; the registration.
 */

require __DIR__ . '/wp-stubs.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Stand-in for $wpdb: the plain read and the conditional UPDATE. */
class wpdb {
    public $posts = 'wp_posts';
    public $charset = 'utf8mb4';
    public $rows = [];
    public $log = [];
    public $last_error = '';
    public $dbh;
    public $read_fails = false;
    public $update_result = null;  // forced return of the UPDATE ( false: an error )
    public $before_update = null;  // callable( $wpdb ): runs when the UPDATE reaches the server
    public function __construct() { $this->dbh = new stdClass(); }
    public function prepare($query, ...$args) {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    public function get_row($sql, $output = null) {
        $this->log[] = 'SELECT';
        $this->last_error = '';
        if ($this->read_fails) {
            $this->last_error = 'forced read failure';
            return null;
        }
        return preg_match('/^SELECT \* FROM wp_posts WHERE ID = (\d+)$/', $sql, $m) ? ($this->rows[(int) $m[1]] ?? null) : null;
    }
    /** UPDATE `wp_posts` SET `col` = '...', ... WHERE `ID` = n AND <per-column conditions>. */
    public function query($sql) {
        $this->log[] = 'UPDATE';
        if (null !== $this->before_update) {
            ($this->before_update)($this);
        }
        if (!preg_match('/^UPDATE `wp_posts` SET /', $sql, $m)) {
            return false;
        }
        $pos = strlen($m[0]);
        $data = [];
        while (preg_match('/\G`(\w+)` = \'((?:[^\'\\\\]|\\\\.)*)\'(, )?/s', $sql, $mm, 0, $pos)) {
            $data[$mm[1]] = stripslashes($mm[2]);
            $pos += strlen($mm[0]);
            if (!isset($mm[3])) { break; }
        }
        if (!preg_match('/\G WHERE `ID` = (\d+) AND (.*)$/s', $sql, $w, 0, $pos)) {
            return false;
        }
        $id = (int) $w[1];
        $cond = [];
        foreach (explode(' AND ', $w[2]) as $part) {
            if (preg_match('/^`(\w+)` IS NULL$/', $part, $c)) {
                $cond[$c[1]] = null;
            } elseif (preg_match('/^SHA2\(CONVERT\(`(\w+)` USING utf8mb4\), 256\) = \'([0-9a-f]{64})\'$/', $part, $c)) {
                $cond[$c[1]] = $c[2];
            } else {
                return false;
            }
        }
        $GLOBALS['cinatra_test']['update_data'][] = $data;
        $match = isset($this->rows[$id]) && array_keys($cond) === array_keys($this->rows[$id]);
        foreach ($cond as $col => $hex) {
            $have = $this->rows[$id][$col] ?? null;
            if (null === $hex ? null !== $have : (null === $have || hash('sha256', (string) $have) !== $hex)) {
                $match = false;
            }
        }
        $changed = 0;
        if ($match) {
            foreach ($data as $col => $value) {
                if ((string) $this->rows[$id][$col] !== (string) $value) {
                    $changed = 1;
                }
                $this->rows[$id][$col] = (string) $value;
            }
        }
        return null !== $this->update_result ? $this->update_result : $changed;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return 0 < (int) ($GLOBALS['cinatra_test']['current_user_id'] ?? 0); }
}
if (!function_exists('sanitize_post')) {
    function sanitize_post($post, $context = 'display') {
        $post = (object) $post;
        $post->filter = $context;
        return $post;
    }
}
if (!function_exists('has_filter')) {
    /** The kses filters kses_init_filters() installs for a user without unfiltered_html. */
    function has_filter($hook, $cb = false) {
        return in_array([$hook, $cb], $GLOBALS['cinatra_test']['installed_filters'] ?? [], true) ? 10 : false;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) { return $gmt ? '2026-11-25 12:00:00' : '2026-11-25 13:00:00'; }
}
if (!function_exists('clean_post_cache')) {
    /** Records each cache clear with the number of statements sent before it. */
    function clean_post_cache($id) { global $wpdb; $GLOBALS['cinatra_test']['cleaned'][] = [(int) $id, count($wpdb->log)]; }
}
if (!function_exists('wp_cache_delete')) {
    /** Records each single cache-entry delete with the number of statements sent before it. */
    function wp_cache_delete($key, $group = '') { global $wpdb; $GLOBALS['cinatra_test']['cache_deletes'][] = [$key, $group, count($wpdb->log)]; return true; }
}
if (!function_exists('wp_check_post_lock')) {
    function wp_check_post_lock($id) { return $GLOBALS['cinatra_test']['locked_by'] ?? false; }
}
if (!function_exists('wp_transition_post_status')) {
    function wp_transition_post_status($new, $old, $post) { do_action('transition_post_status', $new, $old, $post); }
}
if (!function_exists('wp_after_insert_post')) {
    function wp_after_insert_post($post, $update, $post_before) { do_action('wp_after_insert_post', $post->ID, $post, $update, $post_before); }
}
if (!function_exists('wp_register_ability')) {
    function wp_register_ability($id, $args) {
        $GLOBALS['cinatra_test_abilities'][$id] = $args;
        return true;
    }
}
if (!function_exists('wp_register_ability_category')) {
    function wp_register_ability_category($id, $args) { return true; }
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

const T_ID = 42;
const HOOKS = ['transition_post_status', 'edit_post_page', 'edit_post', 'post_updated', 'save_post_page', 'save_post', 'wp_insert_post', 'wp_after_insert_post'];
foreach (HOOKS as $hook) {
    add_action($hook, function (...$args) use ($hook) { $GLOBALS['cinatra_test']['fired'][] = [$hook, $args]; }, 10, 4);
}

function t_row(array $over = []): array {
    return array_merge([
        'ID'                    => (string) T_ID,
        'post_author'           => '3',
        'post_date'             => '2026-01-01 10:00:00',
        'post_date_gmt'         => '2026-01-01 09:00:00',
        'post_content'          => '<p>Old body</p>',
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
        'guid'                  => 'https://blog.example/?page_id=42',
        'menu_order'            => '0',
        'post_type'             => 'page',
        'post_mime_type'        => '',
        'comment_count'         => '0',
    ], $over);
}

function reset_fixture(array $overrides = []) {
    global $wpdb;
    $wpdb = new wpdb();
    $wpdb->rows = [T_ID => t_row(), 7 => t_row(['ID' => '7', 'post_type' => 'attachment', 'post_status' => 'inherit'])];
    $GLOBALS['cinatra_test'] = array_merge([
        'current_user_id' => 3,
        'permalink_base'  => 'https://blog.example/',
        'fired'           => [],
        'update_data'     => [],
        'cleaned'         => [],
        'cache_deletes'   => [],
        'caps_fn'         => function ($cap, ...$args) {
            global $wpdb;
            $row = $wpdb->rows[(int) ($args[0] ?? 0)] ?? null;
            return 'edit_post' === $cap && null !== $row && (int) $GLOBALS['cinatra_test']['current_user_id'] === (int) $row['post_author'];
        },
    ], $overrides);
}

/** A write input based on the current row ( the read the review saw ). */
function base_update(array $changes = ['content' => '<p>New body</p>']): array {
    global $wpdb;
    $row = $wpdb->rows[T_ID];
    return array_merge([
        'post_id'             => T_ID,
        'if_unmodified_since' => $row['post_modified_gmt'],
        'expected'            => [
            'title'     => $row['post_title'],
            'content'   => $row['post_content'],
            'excerpt'   => $row['post_excerpt'],
            'status'    => $row['post_status'],
            'row_token' => cinatra_content_row_token($row),
        ],
    ], $changes);
}
function fired(): array {
    return array_column($GLOBALS['cinatra_test']['fired'], 0);
}
function is_refusal($r, string $code): bool {
    return is_array($r) && false === ($r['success'] ?? null) && $code === ($r['code'] ?? null) && !isset($r['post_content']);
}

echo "Stored: one conditional UPDATE, then the after-save hooks\n";
reset_fixture();
$before = $wpdb->rows[T_ID];
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>New body</p>', 'excerpt' => 'New "excerpt" \\ ok']));
$after = $wpdb->rows[T_ID];
check('success with the stored content and excerpt ( quotes and backslash byte for byte ), title unchanged', true === ($r['success'] ?? null) && '<p>New body</p>' === $r['post_content'] && 'New "excerpt" \\ ok' === $r['post_excerpt'] && 'About "us"' === $r['post_title']);
check('only the written columns and the two modification times changed', array_keys(array_diff_assoc($after, $before)) === ['post_content', 'post_excerpt', 'post_modified', 'post_modified_gmt'] && '2026-11-25 12:00:00' === $after['post_modified_gmt']);
check('exactly one read and one UPDATE, no transaction statement', ['SELECT', 'UPDATE'] === $wpdb->log);
check('the UPDATE sets only those columns', [['post_content', 'post_excerpt', 'post_modified', 'post_modified_gmt']] === array_map('array_keys', $GLOBALS['cinatra_test']['update_data']));
check('the after-save hooks in core\'s order', HOOKS === fired());
check('before the permission check only the post\'s posts-group entry is dropped; the broad clean-up runs once, after the stored UPDATE', [[T_ID, 'posts', 1]] === $GLOBALS['cinatra_test']['cache_deletes'] && [[T_ID, 2]] === $GLOBALS['cinatra_test']['cleaned']);
$args = [];
foreach ($GLOBALS['cinatra_test']['fired'] as [$h, $a]) { $args[$h] = $a; }
check('post_updated gets the id, the post as stored and the post before', T_ID === $args['post_updated'][0] && '<p>New body</p>' === $args['post_updated'][1]->post_content && '<p>Old body</p>' === $args['post_updated'][2]->post_content);
check('transition_post_status( new, old, post ) and save_post( id, post, true )', ['publish', 'publish'] === array_slice($args['transition_post_status'], 0, 2) && true === $args['save_post'][2] && '<p>New body</p>' === $args['save_post'][1]->post_content);
check('wp_after_insert_post( id, post, true, post_before )', true === $args['wp_after_insert_post'][2] && '<p>Old body</p>' === $args['wp_after_insert_post'][3]->post_content);
check('answer: stored modified time, row_token of the stored row, permalink, empty sanitized_fields', '2026-11-25 12:00:00' === $r['post_modified_gmt'] && cinatra_content_row_token($after) === $r['row_token'] && 'https://blog.example/about/' === $r['permalink'] && [] === $r['sanitized_fields']);

reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft']));
check('status draft alone: stored as draft, transition publish -> draft', true === ($r['success'] ?? null) && 'draft' === $wpdb->rows[T_ID]['post_status'] && ['draft', 'publish'] === array_slice($GLOBALS['cinatra_test']['fired'][0][1], 0, 2));

echo "The row changed between the read and the write\n";
reset_fixture();
$wpdb->before_update = function ($db) { $db->rows[T_ID]['post_title'] = 'saved by another request'; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('another save right before the UPDATE: no changed row -> post_changed, the other save intact, no hook', is_refusal($r, 'post_changed') && 'saved by another request' === $wpdb->rows[T_ID]['post_title'] && '<p>Old body</p>' === $wpdb->rows[T_ID]['post_content'] && [] === fired());
reset_fixture();
$wpdb->before_update = function ($db) { $db->rows[T_ID]['post_name'] = 'other-slug'; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a change of a column the review did not show ( the slug ) also stops the UPDATE', is_refusal($r, 'post_changed') && '<p>Old body</p>' === $wpdb->rows[T_ID]['post_content']);

echo "The row read differs from the reviewed one\n";
foreach ([
    'post_modified_gmt' => function (&$in) { $in['if_unmodified_since'] = '2026-11-25 09:59:59'; },
    'post_title'        => function (&$in) { $in['expected']['title'] = 'Other'; },
    'post_status'       => function (&$in) { $in['expected']['status'] = 'draft'; },
    'row_token'         => function (&$in) { $in['expected']['row_token'] = str_repeat('0', 64); },
] as $what => $edit) {
    reset_fixture();
    $in = base_update();
    $edit($in);
    $r = cinatra_ability_post_update_if_unchanged($in);
    check("$what differs -> post_changed naming it, no UPDATE, no hook", is_refusal($r, 'post_changed') && in_array($what, $r['data']['changed'], true) && ['SELECT'] === $wpdb->log && [] === fired());
}
reset_fixture();
$in = base_update();
$wpdb->rows[T_ID]['post_parent'] = '9';
$r = cinatra_ability_post_update_if_unchanged($in);
check('a same-second change of any other column -> post_changed ( row_token )', is_refusal($r, 'post_changed') && ['row_token'] === $r['data']['changed'] && '2026-11-25 10:00:00' === $r['data']['current_modified_gmt']);

echo "Refusals before the write\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(array_merge(base_update(), ['post_id' => 999]));
$missing = $r;
check('missing post -> not_found', is_refusal($r, 'not_found'));
reset_fixture(['current_user_id' => 5]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a post the caller may not edit -> the same not_found, no UPDATE', json_encode($missing) === json_encode($r) && ['SELECT'] === $wpdb->log);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(array_merge(base_update(), ['post_id' => 7]));
check('another post type -> the same not_found', json_encode($missing) === json_encode($r));
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'private';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a private post -> unsupported_status, no UPDATE', is_refusal($r, 'unsupported_status') && 'private' === $r['data']['current_status'] && ['SELECT'] === $wpdb->log);
reset_fixture(['locked_by' => 9]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('another user is editing -> post_locked, no UPDATE', is_refusal($r, 'post_locked') && ['SELECT'] === $wpdb->log);
reset_fixture();
$wpdb->read_fails = true;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a failed read -> db_error, never not_found', is_refusal($r, 'db_error'));
reset_fixture();
$wpdb->update_result = false;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('the UPDATE returns an error -> db_error, no hook', is_refusal($r, 'db_error') && [] === fired());
reset_fixture();
$wpdb->before_update = function ($db) { $db->dbh = new stdClass(); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('wpdb reconnected during the UPDATE -> db_error that says the outcome is not known, no hook', is_refusal($r, 'db_error') && false !== strpos($r['message'], 'not known') && [] === fired());

echo "Input contract\n";
reset_fixture();
foreach ([
    'not an object'      => '42',
    'unknown field'      => array_merge(base_update(), ['slug' => 'x']),
    'no post_id'         => array_diff_key(base_update(), ['post_id' => 1]),
    'bad since'          => array_merge(base_update(), ['if_unmodified_since' => 'yesterday']),
    'expected missing'   => array_diff_key(base_update(), ['expected' => 1]),
    'expected partial'   => array_merge(base_update(), ['expected' => ['title' => 'x']]),
    'bad token'          => array_merge(base_update(), ['expected' => array_merge(base_update()['expected'], ['row_token' => 'abc'])]),
    'publish requested'  => array_merge(base_update(), ['status' => 'publish']),
    'nothing to write'   => array_diff_key(base_update(), ['content' => 1]),
    'non-string content' => array_merge(base_update(), ['content' => 5]),
] as $name => $in) {
    $r = cinatra_ability_post_update_if_unchanged($in);
    check("$name -> invalid, no statement", is_refusal($r, 'invalid') && [] === $wpdb->log);
}

echo "kses for a user without unfiltered_html\n";
$strip_script = function ($text, $ctx) { $GLOBALS['cinatra_test']['kses_ctx'][] = $ctx; return preg_replace('#<script>.*?</script>#s', '', $text); };
reset_fixture(['kses' => $strip_script, 'installed_filters' => [['title_save_pre', 'wp_filter_kses'], ['content_save_pre', 'wp_filter_post_kses'], ['excerpt_save_pre', 'wp_filter_post_kses']]]);
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>Hi</p><script>x</script>', 'title' => 'T<script>y</script>']));
check('the kses filter WordPress installed for the user runs before the write: the stored values are filtered', true === ($r['success'] ?? null) && '<p>Hi</p>' === $wpdb->rows[T_ID]['post_content'] && 'T' === $wpdb->rows[T_ID]['post_title']);
check('kses contexts as core: title_save_pre for the title, post for the content', ['title_save_pre', 'post'] === $GLOBALS['cinatra_test']['kses_ctx'] || ['post', 'title_save_pre'] === $GLOBALS['cinatra_test']['kses_ctx']);
check('sanitized_fields names the changed fields', ['post_content', 'post_title'] === $r['sanitized_fields'] || ['post_title', 'post_content'] === $r['sanitized_fields']);
reset_fixture(['kses' => $strip_script]);
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>Hi</p><script>x</script>']));
check('a user with unfiltered_html ( no kses filter installed ): stored as sent', '<p>Hi</p><script>x</script>' === $wpdb->rows[T_ID]['post_content'] && [] === $r['sanitized_fields']);

echo "Registration\n";
$GLOBALS['cinatra_test_abilities'] = [];
cinatra_register_content_write_ability();
$w = $GLOBALS['cinatra_test_abilities']['cinatra/post-update-if-unchanged'] ?? null;
check('registered, not read-only, public to MCP, its callbacks', null !== $w && false === $w['meta']['annotations']['readonly'] && true === $w['meta']['mcp']['public'] && 'cinatra_ability_post_update_if_unchanged' === $w['execute_callback'] && 'cinatra_content_precheck' === $w['permission_callback']);
check('status enum draft only; expected requires all five', ['draft'] === $w['input_schema']['properties']['status']['enum'] && ['title', 'content', 'excerpt', 'status', 'row_token'] === $w['input_schema']['properties']['expected']['required']);
check('refusal codes and sanitized_fields in the output schema', ['invalid', 'not_found', 'unsupported_status', 'post_locked', 'post_changed', 'db_error'] === $w['output_schema']['properties']['code']['enum'] && isset($w['output_schema']['properties']['sanitized_fields']));
check('registers on wp_abilities_api_init', in_array('cinatra_register_content_write_ability', $GLOBALS['cinatra_test_actions']['wp_abilities_api_init'] ?? [], true));

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "All checks passed.\n";
exit(0);
