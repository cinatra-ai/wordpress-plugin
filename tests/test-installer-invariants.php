<?php
/**
 * Static "never automatic" invariant test for the one-click plugin installer
 * (cinatra-ai/cinatra#2021 S6, PR zeta, design §3/§10). Asserts that
 * download_url(), the Plugin_Upgrader install call (->install(), reachable
 * only via `new Plugin_Upgrader(...)`), and activate_plugin() are reachable
 * ONLY from inside the two nonce-protected admin-post handlers below --
 * never from an admin_init/init/cron closure, a REST callback, or an AJAX
 * shortcut anywhere else in cinatra.php.
 *
 * Deliberately a plain-text / brace-matching check, not a full PHP AST parse
 * -- cheap, dependency-free, and precise enough for this file's own coding
 * style (no brace characters appear inside the strings/comments in the
 * checked region). Exit code 0 = invariant holds, 1 = a violation (or the
 * expected anchors were not found at all, which would itself indicate the
 * handlers moved or were renamed without updating this test).
 */

$source = file_get_contents(dirname(__DIR__) . '/cinatra.php');
if ($source === false) {
    fwrite(STDERR, "Could not read cinatra.php\n");
    exit(1);
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

/**
 * Extract the byte range of the closure passed to
 * add_action('$hook', function () {...}), by locating the hook-name string
 * literal and brace-matching from the first '{' that follows it to its
 * balanced close.
 */
function extract_closure_body($source, $hook) {
    $needle = "'" . $hook . "'";
    $hook_pos = strpos($source, $needle);
    if ($hook_pos === false) {
        return null;
    }
    $brace_start = strpos($source, '{', $hook_pos);
    if ($brace_start === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace_start; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace_start, $i - $brace_start + 1);
            }
        }
    }
    return null;
}

$mcp_body  = extract_closure_body($source, 'admin_post_cinatra_install_mcp_adapter');
$eafm_body = extract_closure_body($source, 'admin_post_cinatra_install_eafm');

check('mcp_adapter installer handler closure found', $mcp_body !== null);
check('eafm installer handler closure found', $eafm_body !== null);

// cinatra_installer_finish_install() is the ONLY place that constructs a
// Plugin_Upgrader and calls ->install()/activate_plugin() -- both handlers
// above call it, but it is itself just a plain function (not hooked to
// admin_init/init/cron/REST/AJAX anywhere), so folding its body into the
// "reachable surface" for each handler is the correct unit for this check.
// Brace-match from the `function cinatra_installer_finish_install(` anchor
// directly (there is no leading quote to strpos from, unlike the add_action
// hook-name anchors above).
$fn_pos = strpos($source, 'function cinatra_installer_finish_install(');
check('cinatra_installer_finish_install() found', $fn_pos !== false);
$finish_install_body = '';
if ($fn_pos !== false) {
    $brace_start = strpos($source, '{', $fn_pos);
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace_start; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $finish_install_body = substr($source, $brace_start, $i - $brace_start + 1);
                break;
            }
        }
    }
}
check('cinatra_installer_finish_install() body extracted', $finish_install_body !== '');

// Confirm ONLY the two installer handlers call cinatra_installer_finish_install()
// (i.e. no third, unaudited call site reaches Plugin_Upgrader/activate_plugin
// through it).
$total_finish_install_calls = substr_count($source, 'cinatra_installer_finish_install(') - 1; // -1 for the `function` declaration itself.
$handler_finish_install_calls = substr_count((string) $mcp_body, 'cinatra_installer_finish_install(')
    + substr_count((string) $eafm_body, 'cinatra_installer_finish_install(');
check(
    "cinatra_installer_finish_install() is called only by the two installer handlers ($handler_finish_install_calls of $total_finish_install_calls call sites)",
    $total_finish_install_calls > 0 && $total_finish_install_calls === $handler_finish_install_calls
);

// The combined "reachable surface" for the forbidden primitives is: each
// handler's own body, PLUS the one shared helper both handlers (and nothing
// else) call into.
$combined_reachable = (string) $mcp_body . (string) $eafm_body . $finish_install_body;

// Note the trailing space on the two argument-taking calls: this file's own
// prose (doc comments) refers to "download_url()" / "activate_plugin()" with
// EMPTY parens, whereas every real call in this codebase is WPCS-spaced
// ("download_url( $x )") -- the space disambiguates a real call site from a
// comment mentioning the function name.
$forbidden = ['download_url( ', 'new Plugin_Upgrader(', '->install(', 'activate_plugin( '];

foreach ($forbidden as $needle) {
    $total_count = substr_count($source, $needle);
    $in_reachable = substr_count($combined_reachable, $needle);
    check(
        "`$needle` appears only inside the installer handlers / their shared helper ($in_reachable of $total_count occurrences)",
        $total_count > 0 && $total_count === $in_reachable
    );
}

// Confirm neither installer handler is co-located with (i.e. itself
// registers, schedules, or relays into) a disallowed automatic-execution
// context -- admin_init, a bare 'init' hook, WP-Cron scheduling, or REST
// route registration.
foreach (['admin_init', "'init'", 'wp_schedule_event', 'register_rest_route'] as $disallowed_context) {
    check(
        "installer handlers do not reference `$disallowed_context`",
        strpos((string) $mcp_body, $disallowed_context) === false && strpos((string) $eafm_body, $disallowed_context) === false
    );
}

if ($failures > 0) {
    echo "\n$failures check(s) FAILED\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
