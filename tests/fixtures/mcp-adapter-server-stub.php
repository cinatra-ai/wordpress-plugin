<?php
/**
 * A richer fake MCP Adapter server than tests/fixtures/mcp-adapter-stub.php's
 * StubMcpServer (which only implements get_server_id()/get_tools(), enough
 * for the epsilon ensure-panel's ability-count read). The site-inventory
 * sender (cinatra-ai/cinatra#2021 S6 / eta) maps the FULL server-entry shape
 * (namespace/route/name/description/version/transport-permission-callback),
 * so this fixture implements the complete duck-typed method surface
 * cinatra_map_adapter_server_to_inventory_entry() reads.
 *
 * Deliberately a SEPARATE class (not an edit to StubMcpServer) so epsilon's
 * own test-setup-checklist.php is untouched and stays byte-for-byte as it
 * was before this PR.
 *
 * Reuses the SAME \WP\MCP\Core\McpAdapter::$servers_fixture registry that
 * tests/fixtures/mcp-adapter-stub.php declares -- both fixtures share one
 * McpAdapter class (PHP cannot declare it twice), so a test that needs the
 * full server shape must require BOTH files (mcp-adapter-stub.php first, for
 * McpAdapter itself; this file second, for the richer server fake).
 */

namespace WP\MCP\Core;

class FullStubMcpServer {
    private $id;
    private $namespace;
    private $route;
    private $name;
    private $description;
    private $version;
    private $tools;
    private $permission_callback;

    public function __construct(array $fields = []) {
        $this->id                  = $fields['id'] ?? 'stub-server';
        $this->namespace           = $fields['namespace'] ?? 'mcp';
        $this->route               = $fields['route'] ?? 'stub-server';
        $this->name                = $fields['name'] ?? 'Stub Server';
        $this->description         = $fields['description'] ?? '';
        $this->version             = $fields['version'] ?? '';
        $this->tools               = $fields['tools'] ?? [];
        $this->permission_callback = $fields['permission_callback'] ?? null;
    }

    public function get_server_id() {
        return $this->id;
    }
    public function get_server_route_namespace() {
        return $this->namespace;
    }
    public function get_server_route() {
        return $this->route;
    }
    public function get_server_name() {
        return $this->name;
    }
    public function get_server_description() {
        return $this->description;
    }
    public function get_server_version() {
        return $this->version;
    }
    public function get_tools() {
        return $this->tools;
    }
    public function get_transport_permission_callback() {
        return $this->permission_callback;
    }
}
