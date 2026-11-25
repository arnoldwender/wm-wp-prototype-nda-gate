/**
 * WM Suite Prototype NDA Gate — asks before a new agreement version is published.
 * The question comes from the catalog through wp_localize_script() (wmNdaAdmin.confirmVersion).
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var bumpBtn = document.querySelector('button[name="wm_nda_create_version"]');
		if (!bumpBtn) {
			return;
		}

		// Without the localized text the button label is asked, never nothing.
		var question = (window.wmNdaAdmin && window.wmNdaAdmin.confirmVersion) || (bumpBtn.textContent.trim() + '?');

		bumpBtn.addEventListener('click', function (e) {
			if (!window.confirm(question)) {
				e.preventDefault();
			}
		});
	});
})();
