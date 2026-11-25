<?php
/**
 * Standalone behavior tests for the content abilities cinatra/post-read and
 * cinatra/post-update-if-unchanged (cinatra-ai/cinatra#3745). Runs under plain
 * `php tests/test-post-update-if-unchanged.php` -- no PHPUnit, no WordPress
 * install, no database. Exit code 0 = all pass, 1 = a failure.
 *
 * The write is a NARROW WRITER: under a row lock it re-checks the capability
 * against the fresh row, compares the reviewed values, runs the per-field save
 * filters over the written fields only, writes exactly those columns plus the
 * two modification times with one $wpdb->update(), commits, and only then
 * fires the hooks core's wp_insert_post() fires on an update.
 *
 * The stubs record every statement and every hook in one ordered log, simulate
 * the transaction (ROLLBACK restores the rows), model the object cache that
 * get_post() and the capability check read, and model core's per-field save
 * chain (sanitize_post_field( ..., 'db' ): pre_post_<field> and
 * <field>_save_pre, with kses for a user without unfiltered_html, on SLASHED
 * data). A wp_update_post() stub models core's full save (merge, the
 * wp_insert_post_data filter, term rewrite, hooks) so the tests prove the
 * narrow writer never goes through it.
 *
 * Covers: statement and hook order with core's hook arguments; the exact
 * columns written; capability re-checked against the fresh row; a filter can
 * never make the stored status anything but the requested draft; no hook on a
 * refusal; a floating-date draft keeps its dates; no term function; the save
 * filters run over written fields only (untouched markup survives a restricted
 * user's title edit); slashing; affected rows; the expected/written contract;
 * the status rules; the engine read; the read ability; the registration.
 */

require __DIR__ . '/wp-stubs.php';

// ---------------------------------------------------------------------------
// Test-local stubs (function_exists-guarded so a shared load is harmless).
// ---------------------------------------------------------------------------
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Recording, transaction-simulating stand-in for WordPress's $wpdb. */
class Cinatra_Test_Wpdb {
    public $posts = 'wp_posts';
    public $log = [];
    public $rows = [];
    public $engine = 'InnoDB';
    public $charset = 'utf8mb4';
    public $fail = [];          // statement => true to make it return false
    public $update_result = null; // forced return of update() (e.g. false, 2)
    public $store_as = []; // column => value the database keeps instead of the sent one
    public $updates = [];       // captured update() calls
    private $snapshot = null;

    public function prepare($query, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    public function query($sql) {
        $this->log[] = $sql;
        if (!empty($this->fail[$sql])) {
            return false;
        }
        if ('START TRANSACTION' === $sql) {
            $this->snapshot = $this->rows;
        } elseif ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->rows = $this->snapshot;
            }
            $this->snapshot = null;
        } elseif ('COMMIT' === $sql) {
            $this->snapshot = null;
        }
        return 0;
    }
    public function get_row($sql, $output = null) {
        $this->log[] = $sql;
        if (!preg_match('/WHERE ID = (\d+)/', $sql, $m)) {
            return null;
        }
        return $this->rows[(int) $m[1]] ?? null;
    }
    public function get_var($sql) {
        $this->log[] = $sql;
        return $this->engine;
    }
    public function get_col_charset($table, $column) {
        return $this->charset;
    }
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $this->log[] = 'UPDATE-ROW';
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        $id = (int) ($where['ID'] ?? 0);
        $changed = 0;
        if (isset($this->rows[$id])) {
            foreach ($data as $col => $value) {
                if ((string) $this->rows[$id][$col] !== (string) $value) {
                    $changed = 1;
                }
                // store_as: the database keeps a different value than sent ( e.g. truncation ).
                $this->rows[$id][$col] = (string) ($this->store_as[$col] ?? $value);
            }
        }
        // MySQL reports CHANGED rows: an identical write affects 0 rows.
        return null !== $this->update_result ? $this->update_result : $changed;
    }
}

/** Every hook the tests observe; each firing is logged in order. */
function cinatra_test_record_hooks(array $hooks): void {
    foreach ($hooks as $hook) {
        add_action($hook, function (...$args) use ($hook) {
            global $wpdb;
            $GLOBALS['cinatra_test']['fired'][] = [$hook, $args];
            $wpdb->log[] = 'HOOK:' . $hook;
        }, 10, 4);
    }
}

if (!function_exists('wp_slash')) {
    function wp_slash($value) {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}
if (!function_exists('wp_check_post_lock')) {
    function wp_check_post_lock($post_id) {
        return $GLOBALS['cinatra_test']['post_lock'][(int) $post_id] ?? false;
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        if ('posts' === $group) {
            $GLOBALS['cinatra_test']['post_cache'][(int) $key] = (array) $data;
        }
        return true;
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        if ('posts' === $group) {
            unset($GLOBALS['cinatra_test']['post_cache'][(int) $key]);
        }
        return true;
    }
}
if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post_id) {
        global $wpdb;
        $GLOBALS['cinatra_test']['cleaned'][] = (int) $post_id;
        unset($GLOBALS['cinatra_test']['post_cache'][(int) $post_id]);
        $wpdb->log[] = 'CLEAN-CACHE';
    }
}
if (!function_exists('get_post')) {
    /** Like WP_Post::get_instance(): the object cache first, else the DB row (then cached). */
    function get_post($id = null) {
        global $wpdb;
        $id = (int) $id;
        if (!isset($GLOBALS['cinatra_test']['post_cache'][$id])) {
            if (!isset($wpdb->rows[$id])) {
                return null;
            }
            $GLOBALS['cinatra_test']['post_cache'][$id] = $wpdb->rows[$id];
        }
        $p = new WP_Post($GLOBALS['cinatra_test']['post_cache'][$id]);
        $p->ID = (int) $p->ID;
        return $p;
    }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return $gmt ? $GLOBALS['cinatra_test']['now'] : $GLOBALS['cinatra_test']['now_local'];
    }
}
if (!function_exists('sanitize_post_field')) {
    /**
     * Core's db-context chain for one field: pre_post_<x> then <x>_save_pre
     * (the kses filters are on the latter for a user without unfiltered_html).
     * Like core's filters, it expects and returns SLASHED data.
     */
    function sanitize_post_field($field, $value, $post_id, $context = 'display') {
        $GLOBALS['cinatra_test']['sanitized'][] = $field;
        $short = substr($field, 5); // post_title -> title
        foreach (["pre_{$field}", "{$short}_save_pre"] as $hook) {
            foreach ($GLOBALS['cinatra_test']['save_filters'][$hook] ?? [] as $cb) {
                $value = $cb($value);
            }
        }
        if (!current_user_can('unfiltered_html')) {
            $value = addslashes(preg_replace('#<script\b[^>]*>.*?</script>#is', '', stripslashes($value)));
        }
        return $value;
    }
}
if (!function_exists('wp_encode_emoji')) {
    function wp_encode_emoji($content) {
        return str_replace("\u{1F600}", '&#x1f600;', $content);
    }
}
if (!function_exists('wp_transition_post_status')) {
    /** Core: transition_post_status, {old}_to_{new}, {new}_{type}( id, post, old_status ). */
    function wp_transition_post_status($new_status, $old_status, $post) {
        do_action('transition_post_status', $new_status, $old_status, $post);
        do_action("{$old_status}_to_{$new_status}", $post);
        do_action("{$new_status}_{$post->post_type}", $post->ID, $post, $old_status);
    }
}
if (!function_exists('apply_filters')) {
    /** Runs the test's filters for a hook with core's arguments; records each call. */
    function apply_filters($hook, $value, ...$args) {
        $GLOBALS['cinatra_test']['filter_calls'][] = [$hook, $value, $args];
        foreach ($GLOBALS['cinatra_test']['save_filters'][$hook] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('wp_after_insert_post')) {
    function wp_after_insert_post($post, $update, $post_before) {
        $post = get_post($post->ID);
        do_action('wp_after_insert_post', $post->ID, $post, $update, $post_before);
    }
}
foreach (['wp_set_post_categories', 'wp_set_post_tags', 'wp_set_post_terms', 'wp_set_object_terms'] as $fn) {
    if (!function_exists($fn)) {
        eval("function $fn(...\$a) { \$GLOBALS['cinatra_test']['term_calls'][] = '$fn'; return []; }");
    }
}
if (!function_exists('wp_update_post')) {
    /**
     * Models core's full save, which the narrow writer must NEVER use: merge
     * with the cached post, the wp_insert_post_data filter over the whole
     * row, a term rewrite, then the hooks -- all before the caller could
     * refuse.
     */
    function wp_update_post($postarr, $wp_error = false) {
        global $wpdb;
        $id = (int) $postarr['ID'];
        $wpdb->log[] = 'UPDATE-VIA-wp_update_post';
        $GLOBALS['cinatra_test']['update_calls'][] = $postarr;
        $merged = array_merge(wp_slash(get_post($id) ? (array) $GLOBALS['cinatra_test']['post_cache'][$id] : []), $postarr);
        foreach ($GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'] ?? [] as $cb) {
            $merged = $cb($merged);
        }
        if ('post' === ($merged['post_type'] ?? '')) {
            wp_set_post_categories($id, []);
        }
        $old = $wpdb->rows[$id]['post_status'];
        foreach (array_keys($wpdb->rows[$id]) as $col) {
            if ('ID' !== $col && array_key_exists($col, $merged)) {
                $wpdb->rows[$id][$col] = wp_unslash($merged[$col]);
            }
        }
        $wpdb->rows[$id]['post_modified_gmt'] = $GLOBALS['cinatra_test']['now'];
        clean_post_cache($id);
        $post = get_post($id);
        wp_transition_post_status($post->post_status, $old, $post);
        do_action('save_post', $id, $post, true);
        return $id;
    }
}
// Abilities API registration capture.
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

const T_ID       = 42;
const T_POST_ID  = 43;
const T_MODIFIED = '2026-11-25 10:00:00';
const T_NOW      = '2026-11-25 10:05:00';
const T_NOW_LOC  = '2026-11-25 11:05:00';
// Content with backslashes, both quote kinds and a marker the refusal tests
// search for (a refusal must never echo the post's content).
const T_CONTENT  = "<p>Path C:\\new\\table \"double\" 'single' \\\\ two backslashes SECRET-BODY-MARKER</p>";

cinatra_test_record_hooks([
    'pre_post_update', 'transition_post_status', 'publish_to_publish', 'publish_to_draft', 'draft_to_draft',
    'publish_page', 'draft_page', 'publish_post', 'draft_post', 'edit_post_page', 'edit_post_post', 'edit_post',
    'post_updated', 'save_post_page', 'save_post_post', 'save_post', 'wp_insert_post', 'wp_after_insert_post',
]);
// Per-test pre_post_update callbacks ( reset with the fixture ): a site callback
// that writes to the database inside the transaction.
add_action('pre_post_update', function (...$args) {
    foreach ($GLOBALS['cinatra_test']['pre_post_update_cbs'] ?? [] as $cb) {
        $cb(...$args);
    }
}, 10, 2);

/** A posts row with every column of core's wp_posts schema, in table order. */
function t_row(int $id, string $type, array $over = []): array {
    return array_merge([
        'ID'                    => (string) $id,
        'post_author'           => '3',
        'post_date'             => '2026-01-01 10:00:00',
        'post_date_gmt'         => '2026-01-01 09:00:00',
        'post_content'          => T_CONTENT,
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
        'post_modified_gmt'     => T_MODIFIED,
        'post_content_filtered' => '',
        'post_parent'           => '0',
        'guid'                  => "https://blog.example/?page_id=$id",
        'menu_order'            => '0',
        'post_type'             => $type,
        'post_mime_type'        => '',
        'comment_count'         => '0',
    ], $over);
}

/** Fresh fixture: one editable page (42), one post (43), an attachment (7); InnoDB. */
function reset_fixture(array $overrides = []) {
    global $wpdb;
    $wpdb = new Cinatra_Test_Wpdb();
    $wpdb->rows = [
        T_ID      => t_row(T_ID, 'page'),
        T_POST_ID => t_row(T_POST_ID, 'post'),
        7         => t_row(7, 'attachment', ['post_status' => 'inherit', 'post_title' => 'logo', 'post_content' => '', 'post_excerpt' => '']),
    ];
    $GLOBALS['cinatra_test'] = array_merge([
        'options'          => [],
        'current_user_can' => false,
        'current_user_id'  => 3,
        'transients'       => [],
        'permalinks'       => [T_ID => 'https://blog.example/about/'],
        'post_lock'        => [],
        'cleaned'          => [],
        'update_calls'     => [],
        'fired'            => [],
        'sanitized'        => [],
        'term_calls'       => [],
        'save_filters'     => [],
        'remote_post_calls' => [],
        'filter_calls'     => [],
        'now'              => T_NOW,
        'now_local'        => T_NOW_LOC,
        // The object cache starts consistent with the database.
        'post_cache'       => [T_ID => $wpdb->rows[T_ID], T_POST_ID => $wpdb->rows[T_POST_ID], 7 => $wpdb->rows[7]],
        // Models map_meta_cap for edit_post: the author of the post AS
        // get_post() SEES IT (object cache first) must be the current user.
        'caps_fn'          => function ($cap, ...$args) {
            if ('edit_post' === $cap) {
                $post = get_post((int) ($args[0] ?? 0));
                return null !== $post && (int) $GLOBALS['cinatra_test']['current_user_id'] === (int) $GLOBALS['cinatra_test']['post_cache'][$post->ID]['post_author'];
            }
            if ('unfiltered_html' === $cap) {
                return $GLOBALS['cinatra_test']['unfiltered_html'] ?? true;
            }
            return false;
        },
    ], $overrides);
    $GLOBALS['t_reviewed'] = $wpdb->rows;
}

/**
 * The row token computed INDEPENDENTLY from the documented canonical encoding:
 * "cinatra-post-row-v1", then every column sorted by name (byte order), each
 * as a 4-byte big-endian name length, the name, then "\x00" for NULL or "\x01",
 * a 4-byte big-endian value length and the value; SHA-256, lower-case hex.
 */
function t_token(array $row): string {
    ksort($row, SORT_STRING);
    $enc = 'cinatra-post-row-v1';
    foreach ($row as $name => $value) {
        $name = (string) $name;
        $enc .= pack('N', strlen($name)) . $name;
        $enc .= null === $value ? "\x00" : "\x01" . pack('N', strlen((string) $value)) . (string) $value;
    }
    return hash('sha256', $enc);
}
/** Mark the current rows as what the caller read (and the cache as consistent). */
function t_state_is_read(): void {
    global $wpdb;
    foreach ($wpdb->rows as $id => $row) {
        $GLOBALS['t_reviewed'][$id] = $row;
        $GLOBALS['cinatra_test']['post_cache'][$id] = $row;
    }
}

/** The log with SELECTs reduced to a tag. */
function statement_kinds(): array {
    global $wpdb;
    return array_map(function ($sql) {
        if (0 === strpos($sql, 'SELECT ENGINE')) {
            return 'ENGINE';
        }
        if (0 === strpos($sql, 'SELECT ')) {
            return false !== strpos($sql, 'FOR UPDATE') ? 'SELECT-FOR-UPDATE' : 'SELECT';
        }
        return $sql;
    }, $wpdb->log);
}
function hooks_fired(): array {
    return array_column($GLOBALS['cinatra_test']['fired'], 0);
}
function hook_args(string $hook): ?array {
    foreach ($GLOBALS['cinatra_test']['fired'] as [$h, $args]) {
        if ($h === $hook) {
            return $args;
        }
    }
    return null;
}

/** The before-values of the whole reviewed post (as read), with its row token. */
function full_expected(array $over = [], int $id = T_ID): array {
    $read = $GLOBALS['t_reviewed'][$id] ?? t_row($id, 'page');
    return array_merge([
        'title'     => $read['post_title'],
        'content'   => $read['post_content'],
        'excerpt'   => $read['post_excerpt'],
        'status'    => $read['post_status'],
        'row_token' => t_token($read),
    ], $over);
}

function base_update(array $extra = []): array {
    return array_merge([
        'post_id'             => T_ID,
        'if_unmodified_since' => T_MODIFIED,
        'expected'            => full_expected([], (int) ($extra['post_id'] ?? T_ID)),
        'content'             => '<p>New body</p>',
    ], $extra);
}

/**
 * A refusal at or after the write: well-formed, everything rolled back, the
 * cache cleaned, pre_post_update fired ( it cannot be un-fired ) and no
 * after-save hook.
 */
function is_late_refusal($r, string $code, array $before): bool {
    global $wpdb;
    return is_array($r)
        && false === ($r['success'] ?? null)
        && $code === ($r['code'] ?? null)
        && is_array($r['data'] ?? null)
        && $before === $wpdb->rows
        && ['pre_post_update'] === hooks_fired()
        && !in_array('COMMIT', statement_kinds(), true)
        && ['ROLLBACK', 'CLEAN-CACHE'] === array_slice(statement_kinds(), -2);
}

/** A refusal is well-formed, carries no post content, and fired no hook. */
function is_refusal($r, string $code): bool {
    return is_array($r)
        && false === ($r['success'] ?? null)
        && $code === ($r['code'] ?? null)
        && is_string($r['message'] ?? null)
        && is_array($r['data'] ?? null)
        && array_key_exists('current_modified_gmt', $r['data'])
        && false === strpos(json_encode($r), 'SECRET-BODY-MARKER')
        && [] === $GLOBALS['cinatra_test']['fired']
        && [] === $GLOBALS['cinatra_test']['update_calls'];
}

$retired_ids = [
    'cinatra/post-get', 'cinatra/post-update', 'cinatra/post-status', 'cinatra/posts-list',
    'cinatra/post-delete', 'cinatra/post-create-draft', 'cinatra/media-upload', 'cinatra/post-update-meta',
];

const T_AFTER_COMMIT = [
    'HOOK:transition_post_status', 'HOOK:publish_to_publish', 'HOOK:publish_page', 'HOOK:edit_post_page', 'HOOK:edit_post',
    'HOOK:post_updated', 'HOOK:save_post_page', 'HOOK:save_post', 'HOOK:wp_insert_post', 'HOOK:wp_after_insert_post',
];

// ---------------------------------------------------------------------------
echo "Success: one narrow update, hooks only after COMMIT, in core's order\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('success is true', true === ($r['success'] ?? null));
check('order: START, FOR UPDATE, engine, pre_post_update, one UPDATE, read-back, COMMIT, cache clean, then the update hooks',
    array_merge(['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'HOOK:pre_post_update', 'UPDATE-ROW', 'SELECT', 'COMMIT', 'CLEAN-CACHE'], T_AFTER_COMMIT) === statement_kinds());
check('wp_update_post is never used', [] === $GLOBALS['cinatra_test']['update_calls']);
$u = $wpdb->updates[0] ?? ['data' => [], 'where' => [], 'table' => ''];
$keys = array_keys($u['data']);
sort($keys);
check('the update writes exactly the written column and the two modified times', ['post_content', 'post_modified', 'post_modified_gmt'] === $keys);
check('the update data values', '<p>New body</p>' === $u['data']['post_content'] && T_NOW === $u['data']['post_modified_gmt'] && T_NOW_LOC === $u['data']['post_modified']);
check('the update targets the posts table, WHERE ID = 42 only', 'wp_posts' === $u['table'] && ['ID' => T_ID] === $u['where']);
check('answer returns the stored content and new modified time', '<p>New body</p>' === $r['post_content'] && T_NOW === $r['post_modified_gmt']);
check('answer carries unchanged fields, permalink, type, status, id', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt'] && 'https://blog.example/about/' === $r['permalink'] && 'page' === $r['post_type'] && 'publish' === $r['post_status'] && T_ID === $r['post_id']);
check('nothing was changed by filters', [] === $r['sanitized_fields']);
check('no also_changed field any more', !array_key_exists('also_changed', $r));
check('every other column is byte-identical', array_diff_key($wpdb->rows[T_ID], array_flip(['post_content', 'post_modified', 'post_modified_gmt'])) === array_diff_key(t_row(T_ID, 'page'), array_flip(['post_content', 'post_modified', 'post_modified_gmt'])));

echo "Hooks: core's arguments\n";
$a = hook_args('pre_post_update');
$core_cols = ['post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid'];
check('pre_post_update(post_id, data): data has exactly core\'s compact() columns, in core\'s order', T_ID === $a[0] && $core_cols === array_keys($a[1]));
check('pre_post_update data: unslashed, written column new, others as stored, status and type present', '<p>New body</p>' === $a[1]['post_content'] && 'About "us"' === $a[1]['post_title'] && 'publish' === $a[1]['post_status'] && 'page' === $a[1]['post_type'] && 'about' === $a[1]['post_name'] && T_NOW === $a[1]['post_modified_gmt']);
$f = null;
foreach ($GLOBALS['cinatra_test']['filter_calls'] as $call) { if ('wp_insert_post_data' === $call[0]) { $f = $call; } }
check('wp_insert_post_data ran once with (data, postarr, unsanitized_postarr, true)', null !== $f && $core_cols === array_keys($f[1]) && T_ID === (int) $f[2][0]['ID'] && is_array($f[2][1]) && true === $f[2][2]);
check('wp_insert_post_data data is slashed, as in core', wp_slash(T_CONTENT) !== T_CONTENT && wp_slash('About "us"') === $f[1]['post_title']);
$a = hook_args('transition_post_status');
check('transition_post_status(new, old, post)', 'publish' === $a[0] && 'publish' === $a[1] && $a[2] instanceof WP_Post && T_ID === $a[2]->ID);
$a = hook_args('publish_page');
check('{new}_{type}(post_id, post, old_status)', T_ID === $a[0] && $a[1] instanceof WP_Post && 'publish' === $a[2]);
$a = hook_args('edit_post_page');
check('edit_post_{type}(post_id, post)', T_ID === $a[0] && $a[1] instanceof WP_Post);
$a = hook_args('edit_post');
check('edit_post(post_id, post)', T_ID === $a[0] && $a[1] instanceof WP_Post);
$a = hook_args('post_updated');
check('post_updated(post_id, post_after, post_before): after is new, before is the locked row', T_ID === $a[0] && '<p>New body</p>' === $a[1]->post_content && T_CONTENT === $a[2]->post_content);
$a = hook_args('save_post_page');
check('save_post_{type}(post_id, post, true)', T_ID === $a[0] && $a[1] instanceof WP_Post && true === $a[2]);
$a = hook_args('save_post');
check('save_post(post_id, post, true) sees the committed content', T_ID === $a[0] && '<p>New body</p>' === $a[1]->post_content && true === $a[2]);
$a = hook_args('wp_insert_post');
check('wp_insert_post(post_id, post, true)', T_ID === $a[0] && $a[1] instanceof WP_Post && true === $a[2]);
$a = hook_args('wp_after_insert_post');
check('wp_after_insert_post(post_id, post, true, post_before)', T_ID === $a[0] && $a[1] instanceof WP_Post && true === $a[2] && T_CONTENT === $a[3]->post_content);

// ---------------------------------------------------------------------------
echo "Status: never publishes; draft only when asked, as the literal value\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft', 'expected' => full_expected()]));
check('publish -> draft succeeds, stored status is draft', true === $r['success'] && 'draft' === $wpdb->rows[T_ID]['post_status'] && 'draft' === $r['post_status']);
check('the update data carries post_status draft', 'draft' === ($wpdb->updates[0]['data']['post_status'] ?? null));
check('transition is publish -> draft (publish_to_draft, draft_page)', in_array('publish_to_draft', hooks_fired(), true) && in_array('draft_page', hooks_fired(), true) && ['draft', 'publish'] === array_slice(hook_args('transition_post_status'), 0, 2));
check('the publish webhook is not sent', [] === $GLOBALS['cinatra_test']['remote_post_calls']);
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
t_state_is_read();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a content-only edit of a draft keeps draft, writes no post_status column', true === $r['success'] && 'draft' === $wpdb->rows[T_ID]['post_status'] && isset($wpdb->updates[0]) && !array_key_exists('post_status', $wpdb->updates[0]['data']));
check('no transition into publish', !in_array('draft_to_publish', hooks_fired(), true) && 'draft' === (hook_args('transition_post_status')[0] ?? null));
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
t_state_is_read();
$before = $wpdb->rows;
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { $d['post_status'] = 'publish'; return $d; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a site filter that would publish a draft -> site_policy_conflict naming post_status, nothing written, no hook', is_refusal($r, 'site_policy_conflict') && ['post_status'] === $r['data']['changed'] && $before === $wpdb->rows);

// ---------------------------------------------------------------------------
echo "Capability: re-checked against the FRESH row under the lock\n";
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9'; // reassigned; the object cache still says author 3
check('the early permission callback (cache) would still allow', true === cinatra_content_can_edit(['post_id' => T_ID]));
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('write refused as not_found after the lock', is_refusal($r, 'not_found'));
check('ROLLBACK, no update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds() && $before === $wpdb->rows);
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('read refused as not_found too', is_refusal($r, 'not_found'));
check('the read leaves no primed cache entry behind', !isset($GLOBALS['cinatra_test']['post_cache'][T_ID]));

echo "Capability: unknown, forbidden and other-type posts look the same\n";
reset_fixture();
$unknown = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 999]));
$unknown_log = statement_kinds();
$wpdb->rows[5] = t_row(5, 'page', ['post_author' => '9']);
$GLOBALS['cinatra_test']['post_cache'][5] = $wpdb->rows[5];
$wpdb->log = [];
$forbidden = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 5]));
$forbidden_log = statement_kinds();
$wpdb->log = [];
$other_type = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 7, 'expected' => full_expected([], 7), 'content' => 'x']));
check('unknown post -> not_found', is_refusal($unknown, 'not_found'));
check('forbidden post -> not_found', is_refusal($forbidden, 'not_found'));
check('attachment (not post/page) -> not_found', is_refusal($other_type, 'not_found'));
check('the three answers are identical', $unknown === $forbidden && $unknown === $other_type);
check('not_found carries no current_modified_gmt', null === $unknown['data']['current_modified_gmt']);
check('unknown and forbidden are refused by the early check, no statement', [] === $unknown_log && [] === $forbidden_log);
check('other-type rolls back after the locked select', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());
reset_fixture();
check('permission callback: own post allowed, missing / zero / bad id refused', true === cinatra_content_can_edit(['post_id' => T_ID]) && false === cinatra_content_can_edit([]) && false === cinatra_content_can_edit(['post_id' => 0]) && false === cinatra_content_can_edit(['post_id' => '42abc']));

// ---------------------------------------------------------------------------
echo "Refusals: nothing written, no hook\n";
reset_fixture();
$wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:03:00';
$GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID];
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('post_changed (modified), current value given, no hook', is_refusal($r, 'post_changed') && '2026-11-25 10:03:00' === $r['data']['current_modified_gmt'] && ['post_modified_gmt', 'row_token'] === $r['data']['changed']);
check('post_changed rolls back without an update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());
foreach (['post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt'] as $col => $name) {
    reset_fixture();
    $wpdb->rows[T_ID][$col] .= ' changed in the same second';
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    check("same-second change to $col (written or not) -> post_changed naming it", is_refusal($r, 'post_changed') && [$col, 'row_token'] === $r['data']['changed']);
}
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('same-second unpublish by someone else -> post_changed naming post_status', is_refusal($r, 'post_changed') && ['post_status', 'row_token'] === $r['data']['changed']);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['content' => T_CONTENT . ' '])]));
check('a one-byte difference refuses', is_refusal($r, 'post_changed'));
reset_fixture(['post_lock' => [T_ID => 9]]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('post_locked, no hook', is_refusal($r, 'post_locked') && T_MODIFIED === $r['data']['current_modified_gmt']);
foreach (['pending', 'future', 'private', 'trash', 'auto-draft'] as $state) {
    reset_fixture();
    $wpdb->rows[T_ID]['post_status'] = $state;
    t_state_is_read();
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft', 'expected' => full_expected(['status' => $state])]));
    check("current status $state -> unsupported_status, nothing written", is_refusal($r, 'unsupported_status') && $state === $r['data']['current_status'] && $before === $wpdb->rows);
}
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['title' => '', 'content' => '', 'excerpt' => '']));
check('emptying title, content and excerpt together is refused (core refuses empty content)', is_refusal($r, 'invalid'));

// ---------------------------------------------------------------------------
echo "Dates and terms: untouched\n";
reset_fixture();
$wpdb->rows[T_ID] = t_row(T_ID, 'page', ['post_status' => 'draft', 'post_date_gmt' => '0000-00-00 00:00:00', 'post_date' => '2026-02-02 02:02:02']);
t_state_is_read();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a floating-date draft keeps post_date and post_date_gmt exactly', true === $r['success'] && '2026-02-02 02:02:02' === $wpdb->rows[T_ID]['post_date'] && '0000-00-00 00:00:00' === $wpdb->rows[T_ID]['post_date_gmt']);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => T_POST_ID, 'title' => 'New post title']));
check('editing a post (categories/tags taxonomy) calls no term function', true === $r['success'] && [] === $GLOBALS['cinatra_test']['term_calls']);
check('post hooks use the post type', in_array('save_post_post', hooks_fired(), true) && in_array('edit_post_post', hooks_fired(), true));

// ---------------------------------------------------------------------------
echo "Save filters: the written fields only\n";
reset_fixture(['unfiltered_html' => false]);
$admin_markup = '<p>Admin markup</p><script>track()</script>';
$wpdb->rows[T_ID]['post_content'] = $admin_markup;
t_state_is_read();
$GLOBALS['cinatra_test']['save_filters']['content_save_pre'][] = function ($v) { $GLOBALS['cinatra_test']['content_chain_ran'] = true; return $v; };
$r = cinatra_ability_post_update_if_unchanged([
    'post_id'             => T_ID,
    'if_unmodified_since' => T_MODIFIED,
    'expected'            => full_expected(['content' => $admin_markup]),
    'title'               => 'New <script>x()</script>title',
]);
check('a restricted user\'s title edit succeeds', true === ($r['success'] ?? null));
check('the untouched body (admin markup) is kept byte for byte', $admin_markup === $wpdb->rows[T_ID]['post_content']);
check('the written title went through kses', 'New title' === $wpdb->rows[T_ID]['post_title'] && ['post_title'] === $r['sanitized_fields']);
check('only post_title ( and the status, which this save stores ) ran through a save chain; content and excerpt did not', ['post_title', 'post_status'] === $GLOBALS['cinatra_test']['sanitized']);
check('the content chain did not run for an unwritten body', empty($GLOBALS['cinatra_test']['content_chain_ran']));
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['content_save_pre'][] = function ($v) { return str_replace('darn', 'd**n', $v); };
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>darn</p>']));
check('a site rule on content_save_pre applies to written content, reported', '<p>d**n</p>' === $r['post_content'] && ['post_content'] === $r['sanitized_fields']);

echo "Slashing: backslashes and quotes survive the slashed save chain\n";
reset_fixture();
$new_content = "Line \\n not a newline, C:\\temp\\x, \"dq\", 'sq', \\\\ and a trailing backslash \\";
$new_title   = 'Title with \\ and "quotes" and \'single\'';
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => $new_content, 'title' => $new_title]));
check('stored content and title equal the sent bytes', $new_content === $wpdb->rows[T_ID]['post_content'] && $new_title === $wpdb->rows[T_ID]['post_title']);
check('the update data is unslashed (raw), the answer the same bytes', $new_content === ($wpdb->updates[0]['data']['post_content'] ?? null) && $new_content === $r['post_content'] && [] === $r['sanitized_fields']);

reset_fixture(['unfiltered_html' => false]);
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => $new_content]));
check('through the kses chain (which unslashes/reslashes like core) the bytes survive too', $new_content === $wpdb->rows[T_ID]['post_content'] && [] === $r['sanitized_fields']);

echo "Emoji: encoded like core on a utf8 (not utf8mb4) column\n";
reset_fixture();
$wpdb->charset = 'utf8';
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => "Hi \u{1F600}"]));
check('emoji encoded for a utf8 column', 'Hi &#x1f600;' === $wpdb->rows[T_ID]['post_content']);

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
echo "Row token: same-second changes outside the four fields\n";
foreach (['post_name' => 'about-us-2', 'post_type' => 'post', 'post_author' => '3 ', 'post_parent' => '5', 'post_date' => '2020-01-01 00:00:00', 'menu_order' => '1'] as $col => $value) {
    reset_fixture();
    $wpdb->rows[T_ID][$col] = $value; // saved by someone else in the same second
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    check("same-second change of $col -> post_changed naming row_token, nothing written", is_refusal($r, 'post_changed') && ['row_token'] === $r['data']['changed'] && $before === $wpdb->rows);
}

// ---------------------------------------------------------------------------
echo "Site policy: wp_insert_post_data\n";
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { $d['post_content'] = str_replace('forbidden', '', $d['post_content']); return $d; };
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>a forbidden word</p>']));
check('a policy that strips written content is honoured in the stored value', true === ($r['success'] ?? null) && '<p>a  word</p>' === $wpdb->rows[T_ID]['post_content'] && ['post_content'] === $r['sanitized_fields']);
check('pre_post_update sees the filtered value', '<p>a  word</p>' === hook_args('pre_post_update')[1]['post_content']);
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { $d['post_modified_gmt'] = '2026-11-25 10:06:00'; return $d; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('modification times: core stores the filter\'s value', true === ($r['success'] ?? null) && '2026-11-25 10:06:00' === $wpdb->rows[T_ID]['post_modified_gmt'] && '2026-11-25 10:06:00' === $r['post_modified_gmt']);
$conflicts = [
    'post_status'  => function ($d) { $d['post_status'] = 'publish'; return $d; },
    'post_name'    => function ($d) { $d['post_name'] = 'new-slug'; return $d; },
    'post_author'  => function ($d) { $d['post_author'] = '1'; return $d; },
    'post_date'    => function ($d) { $d['post_date'] = '2031-03-03 03:03:03'; return $d; },
    'post_title'   => function ($d) { $d['post_title'] = 'unwritten title changed'; return $d; },
    'guid'         => function ($d) { unset($d['guid']); return $d; },
    'extra_column' => function ($d) { $d['extra_column'] = 'x'; return $d; },
];
foreach ($conflicts as $col => $cb) {
    reset_fixture();
    $GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = $cb;
    $before = $wpdb->rows;
    // post_status: this save unpublishes ( stores draft ); the policy would keep it published.
    $r = cinatra_ability_post_update_if_unchanged(base_update('post_status' === $col ? ['status' => 'draft'] : []));
    check("a policy changing $col (not written) -> site_policy_conflict, nothing written, no hook", is_refusal($r, 'site_policy_conflict') && [$col] === $r['data']['changed'] && $before === $wpdb->rows && [] === $wpdb->updates);
}
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { return 'not an array'; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a policy returning a non-array -> site_policy_conflict', is_refusal($r, 'site_policy_conflict'));

// ---------------------------------------------------------------------------
echo "Site policy: the status save-filter chain\n";
reset_fixture();
$seen = [];
$GLOBALS['cinatra_test']['save_filters']['pre_post_status'][] = function ($v) use (&$seen) { $seen[] = ['pre_post_status', $v]; return $v; };
$GLOBALS['cinatra_test']['save_filters']['status_save_pre'][] = function ($v) use (&$seen) { $seen[] = ['status_save_pre', $v]; return $v; };
$r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft']));
check('status draft runs pre_post_status then status_save_pre with "draft"', true === ($r['success'] ?? null) && [['pre_post_status', 'draft'], ['status_save_pre', 'draft']] === $seen && in_array('post_status', $GLOBALS['cinatra_test']['sanitized'], true));
foreach (['pre_post_status', 'status_save_pre'] as $hook) {
    reset_fixture();
    $GLOBALS['cinatra_test']['save_filters'][$hook][] = function ($v) { return 'pending'; }; // a workflow policy: no direct unpublish
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft']));
    check("a $hook policy that does not allow draft -> site_policy_conflict naming post_status, nothing written, no hook", is_refusal($r, 'site_policy_conflict') && ['post_status'] === $r['data']['changed'] && $before === $wpdb->rows && [] === $wpdb->updates);
}
reset_fixture();
$seen = [];
$GLOBALS['cinatra_test']['save_filters']['status_save_pre'][] = function ($v) use (&$seen) { $seen[] = $v; return 'draft' === $v ? 'pending' : $v; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a content-only edit runs the chain on the kept status ( publish ); a policy that blocks only draft lets it pass', true === ($r['success'] ?? null) && ['publish'] === $seen && 'publish' === $wpdb->rows[T_ID]['post_status']);
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['status_save_pre'][] = function ($v) { return 'pending'; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a content-only edit whose status policy would change the kept status -> site_policy_conflict, nothing written', is_refusal($r, 'site_policy_conflict') && ['post_status'] === $r['data']['changed'] && $before === $wpdb->rows);

// ---------------------------------------------------------------------------
echo "Post-write check: callbacks cannot widen the write\n";
$widen = [
    'pre_post_update publishes the draft'  => ['post_status', function () { global $wpdb; $wpdb->rows[T_ID]['post_status'] = 'publish'; }],
    'pre_post_update changes the slug'     => ['post_name', function () { global $wpdb; $wpdb->rows[T_ID]['post_name'] = 'hijacked'; }],
    'pre_post_update rewrites the content' => ['post_content', function () { global $wpdb; $wpdb->rows[T_ID]['post_content'] = 'other'; }],
];
foreach ($widen as $label => [$col, $cb]) {
    reset_fixture();
    $wpdb->rows[T_ID]['post_status'] = 'draft';
    t_state_is_read();
    $GLOBALS['cinatra_test']['pre_post_update_cbs'][] = $cb;
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    // A callback's change to a column this save writes is overwritten by the write itself.
    if ('post_content' === $col) {
        check("$label -> overwritten by this save's own value (the stored content is what this save wrote)", true === ($r['success'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content']);
        continue;
    }
    check("$label -> site_policy_conflict naming $col, rolled back, no after-save hook", is_late_refusal($r, 'site_policy_conflict', $before) && [$col] === ($r['data']['changed'] ?? null));
    check("$label -> the publish webhook is not sent", [] === $GLOBALS['cinatra_test']['remote_post_calls']);
}
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
t_state_is_read();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () { global $wpdb; $wpdb->rows[T_ID]['post_excerpt'] = 'changed by a callback'; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a callback changing an unwritten text column -> site_policy_conflict naming post_excerpt', is_late_refusal($r, 'site_policy_conflict', $before) && ['post_excerpt'] === ($r['data']['changed'] ?? null));
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['content_save_pre'][] = function ($v) { global $wpdb; $wpdb->rows[T_ID]['post_name'] = 'by-a-save-filter'; return $v; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a *_save_pre filter writing the slug to the database -> site_policy_conflict, rolled back', false === ($r['success'] ?? null) && 'site_policy_conflict' === ($r['code'] ?? null) && ['post_name'] === $r['data']['changed'] && $before === $wpdb->rows && !in_array('COMMIT', statement_kinds(), true));
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { global $wpdb; $wpdb->rows[T_ID]['post_author'] = '1'; return $d; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a wp_insert_post_data filter writing the author to the database -> site_policy_conflict, rolled back', false === ($r['success'] ?? null) && 'site_policy_conflict' === ($r['code'] ?? null) && ['post_author'] === $r['data']['changed'] && $before === $wpdb->rows && !in_array('COMMIT', statement_kinds(), true));
reset_fixture();
$wpdb->store_as = ['post_content' => '<p>New bo'];
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a written column stored differently than written ( e.g. truncated ) -> site_policy_conflict naming it, rolled back', is_late_refusal($r, 'site_policy_conflict', $before) && ['post_content'] === ($r['data']['changed'] ?? null));
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () { global $wpdb; $wpdb->rows[T_ID]['post_content'] = '<p>New body</p>'; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a callback writing the same values as this save is no conflict', true === ($r['success'] ?? null));

echo "Affected rows\n";
reset_fixture();
$wpdb->update_result = false;
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('update returning false -> db_error at the write: rolled back, pre_post_update had fired, no after-save hook', is_late_refusal($r, 'db_error', $before));
reset_fixture();
$wpdb->update_result = 2;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('update affecting 2 rows -> db_error, rolled back', false === $r['success'] && 'db_error' === $r['code'] && !in_array('COMMIT', statement_kinds(), true));
reset_fixture(['now' => T_MODIFIED, 'now_local' => '2026-11-25 11:00:00']);
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => T_CONTENT]));
check('a real identical write in the same second changes 0 rows and is success', null === $wpdb->update_result && true === ($r['success'] ?? null) && t_row(T_ID, 'page') === $wpdb->rows[T_ID]);

echo "Transaction errors\n";
reset_fixture();
$wpdb->fail['START TRANSACTION'] = true;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('START TRANSACTION failure -> db_error, no select, no hook', is_refusal($r, 'db_error') && ['START TRANSACTION'] === statement_kinds());
reset_fixture();
$wpdb->fail['COMMIT'] = true;
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failure -> db_error, rolled back, no after-commit hook', 'db_error' === $r['code'] && $before === $wpdb->rows && ['pre_post_update'] === hooks_fired());

// ---------------------------------------------------------------------------
echo "Storage: the engine is read fresh inside the transaction on every write\n";
reset_fixture();
$wpdb->engine = 'MyISAM';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('non-InnoDB -> unsupported_storage after the lock, ROLLBACK', is_refusal($r, 'unsupported_storage') && ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds() && $before === $wpdb->rows);
reset_fixture();
$wpdb->engine = null;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('unreadable engine -> unsupported_storage', is_refusal($r, 'unsupported_storage'));
reset_fixture(['transients' => ['cinatra_posts_table_engine' => 'InnoDB']]);
$wpdb->engine = 'MyISAM';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a leftover engine transient is not trusted', is_refusal($r, 'unsupported_storage'));
reset_fixture();
$wpdb->engine = 'innodb';
check('engine compared case-insensitively', true === cinatra_ability_post_update_if_unchanged(base_update())['success']);

// ---------------------------------------------------------------------------
echo "Input contract\n";
$cases = [
    'expected.title missing'            => base_update(['expected' => array_diff_key(full_expected(), ['title' => 1])]),
    'expected.content missing'          => base_update(['expected' => array_diff_key(full_expected(), ['content' => 1])]),
    'expected.excerpt missing'          => base_update(['expected' => array_diff_key(full_expected(), ['excerpt' => 1])]),
    'expected.status missing'           => base_update(['expected' => array_diff_key(full_expected(), ['status' => 1])]),
    'expected.row_token missing'        => base_update(['expected' => array_diff_key(full_expected(), ['row_token' => 1])]),
    'malformed expected.row_token'      => base_update(['expected' => full_expected(['row_token' => 'abc'])]),
    'upper-case expected.row_token'     => base_update(['expected' => full_expected(['row_token' => strtoupper(t_token(t_row(T_ID, 'page')))])]),
    'status-only with empty expected'   => ['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => [], 'status' => 'draft'],
    'expected names an unknown field'   => base_update(['expected' => full_expected(['slug' => 'x'])]),
    'non-string written value'          => base_update(['content' => ['x']]),
    'non-string expected value'         => base_update(['expected' => full_expected(['excerpt' => null])]),
    'empty expected.status'             => base_update(['expected' => full_expected(['status' => ''])]),
    'expected is not an object'         => base_update(['expected' => 'x']),
    'nothing written'                   => ['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected()],
    'unknown top-level field'           => base_update(['post_date' => '2020-01-01 00:00:00']),
    'missing if_unmodified_since'       => array_diff_key(base_update(), ['if_unmodified_since' => 1]),
    'malformed if_unmodified_since'     => base_update(['if_unmodified_since' => '2026-11-25T10:00:00Z']),
    'invalid post_id'                   => base_update(['post_id' => 0]),
    'input is not an object'            => 'x',
];
foreach (['publish', 'pending', 'private', 'future', 'trash', 'Draft', ''] as $bad) {
    $cases["status \"$bad\""] = base_update(['status' => $bad]);
}
foreach ($cases as $label => $input) {
    reset_fixture();
    $r = cinatra_ability_post_update_if_unchanged($input);
    check("$label -> invalid, no statement", is_refusal($r, 'invalid') && [] === statement_kinds());
}
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected(), 'status' => 'draft']);
check('status-only unpublish with the whole expected post -> success, only status + modified written', true === $r['success'] && ['post_status', 'post_modified', 'post_modified_gmt'] === array_keys($wpdb->updates[0]['data'] ?? []));
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
t_state_is_read();
$r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft']));
check('status draft on a draft is allowed (no change)', true === $r['success'] && 'draft' === $r['post_status']);

// ---------------------------------------------------------------------------
echo "Read: raw values, fresh capability\n";
reset_fixture();
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('success with raw content (backslashes intact)', true === $r['success'] && T_CONTENT === $r['post_content']);
check('raw title, excerpt, status, type, modified, permalink', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt'] && 'publish' === $r['post_status'] && 'page' === $r['post_type'] && T_MODIFIED === $r['post_modified_gmt'] && 'https://blog.example/about/' === $r['permalink']);
check('exactly one plain select, no lock, no transaction, no hook', ['SELECT'] === statement_kinds() && [] === hooks_fired());
check('read output keys', ['success', 'post_id', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_modified_gmt', 'permalink', 'row_token'] === array_keys($r));
check('read row_token is the canonical token of the row it read', t_token($wpdb->rows[T_ID]) === $r['row_token']);
$r2 = cinatra_ability_post_read(['post_id' => T_ID]);
check('row_token is stable across two reads of an unchanged row', $r['row_token'] === $r2['row_token']);
$all_differ = true;
foreach (array_keys(t_row(T_ID, 'page')) as $col) {
    reset_fixture();
    $base = cinatra_ability_post_read(['post_id' => T_ID])['row_token'];
    $wpdb->rows[T_ID][$col] = $wpdb->rows[T_ID][$col] . 'x';
    $changed = cinatra_ability_post_read(['post_id' => T_ID]);
    if (!isset($changed['row_token']) || $changed['row_token'] === $base) {
        // A change of ID or post_type/author may make the read refuse; that is not a token match either.
        if (true === ($changed['success'] ?? null)) { $all_differ = false; echo "    (token unchanged after changing $col)\n"; }
    }
}
check('row_token differs after a change to any single column', $all_differ);
check('length-prefixed: moving a byte between adjacent columns changes the token', t_token(['a' => 'xy', 'b' => 'z']) !== t_token(['a' => 'x', 'b' => 'yz']) && cinatra_content_row_token(['a' => 'xy', 'b' => 'z']) !== cinatra_content_row_token(['a' => 'x', 'b' => 'yz']));
check('plugin token equals the documented encoding (NULL distinct from empty)', cinatra_content_row_token(['b' => null, 'a' => '1']) === t_token(['a' => '1', 'b' => null]) && cinatra_content_row_token(['a' => null]) !== cinatra_content_row_token(['a' => '']));
$unknown = cinatra_ability_post_read(['post_id' => 999]);
$other = cinatra_ability_post_read(['post_id' => 7]);
check('read: unknown and other-type give the same not_found', is_refusal($unknown, 'not_found') && $unknown === $other);

// ---------------------------------------------------------------------------
echo "Registration\n";
check('abilities register on wp_abilities_api_init', in_array('cinatra_register_content_abilities', $GLOBALS['cinatra_test_actions']['wp_abilities_api_init'] ?? [], true));
check('category registers on wp_abilities_api_categories_init', in_array('cinatra_register_ability_category', $GLOBALS['cinatra_test_actions']['wp_abilities_api_categories_init'] ?? [], true));
$GLOBALS['cinatra_test_abilities'] = [];
cinatra_register_ability_category();
cinatra_register_content_abilities();
$abilities = $GLOBALS['cinatra_test_abilities'];
check('category cinatra registered', isset($GLOBALS['cinatra_test_categories']['cinatra']));
check('exactly the two new ability ids, none retired', ['cinatra/post-read', 'cinatra/post-update-if-unchanged'] === array_keys($abilities) && [] === array_intersect(array_keys($abilities), $retired_ids));
$read = $abilities['cinatra/post-read'];
$write = $abilities['cinatra/post-update-if-unchanged'];
check('both public to MCP; read is read-only, write is not', true === $read['meta']['mcp']['public'] && true === $write['meta']['mcp']['public'] && true === $read['meta']['annotations']['readonly'] && false === $write['meta']['annotations']['readonly']);
check('both use the per-post permission callback and their execute callbacks', 'cinatra_content_can_edit' === $read['permission_callback'] && 'cinatra_content_can_edit' === $write['permission_callback'] && 'cinatra_ability_post_read' === $read['execute_callback'] && 'cinatra_ability_post_update_if_unchanged' === $write['execute_callback']);
check('write schema: status enum is draft only; expected requires the four values and row_token', ['draft'] === $write['input_schema']['properties']['status']['enum'] && ['title', 'content', 'excerpt', 'status', 'row_token'] === $write['input_schema']['properties']['expected']['required']);
check('read output schema declares row_token', isset($read['output_schema']['properties']['row_token']));
check('output schema has no also_changed', !isset($write['output_schema']['properties']['also_changed']) && isset($write['output_schema']['properties']['sanitized_fields']));
check('refusal shape has no "error" key (the adapter would flatten it)', !array_key_exists('error', cinatra_content_not_found()));

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "All checks passed.\n";
exit(0);
