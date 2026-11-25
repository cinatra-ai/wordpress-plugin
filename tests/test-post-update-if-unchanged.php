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
 * never make the stored status anything but the requested draft; no
 * after-save hook on a refusal ( the clean_post_cache action of the rollback's
 * cache clean may fire ); a floating-date draft keeps its dates; no term function; the save
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
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0]; // wpdb::prepare( $query, array( ... ) )
        }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }
    // The savepoint probe, modelled on MySQL/MariaDB's sql/transaction.cc:
    // SAVEPOINT outside a multi-statement transaction ( autocommit on, no
    // START TRANSACTION ) is accepted and does nothing; ROLLBACK TO / RELEASE
    // of a savepoint that does not exist fails with errno 1305. Inside a
    // transaction SAVEPOINT sets it ( an existing name is replaced ).
    public $dbh;                 // the connection: errno of the last statement
    public $foreign_open = false; // a transaction opened by site code before the call
    public $savepoints = [];     // savepoint name => true, of the open transaction
    public $probe_errno = [];    // 'SAVEPOINT' | 'ROLLBACK TO SAVEPOINT' | 'RELEASE SAVEPOINT' => forced errno
    public function __construct() {
        $this->dbh = new stdClass();
        $this->dbh->errno = 0;
    }
    public function query($sql) {
        $this->log[] = $sql;
        if (preg_match('/^(SAVEPOINT|ROLLBACK TO SAVEPOINT|RELEASE SAVEPOINT) (\S+)$/', $sql, $m)) {
            [, $kind, $name] = $m;
            if (0 === strpos($name, 'cinatra_probe_')) {
                $GLOBALS['cinatra_test']['probe_names'][] = $name;
                $GLOBALS['cinatra_test']['probe_suppressed'][$kind] = $this->suppressed;
            } else {
                $GLOBALS['cinatra_test']['lock_savepoint'][] = [$kind, $name, $this->suppressed];
            }
            if (isset($this->probe_errno[$kind])) {
                if (is_object($this->dbh)) { $this->dbh->errno = $this->probe_errno[$kind]; }
                return false;
            }
            $open = $this->foreign_open || null !== $this->snapshot || '0' === (string) $this->autocommit;
            if ('SAVEPOINT' === $kind) {
                if ($open) { $this->savepoints[$name] = true; }
                if (is_object($this->dbh)) { $this->dbh->errno = 0; }
                return 0;
            }
            if (!isset($this->savepoints[$name])) {
                if (is_object($this->dbh)) { $this->dbh->errno = 1305; }
                return false;
            }
            if ('RELEASE SAVEPOINT' === $kind) { unset($this->savepoints[$name]); }
            if (is_object($this->dbh)) { $this->dbh->errno = 0; }
            return 0;
        }
        // The conditional write: UPDATE `wp_posts` SET `col` = '...', ...
        // WHERE `ID` = n AND <one condition per column of the locked row>.
        if (0 === strpos($sql, 'UPDATE `')) {
            return $this->conditional_update($sql);
        }
        // on_commit: the test decides what COMMIT does ( see apply_commit(),
        // server_rollback(), lose_connection() ) and what it returns.
        if ('COMMIT' === $sql && null !== $this->on_commit) {
            return ($this->on_commit)($this);
        }
        if (!empty($this->fail[$sql])) {
            return false;
        }
        if ('START TRANSACTION' === $sql) {
            $this->snapshot = $this->rows;
            $this->savepoints = [];
        } elseif ('ROLLBACK' === $sql) {
            if (null !== $this->snapshot) {
                $this->rows = $this->snapshot;
            }
            $this->snapshot = null;
            $this->savepoints = [];
        } elseif ('COMMIT' === $sql) {
            $this->snapshot = null;
            $this->savepoints = [];
        }
        return 0;
    }
    public $before_update = null;  // callable( $wpdb ): runs when the UPDATE reaches the server
    public $update_sql = [];       // each UPDATE statement as sent
    public $conditions = [];       // the parsed row condition of each UPDATE: column => sha256 hex | null
    /**
     * Parse and run the plugin's conditional UPDATE: the SET list ( values
     * quoted by prepare() above ), WHERE `ID` = n, then per column either
     * `col` IS NULL or SHA2(CONVERT(`col` USING cs), 256) = 'hex'. The row
     * changes only when every condition holds on the current row.
     */
    private function conditional_update($sql) {
        $this->log[count($this->log) - 1] = 'UPDATE-ROW'; // query() logged the statement
        $this->update_sql[] = $sql;
        if (null !== $this->before_update) {
            ($this->before_update)($this);
        }
        if (!preg_match('/^UPDATE `([^`]+)` SET /', $sql, $m)) {
            return false;
        }
        $table = $m[1];
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
            } elseif (preg_match('/^SHA2\((?:CONVERT\(`(\w+)` USING \w+\)|`(\w+)`), 256\) = \'([0-9a-f]{64})\'$/', $part, $c)) {
                $cond['' !== $c[1] ? $c[1] : $c[2]] = $c[3];
            } else {
                return false;
            }
        }
        $this->conditions[] = $cond;
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => ['ID' => $id]];
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
                $this->rows[$id][$col] = (string) ($this->store_as[$col] ?? $value);
            }
        }
        return null !== $this->update_result ? $this->update_result : $changed;
    }
    public $on_commit = null;      // callable( $wpdb ): what COMMIT does and returns
    public $select_fails = false;  // get_row() answers null ( a failed read )
    public $connection_ok = true;  // what check_connection() answers
    public $connection_checks = 0;
    /** The server committed the transaction ( whatever COMMIT then returns ). */
    public function apply_commit() {
        $this->snapshot = null;
        $this->savepoints = [];
    }
    /** The server rolled the transaction back ( e.g. its connection ended ). */
    public function server_rollback() {
        if (null !== $this->snapshot) {
            $this->rows = $this->snapshot;
        }
        $this->snapshot = null;
        $this->savepoints = [];
    }
    /**
     * The connection is lost and wpdb reconnects, as wpdb::query() does after
     * error 2006: the server rolls the open transaction back, $wpdb->dbh is a
     * new connection object, and later statements run with autocommit.
     */
    public function lose_connection() {
        $this->server_rollback();
        $this->dbh = new stdClass();
        $this->dbh->errno = 0;
    }
    /** wpdb::check_connection( $allow_bail ): ping, reconnect if needed. */
    public function check_connection($allow_bail = true) {
        $this->connection_checks++;
        return $this->connection_ok;
    }
    public function get_row($sql, $output = null) {
        $this->log[] = $sql;
        if ($this->select_fails) {
            return null;
        }
        if (!preg_match('/WHERE ID = (\d+)/', $sql, $m)) {
            return null;
        }
        $row = $this->rows[(int) $m[1]] ?? null;
        // after_select: another request runs right after this SELECT returned
        // ( it may commit a newer row and clean the cache, as core does ).
        if (isset($GLOBALS['cinatra_test']['after_select'])) {
            ($GLOBALS['cinatra_test']['after_select'])($sql);
        }
        return $row;
    }
    // Pre-transaction check: @@autocommit ( '1'; null: unreadable ).
    public $autocommit = '1';
    public $suppress_calls = [];
    private $suppressed = false;
    public function suppress_errors($suppress = true) {
        $previous = $this->suppressed;
        $this->suppressed = (bool) $suppress;
        $this->suppress_calls[] = (bool) $suppress;
        return $previous;
    }
    public function get_var($sql, $x = 0, $y = 0) {
        $this->log[] = $sql;
        if (false !== strpos($sql, '@@SESSION.autocommit')) {
            return $this->autocommit;
        }
        if (0 === strpos($sql, 'SHOW SESSION VARIABLES') || false !== strpos($sql, 'INNODB_TRX')) {
            $GLOBALS['cinatra_test']['dropped_detection_query'] = $sql;
            return null;
        }
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
// Spy: every cache WRITE is recorded. The abilities must never write the posts
// group themselves ( core's own get_post() fills the cache in this harness
// without these functions, as WP_Post::get_instance() does with wp_cache_add ).
foreach (['wp_cache_set', 'wp_cache_add', 'wp_cache_replace'] as $cinatra_fn) {
    if (!function_exists($cinatra_fn)) {
        eval("function $cinatra_fn(\$key, \$data, \$group = '', \$expire = 0) { \$GLOBALS['cinatra_test']['cache_writes'][] = ['$cinatra_fn', \$key, \$group]; if ('posts' === \$group) { \$GLOBALS['cinatra_test']['post_cache'][(int) \$key] = (array) \$data; } return true; }");
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
if (!function_exists('wp_suspend_cache_invalidation')) {
    /** Core ( wp-includes/functions.php ): sets the global, returns the previous value. */
    function wp_suspend_cache_invalidation($suspend = true) {
        $current = $GLOBALS['_wp_suspend_cache_invalidation'] ?? null;
        $GLOBALS['_wp_suspend_cache_invalidation'] = $suspend;
        $GLOBALS['cinatra_test']['suspend_calls'][] = $suspend;
        return $current;
    }
}
if (!function_exists('wp_cache_get')) {
    /** The posts group of the modelled object cache: the cached row ( an object ) or false. */
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        if ('posts' === $group && isset($GLOBALS['cinatra_test']['post_cache'][(int) $key])) {
            $found = true;
            return (object) $GLOBALS['cinatra_test']['post_cache'][(int) $key];
        }
        $found = false;
        return false;
    }
}
if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post_id) {
        global $wpdb;
        $GLOBALS['cinatra_test']['cleaned'][] = (int) $post_id;
        // Core returns at once while invalidation is suspended ( wp-includes/post.php, clean_post_cache() ).
        if (!empty($GLOBALS['_wp_suspend_cache_invalidation'])) {
            $wpdb->log[] = 'CLEAN-CACHE';
            $GLOBALS['cinatra_test']['clean_suspended'][] = (int) $post_id;
            return;
        }
        // clean_ignored: a broken object-cache drop-in that keeps the entry.
        if (empty($GLOBALS['cinatra_test']['clean_ignored'])) {
            unset($GLOBALS['cinatra_test']['post_cache'][(int) $post_id]);
        }
        $wpdb->log[] = 'CLEAN-CACHE';
        do_action('clean_post_cache', (int) $post_id); // core fires it after the deletes
    }
}
if (!function_exists('get_post')) {
    /** Like WP_Post::get_instance(): the object cache first, else the DB row (then cached). */
    function get_post($id = null) {
        global $wpdb;
        if ($id instanceof WP_Post) {
            return $id; // core: a WP_Post instance is returned as given
        }
        $id = (int) $id;
        if (!isset($GLOBALS['cinatra_test']['post_cache'][$id])) {
            if (!isset($wpdb->rows[$id])) {
                return null;
            }
            $GLOBALS['cinatra_test']['post_cache'][$id] = $wpdb->rows[$id];
        }
        $p = new WP_Post($GLOBALS['cinatra_test']['post_cache'][$id]);
        // As WP_Post::get_instance(): sanitize_post( $_post, 'raw' ) casts the int fields; filter is 'raw'.
        $p->ID = (int) $p->ID;
        $p->post_parent = (int) $p->post_parent;
        $p->menu_order = (int) $p->menu_order;
        $p->filter = 'raw';
        // get_post_rewrite: site code that changes what get_post() returns.
        if (isset($GLOBALS['cinatra_test']['get_post_rewrite'])) {
            $p = ($GLOBALS['cinatra_test']['get_post_rewrite'])($p);
        }
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
     * Core's sanitize_post_field() for the db context ( wp-includes/post.php ):
     * ID, post_parent, menu_order cast to int; ancestors absint and returned;
     * a post_-prefixed field runs pre_<field> then <field without post_>_save_pre,
     * any other field pre_post_<field> then <field>_pre. kses sits on
     * title/content/excerpt/content_filtered _save_pre for a user without
     * unfiltered_html. Like core's filters, it expects and returns SLASHED data.
     */
    function sanitize_post_field($field, $value, $post_id, $context = 'display') {
        if (in_array($field, ['ID', 'post_parent', 'menu_order'], true)) {
            $value = (int) $value;
        }
        if ('raw' === $context) {
            return $value; // core: the raw context returns after the int casts
        }
        if ('ancestors' === $field) {
            return array_map('absint', $value);
        }
        $GLOBALS['cinatra_test']['sanitized'][] = $field;
        if (false !== strpos($field, 'post_')) {
            $hooks = ["pre_{$field}", str_replace('post_', '', $field) . '_save_pre'];
        } else {
            $hooks = ["pre_post_{$field}", "{$field}_pre"];
        }
        foreach ($hooks as $hook) {
            foreach ($GLOBALS['cinatra_test']['save_filters'][$hook] ?? [] as $cb) {
                $value = $cb($value);
            }
            if (in_array($hook, ['title_save_pre', 'content_save_pre', 'excerpt_save_pre', 'content_filtered_save_pre'], true) && is_string($value) && !current_user_can('unfiltered_html')) {
                $value = addslashes(preg_replace('#<script\b[^>]*>.*?</script>#is', '', stripslashes($value)));
            }
        }
        return $value;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return 0 < (int) $GLOBALS['cinatra_test']['current_user_id']; }
}
if (!function_exists('post_type_supports')) {
    /** Core's default supports: post has title, editor, excerpt; page has title and editor, no excerpt. */
    function post_type_supports($type, $feature) {
        $supports = $GLOBALS['cinatra_test']['supports'] ?? ['post' => ['title', 'editor', 'excerpt'], 'page' => ['title', 'editor']];
        return in_array($feature, $supports[$type] ?? [], true);
    }
}
if (!function_exists('absint')) {
    function absint($v) { return abs((int) $v); }
}
if (!function_exists('sanitize_post')) {
    /** Core's sanitize_post() for an array ( wp-includes/post.php ). */
    function sanitize_post($post, $context = 'display') {
        if (is_object($post)) {
            // core: an object gets the same per-field pass, and stays an object
            return (object) sanitize_post((array) $post, $context);
        }
        if (isset($post['filter']) && $context === $post['filter']) {
            return $post;
        }
        if (!isset($post['ID'])) {
            $post['ID'] = 0;
        }
        foreach (array_keys($post) as $field) {
            $post[$field] = sanitize_post_field($field, $post[$field], $post['ID'], $context);
        }
        $post['filter'] = $context;
        return $post;
    }
}
if (!function_exists('wp_parse_args')) {
    /** Core's wp_parse_args() for arrays: array_merge( $defaults, $args ). */
    function wp_parse_args($args, $defaults = []) {
        return is_array($defaults) && $defaults ? array_merge($defaults, $args) : $args;
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
        $post = get_post($post); // core: get_post( $post ), a WP_Post passes through
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

add_action('clean_post_cache', function () { $GLOBALS['cinatra_test']['clean_post_cache_fired'] = ($GLOBALS['cinatra_test']['clean_post_cache_fired'] ?? 0) + 1; });

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
    $GLOBALS['_wp_suspend_cache_invalidation'] = false;
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
                return null !== $post && (int) $GLOBALS['cinatra_test']['current_user_id'] === (int) $post->post_author;
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
/** The pre-transaction checks in the log ( excluded from statement_kinds() ). */
function precheck_kinds(): array {
    global $wpdb;
    $kinds = [];
    foreach ($wpdb->log as $sql) {
        if (false !== strpos($sql, '@@SESSION.autocommit')) { $kinds[] = 'AUTOCOMMIT?'; }
        elseif (0 === strpos($sql, 'SHOW SESSION VARIABLES')) { $kinds[] = 'IN-TRANSACTION?'; }
        elseif (false !== strpos($sql, 'INNODB_TRX')) { $kinds[] = 'INNODB-TRX?'; }
        elseif (preg_match('/^(SAVEPOINT|ROLLBACK TO SAVEPOINT|RELEASE SAVEPOINT) /', $sql, $m)) { $kinds[] = $m[1]; }
        elseif ('START TRANSACTION' === $sql) { $kinds[] = 'START TRANSACTION'; break; } // the checks before the transaction
    }
    return $kinds;
}
function statement_kinds(): array {
    global $wpdb;
    $log = array_values(array_filter($wpdb->log, function ($sql) {
        return false === strpos($sql, '@@SESSION.autocommit') && 0 !== strpos($sql, 'SHOW SESSION VARIABLES') && false === strpos($sql, 'INNODB_TRX')
            && !preg_match('/^(SAVEPOINT|ROLLBACK TO SAVEPOINT|RELEASE SAVEPOINT) /', $sql);
    }));
    return array_map(function ($sql) {
        if (0 === strpos($sql, 'SELECT ENGINE')) {
            return 'ENGINE';
        }
        if (0 === strpos($sql, 'SELECT ')) {
            return false !== strpos($sql, 'FOR UPDATE') ? 'SELECT-FOR-UPDATE' : 'SELECT';
        }
        return $sql;
    }, $log);
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

/** The hooks core's wp_insert_post() fires after an update ( a refusal fires none ). */
const T_AFTER_SAVE_HOOKS = [
    'transition_post_status', 'publish_to_publish', 'publish_to_draft', 'draft_to_draft', 'publish_page', 'draft_page', 'publish_post', 'draft_post',
    'edit_post_page', 'edit_post_post', 'edit_post', 'post_updated', 'save_post_page', 'save_post_post', 'save_post', 'wp_insert_post', 'wp_after_insert_post',
];
/**
 * A refusal before the write: well-formed, no post content, no after-save
 * hook and no pre_post_update. The rollback's cache clean may fire core's
 * clean_post_cache action; that is not a save hook.
 */
function is_refusal($r, string $code): bool {
    return is_array($r)
        && false === ($r['success'] ?? null)
        && $code === ($r['code'] ?? null)
        && is_string($r['message'] ?? null)
        && is_array($r['data'] ?? null)
        && array_key_exists('current_modified_gmt', $r['data'])
        && false === strpos(json_encode($r), 'SECRET-BODY-MARKER')
        && [] === array_intersect(hooks_fired(), T_AFTER_SAVE_HOOKS)
        && !in_array('pre_post_update', hooks_fired(), true)
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
echo "wp_insert_post_data: core's postarr and unsanitized_postarr\n";
/*
 * The expected arguments, derived from core 6.8 ( wp-includes/post.php ):
 *   wp_update_post():  $post = get_post( $postarr['ID'], ARRAY_A );   // WP_Post::to_array(): every column, filter,
 *                                                                       // ancestors, page_template, post_category, tags_input
 *                      $post = wp_slash( $post );
 *                      $postarr = array_merge( $post, $postarr );       // the caller's fields win
 *                      $postarr['post_category'] = $post_cats;          // the existing categories
 *   wp_insert_post():  $unsanitized_postarr = $postarr;
 *                      $postarr = wp_parse_args( $postarr, $defaults ); // array_merge( $defaults, $postarr )
 *                      unset( $postarr['filter'] );
 *                      $postarr = sanitize_post( $postarr, 'db' );      // every field through its chain; filter = 'db'
 * Core never puts the new modification times into $postarr: they exist only in $data.
 */
function t_core_args(array $row, array $request): array {
    $existing = $row;
    foreach (['ID', 'post_parent', 'menu_order'] as $k) { $existing[$k] = (int) $existing[$k]; }
    $existing['filter'] = 'raw';
    $existing = array_merge($existing, ['ancestors' => [], 'page_template' => '', 'post_category' => [], 'tags_input' => []]);
    $unsanitized = array_merge(wp_slash($existing), wp_slash($request));
    $defaults = ['post_author' => 3, 'post_content' => '', 'post_content_filtered' => '', 'post_title' => '', 'post_excerpt' => '', 'post_status' => 'draft', 'post_type' => 'post', 'comment_status' => '', 'ping_status' => '', 'post_password' => '', 'to_ping' => '', 'pinged' => '', 'post_parent' => 0, 'menu_order' => 0, 'guid' => '', 'import_id' => 0, 'context' => '', 'post_date' => '', 'post_date_gmt' => ''];
    $postarr = array_merge($defaults, $unsanitized);
    unset($postarr['filter']);
    foreach ($postarr as $k => $v) {
        if (in_array($k, ['ID', 'post_parent', 'menu_order'], true)) { $postarr[$k] = (int) $v; }
    }
    $postarr['filter'] = 'db';
    return [$postarr, $unsanitized];
}
$parity = [
    'content-only'    => [base_update(['content' => "<p>New \\ \"body\"</p>"]), ['ID' => T_ID, 'post_content' => "<p>New \\ \"body\"</p>"]],
    'status to draft' => [array_diff_key(base_update(['status' => 'draft']), ['content' => 1]), ['ID' => T_ID, 'post_status' => 'draft']],
    'combined'        => [base_update(['title' => 'T "2"', 'content' => '<p>c</p>', 'excerpt' => 'e', 'status' => 'draft']), ['ID' => T_ID, 'post_title' => 'T "2"', 'post_content' => '<p>c</p>', 'post_excerpt' => 'e', 'post_status' => 'draft']],
];
foreach ($parity as $label => [$input, $request]) {
    reset_fixture();
    $r = cinatra_ability_post_update_if_unchanged($input);
    $f = null;
    foreach ($GLOBALS['cinatra_test']['filter_calls'] as $call) { if ('wp_insert_post_data' === $call[0]) { $f = $call; } }
    [$want_postarr, $want_unsanitized] = t_core_args(t_row(T_ID, 'page'), $request);
    check("$label: postarr is core's merged, parsed, sanitized array, field by field", true === ($r['success'] ?? null) && null !== $f && $want_postarr === $f[2][0]);
    check("$label: unsanitized_postarr is core's merged array before sanitising", null !== $f && $want_unsanitized === $f[2][1]);
}
reset_fixture(['unfiltered_html' => false]);
$seen = [];
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d, $postarr, $raw) use (&$seen) { $seen = [$raw['post_content'], $postarr['post_content'], $postarr['post_modified'], $postarr['post_modified_gmt']]; return $d; };
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>x</p><script>y()</script>']));
check('a four-argument filter sees the caller\'s raw content in unsanitized_postarr and the sanitized one in postarr', wp_slash('<p>x</p><script>y()</script>') === ($seen[0] ?? null) && '<p>x</p>' === ($seen[1] ?? null));
check('postarr post_modified is what core gives it: the stored value, not the new time', '2026-11-25 11:00:00' === ($seen[2] ?? null) && T_MODIFIED === ($seen[3] ?? null));

// ---------------------------------------------------------------------------
echo "wp_insert_post_data and pre_post_update: each column has core's type\n";
/*
 * Core 7.1.5, wp-includes/post.php, wp_insert_post() on an update through
 * wp_update_post(): post_parent and menu_order are cast with (int) ( lines
 * 4834-4848 ); every other column of the compact() at line 4912 is a string
 * ( post_author: $postarr['post_author'], line 4824, the numeric string
 * WP_Post holds, class-wp-post.php line 68; guid: get_post_field(), line
 * 4653; the dates, slug, status and texts are strings ). wp_slash() at the
 * filter and wp_unslash() before pre_post_update ( line 4981 ) keep the types.
 */
$want_types = [];
foreach ($core_cols as $col) { $want_types[$col] = in_array($col, ['post_parent', 'menu_order'], true) ? 'integer' : 'string'; }
reset_fixture();
$seen_types = [];
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) use (&$seen_types) { $seen_types = array_map('gettype', $d); return $d; };
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function ($id, $d) use (&$pre_types) { $pre_types = array_map('gettype', $d); };
$pre_types = [];
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('wp_insert_post_data: post_parent and menu_order are integers, every other column a string', true === ($r['success'] ?? null) && $want_types === $seen_types);
check('pre_post_update: the same types', $want_types === $pre_types);
// A strict site policy ( === on an integer ) takes core's branch.
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) {
    if (0 !== $d['post_parent'] || 0 !== $d['menu_order']) {
        $d['post_name'] = 'strict-policy-moved'; // the branch core's types never take
    }
    return $d;
};
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a strict policy ( 0 === post_parent ) leaves the data alone, as on a core save: success', true === ($r['success'] ?? null) && 'about' === $wpdb->rows[T_ID]['post_name']);

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
check('a site filter that would publish a draft -> site_policy_conflict naming post_status, nothing written, no after-save hook', is_refusal($r, 'site_policy_conflict') && ['post_status'] === $r['data']['changed'] && $before === $wpdb->rows);

// ---------------------------------------------------------------------------
echo "Capability: re-checked against the FRESH row under the lock\n";
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9'; // reassigned; the object cache still says author 3
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('write refused as not_found after the lock', is_refusal($r, 'not_found'));
check('the stale entry is invalidated by core ( clean_post_cache ), then ROLLBACK ( and the cache evicted again ), no update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'CLEAN-CACHE', 'ROLLBACK', 'CLEAN-CACHE'] === statement_kinds() && $before === $wpdb->rows);
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('read refused as not_found too', is_refusal($r, 'not_found'));
check('the read decided on the fresh row by core\'s own road ( invalidated, reloaded ), and wrote no cache entry itself', [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []) && in_array(T_ID, $GLOBALS['cinatra_test']['cleaned'] ?? [], true) && '9' === $GLOBALS['cinatra_test']['post_cache'][T_ID]['post_author']);

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
check('unknown and forbidden take the same road ( no early cache check ): locked select, ROLLBACK, cache evicted', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK', 'CLEAN-CACHE'] === $unknown_log && $unknown_log === $forbidden_log);
check('other-type rolls back after the locked select', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK', 'CLEAN-CACHE'] === statement_kinds());
reset_fixture();
check('permission callback: needs no post, only a logged-in caller ( it can never refuse a user the fresh-row check would allow )', true === cinatra_content_precheck(['post_id' => T_ID]) && true === cinatra_content_precheck(['post_id' => 999]) && true === cinatra_content_precheck([]));
$GLOBALS['cinatra_test']['current_user_id'] = 0;
check('permission callback: a logged-out caller is refused', false === cinatra_content_precheck(['post_id' => T_ID]));
$GLOBALS['cinatra_test']['current_user_id'] = 3;

// ---------------------------------------------------------------------------
echo "Refusals: nothing written, no after-save hook\n";
reset_fixture();
$wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:03:00';
$GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID];
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('post_changed (modified), current value given, no after-save hook', is_refusal($r, 'post_changed') && '2026-11-25 10:03:00' === $r['data']['current_modified_gmt'] && ['post_modified_gmt', 'row_token'] === $r['data']['changed']);
check('post_changed rolls back without an update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK', 'CLEAN-CACHE'] === statement_kinds());
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
check('post_locked, no after-save hook', is_refusal($r, 'post_locked') && T_MODIFIED === $r['data']['current_modified_gmt']);
check('that refusal\'s rollback cleaned the cache through core, so the clean_post_cache action fired; it is not a save hook', ($GLOBALS['cinatra_test']['clean_post_cache_fired'] ?? 0) > 0);
foreach (['pending', 'future', 'private', 'trash', 'auto-draft'] as $state) {
    reset_fixture();
    $wpdb->rows[T_ID]['post_status'] = $state;
    t_state_is_read();
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft', 'expected' => full_expected(['status' => $state])]));
    check("current status $state -> unsupported_status, nothing written", is_refusal($r, 'unsupported_status') && $state === $r['data']['current_status'] && $before === $wpdb->rows);
}
reset_fixture();
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => T_POST_ID, 'title' => '', 'content' => '', 'excerpt' => '']));
check('a post emptied ( title, content and excerpt ) -> empty_content, nothing written', is_refusal($r, 'empty_content') && $before === $wpdb->rows);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => T_POST_ID, 'title' => '', 'content' => '0', 'excerpt' => '']));
check('content "0" with empty title and excerpt is empty for core ( ! "0" ) -> empty_content', is_refusal($r, 'empty_content'));
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['title' => '', 'content' => '', 'excerpt' => '']));
check('a page ( no excerpt support by default ) may be emptied, as core allows', true === ($r['success'] ?? null));
reset_fixture();
$seen = null;
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_empty_content'][] = function ($maybe_empty, $postarr) use (&$seen) { $seen = [$maybe_empty, $postarr]; return true; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a site filter vetoing a non-empty save is honoured -> empty_content, nothing written, no after-save hook', is_refusal($r, 'empty_content') && $before === $wpdb->rows);
check('the veto filter got core\'s arguments: false and the sanitized postarr', is_array($seen) && false === $seen[0] && T_ID === ($seen[1]['ID'] ?? null) && 'db' === ($seen[1]['filter'] ?? null) && '<p>New body</p>' === ($seen[1]['post_content'] ?? null));

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
check('the unwritten body\'s chain runs only to build core\'s $postarr ( as core does ); the stored body is untouched', !empty($GLOBALS['cinatra_test']['content_chain_ran']) && $admin_markup === $wpdb->rows[T_ID]['post_content'] && !array_key_exists('post_content', $wpdb->updates[0]['data'] ?? []));
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
    check("a policy changing $col (not written) -> site_policy_conflict, nothing written, no after-save hook", is_refusal($r, 'site_policy_conflict') && [$col] === $r['data']['changed'] && $before === $wpdb->rows && [] === $wpdb->updates);
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
    check("a $hook policy that does not allow draft -> site_policy_conflict naming post_status, nothing written, no after-save hook", is_refusal($r, 'site_policy_conflict') && ['post_status'] === $r['data']['changed'] && $before === $wpdb->rows && [] === $wpdb->updates);
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
        // The write is conditional on the locked row: a column changed during the save is never overwritten.
        check("$label -> the conditional write matches no row: site_policy_conflict naming post_content, rolled back, no after-save hook", is_late_refusal($r, 'site_policy_conflict', $before) && ['post_content'] === ($r['data']['changed'] ?? null) && false !== strpos($r['message'], 'matched no row'));
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
check('a callback writing the same content as this save also changed the locked row: refused ( site_policy_conflict naming post_content ), never overwritten', 'site_policy_conflict' === ($r['code'] ?? null) && ['post_content'] === ($r['data']['changed'] ?? null) && T_CONTENT === $wpdb->rows[T_ID]['post_content']);

// ---------------------------------------------------------------------------
echo "Permission: a stale cache that wrongly DENIES does not refuse\n";
reset_fixture();
$GLOBALS['cinatra_test']['post_cache'][T_ID]['post_author'] = '9'; // stale: someone else's; the database row ( author 3 ) grants
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('read: the fresh row grants -> success with the content', true === ($r['success'] ?? null) && T_CONTENT === $r['post_content']);
reset_fixture();
$GLOBALS['cinatra_test']['post_cache'][T_ID]['post_author'] = '9';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('write: the fresh row grants -> success', true === ($r['success'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content']);
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a caller the fresh row denies still gets not_found', is_refusal($r, 'not_found'));

// ---------------------------------------------------------------------------
echo "Exceptions: the transaction never stays open\n";
$throwers = [
    'sanitisation ( content_save_pre )' => function () { $GLOBALS['cinatra_test']['save_filters']['content_save_pre'][] = function ($v) { throw new RuntimeException('site filter failed'); }; },
    'wp_insert_post_data'               => function () { $GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { throw new RuntimeException('site filter failed'); }; },
    'pre_post_update'                   => function () { $GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () { global $wpdb; $wpdb->rows[T_ID]['post_name'] = 'half-done'; throw new RuntimeException('site filter failed'); }; },
];
foreach ($throwers as $stage => $install) {
    reset_fixture();
    $install();
    $before = $wpdb->rows;
    $caught = null;
    try {
        cinatra_ability_post_update_if_unchanged(base_update());
    } catch (Throwable $e) {
        $caught = $e;
    }
    check("a throw in $stage propagates unchanged", $caught instanceof RuntimeException && 'site filter failed' === $caught->getMessage());
    check("a throw in $stage rolls back: nothing written, no COMMIT, ROLLBACK issued, transaction closed", $before === $wpdb->rows && !in_array('COMMIT', statement_kinds(), true) && in_array('ROLLBACK', statement_kinds(), true) && false === cinatra_content_transaction()['open']);
    check("a throw in $stage fires no after-save hook", [] === array_diff(hooks_fired(), ['pre_post_update']));
}
reset_fixture();
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'][] = function ($d) { throw new Error('fatal in a site plugin'); };
$caught = null;
try { cinatra_ability_post_update_if_unchanged(base_update()); } catch (Throwable $e) { $caught = $e; }
check('an Error ( not only an Exception ) is rolled back and rethrown', $caught instanceof Error && false === cinatra_content_transaction()['open'] && in_array('ROLLBACK', statement_kinds(), true));
reset_fixture();
add_action('save_post', function () { if (!empty($GLOBALS['cinatra_test']['throw_after_commit'])) { throw new RuntimeException('after commit'); } }, 10, 3);
$GLOBALS['cinatra_test']['throw_after_commit'] = true;
$caught = null;
try { cinatra_ability_post_update_if_unchanged(base_update()); } catch (Throwable $e) { $caught = $e; }
$GLOBALS['cinatra_test']['throw_after_commit'] = false;
check('a throw in an after-save hook propagates; the save stays committed ( no ROLLBACK after COMMIT )', $caught instanceof RuntimeException && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content'] && !in_array('ROLLBACK', statement_kinds(), true) && false === cinatra_content_transaction()['open']);
// exit / wp_die inside a callback: the guard on the shutdown action rolls back.
check('a shutdown guard is registered on the shutdown action', in_array('cinatra_content_shutdown_guard', $GLOBALS['cinatra_test_actions']['shutdown'] ?? [], true));
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () {
    global $wpdb;
    $wpdb->rows[T_ID]['post_status'] = 'publish-by-exit';
    // What exit/wp_die leads to: PHP runs the shutdown functions, WordPress fires "shutdown".
    cinatra_content_shutdown_guard();
    // Observed at the moment the guard returns: an exit ends the request here.
    $GLOBALS['cinatra_test']['after_guard'] = [cinatra_content_transaction()['open'], $wpdb->rows, statement_kinds()];
    throw new RuntimeException('simulated exit'); // the harness cannot exit; stop here
};
$before = $wpdb->rows;
try { cinatra_ability_post_update_if_unchanged(base_update()); } catch (Throwable $e) { }
[$open_after_guard, $rows_after_guard, $log_after_guard] = $GLOBALS['cinatra_test']['after_guard'] ?? [true, null, []];
check('the shutdown guard itself rolls back the open transaction ( exit / wp_die inside a callback )', false === $open_after_guard && $before === $rows_after_guard && 'ROLLBACK' === ($log_after_guard[count($log_after_guard) - 2] ?? null) && 'CLEAN-CACHE' === end($log_after_guard));
// Ordering on the shutdown action ( the stub runs callbacks by priority, as WordPress ): the guard
// ( PHP_INT_MIN ) must roll back before a later callback issues a statement on the connection.
reset_fixture();
add_action('shutdown', function () { global $wpdb; if (!empty($GLOBALS['cinatra_test']['late_shutdown'])) { $wpdb->query('INSERT late shutdown write'); } }, 1); // registered after the guard, priority 1 ( runs before any default-priority callback )
$GLOBALS['cinatra_test']['late_shutdown'] = true;
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () {
    do_action('shutdown'); // what WordPress fires after exit / wp_die()
    throw new RuntimeException('simulated exit'); // the harness cannot exit; stop here
};
try { cinatra_ability_post_update_if_unchanged(base_update()); } catch (Throwable $e) { }
$GLOBALS['cinatra_test']['late_shutdown'] = false;
$kinds = statement_kinds();
$rollback_at = array_search('ROLLBACK', $kinds, true);
$late_at = array_search('INSERT late shutdown write', $kinds, true);
check('on the shutdown action the guard rolls back BEFORE a later-priority callback issues a statement', false !== $rollback_at && false !== $late_at && $rollback_at < $late_at && !in_array('COMMIT', $kinds, true));
reset_fixture();
$wpdb->log = [];
cinatra_content_shutdown_guard();
check('the shutdown guard does nothing when no transaction is open', [] === statement_kinds());

// ---------------------------------------------------------------------------
echo "Transactions: the ability owns its transaction or it does not run\n";
reset_fixture();
$inner = null;
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () use (&$inner) {
    if (null === $inner) {
        $inner = 'running';
        $inner = cinatra_ability_post_update_if_unchanged(array_diff_key(base_update(['title' => 'inner title']), ['content' => 1]));
    }
};
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a nested call from pre_post_update -> transaction_conflict ( nested ), before any SQL of its own', is_array($inner) && false === $inner['success'] && 'transaction_conflict' === $inner['code'] && 'nested' === ($inner['data']['cause'] ?? null));
check('the inner call wrote nothing; the outer save completed with its own values', true === ($r['success'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content'] && 'About "us"' === $wpdb->rows[T_ID]['post_title']);
check('exactly one START TRANSACTION and one set of checks ( the inner call issued no statement )', ['AUTOCOMMIT?', 'SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'START TRANSACTION'] === precheck_kinds() && 1 === count(array_keys(statement_kinds(), 'COMMIT')));
check('the outer lock was never released before its COMMIT ( no START or COMMIT between the lock and the write )', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'HOOK:pre_post_update', 'UPDATE-ROW', 'SELECT', 'COMMIT'] === array_slice(statement_kinds(), 0, 7));
// The savepoint probe ( no privilege, no implicit commit ): SAVEPOINT, then
// ROLLBACK TO SAVEPOINT. Nothing open: SAVEPOINT is a no-op and ROLLBACK TO
// fails with 1305, the signal "no transaction". Open: both succeed, then
// RELEASE SAVEPOINT removes the probe's savepoint.
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
$names = $GLOBALS['cinatra_test']['probe_names'] ?? [];
check('nothing open: SAVEPOINT, ROLLBACK TO fails with 1305 -> proceeds; no in_transaction or INNODB_TRX query', true === ($r['success'] ?? null) && ['AUTOCOMMIT?', 'SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'START TRANSACTION'] === precheck_kinds() && !isset($GLOBALS['cinatra_test']['dropped_detection_query']));
check('the probe savepoint name is per call, unlikely to collide ( cinatra_probe_ + 16 hex ), the same in both statements', 2 === count($names) && 1 === preg_match('/^cinatra_probe_[0-9a-f]{16}$/', $names[0]) && $names[0] === $names[1]);
check('the expected 1305 runs with database errors suppressed, restored afterwards', true === ($GLOBALS['cinatra_test']['probe_suppressed']['ROLLBACK TO SAVEPOINT'] ?? null) && false === ($GLOBALS['cinatra_test']['probe_suppressed']['SAVEPOINT'] ?? null) && [true, false] === array_slice($wpdb->suppress_calls, 0, 2));
$first_name = $names[0] ?? '';
reset_fixture();
cinatra_ability_post_update_if_unchanged(base_update());
check('a second call uses another savepoint name', ($GLOBALS['cinatra_test']['probe_names'][0] ?? $first_name) !== $first_name);
reset_fixture();
$wpdb->foreign_open = true;
$wpdb->savepoints = ['site_sp' => true];
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a foreign transaction ( with or without a write ) -> transaction_conflict ( open_transaction ), no START TRANSACTION, nothing written', is_refusal($r, 'transaction_conflict') && 'open_transaction' === $r['data']['cause'] && ['AUTOCOMMIT?', 'SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] === precheck_kinds() && [] === statement_kinds() && $before === $wpdb->rows);
check('the probe leaves no savepoint behind and keeps the foreign one', ['site_sp' => true] === $wpdb->savepoints);
reset_fixture();
$wpdb->foreign_open = true;
$wpdb->probe_errno = ['RELEASE SAVEPOINT' => 1305];
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('open, and RELEASE fails -> still transaction_conflict ( open_transaction ), no START TRANSACTION', is_refusal($r, 'transaction_conflict') && 'open_transaction' === $r['data']['cause'] && [] === statement_kinds());
$probe_failures = [
    'SAVEPOINT fails'                         => ['probe_errno' => ['SAVEPOINT' => 1178]],
    'ROLLBACK TO fails with another error'    => ['probe_errno' => ['ROLLBACK TO SAVEPOINT' => 2013]],
    'ROLLBACK TO fails with errno 0'          => ['probe_errno' => ['ROLLBACK TO SAVEPOINT' => 0]],
    'the connection reports no errno'         => ['dbh' => null],
];
foreach ($probe_failures as $label => $case) {
    reset_fixture();
    foreach ($case as $prop => $value) { $wpdb->$prop = $value; }
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    check("probe: $label -> unsupported_storage ( fail closed ), no START TRANSACTION, nothing written", is_refusal($r, 'unsupported_storage') && [] === statement_kinds() && !in_array('START TRANSACTION', precheck_kinds(), true) && $before === $wpdb->rows);
}
reset_fixture();
$wpdb->autocommit = '0';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('autocommit = 0 ( always in a transaction ) -> transaction_conflict ( autocommit_off ), before the probe', is_refusal($r, 'transaction_conflict') && 'autocommit_off' === $r['data']['cause'] && ['AUTOCOMMIT?'] === precheck_kinds() && [] === statement_kinds());
reset_fixture();
$wpdb->autocommit = null;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('@@autocommit unreadable -> unsupported_storage ( fail closed: the variable exists on every supported server ), no START TRANSACTION', is_refusal($r, 'unsupported_storage') && [] === statement_kinds());
reset_fixture();
$r1 = cinatra_ability_post_update_if_unchanged(base_update());
$r2 = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected([], T_ID), 'if_unmodified_since' => T_NOW, 'content' => '<p>Again</p>']));
check('after a completed save the tracker is closed: a second save on the request runs', true === ($r1['success'] ?? null) && false === cinatra_content_transaction()['open']);
reset_fixture();
add_action('save_post', function () { if (!empty($GLOBALS['cinatra_test']['nested_after_commit'])) { $GLOBALS['cinatra_test']['nested_after_commit'] = false; $GLOBALS['cinatra_test']['nested_result'] = cinatra_ability_post_update_if_unchanged(['post_id' => T_POST_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected([], T_POST_ID), 'title' => 'by a save_post callback']); } }, 10, 3);
$GLOBALS['cinatra_test']['nested_after_commit'] = true;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a call from an after-commit hook ( outer transaction closed ) is not nested and runs', true === ($r['success'] ?? null) && true === ($GLOBALS['cinatra_test']['nested_result']['success'] ?? null) && 'by a save_post callback' === $wpdb->rows[T_POST_ID]['post_title']);

echo "Rollback: every rollback evicts the post's cache through core\n";
// A guarded save callback updates the same post inside the transaction and
// caches the uncommitted row, then a refusal rolls the database back: the
// cached uncommitted row must not survive.
$poison = function () {
    global $wpdb;
    $wpdb->rows[T_ID]['post_content'] = 'UNCOMMITTED';
    $GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID];
};
$late_sites = [
    'empty_content ( wp_insert_post_empty_content )' => ['wp_insert_post_empty_content', function ($empty) use ($poison) { $poison(); return true; }, 'empty_content'],
    'status chain conflict ( status_save_pre )'      => ['status_save_pre', function ($status) use ($poison) { $poison(); return 'pending'; }, 'site_policy_conflict'],
    'wp_insert_post_data conflict'                   => ['wp_insert_post_data', function ($data) use ($poison) { $poison(); $data['post_name'] = 'moved'; return $data; }, 'site_policy_conflict'],
];
foreach ($late_sites as $label => [$hook, $cb, $code]) {
    reset_fixture();
    $GLOBALS['cinatra_test']['save_filters'][$hook] = [$cb];
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    $cached = get_post(T_ID);
    check("$label: refused, rolled back, the post's cache evicted ( no uncommitted row served )", false === ($r['success'] ?? null) && $code === ($r['code'] ?? null) && $before === $wpdb->rows && ['ROLLBACK', 'CLEAN-CACHE'] === array_slice(statement_kinds(), -2) && 'UNCOMMITTED' !== $cached->post_content);
}
$early_sites = [
    'unsupported_storage ( engine )' => [function () { global $wpdb; $wpdb->engine = 'MyISAM'; }, 'unsupported_storage'],
    'not_found ( denied )'           => [function () { $GLOBALS['cinatra_test']['current_user_id'] = 8; }, 'not_found'],
    'unsupported_status'             => [function () { global $wpdb; $wpdb->rows[T_ID]['post_status'] = 'pending'; t_state_is_read(); }, 'unsupported_status'],
    'post_locked'                    => [function () { $GLOBALS['cinatra_test']['post_lock'][T_ID] = 5; }, 'post_locked'],
    'post_changed'                   => [function () { global $wpdb; $wpdb->rows[T_ID]['post_name'] = 'other'; $GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID]; }, 'post_changed'],
];
foreach ($early_sites as $label => [$setup, $code]) {
    reset_fixture();
    $setup();
    $r = cinatra_ability_post_update_if_unchanged(base_update());
    check("$label after the lock: ROLLBACK, then the post's cache cleaned through core", ($r['code'] ?? null) === $code && ['ROLLBACK', 'CLEAN-CACHE'] === array_slice(statement_kinds(), -2) && in_array(T_ID, $GLOBALS['cinatra_test']['cleaned'], true));
}
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 999, 'expected' => full_expected([], 999)]));
check('not_found ( no row ) after the lock: ROLLBACK, then clean_post_cache( 999 )', is_refusal($r, 'not_found') && ['ROLLBACK', 'CLEAN-CACHE'] === array_slice(statement_kinds(), -2) && [999] === $GLOBALS['cinatra_test']['cleaned']);

echo "Rollback with a poisoned cache: suspended invalidation and a drop-in that ignores deletes\n";
// A wp_insert_post_data callback updates the post and caches the uncommitted
// row, then its own change forces a refusal: the database rolls back.
$poison_and_conflict = function ($data) use ($poison) { $poison(); $data['post_name'] = 'moved'; return $data; };
// ( a ) invalidation suspended ( wp_suspend_cache_invalidation( true ) ): the
// rollback lifts the suspension for its own clean only, then restores it.
reset_fixture();
$GLOBALS['_wp_suspend_cache_invalidation'] = true;
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'] = [$poison_and_conflict];
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('suspended: refused ( site_policy_conflict ), the database rolled back', 'site_policy_conflict' === ($r['code'] ?? null) && $before === $wpdb->rows);
check('suspended: the rolled-back row is not served afterwards ( the clean ran with the suspension lifted )', !isset($GLOBALS['cinatra_test']['post_cache'][T_ID]) || 'UNCOMMITTED' !== get_post(T_ID)->post_content);
check('suspended: the suspension is restored after the clean', true === $GLOBALS['_wp_suspend_cache_invalidation']);
check('suspended: the refusal reports no stale cache ( the entry was removed )', !array_key_exists('cache_stale', $r['data']) && 0 === strpos($r['message'], 'A site save policy'));
check('suspended: no cache write', [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []));
// The suspension is restored also when the clean throws.
reset_fixture();
$GLOBALS['_wp_suspend_cache_invalidation'] = true;
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'] = [$poison_and_conflict];
add_action('clean_post_cache', function () { if (!empty($GLOBALS['cinatra_test']['clean_throws'])) { throw new RuntimeException('clean failed'); } });
$GLOBALS['cinatra_test']['clean_throws'] = true;
$thrown = null;
try { cinatra_ability_post_update_if_unchanged(base_update()); } catch (RuntimeException $e) { $thrown = $e->getMessage(); }
$GLOBALS['cinatra_test']['clean_throws'] = false;
check('suspended, the clean throws: the exception propagates and the suspension is still restored', 'clean failed' === $thrown && true === $GLOBALS['_wp_suspend_cache_invalidation']);
// ( b ) a drop-in that ignores deletes: the entry stays and cannot be repaired
// without a cache write; the refusal says so truthfully.
reset_fixture(['clean_ignored' => true]);
$GLOBALS['cinatra_test']['save_filters']['wp_insert_post_data'] = [$poison_and_conflict];
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('ignored deletes: refused, the database rolled back', 'site_policy_conflict' === ($r['code'] ?? null) && $before === $wpdb->rows);
check('ignored deletes: the refusal carries data.cache_stale true and says that the cache still serves another copy', true === ($r['data']['cache_stale'] ?? null) && false !== strpos($r['message'], 'object cache still serves'));
check('ignored deletes: no cache write, the refusal carries no content', [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []) && false === strpos(json_encode($r), 'UNCOMMITTED') && false === strpos(json_encode($r), 'SECRET-BODY-MARKER'));
// The flag is truthful: a cache entry equal to the database is not stale.
reset_fixture(['clean_ignored' => true]);
$GLOBALS['cinatra_test']['post_lock'][T_ID] = 5;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('ignored deletes, no poison ( entry equals the row ): post_locked without cache_stale', 'post_locked' === ($r['code'] ?? null) && !array_key_exists('cache_stale', $r['data']));
// No existence oracle: a refusal decided before the permission is verified never carries the flag.
reset_fixture(['clean_ignored' => true]);
$GLOBALS['cinatra_test']['post_cache'][999] = t_row(999, 'page');
$r = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 999, 'expected' => full_expected([], 999)]));
check('not_found with a lingering cache entry: the same not_found answer, no cache_stale', is_refusal($r, 'not_found') && $r === cinatra_content_not_found());
check('every refusal data key the write can add is declared in the output schema', isset(cinatra_content_output_schema(true)['properties']['data']['properties']['cache_stale'], cinatra_content_output_schema(true)['properties']['data']['properties']['commit_unacknowledged']));

echo "After COMMIT: get_post() must return the committed row\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('invalidation accepted: success, data.cache_stale false', true === ($r['success'] ?? null) && false === ($r['data']['cache_stale'] ?? null));
reset_fixture(['clean_ignored' => true]); // a drop-in ( or wp_suspend_cache_invalidation ) ignores the invalidation
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('invalidation ignored: the save is committed and reported as success with data.cache_stale true', true === ($r['success'] ?? null) && true === ($r['data']['cache_stale'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content'] && '<p>New body</p>' === $r['post_content']);
$hook_posts = [];
foreach ([['transition_post_status', 2], ['edit_post', 1], ['post_updated', 1], ['save_post', 1], ['wp_insert_post', 1], ['wp_after_insert_post', 1]] as [$hook, $at]) {
    $args = hook_args($hook);
    $hook_posts[$hook] = is_array($args) && ($args[$at] ?? null) instanceof WP_Post ? $args[$at]->post_content : null;
}
check('every after-save hook receives the committed post, built from the committed row ( not the stale cached one )', ['<p>New body</p>'] === array_values(array_unique($hook_posts)));
check('the built post is like WP_Post::get_instance() builds it: every column equal to the committed row, filter raw, int ID', cinatra_content_post_matches_row(hook_args('post_updated')[1], $wpdb->rows[T_ID]) && 'raw' === hook_args('post_updated')[1]->filter && T_ID === hook_args('post_updated')[1]->ID);
check('post_updated still gets the post before the save as its third argument', T_CONTENT === hook_args('post_updated')[2]->post_content);
check('no cache write: the stale entry is left to the site ( reported, not overwritten )', [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []) && T_CONTENT === $GLOBALS['cinatra_test']['post_cache'][T_ID]['post_content']);

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
check('START TRANSACTION failure -> db_error, no select, no after-save hook', is_refusal($r, 'db_error') && ['START TRANSACTION'] === statement_kinds());
reset_fixture();
$wpdb->fail['COMMIT'] = true;
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failure -> db_error, rolled back, no after-commit hook', 'db_error' === $r['code'] && $before === $wpdb->rows && ['pre_post_update'] === hooks_fired());
check('the failed COMMIT was followed by a read of the row before answering db_error', 'SELECT' === (statement_kinds()[count(statement_kinds()) - 1] ?? null) && 1 === $wpdb->connection_checks);

echo "Unconfirmed COMMIT: the outcome is read, never assumed\n";
// ( 1 ) The server committed, the acknowledgement was lost: COMMIT returns false.
reset_fixture();
$wpdb->on_commit = function ($db) { $db->apply_commit(); return false; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('lost acknowledgement: the row is read back, holds the intended row -> success, data.commit_unacknowledged true', true === ($r['success'] ?? null) && true === ($r['data']['commit_unacknowledged'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content'] && '<p>New body</p>' === $r['post_content'] && T_NOW === $r['post_modified_gmt']);
check('lost acknowledgement: the after-save hooks run, in core\'s order, after the read', T_AFTER_COMMIT === array_values(array_filter(statement_kinds(), function ($k) { return 0 === strpos($k, 'HOOK:') && 'HOOK:pre_post_update' !== $k; })));
check('lost acknowledgement: post_updated gets the stored post and the post before the save', '<p>New body</p>' === hook_args('post_updated')[1]->post_content && T_CONTENT === hook_args('post_updated')[2]->post_content);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('an acknowledged COMMIT: data.commit_unacknowledged false', false === ($r['data']['commit_unacknowledged'] ?? null));
// ( 2 ) The server did not commit.
reset_fixture();
$wpdb->on_commit = function ($db) { $db->server_rollback(); return false; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failed and the read shows the row before the save -> db_error, nothing written, no after-save hook', is_late_refusal($r, 'db_error', $before) === false && 'db_error' === ($r['code'] ?? null) && $before === $wpdb->rows && ['pre_post_update'] === hooks_fired());
// ( 3 ) The connection is gone and cannot be re-established.
reset_fixture();
$wpdb->on_commit = function ($db) { $db->connection_ok = false; return false; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failed, no connection to read -> write_outcome_unknown ( cause unreadable ), no after-save hook, never "Nothing was written"', 'write_outcome_unknown' === ($r['code'] ?? null) && false === ($r['success'] ?? null) && 'unreadable' === ($r['data']['cause'] ?? null) && ['pre_post_update'] === hooks_fired() && false === strpos($r['message'], 'Nothing was written') && false === strpos(json_encode($r), 'SECRET-BODY-MARKER'));
// ( 4 ) The connection answers but the read fails.
reset_fixture();
$wpdb->on_commit = function ($db) { $db->apply_commit(); $db->select_fails = true; return false; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT unacknowledged, the read fails -> write_outcome_unknown ( unreadable ), although the row is stored', 'write_outcome_unknown' === ($r['code'] ?? null) && 'unreadable' === ($r['data']['cause'] ?? null) && ['pre_post_update'] === hooks_fired());
// ( 5 ) Another request changed the row after the transaction ended.
reset_fixture();
$wpdb->on_commit = function ($db) { $db->apply_commit(); $db->rows[T_ID]['post_title'] = 'by another request'; return false; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT unacknowledged, the row is neither the old nor the intended one -> write_outcome_unknown ( differs )', 'write_outcome_unknown' === ($r['code'] ?? null) && 'differs' === ($r['data']['cause'] ?? null) && ['pre_post_update'] === hooks_fired());
// ( 6 ) wpdb reconnected during COMMIT ( error 2006 ): the server rolled the
// transaction back, and the repeated COMMIT on the new connection "succeeds".
reset_fixture();
$wpdb->on_commit = function ($db) { $db->lose_connection(); return 0; };
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT "succeeds" on a new connection: not taken as a commit; the read shows the old row -> db_error, no after-save hook', 'db_error' === ($r['code'] ?? null) && $before === $wpdb->rows && ['pre_post_update'] === hooks_fired());
echo "Transaction lost before the write: refused, nothing written, another save survives\n";
// ( 7 ) A pre_post_update callback ends the transaction ( COMMIT ); then
// another request saves new content ( the row is no longer locked ).
$other_save = function () {
    global $wpdb;
    $wpdb->rows[T_ID]['post_content'] = 'saved by another request';
    $wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:04:00';
};
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () use ($other_save) { global $wpdb; $wpdb->query('COMMIT'); $other_save(); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('callback COMMIT, then another save -> transaction_conflict ( transaction_lost ), no UPDATE, the other save\'s content intact', 'transaction_conflict' === ($r['code'] ?? null) && 'transaction_lost' === ($r['data']['cause'] ?? null) && [] === $wpdb->updates && 'saved by another request' === $wpdb->rows[T_ID]['post_content'] && ['pre_post_update'] === hooks_fired());
// The same when the callback opens a new transaction afterwards ( a generic
// "is a transaction open" probe would say yes; this save's savepoint is gone ).
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () use ($other_save) { global $wpdb; $wpdb->query('COMMIT'); $other_save(); $wpdb->query('START TRANSACTION'); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('callback COMMIT + START TRANSACTION, another save in between -> transaction_conflict ( transaction_lost ), nothing written', 'transaction_conflict' === ($r['code'] ?? null) && 'transaction_lost' === ($r['data']['cause'] ?? null) && [] === $wpdb->updates && 'saved by another request' === $wpdb->rows[T_ID]['post_content']);
check('the check is RELEASE SAVEPOINT of the savepoint set right after the lock ( cinatra_lock_ + 16 hex ), errors suppressed', 2 === count($GLOBALS['cinatra_test']['lock_savepoint'] ?? []) && 'SAVEPOINT' === $GLOBALS['cinatra_test']['lock_savepoint'][0][0] && 'RELEASE SAVEPOINT' === $GLOBALS['cinatra_test']['lock_savepoint'][1][0] && 1 === preg_match('/^cinatra_lock_[0-9a-f]{16}$/', $GLOBALS['cinatra_test']['lock_savepoint'][0][1]) && $GLOBALS['cinatra_test']['lock_savepoint'][0][1] === $GLOBALS['cinatra_test']['lock_savepoint'][1][1] && true === $GLOBALS['cinatra_test']['lock_savepoint'][1][2]);
// ( 8 ) wpdb reconnects after the lock; another request saves in between.
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () use ($other_save) { global $wpdb; $wpdb->lose_connection(); $other_save(); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('reconnect after the lock, another save in between -> transaction_conflict ( connection_replaced ), no UPDATE, the other save intact', 'transaction_conflict' === ($r['code'] ?? null) && 'connection_replaced' === ($r['data']['cause'] ?? null) && [] === $wpdb->updates && 'saved by another request' === $wpdb->rows[T_ID]['post_content']);
reset_fixture();
$GLOBALS['cinatra_test']['pre_post_update_cbs'][] = function () { global $wpdb; $wpdb->lose_connection(); $wpdb->rows[T_ID]['post_name'] = 'by-a-callback'; };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('reconnect after the lock, the callback\'s own write -> transaction_conflict, this save wrote nothing', 'transaction_conflict' === ($r['code'] ?? null) && [] === $wpdb->updates && T_CONTENT === $wpdb->rows[T_ID]['post_content']);
// ( 9 ) wpdb reconnects DURING the UPDATE and repeats it with autocommit;
// another request saved in between: the row condition does not match.
reset_fixture();
$wpdb->before_update = function ($db) use ($other_save) { $db->before_update = null; $db->lose_connection(); $other_save(); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('reconnect during the UPDATE, another save in between -> zero rows, post_changed, the other save intact, no after-save hook', 'post_changed' === ($r['code'] ?? null) && in_array('post_content', $r['data']['changed'] ?? [], true) && 'saved by another request' === $wpdb->rows[T_ID]['post_content'] && ['pre_post_update'] === hooks_fired());
reset_fixture();
$wpdb->before_update = function ($db) { $db->before_update = null; $db->lose_connection(); };
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('reconnect during the UPDATE, no other save -> the condition matches the locked row; settled by the read: success, data.commit_unacknowledged true', true === ($r['success'] ?? null) && true === ($r['data']['commit_unacknowledged'] ?? null) && '<p>New body</p>' === $wpdb->rows[T_ID]['post_content']);

echo "The row condition: every column, byte-exact, NULL-safe\n";
reset_fixture();
$wpdb->rows[T_ID]['post_password'] = null;
t_state_is_read();
$r = cinatra_ability_post_update_if_unchanged(base_update());
$cond = $wpdb->conditions[0] ?? [];
$want = [];
foreach ($wpdb->rows[T_ID] as $col => $v) { $want[$col] = null; }
foreach (t_row(T_ID, 'page', ['post_password' => null]) as $col => $v) { $want[$col] = null === $v ? null : hash('sha256', (string) $v); }
check('the UPDATE carries one condition per column of the locked row: SHA-256 of the value as read, IS NULL for NULL', true === ($r['success'] ?? null) && $want === $cond);
check('the hash is computed by the server over the value in the connection character set ( SHA2(CONVERT(`col` USING utf8mb4), 256) ), WHERE `ID` first', false !== strpos($wpdb->update_sql[0] ?? '', "WHERE `ID` = 42 AND SHA2(CONVERT(`ID` USING utf8mb4), 256) = '") && false !== strpos($wpdb->update_sql[0], '`post_password` IS NULL'));
check('the identical-content write ( zero changed rows, the row is this save\'s row ) still answers success', (function () { global $wpdb; reset_fixture(['now' => T_MODIFIED, 'now_local' => '2026-11-25 11:00:00']); $r = cinatra_ability_post_update_if_unchanged(base_update(['content' => T_CONTENT])); return true === ($r['success'] ?? null) && t_row(T_ID, 'page') === $wpdb->rows[T_ID]; })());
check('write_outcome_unknown and its causes are declared in the output schema', in_array('unreadable', cinatra_content_output_schema(true)['properties']['data']['properties']['cause']['enum'], true) && in_array('differs', cinatra_content_output_schema(true)['properties']['data']['properties']['cause']['enum'], true));

// ---------------------------------------------------------------------------
echo "Storage: the engine is read fresh inside the transaction on every write\n";
reset_fixture();
$wpdb->engine = 'MyISAM';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('non-InnoDB -> unsupported_storage after the lock, ROLLBACK', is_refusal($r, 'unsupported_storage') && ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK', 'CLEAN-CACHE'] === statement_kinds() && $before === $wpdb->rows);
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
// ---------------------------------------------------------------------------
echo "Permission: provably decided on the fresh row, no cache writes\n";
// The object cache holds the row with author 3 ( allowed ); the fresh row says author 9.
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('stale allowing cache entry: core invalidation + reload decides on the fresh row -> not_found, no content', is_refusal($r, 'not_found') && !isset($r['post_content']));
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('stale allowing cache entry, write: decided on the fresh locked row -> not_found, nothing written', is_refusal($r, 'not_found') && $before === $wpdb->rows);
reset_fixture(['clean_ignored' => true]);
$wpdb->rows[T_ID]['post_author'] = '9';
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a drop-in that ignores invalidation ( stale allowing entry stays ): read -> permission_unverified, no content', is_refusal($r, 'permission_unverified') && !isset($r['post_content']) && false === strpos(json_encode($r), 'Old excerpt'));
check('the read retried once from a fresh SELECT before refusing', 2 === count(array_filter(statement_kinds(), function ($k) { return 'SELECT' === $k; })));
reset_fixture(['clean_ignored' => true]);
$wpdb->rows[T_ID]['post_author'] = '9';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('the same for the write: permission_unverified at once ( row locked ), nothing written, no after-save hook', is_refusal($r, 'permission_unverified') && $before === $wpdb->rows && 1 === count(array_filter(statement_kinds(), function ($k) { return 'SELECT-FOR-UPDATE' === $k; })));
// Whole-row rule: any column of the object get_post() returns must equal the fresh row.
foreach (['post_name' => 'old-slug', 'post_content' => 'older cached content', 'post_author' => '3', 'post_type' => 'post', 'post_status' => 'draft', 'post_parent' => '5', 'menu_order' => '4', 'guid' => 'x'] as $col => $stale) {
    reset_fixture(['clean_ignored' => true]);
    if ('post_author' === $col) {
        $wpdb->rows[T_ID]['post_author'] = '9'; // fresh: someone else's post; the stale entry ( 3 ) would allow
    }
    $GLOBALS['cinatra_test']['post_cache'][T_ID][$col] = $stale;
    $r = cinatra_ability_post_read(['post_id' => T_ID]);
    check("a cached $col that differs from the fresh row ( not invalidated ) -> permission_unverified", is_refusal($r, 'permission_unverified') && !isset($r['post_content']));
}
reset_fixture();
$GLOBALS['cinatra_test']['get_post_rewrite'] = function ($p) { $p->post_name = 'policy-sees-this'; return $p; };
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a get_post rewrite of a non-capability field ( slug ) -> permission_unverified', is_refusal($r, 'permission_unverified'));
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('the same for the write, nothing written', is_refusal($r, 'permission_unverified') && $before === $wpdb->rows);
// The decision is bracketed by the whole-row comparison before and after.
$decide_by_get_post = function ($post_id) { $p = get_post($post_id); return null !== $p && (int) $GLOBALS['cinatra_test']['current_user_id'] === (int) $p->post_author; };
reset_fixture(['clean_ignored' => true]);
$wpdb->rows[T_ID]['post_author'] = '9';
$GLOBALS['cinatra_test']['early_done'] = false;
$GLOBALS['cinatra_test']['caps_fn'] = function ($cap, ...$args) use ($decide_by_get_post) {
    if ('edit_post' !== $cap) { return 'unfiltered_html' === $cap; }
    $allowed = $decide_by_get_post((int) $args[0]);
    unset($GLOBALS['cinatra_test']['post_cache'][(int) $args[0]]); // evicted after the decision
    return $allowed;
};
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a stale entry that decides and is then evicted -> permission_unverified ( the comparison before the decision )', is_refusal($r, 'permission_unverified') && !isset($r['post_content']));
reset_fixture();
$wpdb->rows[T_ID]['post_author'] = '9';
$GLOBALS['cinatra_test']['post_cache'][T_ID]['post_author'] = '9';
$GLOBALS['cinatra_test']['early_done'] = false;
$GLOBALS['cinatra_test']['caps_fn'] = function ($cap, ...$args) use ($decide_by_get_post) {
    if ('edit_post' !== $cap) { return 'unfiltered_html' === $cap; }
    $GLOBALS['cinatra_test']['post_cache'][(int) $args[0]]['post_author'] = '3'; // stale, allowing
    return $decide_by_get_post((int) $args[0]);
};
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('a stale row put into the cache during the decision -> permission_unverified ( the comparison after the decision )', is_refusal($r, 'permission_unverified') && !isset($r['post_content']));

echo "Two requests: B commits a newer row between A's SELECT and A's permission check\n";
// The strongest form this single-process harness allows: one shared cache,
// request B interleaved by a hook right after A's SELECT. B commits and cleans
// the cache as core does after its update. A must never write the posts
// cache, and must decide on and return a row that is current.
reset_fixture();
$cache_history = [];
$b_done = false;
$GLOBALS['cinatra_test']['after_select'] = function ($sql) use (&$b_done) {
    global $wpdb;
    if ($b_done) { return; }
    $b_done = true;
    $wpdb->rows[T_ID]['post_content'] = 'committed by B';
    $wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:05:00';
    clean_post_cache(T_ID);
};
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('A issued no cache write of its own ( spy )', [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []));
check('A did not return or decide on its older row: it retried and returned B\'s committed row', true === ($r['success'] ?? null) && 'committed by B' === $r['post_content']);
check('the cache never holds A\'s older row afterwards', 'committed by B' === ($GLOBALS['cinatra_test']['post_cache'][T_ID]['post_content'] ?? 'committed by B'));
reset_fixture();
$r1 = cinatra_ability_post_read(['post_id' => T_ID]);
$r2 = cinatra_ability_post_update_if_unchanged(base_update());
check('normal case: read and write succeed with no cache write and no invalidation before the save', true === ($r1['success'] ?? null) && true === ($r2['success'] ?? null) && [] === ($GLOBALS['cinatra_test']['cache_writes'] ?? []));

echo "Read: raw values, fresh capability\n";
reset_fixture();
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('success with raw content (backslashes intact)', true === $r['success'] && T_CONTENT === $r['post_content']);
check('raw title, excerpt, status, type, modified, permalink', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt'] && 'publish' === $r['post_status'] && 'page' === $r['post_type'] && T_MODIFIED === $r['post_modified_gmt'] && 'https://blog.example/about/' === $r['permalink']);
check('exactly one plain select, no lock, no transaction, no save hook', ['SELECT'] === statement_kinds() && [] === hooks_fired());
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
check('both use the post-free pre-check as permission callback and their execute callbacks', 'cinatra_content_precheck' === $read['permission_callback'] && 'cinatra_content_precheck' === $write['permission_callback'] && 'cinatra_ability_post_read' === $read['execute_callback'] && 'cinatra_ability_post_update_if_unchanged' === $write['execute_callback']);
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
