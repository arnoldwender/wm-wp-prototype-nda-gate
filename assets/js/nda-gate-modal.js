/**
 * WM Suite Prototype NDA Gate — signature modal: focus trap, keyboard handling, acceptance through REST.
 * The messages come from data attributes that PHP fills from the translation catalog.
 *
 * It also collects the click-wrap interaction record: counted facts about how the dialog was
 * operated, sent with the acceptance and signed with it on the server (WM_NDA_Evidence). No
 * coordinates and no timing series leave the browser — counts, distances and durations only.
 */

(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var overlay = document.getElementById('wm-nda-gate-overlay');
		if (!overlay) {
			return;
		}

		var triggerBtn = document.getElementById('wm-nda-open-modal-trigger');
		var confirmCheckbox = document.getElementById('wm-nda-modal-confirm');
		var acceptBtn = document.getElementById('wm-nda-btn-accept');
		var statusMsg = document.getElementById('wm-nda-status-message');
		var isFullscreen = overlay.classList.contains('wm-nda-gate-fullscreen');
		var card = overlay.querySelector('.wm-nda-gate-card');
		var body = overlay.querySelector('.wm-nda-gate-body');

		// Only elements that can take focus now. The accept button stays disabled until the box is ticked; as the
		// last element of the list it never received focus, so the wrap never ran and Tab left the dialog
		// (reader audit 2026-09-15, D20).
		var focusableSelectors = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

		// -----------------------------------------------------------------
		// Click-wrap interaction record
		// -----------------------------------------------------------------
		var interaction = {
			openedAt: isFullscreen ? now() : null,
			checkedAt: null,
			acceptInput: null,
			pointerType: null,
			moveSamples: 0,
			pathPx: 0,
			pressureMax: 0,
			pressureSamples: 0,
			scrollPct: 0,
			lastX: null,
			lastY: null
		};

		function now() {
			return (window.performance && window.performance.now) ? window.performance.now() : Date.now();
		}

		function since(mark) {
			return mark === null ? null : Math.max(0, Math.round(now() - mark));
		}

		function trackPointer(e) {
			if (e.pointerType) {
				interaction.pointerType = e.pointerType;
			}

			if (interaction.lastX !== null) {
				var dx = e.clientX - interaction.lastX;
				var dy = e.clientY - interaction.lastY;
				interaction.pathPx += Math.sqrt(dx * dx + dy * dy);
			}
			interaction.lastX = e.clientX;
			interaction.lastY = e.clientY;
			interaction.moveSamples++;

			// Pointer Events report exactly 0.5 for hardware without a pressure sensor while a button
			// is down, and 0 otherwise. Both are counted; the server names 0.5 from a mouse for what
			// it is instead of reading it as half pressure.
			if (typeof e.pressure === 'number' && e.pressure > 0) {
				interaction.pressureSamples++;
				if (e.pressure > interaction.pressureMax) {
					interaction.pressureMax = e.pressure;
				}
			}
		}

		function trackScroll() {
			if (!body || body.scrollHeight <= body.clientHeight) {
				// Nothing to scroll: everything that exists is on screen.
				interaction.scrollPct = 100;
				return;
			}
			var seen = (body.scrollTop + body.clientHeight) / body.scrollHeight * 100;
			if (seen > interaction.scrollPct) {
				interaction.scrollPct = Math.min(100, seen);
			}
		}

		function buildRecord() {
			var record = {
				pointer_type: interaction.pointerType || (interaction.acceptInput === 'keyboard' ? 'keyboard' : 'unknown'),
				move_samples: interaction.moveSamples,
				path_px: Math.round(interaction.pathPx),
				scroll_pct: Math.round(interaction.scrollPct),
				viewport_w: window.innerWidth || 0,
				viewport_h: window.innerHeight || 0,
				// Positive east of UTC: getTimezoneOffset() counts the other way round.
				tz_offset_min: -new Date().getTimezoneOffset()
			};

			if (interaction.acceptInput) {
				record.accept_input = interaction.acceptInput;
			}
			var dwell = since(interaction.openedAt);
			if (dwell !== null) {
				record.dwell_ms = dwell;
			}
			var checked = since(interaction.checkedAt);
			if (checked !== null) {
				record.check_to_accept_ms = checked;
			}
			if (interaction.pressureSamples > 0) {
				record.pressure_samples = interaction.pressureSamples;
				record.pressure_max_permille = Math.round(interaction.pressureMax * 1000);
			}

			return record;
		}

		if (window.PointerEvent) {
			overlay.addEventListener('pointermove', trackPointer);
			overlay.addEventListener('pointerdown', trackPointer);
		} else {
			// No Pointer Events: distance and sample count still work, pressure and device do not.
			overlay.addEventListener('mousemove', trackPointer);
		}
		if (body) {
			body.addEventListener('scroll', trackScroll);
		}
		document.addEventListener('click', function (e) {
			var target = e.target && e.target.closest ? e.target.closest('#wm-nda-btn-accept, #wp-submit') : null;
			if (target) {
				// A button operated from the keyboard dispatches a click whose detail is 0.
				interaction.acceptInput = e.detail === 0 ? 'keyboard' : 'pointer';
			}
		}, true);

		// wp-login.php: the record travels in a hidden field of the login form, because that path
		// records the acceptance in PHP (WM_NDA_Interceptor::validate_login_nda) and never calls REST.
		var loginForm = document.getElementById('loginform');
		var hiddenField = document.getElementById('wm_nda_interaction');
		if (loginForm && hiddenField) {
			loginForm.addEventListener('submit', function () {
				trackScroll();
				hiddenField.value = JSON.stringify(buildRecord());
			});
		}

		function message(name) {
			return overlay.getAttribute('data-msg-' + name) || '';
		}

		function openModal() {
			overlay.classList.add('wm-nda-active');
			overlay.setAttribute('aria-hidden', 'false');
			if (interaction.openedAt === null) {
				interaction.openedAt = now();
			}
			if (card) {
				card.focus();
			}
			trackScroll();
			document.body.style.overflow = 'hidden';
		}

		function closeModal() {
			if (isFullscreen) {
				return; // Cannot dismiss full-screen blocker without accepting or logging out
			}
			overlay.classList.remove('wm-nda-active');
			overlay.setAttribute('aria-hidden', 'true');
			document.body.style.overflow = '';
			if (triggerBtn) {
				triggerBtn.focus();
			}
		}

		// Login Screen Open Trigger
		if (triggerBtn) {
			triggerBtn.addEventListener('click', function (e) {
				e.preventDefault();
				openModal();
			});
		}

		// Enable/Disable Accept button based on checkbox
		if (confirmCheckbox && acceptBtn) {
			confirmCheckbox.addEventListener('change', function () {
				var isChecked = confirmCheckbox.checked;
				acceptBtn.disabled = !isChecked;
				acceptBtn.setAttribute('aria-disabled', isChecked ? 'false' : 'true');
				interaction.checkedAt = isChecked ? now() : null;

				// Sync with login form checkbox if present
				var loginCheckbox = document.getElementById('wm_nda_accept');
				if (loginCheckbox) {
					loginCheckbox.checked = isChecked;
				}
			});
		}

		// The login checkbox can be ticked without ever opening the modal.
		var standaloneLoginCheckbox = document.getElementById('wm_nda_accept');
		if (standaloneLoginCheckbox) {
			standaloneLoginCheckbox.addEventListener('change', function () {
				interaction.checkedAt = standaloneLoginCheckbox.checked ? now() : null;
			});
		}

		// Keyboard Trapping & Escape Key Handler
		overlay.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !isFullscreen) {
				closeModal();
				return;
			}

			if (e.key === 'Tab') {
				var focusableElements = overlay.querySelectorAll(focusableSelectors);
				if (focusableElements.length === 0) {
					e.preventDefault();
					return;
				}

				var firstElement = focusableElements[0];
				var lastElement = focusableElements[focusableElements.length - 1];

				if (e.shiftKey) {
					// Shift + Tab
					if (document.activeElement === firstElement || document.activeElement === card) {
						e.preventDefault();
						lastElement.focus();
					}
				} else {
					// Tab
					if (document.activeElement === lastElement) {
						e.preventDefault();
						firstElement.focus();
					}
				}
			}
		});

		// Async Signature Submission Handler
		if (acceptBtn) {
			acceptBtn.addEventListener('click', function (e) {
				e.preventDefault();

				var termId = overlay.getAttribute('data-term-id');
				var restUrl = overlay.getAttribute('data-rest-url');
				var nonce = overlay.getAttribute('data-nonce');
				var userId = parseInt(overlay.getAttribute('data-user-id'), 10);

				trackScroll();

				// If on login screen with user_id = 0, just close modal and mark login checkbox as checked
				if (isNaN(userId) || userId <= 0) {
					var loginCheckbox = document.getElementById('wm_nda_accept');
					if (loginCheckbox) {
						loginCheckbox.checked = true;
					}
					closeModal();
					return;
				}

				// If authenticated (admin gate), submit signature via REST API
				acceptBtn.disabled = true;
				acceptBtn.classList.add('wm-nda-loading');
				statusMsg.className = 'wm-nda-status-message';
				statusMsg.textContent = '';

				fetch(restUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					body: JSON.stringify({
						term_id: parseInt(termId, 10),
						interaction: buildRecord()
					})
				})
				.then(function (res) {
					return res.json().then(function (data) {
						return { status: res.status, data: data };
					});
				})
				.then(function (result) {
					acceptBtn.classList.remove('wm-nda-loading');

					if (result.status === 200 && result.data.success) {
						statusMsg.className = 'wm-nda-status-message wm-status-success';
						statusMsg.textContent = message('success');

						// Reload page to grant full access
						setTimeout(function () {
							window.location.reload();
						}, 800);
					} else {
						acceptBtn.disabled = false;
						statusMsg.className = 'wm-nda-status-message wm-status-error';
						statusMsg.textContent = result.data.message || message('error');
					}
				})
				.catch(function (err) {
					acceptBtn.disabled = false;
					acceptBtn.classList.remove('wm-nda-loading');
					statusMsg.className = 'wm-nda-status-message wm-status-error';
					statusMsg.textContent = message('network') + ' ' + err.message;
				});
			});
		}
	});
})();
