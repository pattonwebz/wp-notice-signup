/**
 * Announcement bar modal.
 *
 * The previous version toggled a class and flipped aria-hidden. That produced
 * the classic home-grown-modal bug set: focus stayed behind the overlay,
 * Escape did nothing, and closing the dialog left focus on an element that was
 * no longer on screen.
 *
 * Only the aria-hidden part of that is detectable by axe-core. Everything else
 * here — focus moving in, staying in, and coming back out to where it started —
 * is invisible to automated testing and has to be built deliberately or
 * verified by hand. Worth saying out loud when this demo is on screen.
 */
(function () {
	var modal = document.querySelector('[data-wpns-modal]');
	var openButton = document.querySelector('[data-wpns-open-modal]');
	var closeButton = document.querySelector('[data-wpns-close-modal]');

	if (!modal || !openButton || !closeButton) {
		return;
	}

	var dialog = modal.querySelector('.wpns-modal-shell__dialog');
	var previouslyFocused = null;

	// When the aria_hidden_focus demo is switched on, the markup ships
	// aria-hidden instead of `hidden` and this flag makes the JS toggle the
	// same attribute. Keeping the two in step matters: a demo where the markup
	// is broken but the script quietly fixes it would show a violation that
	// disappears the moment anyone interacts, which is worse than no demo.
	var hideMode = modal.getAttribute('data-wpns-hide-mode') === 'aria' ? 'aria' : 'hidden';

	function setHidden(isHidden) {
		if (hideMode === 'aria') {
			// The broken pattern, on purpose: removed from the accessibility
			// tree, still in the tab order.
			modal.setAttribute('aria-hidden', isHidden ? 'true' : 'false');
			return;
		}

		modal.hidden = isHidden;
	}

	var FOCUSABLE = [
		'a[href]',
		'button:not([disabled])',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])'
	].join(',');

	function focusableItems() {
		return Array.prototype.filter.call(
			dialog.querySelectorAll(FOCUSABLE),
			function (el) {
				return el.offsetParent !== null;
			}
		);
	}

	function openModal() {
		// Remember where focus came from so it can be put back on close.
		// Without this, closing the dialog drops focus to <body> and a keyboard
		// user restarts from the top of the page.
		previouslyFocused = document.activeElement;

		setHidden(false);
		modal.classList.add('is-open');

		var items = focusableItems();
		if (items.length) {
			items[0].focus();
		}

		document.addEventListener('keydown', onKeydown);
	}

	function closeModal() {
		modal.classList.remove('is-open');

		// `hidden` rather than aria-hidden: it removes the dialog from the
		// accessibility tree AND the tab order. aria-hidden alone leaves the
		// children focusable, which is what axe reports as aria-hidden-focus.
		setHidden(true);

		document.removeEventListener('keydown', onKeydown);

		if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
			previouslyFocused.focus();
		}
	}

	function onKeydown(event) {
		if (event.key === 'Escape') {
			closeModal();
			return;
		}

		if (event.key !== 'Tab') {
			return;
		}

		// Keep Tab inside the dialog while it is open. A modal that lets focus
		// wander behind the overlay is only modal for mouse users.
		var items = focusableItems();
		if (!items.length) {
			return;
		}

		var first = items[0];
		var last = items[items.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	openButton.addEventListener('click', openModal);
	closeButton.addEventListener('click', closeModal);

	// Clicking the backdrop closes, matching the Escape behaviour above.
	modal.addEventListener('click', function (event) {
		if (event.target === modal) {
			closeModal();
		}
	});
})();
