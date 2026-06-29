<?php
/**
 * Standalone behavior tests for the Cinatra plugin's publish webhook EMITTER
 * (wp#48). Runs under plain `php tests/test-publish-emitter.php` — no PHPUnit,
 * no WordPress install. Exit code 0 = all pass, 1 = a failure.
 *
 * Asserts the MERGED host wire contract
 * (cinatra: src/app/api/webhooks/wordpress/route.ts + packages/webhooks
 * verifyLegacyHmac):
 *   - X-Cinatra-Sig-256 == 'sha256=' . hash_hmac('sha256', <exact body>, secret)
 *     recomputed over the EXACT captured body string (sign==send bytes).
 *   - the JSON body decodes to the strict host schema with correct values.
 *   - X-Cinatra-Webhook-Id is present and STABLE across two invokes of the same
 *     publish event (retry-dedupe friendly).
 *   - the endpoint is {cinatra_url}/api/webhooks/wordpress (the simple route).
 *   - NO fire when: secret missing / url missing / no matching subscription /
 *     post type not in the subscription's post_types.
 *   - NO fire on publish->publish (edit) or draft->draft (no transition into
 *     publish).
 *   - NO fire for a non-public post type / a revision / an autosave.
 *   - the secret, the signature, and the post title NEVER appear in the log.
 */

require __DIR__ . '/wp-stubs.php';
require dirname(__DIR__) . '/cinatra.php';

// Route PHP error_log() to a temp file so the plugin's intentional fixed-text
// warnings don't pollute CI output, and so tests can assert the log carries NO
// secret / signature / title.
$GLOBALS['cinatra_test_log'] = tempnam(sys_get_temp_dir(), 'cinatra-emit-log-');
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['cinatra_test_log']);
register_shutdown_function(static function () {
    if (!empty($GLOBALS['cinatra_test_log']) && is_file($GLOBALS['cinatra_test_log'])) {
        @unlink($GLOBALS['cinatra_test_log']);
    }
});
function cinatra_test_log_contents(): string {
    return (string) @file_get_contents($GLOBALS['cinatra_test_log']);
}
function cinatra_test_log_reset(): void {
    @file_put_contents($GLOBALS['cinatra_test_log'], '');
}

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

// The shared secret + a distinctive title we can scan the log for. Assembled
// from fragments so no credentialed-URI/secret literal sits verbatim in the
// fixture source (secret-scan hygiene).
const EMIT_SECRET = 'whk' . '-shared-' . 'SECRET-' . 'abc123';
const EMIT_TITLE  = 'Distinctive Title ' . 'GAMMA-DELTA';

function reset_fixture(array $overrides = []) {
    $base = [
        'options' => [
            'cinatra_url'                   => 'https://app.cinatra.ai',
            'cinatra_instance_id'           => 'wp-prod',
            'cinatra_webhook_secret'        => EMIT_SECRET,
            // A subscription enabling post_published for the 'post' type only.
            'cinatra_webhook_subscriptions' => json_encode([
                [
                    'id'         => 'sub-1',
                    'event_type' => 'post_published',
                    'target_url' => 'https://operator-entered.example/anything',
                    'post_types' => ['post'],
                    'created_at' => '2026-06-24T00:00:00+00:00',
                ],
            ]),
        ],
        'home_url'          => 'https://blog.example',
        'current_user_id'   => 7,
        'remote_post'       => null,
        'remote_post_calls' => [],
        'filters'           => [],
        'filter_cbs'        => [],
        // Publish-emitter fixture knobs.
        'post_types'        => [
            'post'       => ['public' => true],
            'page'       => ['public' => true],
            'wp_block'   => ['public' => false], // a non-public internal type
            'attachment' => ['public' => false],
        ],
        'is_revision'       => false,
        'is_autosave'       => false,
    ];
    $GLOBALS['cinatra_test'] = array_replace($base, $overrides);
    putenv('CINATRA_BASE_URL');
}

function ok_204() {
    return function ($url, $args) {
        return ['response' => ['code' => 204], 'body' => ''];
    };
}

function make_post(array $fields = []): WP_Post {
    return new WP_Post(array_replace([
        'ID'                => 4242,
        'post_type'         => 'post',
        'post_title'        => EMIT_TITLE,
        'post_status'       => 'publish',
        'post_modified_gmt' => '2026-06-24 12:00:00',
        'permalink'         => 'https://blog.example/?p=4242',
    ], $fields));
}

function last_call() {
    return end($GLOBALS['cinatra_test']['remote_post_calls']) ?: null;
}

// ---------------------------------------------------------------------------
echo "Test: happy path — a draft->publish transition emits the signed webhook\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
$post = make_post();
cinatra_emit_post_published('publish', 'draft', $post);
$call = last_call();
check('emitted exactly one webhook', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);

// (d) endpoint is the simple route on the configured cinatra_url.
check('endpoint == {cinatra_url}/api/webhooks/wordpress',
    $call && $call['url'] === 'https://app.cinatra.ai/api/webhooks/wordpress');

// (a) signature is over the EXACT captured body bytes.
$body    = $call ? (string) $call['args']['body'] : '';
$sig     = $call ? ($call['args']['headers']['X-Cinatra-Sig-256'] ?? '') : '';
$expected_sig = 'sha256=' . hash_hmac('sha256', $body, EMIT_SECRET);
check('X-Cinatra-Sig-256 == sha256=hash_hmac(sha256, EXACT body, secret)',
    $sig !== '' && hash_equals($expected_sig, $sig));
check('signature header carries the sha256= prefix', strpos($sig, 'sha256=') === 0);

// Content negotiation headers per the contract.
check('Content-Type: application/json', ($call['args']['headers']['Content-Type'] ?? '') === 'application/json');
check('Accept: application/json', ($call['args']['headers']['Accept'] ?? '') === 'application/json');

// (b) the body decodes to the strict host schema with correct values.
$payload = json_decode($body, true);
check('payload event == post_published', ($payload['event'] ?? null) === 'post_published');
check('payload postId is the int post ID', ($payload['postId'] ?? null) === 4242 && is_int($payload['postId']));
check('payload postType == post', ($payload['postType'] ?? null) === 'post');
check('payload title == post title', ($payload['title'] ?? null) === EMIT_TITLE);
check('payload url == permalink', ($payload['url'] ?? null) === 'https://blog.example/?p=4242');
check('payload siteUrl == home_url()', ($payload['siteUrl'] ?? null) === 'https://blog.example');
check('payload issuedAt is a non-empty string', is_string($payload['issuedAt'] ?? null) && $payload['issuedAt'] !== '');
check('payload has ONLY the schema keys',
    is_array($payload) &&
    count(array_diff(array_keys($payload), ['event', 'postId', 'postType', 'title', 'url', 'siteUrl', 'issuedAt'])) === 0);

// ---------------------------------------------------------------------------
echo "Test: X-Cinatra-Webhook-Id is present and STABLE across two emits of the same event\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
$post = make_post();
cinatra_emit_post_published('publish', 'draft', $post);
$id1 = $GLOBALS['cinatra_test']['remote_post_calls'][0]['args']['headers']['X-Cinatra-Webhook-Id'] ?? '';
cinatra_emit_post_published('publish', 'draft', $post);
$id2 = $GLOBALS['cinatra_test']['remote_post_calls'][1]['args']['headers']['X-Cinatra-Webhook-Id'] ?? '';
check('webhook id present', $id1 !== '');
check('webhook id is stable across retries of the same event', $id1 === $id2);
check('webhook id is derived from instance + post id', strpos($id1, 'wp-prod') !== false && strpos($id1, '4242') !== false);

// A DIFFERENT post (or a re-modified post) yields a DIFFERENT id.
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['ID' => 4242, 'post_modified_gmt' => '2026-06-24 13:00:00']));
$id_remod = $GLOBALS['cinatra_test']['remote_post_calls'][0]['args']['headers']['X-Cinatra-Webhook-Id'] ?? '';
check('a later post_modified_gmt yields a DIFFERENT webhook id', $id_remod !== $id1 && $id_remod !== '');

// ---------------------------------------------------------------------------
echo "Test: url is omitted when the permalink is empty (optional schema field)\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['permalink' => '']));
$payload = json_decode((string) last_call()['args']['body'], true);
check('payload omits url when permalink empty', !array_key_exists('url', $payload));
check('payload still carries the required fields', ($payload['event'] ?? '') === 'post_published');

// ---------------------------------------------------------------------------
echo "Test: a relative / non-absolute permalink is OMITTED (host schema requires an absolute url)\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['permalink' => '/relative/path/only']));
$payload = json_decode((string) last_call()['args']['body'], true);
check('relative permalink -> url omitted (not a valid absolute URL)', !array_key_exists('url', $payload));
check('webhook still fires (url is optional)', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);
// An absolute http(s) permalink IS included.
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['permalink' => 'https://blog.example/hello-world/']));
$payload = json_decode((string) last_call()['args']['body'], true);
check('absolute https permalink -> url included', ($payload['url'] ?? null) === 'https://blog.example/hello-world/');

// ---------------------------------------------------------------------------
echo "Test: an attachment (media) never emits, even with an all-types subscription\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_webhook_subscriptions'] = json_encode([
    ['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => ''],
]);
$GLOBALS['cinatra_test']['post_types']['attachment'] = ['public' => true]; // even if registered public
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['post_type' => 'attachment']));
check('no webhook for an attachment (media), even with a public type + all-types subscription',
    count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: webhook id never ends in an empty suffix when post_modified_gmt is unparseable\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['post_modified_gmt' => 'not-a-real-timestamp']));
$id_bad = last_call()['args']['headers']['X-Cinatra-Webhook-Id'] ?? '';
check('webhook id is non-empty + does NOT end in a trailing dash', $id_bad !== '' && substr($id_bad, -1) !== '-');
// Stable across two emits of the same (unparseable-timestamp) post.
cinatra_emit_post_published('publish', 'draft', make_post(['post_modified_gmt' => 'not-a-real-timestamp']));
$id_bad2 = $GLOBALS['cinatra_test']['remote_post_calls'][1]['args']['headers']['X-Cinatra-Webhook-Id'] ?? '';
check('webhook id stable across retries even with an unparseable timestamp', $id_bad === $id_bad2);

// ---------------------------------------------------------------------------
echo "Test: NO fire when the signing secret is missing\n";
reset_fixture(['options' => array_replace(
    [
        'cinatra_url'                   => 'https://app.cinatra.ai',
        'cinatra_instance_id'           => 'wp-prod',
        'cinatra_webhook_subscriptions' => json_encode([['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => '']]),
    ]
)]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
check('no webhook fired without a secret', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: NO fire when the cinatra_url is missing\n";
reset_fixture(['options' => [
    'cinatra_instance_id'           => 'wp-prod',
    'cinatra_webhook_secret'        => EMIT_SECRET,
    'cinatra_webhook_subscriptions' => json_encode([['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => '']]),
]]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
check('no webhook fired without a configured cinatra_url', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
check('endpoint helper returns empty string when cinatra_url unset', cinatra_publish_webhook_endpoint() === '');

// ---------------------------------------------------------------------------
echo "Test: NO fire when there is no matching post_published subscription\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_webhook_subscriptions'] = json_encode([
    ['id' => 's', 'event_type' => 'post_updated', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => ''],
]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
check('no webhook fired without a post_published subscription', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: NO fire when the post type is not in the subscription's post_types filter\n";
reset_fixture();
// subscription is post-only; publish a 'page'.
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['post_type' => 'page']));
check('no webhook fired for a type outside the subscription filter', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// An EMPTY post_types filter matches ALL public types.
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_webhook_subscriptions'] = json_encode([
    ['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => ''],
]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['post_type' => 'page']));
check('an empty post_types filter matches any public type (page)', count($GLOBALS['cinatra_test']['remote_post_calls']) === 1);

// ---------------------------------------------------------------------------
echo "Test: NO fire on a non-transition (publish->publish edit, draft->draft)\n";
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'publish', make_post());
check('no webhook on publish->publish (an edit, not a new publish)', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('draft', 'draft', make_post(['post_status' => 'draft']));
check('no webhook on draft->draft', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('draft', 'publish', make_post());
check('no webhook on publish->draft (an unpublish)', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: NO fire for a non-public post type / revision / autosave\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_webhook_subscriptions'] = json_encode([
    ['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://x.example/h', 'post_types' => [], 'created_at' => ''],
]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post(['post_type' => 'wp_block'])); // non-public
check('no webhook for a NON-PUBLIC post type', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

reset_fixture();
$GLOBALS['cinatra_test']['is_revision'] = true;
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
check('no webhook for a revision', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

reset_fixture();
$GLOBALS['cinatra_test']['is_autosave'] = true;
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
check('no webhook for an autosave', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// A non-WP_Post argument is ignored.
reset_fixture();
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', null);
check('no webhook when the post arg is not a WP_Post', count($GLOBALS['cinatra_test']['remote_post_calls']) === 0);

// ---------------------------------------------------------------------------
echo "Test: emission only ever targets the operator-configured cinatra_url, never the subscription target_url\n";
reset_fixture();
$GLOBALS['cinatra_test']['options']['cinatra_webhook_subscriptions'] = json_encode([
    ['id' => 's', 'event_type' => 'post_published', 'target_url' => 'https://attacker.evil/steal', 'post_types' => [], 'created_at' => ''],
]);
$GLOBALS['cinatra_test']['remote_post'] = ok_204();
cinatra_emit_post_published('publish', 'draft', make_post());
$call = last_call();
check('endpoint is the configured cinatra_url simple route (NOT the operator target_url)',
    $call && $call['url'] === 'https://app.cinatra.ai/api/webhooks/wordpress');
check('the operator-entered target_url host is never contacted',
    $call && strpos($call['url'], 'attacker.evil') === false);

// ---------------------------------------------------------------------------
echo "Test: a transport failure (WP_Error) and a non-2xx are non-fatal, fixed-text-logged, and leak nothing\n";
reset_fixture();
cinatra_test_log_reset();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return new WP_Error('http_request_failed', 'cURL error 7: connect to 10.0.0.5:443 refused');
};
// Must not throw / fatal.
cinatra_emit_post_published('publish', 'draft', make_post());
$log = cinatra_test_log_contents();
check('transport failure logged a fixed-text line', strpos($log, 'publish webhook transport failed') !== false);
check('transport failure log does NOT leak the upstream error detail',
    strpos($log, 'cURL error 7') === false && strpos($log, '10.0.0.5') === false);

reset_fixture();
cinatra_test_log_reset();
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 503], 'body' => json_encode(['error' => 'instance-internal-detail-XYZ'])];
};
cinatra_emit_post_published('publish', 'draft', make_post());
$log = cinatra_test_log_contents();
check('non-2xx logged HTTP status only', strpos($log, 'HTTP 503') !== false);
check('non-2xx log does NOT reflect the upstream body', strpos($log, 'instance-internal-detail-XYZ') === false);

// ---------------------------------------------------------------------------
echo "Test: the secret, the signature, and the post title NEVER appear in the log\n";
reset_fixture();
cinatra_test_log_reset();
// Drive both a transport-failure and a non-2xx path so any log line is captured.
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return ['response' => ['code' => 500], 'body' => ''];
};
$post = make_post();
cinatra_emit_post_published('publish', 'draft', $post);
$GLOBALS['cinatra_test']['remote_post'] = function ($url, $args) {
    return new WP_Error('x', 'y');
};
cinatra_emit_post_published('publish', 'draft', $post);
$log = cinatra_test_log_contents();
check('log never contains the shared secret', strpos($log, EMIT_SECRET) === false);
check('log never contains a signature (sha256= hex)', strpos($log, 'sha256=') === false);
check('log never contains the post title', strpos($log, EMIT_TITLE) === false);

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "ALL TESTS PASSED\n";
    exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
