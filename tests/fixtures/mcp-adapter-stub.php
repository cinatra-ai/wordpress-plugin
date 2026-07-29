<?php
/**
 * Minimal fixture standing in for the live WordPress MCP Adapter's server
 * registry (\WP\MCP\Core\McpAdapter / \WP\MCP\Core\McpServer), used ONLY by
 * tests/test-setup-checklist.php to exercise
 * cinatra_ensure_content_server_ability_count()'s "adapter class present and
 * reporting a real server" path (cinatra-ai/cinatra#2021 S6 / epsilon).
 *
 * Deliberately kept in its own file (not inlined in the test) so the test can
 * assert the "class not loaded yet" fail-safe branch BEFORE requiring this —
 * PHP cannot un-declare a class once loaded, so the "class absent" case can
 * only be exercised by not having required this file yet.
 */

namespace WP\MCP\Core;

class StubMcpServer {
    private $id;
    private $tools;

    public function __construct($id, array $tools) {
        $this->id    = $id;
        $this->tools = $tools;
    }

    public function get_server_id() {
        return $this->id;
    }

    public function get_tools() {
        return $this->tools;
    }
}

class McpAdapter {
    /** @var array Test-controlled registry: server_id (or any key) => StubMcpServer. */
    public static $servers_fixture = array();

    public static function instance() {
        return new self();
    }

    public function get_servers() {
        return self::$servers_fixture;
    }
}
