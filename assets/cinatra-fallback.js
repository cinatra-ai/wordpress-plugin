/*
 * Cinatra — fallback button behaviour.
 *
 * Always-visible button shown until the main widget mounts (#cinatra-root gets
 * data-cinatra-mounted="true"). On click, if the widget has not mounted, it
 * probes the instance capabilities endpoint and shows a diagnostic message.
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

		if (root) {
			new MutationObserver(function (_, o) {
				if (root.dataset.cinatraMounted === 'true') {
					btn.style.display = 'none';
					box.style.display = 'none';
					mounted = true;
					o.disconnect();
				}
			}).observe(root, { attributes: true });
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
			fetch(cu + '/api/agents/wordpress-content-editor/capabilities', {
				method: 'GET',
				cache: 'no-store',
				signal: AbortSignal.timeout(4000)
			})
				.then(function (r) {
					msg.textContent = r.ok
						? (i18n.reachableNoWidget || 'Cinatra is reachable but the widget has not loaded yet. Try refreshing the page.')
						: format(i18n.httpStatus || 'Cinatra returned HTTP %s. Check your instance.', r.status);
					box.style.display = 'block';
				})
				.catch(function () {
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
