<?php
/**
 * Pin-provenance conformance gate (cinatra-ai/cinatra#2021 S6, design D4 /
 * §10 "Pin-provenance conformance"). Runs under plain
 * `php tests/test-installer-pin-provenance.php` -- no PHPUnit, no WordPress
 * install. Exit code 0 = the baked constant matches the checked-in fixture
 * copy of cinatra's pins.lock `mcpAdapter` block, 1 = drift (build-blocking).
 *
 * This is a DATA-CONSISTENCY check, not a functional test: it does not prove
 * the pin is cryptographically correct (that already happened once, when the
 * pin was baked from the real pins.lock at release time -- see cinatra.php's
 * own provenance comment) -- it proves the four pinned values here and the
 * ones in tests/fixtures/pins-lock-mcp-adapter.json have NOT been edited
 * independently since. A future pin bump must update both in the same PR.
 */

require __DIR__ . '/wp-stubs.php';
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

$fixture_path = __DIR__ . '/fixtures/pins-lock-mcp-adapter.json';
$fixture_raw  = file_get_contents($fixture_path);
check('fixture file is readable', $fixture_raw !== false);

$fixture = json_decode((string) $fixture_raw, true);
check('fixture JSON parses', is_array($fixture));

check(
    'baked CINATRA_MCP_ADAPTER_PIN_VERSION matches the pins.lock fixture',
    ($fixture['version'] ?? null) === CINATRA_MCP_ADAPTER_PIN_VERSION
);
check(
    'baked CINATRA_MCP_ADAPTER_PIN_URL matches the pins.lock fixture',
    ($fixture['url'] ?? null) === CINATRA_MCP_ADAPTER_PIN_URL
);
check(
    'baked CINATRA_MCP_ADAPTER_PIN_SHA256 matches the pins.lock fixture',
    ($fixture['sha256'] ?? null) === CINATRA_MCP_ADAPTER_PIN_SHA256
);
check(
    'baked CINATRA_MCP_ADAPTER_PIN_PROVENANCE_COMMIT matches the pins.lock fixture',
    ($fixture['provenanceCommit'] ?? null) === CINATRA_MCP_ADAPTER_PIN_PROVENANCE_COMMIT
);
check(
    'the sha256 pin is a well-formed 64-char lowercase hex string',
    is_string(CINATRA_MCP_ADAPTER_PIN_SHA256) && preg_match('/^[0-9a-f]{64}$/', CINATRA_MCP_ADAPTER_PIN_SHA256) === 1
);
check(
    'the pinned URL is an https GitHub Releases download URL (never a mutable "latest" link)',
    (bool) preg_match('#^https://github\.com/[^/]+/[^/]+/releases/download/#', CINATRA_MCP_ADAPTER_PIN_URL)
);

if ($failures > 0) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
