/*
 * Cinatra — settings page enhancement.
 *
 * Turns the connector-path hint span(s) into a real link as the admin types the
 * instance URL. Built with DOM APIs (createElement + textContent), never
 * innerHTML, so an admin-entered URL can never inject markup (no DOM-XSS).
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
(function () {
	'use strict';

	var CONNECTOR_PATH = '/settings/connectors/wordpress-widget';

	function isHttpUrl(value) {
		// Only build an href for http(s); never javascript:, data:, etc.
		return /^https?:\/\//i.test(value);
	}

	function buildAnchor(href, text) {
		var a = document.createElement('a');
		a.href = href; // assigning .href (not innerHTML) is XSS-safe
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = text; // text content, never markup
		return a;
	}

	function replaceChildrenWith(el, node) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
		el.appendChild(node);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var connectInput = document.getElementById('cinatra_connect_url');
		var manualInput = document.getElementById('cinatra_url');
		var pathSpans = [document.getElementById('cinatra_api_key_path')].filter(Boolean);

		if (!pathSpans.length) {
			return;
		}

		function currentBase() {
			var raw = '';
			if (manualInput && manualInput.value) {
				raw = manualInput.value;
			} else if (connectInput && connectInput.value) {
				raw = connectInput.value;
			}
			return raw.replace(/\/+$/, '');
		}

		function update() {
			var base = currentBase();
			pathSpans.forEach(function (el) {
				if (base && isHttpUrl(base)) {
					var full = base + CONNECTOR_PATH;
					replaceChildrenWith(el, buildAnchor(full, full));
				} else {
					// No usable base: render the bare path as plain text.
					el.textContent = CONNECTOR_PATH;
				}
			});
		}

		update();
		if (manualInput) {
			manualInput.addEventListener('input', update);
		}
		if (connectInput) {
			connectInput.addEventListener('input', update);
		}
	});
})();
