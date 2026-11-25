<?php
/**
 * Standalone behavior tests for the content abilities cinatra/post-read and
 * cinatra/post-update-if-unchanged (cinatra-ai/cinatra#3745). Runs under plain
 * `php tests/test-post-update-if-unchanged.php` -- no PHPUnit, no WordPress
 * install, no database. Exit code 0 = all pass, 1 = a failure.
 *
 * A recording $wpdb stub keeps every statement in order and simulates the
 * transaction (ROLLBACK restores the rows as they were at START TRANSACTION),
 * and a wp_update_post() stub behaves like WordPress where it matters here: it
 * UNSLASHES its input (so a missing wp_slash() loses backslashes) and runs a
 * kses-like filter for a user without unfiltered_html.
 *
 * Covers:
 *   - the statement order: START TRANSACTION, SELECT ... FOR UPDATE, (compare),
 *     the update, the read-back, COMMIT; ROLLBACK on every refusal, and no
 *     update statement on any refusal;
 *   - each refusal's shape (success false, code, data.current_modified_gmt)
 *     and that no refusal carries post content;
 *   - the capability checks and the uniform not_found for unknown / forbidden /
 *     other-type posts (no existence leak);
 *   - the non-InnoDB refusal, with the engine read fresh inside the
 *     transaction on every write (never cached);
 *   - expected.status always required and compared under the lock;
 *   - a stale object-cache copy is never written back: the stub merges like
 *     core's wp_update_post() (get_post() from the cache, then array_merge),
 *     and the ability passes the full locked row and cleans the cache;
 *   - slashing with backslashes and quotes;
 *   - expected holds title, content, excerpt and status ALWAYS, all compared;
 *   - only publish and draft are written (unsupported_status otherwise); the
 *     only status change is to draft;
 *   - an untouched column changed by the save (core re-filters every content
 *     field; another plugin hook) rolls back as untouched_field_changed;
 *     the floating draft date is reported in also_changed;
 *   - the read (raw values, no lock, no transaction) and the registration.
 */

require __DIR__ . '/wp-stubs.php';

// ---------------------------------------------------------------------------
// Test-local stubs (function_exists-guarded so a shared load is harmless).
// ---------------------------------------------------------------------------
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/** Recording, transaction-simulating stand-in for WordPress's $wpdb. */
class Cinatra_Test_Wpdb {
    public $posts = 'wp_posts';
    public $log = [];
    public $rows = [];
    public $engine = 'InnoDB';
    public $fail = [];  // statement keyword => true to make it return false
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
        $row = $this->rows[(int) $m[1]] ?? null;
        return null === $row ? null : $row;
    }
    public function get_var($sql) {
        $this->log[] = $sql;
        return $this->engine;
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
if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post_id) {
        global $wpdb;
        $GLOBALS['cinatra_test']['cleaned'][] = (int) $post_id;
        unset($GLOBALS['cinatra_test']['post_cache'][(int) $post_id]);
        $wpdb->log[] = 'CLEAN-CACHE';
    }
}
if (!function_exists('wp_update_post')) {
    /**
     * Models WordPress core's wp_update_post() where it matters here:
     *   1. $post = get_post( ID, ARRAY_A ) -- served from the OBJECT CACHE when
     *      the post is cached (it may be stale), else read from the database
     *      and added to the cache;
     *   2. $post = wp_slash( $post ); $postarr = array_merge( $post, $postarr );
     *   3. wp_insert_post() unslashes and writes EVERY column of the merged
     *      array, running a kses stand-in for a user without unfiltered_html,
     *      and sets post_modified_gmt to "now".
     * So a partial update merged with a stale cached copy writes stale values
     * back -- exactly the loss the ability must prevent.
     */
    function wp_update_post($postarr, $wp_error = false) {
        global $wpdb;
        $GLOBALS['cinatra_test']['update_calls'][] = $postarr;
        $wpdb->log[] = 'UPDATE-VIA-wp_update_post';
        $id = (int) $postarr['ID'];
        if (isset($GLOBALS['cinatra_test']['update_result'])) {
            // Simulate a partial write before the failure, so the test proves
            // ROLLBACK undoes it.
            $wpdb->rows[$id]['post_title'] = 'PARTIAL-WRITE';
            return $GLOBALS['cinatra_test']['update_result'];
        }
        if (!isset($GLOBALS['cinatra_test']['post_cache'][$id])) {
            $GLOBALS['cinatra_test']['post_cache'][$id] = $wpdb->rows[$id];
        }
        $merged = array_merge(wp_slash($GLOBALS['cinatra_test']['post_cache'][$id]), $postarr);
        // Core: a draft whose date was never set ("floating", post_date_gmt
        // zero) gets post_date = now on every save.
        if ('draft' === ($merged['post_status'] ?? '') && '0000-00-00 00:00:00' === ($merged['post_date_gmt'] ?? '')) {
            $merged['post_date'] = $GLOBALS['cinatra_test']['now'];
        }
        // A hook of another plugin that rewrites a column (test switch).
        if (isset($GLOBALS['cinatra_test']['hook_rewrites'])) {
            $merged = array_merge($merged, $GLOBALS['cinatra_test']['hook_rewrites']);
        }
        foreach (array_keys($wpdb->rows[$id]) as $col) {
            if ('ID' === $col || 'post_modified_gmt' === $col || !array_key_exists($col, $merged)) {
                continue;
            }
            $value = wp_unslash($merged[$col]);
            if (in_array($col, ['post_title', 'post_content', 'post_excerpt'], true) && !current_user_can('unfiltered_html')) {
                $value = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value);
            }
            $wpdb->rows[$id][$col] = $value;
        }
        $wpdb->rows[$id]['post_modified_gmt'] = $GLOBALS['cinatra_test']['now'];
        unset($GLOBALS['cinatra_test']['post_cache'][$id]); // wp_insert_post() cleans the cache.
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

const T_ID        = 42;
const T_MODIFIED  = '2026-11-25 10:00:00';
const T_NOW       = '2026-11-25 10:05:00';
// Content with backslashes, both quote kinds and a marker the refusal tests
// search for (a refusal must never echo the post's content).
const T_CONTENT   = "<p>Path C:\\new\\table \"double\" 'single' \\\\ two backslashes SECRET-BODY-MARKER</p>";

/** Fresh fixture: one editable page, InnoDB, the user may edit post 42 only. */
function reset_fixture(array $overrides = []) {
    global $wpdb;
    $wpdb = new Cinatra_Test_Wpdb();
    $wpdb->rows = [
        T_ID => [
            'ID'                => (string) T_ID,
            'post_type'         => 'page',
            'post_status'       => 'publish',
            'post_title'        => 'About "us"',
            'post_content'      => T_CONTENT,
            'post_excerpt'      => 'Old excerpt',
            'post_modified_gmt' => T_MODIFIED,
            'post_name'         => 'about',
            'post_author'       => '3',
            'post_date_gmt'     => '2026-01-01 09:00:00',
            'post_date'         => '2026-01-01 10:00:00',
        ],
        7 => [
            'ID'                => '7',
            'post_type'         => 'attachment',
            'post_status'       => 'inherit',
            'post_title'        => 'logo',
            'post_content'      => '',
            'post_excerpt'      => '',
            'post_modified_gmt' => T_MODIFIED,
            'post_name'         => 'logo',
            'post_author'       => '3',
            'post_date_gmt'     => '2026-01-01 09:00:00',
            'post_date'         => '2026-01-01 10:00:00',
        ],
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
        'now'              => T_NOW,
        // The object cache starts consistent with the database.
        'post_cache'       => [T_ID => $wpdb->rows[T_ID], 7 => $wpdb->rows[7]],
        // Per-object capability: edit_post on 42 and 7 (an attachment the
        // user may edit, to prove the type check), unfiltered_html granted.
        'caps_fn'          => function ($cap, ...$args) {
            if ('edit_post' === $cap) {
                return in_array((int) ($args[0] ?? 0), [T_ID, 7], true);
            }
            if ('unfiltered_html' === $cap) {
                return $GLOBALS['cinatra_test']['unfiltered_html'] ?? true;
            }
            return false;
        },
    ], $overrides);
}

/** The statements, with SELECTs reduced to a tag (FOR UPDATE or plain). */
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

/** The before-values of the whole reviewed post (the fixture page as read). */
function full_expected(array $over = []): array {
    return array_merge([
        'title'   => 'About "us"',
        'content' => T_CONTENT,
        'excerpt' => 'Old excerpt',
        'status'  => 'publish',
    ], $over);
}

function base_update(array $extra = []): array {
    return array_merge([
        'post_id'             => T_ID,
        'if_unmodified_since' => T_MODIFIED,
        'expected'            => full_expected(),
        'content'             => '<p>New body</p>',
    ], $extra);
}

/** A refusal is well-formed and carries no post content. */
function is_refusal($r, string $code): bool {
    return is_array($r)
        && false === ($r['success'] ?? null)
        && $code === ($r['code'] ?? null)
        && is_string($r['message'] ?? null)
        && is_array($r['data'] ?? null)
        && array_key_exists('current_modified_gmt', $r['data'])
        && false === strpos(json_encode($r), 'SECRET-BODY-MARKER');
}

$retired_ids = [
    'cinatra/post-get', 'cinatra/post-update', 'cinatra/post-status', 'cinatra/posts-list',
    'cinatra/post-delete', 'cinatra/post-create-draft', 'cinatra/media-upload', 'cinatra/post-update-meta',
];

// ---------------------------------------------------------------------------
echo "Success: statement order and answer\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('success is true', true === ($r['success'] ?? null));
check('statement order is START, SELECT FOR UPDATE, engine (under the lock), cache clean, update, read-back, COMMIT, cache clean',
    ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'CLEAN-CACHE', 'UPDATE-VIA-wp_update_post', 'SELECT', 'COMMIT', 'CLEAN-CACHE'] === statement_kinds());
check('the FOR UPDATE select reads the whole row of the posts table by ID', 1 === preg_match('/^SELECT \\* FROM wp_posts WHERE ID = 42 FOR UPDATE$/', $wpdb->log[1]));
check('answer returns the stored content', '<p>New body</p>' === $r['post_content']);
check('answer returns the new post_modified_gmt', T_NOW === $r['post_modified_gmt']);
check('answer returns unchanged fields raw', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt']);
check('answer carries the permalink, type, status and id', 'https://blog.example/about/' === $r['permalink'] && 'page' === $r['post_type'] && 'publish' === $r['post_status'] && T_ID === $r['post_id']);
check('nothing was changed by filters', [] === $r['sanitized_fields']);
check('wp_update_post gets the full locked row (every column), the written field replaced', array_keys($wpdb->rows[T_ID]) == array_keys($GLOBALS['cinatra_test']['update_calls'][0]) && wp_slash('<p>New body</p>') === $GLOBALS['cinatra_test']['update_calls'][0]['post_content'] && wp_slash('About "us"') === $GLOBALS['cinatra_test']['update_calls'][0]['post_title']);
check('the status passed is the locked row status when status is not written', 'publish' === $GLOBALS['cinatra_test']['update_calls'][0]['post_status']);
check('the row is committed', '<p>New body</p>' === $wpdb->rows[T_ID]['post_content']);

echo "Engine: read fresh for every write, never cached\n";
check('no engine transient is stored', [] === $GLOBALS['cinatra_test']['transients']);
$wpdb->log = [];
$wpdb->rows[T_ID]['post_modified_gmt'] = T_MODIFIED;
$wpdb->rows[T_ID]['post_content']      = T_CONTENT;
$wpdb->engine = 'MyISAM'; // e.g. a restore changed the table after the first write
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a second write reads the engine again and sees the change', in_array('ENGINE', statement_kinds(), true) && is_refusal($r, 'unsupported_storage'));
reset_fixture(['transients' => ['cinatra_posts_table_engine' => 'InnoDB']]);
$wpdb->engine = 'MyISAM';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a leftover positive engine transient is not trusted', is_refusal($r, 'unsupported_storage'));

// ---------------------------------------------------------------------------
echo "Refusal: post_changed (modified differs)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:03:00';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_changed, well-formed, no content echoed', is_refusal($r, 'post_changed'));
check('data.current_modified_gmt is the current value', '2026-11-25 10:03:00' === $r['data']['current_modified_gmt']);
check('data.changed names post_modified_gmt only', ['post_modified_gmt'] === $r['data']['changed']);
check('statements: START, FOR UPDATE, ROLLBACK (no update)', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());
check('wp_update_post was not called', [] === $GLOBALS['cinatra_test']['update_calls']);
check('nothing written', $before === $wpdb->rows);

echo "Refusal: post_changed (same second, field differs)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_content'] = T_CONTENT . ' edited';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_changed', is_refusal($r, 'post_changed'));
check('data.changed names post_content only', ['post_content'] === $r['data']['changed']);
check('ROLLBACK and no update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());

echo "Refusal: post_changed (byte-level difference, trailing space)\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['content' => T_CONTENT . ' '])]));
check('a one-byte difference refuses', is_refusal($r, 'post_changed') && ['post_content'] === $r['data']['changed']);

echo "Refusal: post_locked\n";
reset_fixture(['post_lock' => [T_ID => 9]]);
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_locked, well-formed', is_refusal($r, 'post_locked'));
check('data.current_modified_gmt is given', T_MODIFIED === $r['data']['current_modified_gmt']);
check('ROLLBACK and no update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());
check('nothing written', $before === $wpdb->rows);

// ---------------------------------------------------------------------------
echo "Capability: unknown, forbidden and other-type posts look the same\n";
reset_fixture();
$unknown = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 999]));
$unknown_log = statement_kinds();
$wpdb->rows[5] = $wpdb->rows[T_ID];
$wpdb->rows[5]['ID'] = '5';
$wpdb->log = [];
$forbidden = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 5]));
$forbidden_log = statement_kinds();
$wpdb->log = [];
$other_type = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 7, 'expected' => ['title' => 'logo', 'content' => '', 'excerpt' => '', 'status' => 'inherit'], 'content' => 'x']));
check('unknown post -> not_found', is_refusal($unknown, 'not_found'));
check('forbidden post -> not_found', is_refusal($forbidden, 'not_found'));
check('attachment (not post/page) -> not_found', is_refusal($other_type, 'not_found'));
check('unknown and forbidden answers are identical', $unknown === $forbidden);
check('other-type answer is identical too', $unknown === $other_type);
check('not_found carries no current_modified_gmt', null === $unknown['data']['current_modified_gmt']);
check('unknown and forbidden touch no database statement', [] === $unknown_log && [] === $forbidden_log);
check('other-type rolls back after the locked select', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());

echo "Capability: the permission callback is per post\n";
reset_fixture();
check('edit_post on 42 -> allowed', true === cinatra_content_can_edit(['post_id' => T_ID]));
check('no edit_post on 5 -> refused', false === cinatra_content_can_edit(['post_id' => 5]));
check('missing post_id -> refused', false === cinatra_content_can_edit([]));
check('zero / negative / non-numeric -> refused', false === cinatra_content_can_edit(['post_id' => 0]) && false === cinatra_content_can_edit(['post_id' => -42]) && false === cinatra_content_can_edit(['post_id' => '42abc']));
check('numeric string id is accepted', true === cinatra_content_can_edit(['post_id' => '42']));
$seen = [];
$GLOBALS['cinatra_test']['caps_fn'] = function ($cap, ...$args) use (&$seen) { $seen[] = [$cap, $args]; return true; };
cinatra_ability_post_update_if_unchanged(base_update());
check('the write asks edit_post for this post id', in_array(['edit_post', [T_ID]], $seen, true));
check('the write never asks publish_posts', !in_array('publish_posts', array_column($seen, 0), true));

// ---------------------------------------------------------------------------
echo "Storage: non-InnoDB refuses, checked under the lock, nothing written\n";
reset_fixture();
$wpdb->engine = 'MyISAM';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code unsupported_storage', is_refusal($r, 'unsupported_storage'));
check('engine read under the lock, then ROLLBACK; no update', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds());
check('nothing written', $before === $wpdb->rows);
reset_fixture();
$wpdb->engine = null;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('unreadable engine also refuses', is_refusal($r, 'unsupported_storage'));
reset_fixture();
$wpdb->engine = 'innodb';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('engine name compared case-insensitively', true === $r['success']);

// ---------------------------------------------------------------------------
echo "Slashing: backslashes and quotes survive byte for byte\n";
reset_fixture();
$new_content = "Line \\n not a newline, C:\\temp\\x, \"dq\", 'sq', \\\\ and a trailing backslash \\";
$new_title   = 'Title with \\ and "quotes" and \'single\'';
$r = cinatra_ability_post_update_if_unchanged([
    'post_id'             => T_ID,
    'if_unmodified_since' => T_MODIFIED,
    'expected'            => full_expected(),
    'content'             => $new_content,
    'title'               => $new_title,
]);
check('success', true === ($r['success'] ?? null));
check('stored content equals the sent content exactly', $new_content === $wpdb->rows[T_ID]['post_content']);
check('stored title equals the sent title exactly', $new_title === $wpdb->rows[T_ID]['post_title']);
check('the answer returns the same bytes', $new_content === $r['post_content'] && $new_title === $r['post_title']);
check('wp_update_post received slashed values', addslashes($new_content) === $GLOBALS['cinatra_test']['update_calls'][0]['post_content']);
check('the unchanged excerpt from the locked row is slashed too', 'Old excerpt' === $wpdb->rows[T_ID]['post_excerpt'] && T_CONTENT !== $wpdb->rows[T_ID]['post_content']);
check('no field reported as changed by filters', [] === $r['sanitized_fields']);
check('an expected value with backslashes matched the stored raw value', true === $r['success']);

echo "Sanitization: kses for a user without unfiltered_html; the answer returns what is stored\n";
reset_fixture(['unfiltered_html' => false]);
$r = cinatra_ability_post_update_if_unchanged(base_update(['content' => '<p>ok</p><script>alert(1)</script>']));
check('success', true === ($r['success'] ?? null));
check('stored value is the filtered one', '<p>ok</p>' === $r['post_content']);
check('sanitized_fields names post_content', ['post_content'] === $r['sanitized_fields']);

// ---------------------------------------------------------------------------
echo "Pairing: written fields and expected entries must match\n";
$pairing_cases = [
    'expected.title missing'            => base_update(['expected' => array_diff_key(full_expected(), ['title' => 1])]),
    'expected.content missing (written)' => base_update(['expected' => array_diff_key(full_expected(), ['content' => 1])]),
    'expected.excerpt missing'          => base_update(['expected' => array_diff_key(full_expected(), ['excerpt' => 1])]),
    'expected.status missing'           => base_update(['expected' => array_diff_key(full_expected(), ['status' => 1])]),
    'expected.status missing (status written)' => base_update(['status' => 'draft', 'expected' => array_diff_key(full_expected(), ['status' => 1])]),
    'status-only with empty expected'   => ['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => [], 'status' => 'draft'],
    'expected names an unknown field'   => base_update(['expected' => full_expected(['slug' => 'x'])]),
    'non-string written value'          => base_update(['content' => ['x']]),
    'non-string expected value'         => base_update(['expected' => full_expected(['excerpt' => null])]),
    'non-string expected.status'        => base_update(['expected' => full_expected(['status' => 1])]),
    'empty expected.status'             => base_update(['expected' => full_expected(['status' => ''])]),
    'expected is not an object'         => base_update(['expected' => 'x']),
    'nothing written'                   => ['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected()],
    'unknown top-level field'           => base_update(['post_date' => '2020-01-01 00:00:00']),
    'missing if_unmodified_since'       => array_diff_key(base_update(), ['if_unmodified_since' => 1]),
    'malformed if_unmodified_since'     => base_update(['if_unmodified_since' => '2026-11-25T10:00:00Z']),
    'invalid post_id'                   => base_update(['post_id' => 0]),
    'input is not an object'            => 'x',
];
foreach ($pairing_cases as $label => $input) {
    reset_fixture();
    $r = cinatra_ability_post_update_if_unchanged($input);
    check("$label -> invalid", is_refusal($r, 'invalid'));
    check("$label -> no database statement", [] === statement_kinds());
}

// ---------------------------------------------------------------------------
echo "Status: only draft\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['status' => 'draft']));
check('status draft with a field -> success, row is draft', true === $r['success'] && 'draft' === $wpdb->rows[T_ID]['post_status'] && 'draft' === $r['post_status']);
check('wp_update_post received post_status draft', 'draft' === $GLOBALS['cinatra_test']['update_calls'][0]['post_status']);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected(), 'status' => 'draft']);
check('status-only draft with expected.status -> success', true === $r['success'] && 'draft' === $wpdb->rows[T_ID]['post_status']);
check('status-only write keeps the other fields from the locked row', T_CONTENT === $wpdb->rows[T_ID]['post_content'] && 'About "us"' === $wpdb->rows[T_ID]['post_title']);

echo "Status: compared under the lock (same second, status changed by someone else)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft'; // unpublished by someone in the same post_modified_gmt second
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected(), 'status' => 'draft']);
check('status-only write refuses post_changed naming post_status', is_refusal($r, 'post_changed') && ['post_status'] === $r['data']['changed']);
check('nothing written', $before === $wpdb->rows);
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a content write also refuses when the status differs from expected.status', is_refusal($r, 'post_changed') && ['post_status'] === $r['data']['changed']);

echo "Cache: a stale object-cache copy is never written back\n";
reset_fixture();
$wpdb->rows[T_ID]['post_title'] = 'Title B (saved by someone, in the DB)';
$GLOBALS['cinatra_test']['post_cache'][T_ID]['post_title'] = 'Title A (stale cache)';
$GLOBALS['cinatra_test']['post_cache'][T_ID]['post_name']  = 'stale-slug';
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['title' => 'Title B (saved by someone, in the DB)'])]));
check('content-only write succeeds', true === ($r['success'] ?? null));
check('the DB title B survives (not the stale cached A)', 'Title B (saved by someone, in the DB)' === $wpdb->rows[T_ID]['post_title']);
check('the DB slug survives (not the stale cached slug)', 'about' === $wpdb->rows[T_ID]['post_name']);
check('the answer reports title B', 'Title B (saved by someone, in the DB)' === $r['post_title']);
$kinds = statement_kinds();
check('the cache is cleaned after the lock and before wp_update_post', array_search('CLEAN-CACHE', $kinds, true) > array_search('SELECT-FOR-UPDATE', $kinds, true) && array_search('CLEAN-CACHE', $kinds, true) < array_search('UPDATE-VIA-wp_update_post', $kinds, true));
check('the cache is cleaned again after COMMIT', 'CLEAN-CACHE' === end($kinds));
foreach (['publish', 'pending', 'private', 'future', 'trash', 'Draft', ''] as $bad) {
    reset_fixture();
    $r = cinatra_ability_post_update_if_unchanged(base_update(['status' => $bad]));
    check("status \"$bad\" -> invalid, no statement", is_refusal($r, 'invalid') && [] === statement_kinds());
}

// ---------------------------------------------------------------------------
echo "Expected: the whole reviewed post is compared (same-second change to an UNWRITTEN field)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_title'] = 'Retitled by someone in the same second';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('content write refuses when the unwritten title changed', is_refusal($r, 'post_changed') && ['post_title'] === $r['data']['changed']);
check('nothing written', $before === $wpdb->rows);
reset_fixture();
$wpdb->rows[T_ID]['post_content'] = T_CONTENT . ' changed';
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected(), 'status' => 'draft']);
check('status-only unpublish refuses when the content is not what was reviewed', is_refusal($r, 'post_changed') && ['post_content'] === $r['data']['changed']);
reset_fixture();
$wpdb->rows[T_ID]['post_excerpt'] = 'Other excerpt';
$r = cinatra_ability_post_update_if_unchanged(base_update(['title' => 'T', 'content' => 'C']));
check('unwritten excerpt change refuses too', is_refusal($r, 'post_changed') && ['post_excerpt'] === $r['data']['changed']);

// ---------------------------------------------------------------------------
echo "State: only published posts and drafts are written\n";
foreach (['pending', 'future', 'private', 'trash', 'auto-draft'] as $state) {
    reset_fixture();
    $wpdb->rows[T_ID]['post_status'] = $state;
    $before = $wpdb->rows;
    $r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['status' => $state])]));
    check("current status $state -> unsupported_status", is_refusal($r, 'unsupported_status') && $state === ($r['data']['current_status'] ?? null));
    check("current status $state -> ROLLBACK, no update", ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'ROLLBACK'] === statement_kinds() && $before === $wpdb->rows);
}
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'trash';
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => full_expected(['status' => 'trash']), 'status' => 'draft']);
check('trash -> draft ("restore" outside the untrash flow) is refused', is_refusal($r, 'unsupported_status') && 'trash' === $wpdb->rows[T_ID]['post_status']);
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['status' => 'draft'])]));
check('a draft is edited', true === $r['success'] && 'draft' === $r['post_status']);
reset_fixture();
$wpdb->rows[T_ID]['post_status'] = 'draft';
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['status' => 'draft']), 'status' => 'draft']));
check('status draft on a draft is allowed (no change)', true === $r['success'] && 'draft' === $r['post_status']);

// ---------------------------------------------------------------------------
echo "Untouched columns: never changed silently\n";
// A title-only request by a user without unfiltered_html:
reset_fixture(['unfiltered_html' => false]);
$wpdb->rows[T_ID]['post_content'] = '<p>Admin markup</p><script>track()</script>';
$GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID];
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged([
    'post_id'             => T_ID,
    'if_unmodified_since' => T_MODIFIED,
    'expected'            => full_expected(['content' => '<p>Admin markup</p><script>track()</script>']),
    'title'               => 'New title',
]);
check('title-only edit by a restricted user whose save would re-filter the untouched body -> untouched_field_changed', is_refusal($r, 'untouched_field_changed') && ['post_content'] === $r['data']['changed']);
check('the body markup is not lost (rolled back)', $before === $wpdb->rows);
check('the refusal does not echo the body', false === strpos(json_encode($r), 'track()'));
check('statements: update ran, then ROLLBACK, no COMMIT', !in_array('COMMIT', statement_kinds(), true) && in_array('ROLLBACK', statement_kinds(), true));
reset_fixture(['hook_rewrites' => ['post_name' => 'changed-by-a-hook']]);
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a hook that rewrites an untouched column -> untouched_field_changed naming it', is_refusal($r, 'untouched_field_changed') && ['post_name'] === $r['data']['changed'] && $before === $wpdb->rows);
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a normal write reports no other change', true === $r['success'] && [] === $r['also_changed']);
reset_fixture();
$wpdb->rows[T_ID]['post_status']   = 'draft';
$wpdb->rows[T_ID]['post_date_gmt'] = '0000-00-00 00:00:00';
$GLOBALS['cinatra_test']['post_cache'][T_ID] = $wpdb->rows[T_ID];
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => full_expected(['status' => 'draft'])]));
check('a floating-date draft: core moves post_date to now; success reports it in also_changed', true === $r['success'] && ['post_date'] === $r['also_changed'] && T_NOW === $wpdb->rows[T_ID]['post_date']);
reset_fixture(['hook_rewrites' => ['post_date' => '2030-01-01 00:00:00']]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('post_date change on a fixed-date post -> untouched_field_changed', is_refusal($r, 'untouched_field_changed') && ['post_date'] === $r['data']['changed']);

// ---------------------------------------------------------------------------
echo "Errors: rollback on update failure, start failure and commit failure\n";
reset_fixture(['update_result' => new WP_Error('db_update_error', 'Could not update post in the database.')]);
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code update_failed', is_refusal($r, 'update_failed'));
check('data.wp_error_code is passed on', 'db_update_error' === $r['data']['wp_error_code']);
check('statements end with ROLLBACK, no COMMIT', ['START TRANSACTION', 'SELECT-FOR-UPDATE', 'ENGINE', 'CLEAN-CACHE', 'UPDATE-VIA-wp_update_post', 'ROLLBACK', 'CLEAN-CACHE'] === statement_kinds());
check('the partial write was rolled back', $before === $wpdb->rows);
check('the post cache was cleaned after the rollback', 'CLEAN-CACHE' === end($wpdb->log) && [T_ID, T_ID] === $GLOBALS['cinatra_test']['cleaned']);
reset_fixture(['update_result' => 0]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('wp_update_post returning 0 -> update_failed', is_refusal($r, 'update_failed'));

reset_fixture();
$wpdb->fail['START TRANSACTION'] = true;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('START TRANSACTION failure -> db_error, no select', is_refusal($r, 'db_error') && ['START TRANSACTION'] === statement_kinds());

reset_fixture();
$wpdb->fail['COMMIT'] = true;
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failure -> db_error', is_refusal($r, 'db_error'));
check('COMMIT failure rolls back', in_array('ROLLBACK', array_slice($wpdb->log, -2), true) && $before === $wpdb->rows);
check('COMMIT failure response carries no content', false === strpos(json_encode($r), 'New body'));

// ---------------------------------------------------------------------------
echo "Read: raw values, no lock, no transaction\n";
reset_fixture();
$r = cinatra_ability_post_read(['post_id' => T_ID]);
check('success with raw content (backslashes intact)', true === $r['success'] && T_CONTENT === $r['post_content']);
check('raw title, excerpt, status, type, modified, permalink', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt'] && 'publish' === $r['post_status'] && 'page' === $r['post_type'] && T_MODIFIED === $r['post_modified_gmt'] && 'https://blog.example/about/' === $r['permalink']);
check('exactly one plain select, no FOR UPDATE, no transaction', ['SELECT'] === statement_kinds());
check('read output keys', ['success', 'post_id', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type', 'post_modified_gmt', 'permalink'] === array_keys($r));
$wpdb->log = [];
$unknown = cinatra_ability_post_read(['post_id' => 999]);
$forbidden = cinatra_ability_post_read(['post_id' => 5]);
$other = cinatra_ability_post_read(['post_id' => 7]);
check('read: unknown, forbidden and other-type give the same not_found', is_refusal($unknown, 'not_found') && $unknown === $forbidden && $unknown === $other);

// ---------------------------------------------------------------------------
echo "Registration\n";
check('abilities register on wp_abilities_api_init', in_array('cinatra_register_content_abilities', $GLOBALS['cinatra_test_actions']['wp_abilities_api_init'] ?? [], true));
check('category registers on wp_abilities_api_categories_init', in_array('cinatra_register_ability_category', $GLOBALS['cinatra_test_actions']['wp_abilities_api_categories_init'] ?? [], true));
$GLOBALS['cinatra_test_abilities'] = [];
cinatra_register_ability_category();
cinatra_register_content_abilities();
$abilities = $GLOBALS['cinatra_test_abilities'];
check('category cinatra registered', isset($GLOBALS['cinatra_test_categories']['cinatra']));
check('exactly the two new ability ids', ['cinatra/post-read', 'cinatra/post-update-if-unchanged'] === array_keys($abilities));
check('neither new id is a retired id', [] === array_intersect(array_keys($abilities), $retired_ids));
$read = $abilities['cinatra/post-read'];
$write = $abilities['cinatra/post-update-if-unchanged'];
check('both are public to MCP', true === $read['meta']['mcp']['public'] && true === $write['meta']['mcp']['public']);
check('read is annotated read-only', true === $read['meta']['annotations']['readonly']);
check('write is not read-only', false === $write['meta']['annotations']['readonly']);
check('both use the per-post permission callback', 'cinatra_content_can_edit' === $read['permission_callback'] && 'cinatra_content_can_edit' === $write['permission_callback']);
check('execute callbacks', 'cinatra_ability_post_read' === $read['execute_callback'] && 'cinatra_ability_post_update_if_unchanged' === $write['execute_callback']);
check('write schema: status enum is draft only', ['draft'] === $write['input_schema']['properties']['status']['enum']);
check('write schema requires post_id, if_unmodified_since, expected', ['post_id', 'if_unmodified_since', 'expected'] === $write['input_schema']['required']);
check('output schemas allow only success to be required', ['success'] === $read['output_schema']['required'] && ['success'] === $write['output_schema']['required']);
check('refusal shape has no "error" key (the adapter would flatten it)', !array_key_exists('error', cinatra_content_not_found()));

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "All checks passed.\n";
exit(0);
