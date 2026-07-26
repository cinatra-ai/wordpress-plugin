/*
 * Cinatra — fallback button behaviour.
 *
 * Always-visible button shown until the main widget mounts (#cinatra-root gets
 * data-cinatra-mounted="true"). On click, if the widget has not mounted, it
 * probes the instance's public embed page (the widget host) and shows a
 * diagnostic message.
 *
 * Reads its instance URL from window.CinatraFallback.cinatraUrl (localized by
 * PHP) — no inline <script> is emitted, satisfying Plugin Check.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
(function () {
	'use strict';

	function format(tpl, value) {
		return String(tpl).replace('%s', value);
	}

	function init() {
		var cfg = window.CinatraFallback || {};
		var i18n = cfg.i18n || {};
		var cu = (cfg.cinatraUrl || '').replace(/\/+$/, '');
		var btn = document.getElementById('cw-fallback-btn');
		var box = document.getElementById('cw-fallback-error');
		var msg = document.getElementById('cw-fe-msg');
		var cls = document.getElementById('cw-fe-close');
		var root = document.getElementById('cinatra-root');
		if (!btn || !box || !msg) {
			return;
		}
		var mounted = false;

		function markMounted() {
			btn.style.display = 'none';
			box.style.display = 'none';
			mounted = true;
		}

		if (root) {
			// Check the CURRENT state first — the widget may have already mounted
			// before this script ran (load-order is not guaranteed), in which case
			// a future-only MutationObserver would never fire.
			if (root.dataset.cinatraMounted === 'true') {
				markMounted();
			} else {
				new MutationObserver(function (_, o) {
					if (root.dataset.cinatraMounted === 'true') {
						markMounted();
						o.disconnect();
					}
				}).observe(root, { attributes: true });
			}
		}

		btn.addEventListener('click', function () {
			if (mounted) {
				return;
			}
			if (!cu) {
				msg.textContent = i18n.noUrl || 'No Cinatra instance URL is configured.';
				box.style.display = 'block';
				return;
			}
			// Bounded-timeout reachability probe via a guarded AbortController
			// (AbortSignal.timeout is not universal and can throw synchronously on
			// older browsers). The legacy auth-free /api/agents/{slug}/capabilities
			// probe was removed with the unified-broker cutover (cinatra#2029); the
			// widget host is now the public /embed/assistant page. It is cross-origin
			// and emits no CORS headers, so this is a `no-cors` probe: a resolved
			// (opaque) response means the instance answered — reachable; a rejection
			// (DNS / connection refused / timeout) means it did not — unreachable. The
			// opaque response status is intentionally not read.
			var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
			var timer = controller ? setTimeout(function () { try { controller.abort(); } catch (e) {} }, 4000) : null;
			fetch(cu + '/embed/assistant', {
				method: 'GET',
				mode: 'no-cors',
				cache: 'no-store',
				signal: controller ? controller.signal : undefined
			})
				.then(function () {
					if (timer) { clearTimeout(timer); }
					msg.textContent = i18n.reachableNoWidget || 'Cinatra is reachable but the widget has not loaded yet. Try refreshing the page.';
					box.style.display = 'block';
				})
				.catch(function () {
					if (timer) { clearTimeout(timer); }
					msg.textContent = format(i18n.unreachable || 'Cannot reach %s. Check that your Cinatra instance is running.', cu);
					box.style.display = 'block';
				});
		});

		if (cls) {
			cls.addEventListener('click', function () {
				box.style.display = 'none';
			});
		}
		document.addEventListener('click', function (e) {
			if (box.style.display === 'none') {
				return;
			}
			if (!box.contains(e.target) && e.target !== btn) {
				box.style.display = 'none';
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
