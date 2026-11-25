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
 *   - the non-InnoDB refusal and the cached engine read;
 *   - slashing with backslashes and quotes;
 *   - the expected/written field pairing; the status values;
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
        $GLOBALS['cinatra_test']['cleaned'][] = (int) $post_id;
    }
}
if (!function_exists('wp_update_post')) {
    /**
     * Behaves like WordPress for this test: unslashes (wp_insert_post() does),
     * applies a kses stand-in for a user without unfiltered_html, writes the
     * row, and bumps post_modified_gmt to the fixture "now".
     */
    function wp_update_post($postarr, $wp_error = false) {
        global $wpdb;
        $GLOBALS['cinatra_test']['update_calls'][] = $postarr;
        $wpdb->log[] = 'UPDATE-VIA-wp_update_post';
        if (isset($GLOBALS['cinatra_test']['update_result'])) {
            // Simulate a partial write before the failure, so the test proves
            // ROLLBACK undoes it.
            $id = (int) $postarr['ID'];
            $wpdb->rows[$id]['post_title'] = 'PARTIAL-WRITE';
            return $GLOBALS['cinatra_test']['update_result'];
        }
        $id = (int) $postarr['ID'];
        foreach (['post_title', 'post_content', 'post_excerpt', 'post_status'] as $col) {
            if (array_key_exists($col, $postarr)) {
                $value = wp_unslash($postarr[$col]);
                if ('post_status' !== $col && !current_user_can('unfiltered_html')) {
                    $value = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value);
                }
                $wpdb->rows[$id][$col] = $value;
            }
        }
        $wpdb->rows[$id]['post_modified_gmt'] = $GLOBALS['cinatra_test']['now'];
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
        ],
        7 => [
            'ID'                => '7',
            'post_type'         => 'attachment',
            'post_status'       => 'inherit',
            'post_title'        => 'logo',
            'post_content'      => '',
            'post_excerpt'      => '',
            'post_modified_gmt' => T_MODIFIED,
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

function base_update(array $extra = []): array {
    return array_merge([
        'post_id'             => T_ID,
        'if_unmodified_since' => T_MODIFIED,
        'expected'            => ['content' => T_CONTENT],
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
check('statement order is START, SELECT FOR UPDATE, update, read-back, COMMIT',
    ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'UPDATE-VIA-wp_update_post', 'SELECT', 'COMMIT'] === statement_kinds());
check('the FOR UPDATE select targets the posts table by ID', 1 === preg_match('/FROM wp_posts WHERE ID = 42 FOR UPDATE$/', $wpdb->log[2]));
check('answer returns the stored content', '<p>New body</p>' === $r['post_content']);
check('answer returns the new post_modified_gmt', T_NOW === $r['post_modified_gmt']);
check('answer returns unchanged fields raw', 'About "us"' === $r['post_title'] && 'Old excerpt' === $r['post_excerpt']);
check('answer carries the permalink, type, status and id', 'https://blog.example/about/' === $r['permalink'] && 'page' === $r['post_type'] && 'publish' === $r['post_status'] && T_ID === $r['post_id']);
check('nothing was changed by filters', [] === $r['sanitized_fields']);
check('only the written field is passed to wp_update_post', ['post_content', 'ID'] === array_keys($GLOBALS['cinatra_test']['update_calls'][0]));
check('no status is passed when status is absent', !array_key_exists('post_status', $GLOBALS['cinatra_test']['update_calls'][0]));
check('the row is committed', '<p>New body</p>' === $wpdb->rows[T_ID]['post_content']);

echo "Engine: cached after one read\n";
check('the engine was cached in a transient', 'InnoDB' === ($GLOBALS['cinatra_test']['transients'][CINATRA_POSTS_ENGINE_TRANSIENT] ?? null));
$wpdb->log = [];
$wpdb->rows[T_ID]['post_modified_gmt'] = T_MODIFIED;
$wpdb->rows[T_ID]['post_content']      = T_CONTENT;
cinatra_ability_post_update_if_unchanged(base_update());
check('a second write does not read information_schema again', !in_array('ENGINE', statement_kinds(), true));

// ---------------------------------------------------------------------------
echo "Refusal: post_changed (modified differs)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_modified_gmt'] = '2026-11-25 10:03:00';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_changed, well-formed, no content echoed', is_refusal($r, 'post_changed'));
check('data.current_modified_gmt is the current value', '2026-11-25 10:03:00' === $r['data']['current_modified_gmt']);
check('data.changed names post_modified_gmt only', ['post_modified_gmt'] === $r['data']['changed']);
check('statements: START, FOR UPDATE, ROLLBACK (no update)', ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'ROLLBACK'] === statement_kinds());
check('wp_update_post was not called', [] === $GLOBALS['cinatra_test']['update_calls']);
check('nothing written', $before === $wpdb->rows);

echo "Refusal: post_changed (same second, field differs)\n";
reset_fixture();
$wpdb->rows[T_ID]['post_content'] = T_CONTENT . ' edited';
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_changed', is_refusal($r, 'post_changed'));
check('data.changed names post_content only', ['post_content'] === $r['data']['changed']);
check('ROLLBACK and no update', ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'ROLLBACK'] === statement_kinds());

echo "Refusal: post_changed (byte-level difference, trailing space)\n";
reset_fixture();
$r = cinatra_ability_post_update_if_unchanged(base_update(['expected' => ['content' => T_CONTENT . ' ']]));
check('a one-byte difference refuses', is_refusal($r, 'post_changed') && ['post_content'] === $r['data']['changed']);

echo "Refusal: post_locked\n";
reset_fixture(['post_lock' => [T_ID => 9]]);
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code post_locked, well-formed', is_refusal($r, 'post_locked'));
check('data.current_modified_gmt is given', T_MODIFIED === $r['data']['current_modified_gmt']);
check('ROLLBACK and no update', ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'ROLLBACK'] === statement_kinds());
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
$other_type = cinatra_ability_post_update_if_unchanged(base_update(['post_id' => 7, 'expected' => ['content' => ''], 'content' => 'x']));
check('unknown post -> not_found', is_refusal($unknown, 'not_found'));
check('forbidden post -> not_found', is_refusal($forbidden, 'not_found'));
check('attachment (not post/page) -> not_found', is_refusal($other_type, 'not_found'));
check('unknown and forbidden answers are identical', $unknown === $forbidden);
check('other-type answer is identical too', $unknown === $other_type);
check('not_found carries no current_modified_gmt', null === $unknown['data']['current_modified_gmt']);
check('unknown and forbidden touch no database statement', [] === $unknown_log && [] === $forbidden_log);
check('other-type rolls back after the locked select', ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'ROLLBACK'] === statement_kinds());

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
echo "Storage: non-InnoDB refuses before any transaction\n";
reset_fixture();
$wpdb->engine = 'MyISAM';
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code unsupported_storage', is_refusal($r, 'unsupported_storage'));
check('only the engine was read; no transaction', ['ENGINE'] === statement_kinds());
check('nothing written', $before === $wpdb->rows);
reset_fixture();
$wpdb->engine = null;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('unreadable engine also refuses', is_refusal($r, 'unsupported_storage'));
check('an unreadable engine is not cached', !isset($GLOBALS['cinatra_test']['transients'][CINATRA_POSTS_ENGINE_TRANSIENT]));
reset_fixture(['transients' => [CINATRA_POSTS_ENGINE_TRANSIENT => 'innodb']]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('a cached engine is used without a query (case-insensitive)', true === $r['success'] && !in_array('ENGINE', statement_kinds(), true));

// ---------------------------------------------------------------------------
echo "Slashing: backslashes and quotes survive byte for byte\n";
reset_fixture();
$new_content = "Line \\n not a newline, C:\\temp\\x, \"dq\", 'sq', \\\\ and a trailing backslash \\";
$new_title   = 'Title with \\ and "quotes" and \'single\'';
$r = cinatra_ability_post_update_if_unchanged([
    'post_id'             => T_ID,
    'if_unmodified_since' => T_MODIFIED,
    'expected'            => ['content' => T_CONTENT, 'title' => 'About "us"'],
    'content'             => $new_content,
    'title'               => $new_title,
]);
check('success', true === ($r['success'] ?? null));
check('stored content equals the sent content exactly', $new_content === $wpdb->rows[T_ID]['post_content']);
check('stored title equals the sent title exactly', $new_title === $wpdb->rows[T_ID]['post_title']);
check('the answer returns the same bytes', $new_content === $r['post_content'] && $new_title === $r['post_title']);
check('wp_update_post received slashed values', addslashes($new_content) === $GLOBALS['cinatra_test']['update_calls'][0]['post_content']);
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
    'written without expected'          => base_update(['expected' => []]),
    'expected without written'          => base_update(['expected' => ['content' => T_CONTENT, 'title' => 'About "us"']]),
    'expected names an unknown field'   => base_update(['expected' => ['content' => T_CONTENT, 'slug' => 'x']]),
    'non-string written value'          => base_update(['content' => ['x']]),
    'non-string expected value'         => base_update(['expected' => ['content' => null]]),
    'expected is not an object'         => base_update(['expected' => 'x']),
    'nothing written'                   => ['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => []],
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
$r = cinatra_ability_post_update_if_unchanged(['post_id' => T_ID, 'if_unmodified_since' => T_MODIFIED, 'expected' => [], 'status' => 'draft']);
check('status-only draft with empty expected -> success', true === $r['success'] && 'draft' === $wpdb->rows[T_ID]['post_status']);
check('status-only write passes only post_status', ['post_status', 'ID'] === array_keys($GLOBALS['cinatra_test']['update_calls'][0]));
foreach (['publish', 'pending', 'private', 'future', 'trash', 'Draft', ''] as $bad) {
    reset_fixture();
    $r = cinatra_ability_post_update_if_unchanged(base_update(['status' => $bad]));
    check("status \"$bad\" -> invalid, no statement", is_refusal($r, 'invalid') && [] === statement_kinds());
}

// ---------------------------------------------------------------------------
echo "Errors: rollback on update failure, start failure and commit failure\n";
reset_fixture(['update_result' => new WP_Error('db_update_error', 'Could not update post in the database.')]);
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('code update_failed', is_refusal($r, 'update_failed'));
check('data.wp_error_code is passed on', 'db_update_error' === $r['data']['wp_error_code']);
check('statements end with ROLLBACK, no COMMIT', ['ENGINE', 'START TRANSACTION', 'SELECT-FOR-UPDATE', 'UPDATE-VIA-wp_update_post', 'ROLLBACK'] === statement_kinds());
check('the partial write was rolled back', $before === $wpdb->rows);
check('the post cache was cleaned after the rollback', [T_ID] === $GLOBALS['cinatra_test']['cleaned']);
reset_fixture(['update_result' => 0]);
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('wp_update_post returning 0 -> update_failed', is_refusal($r, 'update_failed'));

reset_fixture();
$wpdb->fail['START TRANSACTION'] = true;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('START TRANSACTION failure -> db_error, no select', is_refusal($r, 'db_error') && ['ENGINE', 'START TRANSACTION'] === statement_kinds());

reset_fixture();
$wpdb->fail['COMMIT'] = true;
$before = $wpdb->rows;
$r = cinatra_ability_post_update_if_unchanged(base_update());
check('COMMIT failure -> db_error', is_refusal($r, 'db_error'));
check('COMMIT failure rolls back', 'ROLLBACK' === end($wpdb->log) && $before === $wpdb->rows);
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
