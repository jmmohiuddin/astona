/* Accessible lightbox for the gallery page. Vanilla JS; links keep working as plain image links without it. */
(function () {
	'use strict';

	var links = Array.prototype.slice.call(document.querySelectorAll('[data-gallery-link]'));
	if (!links.length || typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') {
		return;
	}

	function el(tag, className, attrs) {
		var node = document.createElement(tag);
		node.className = className;
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		return node;
	}

	var dialog = el('dialog', 'lightbox', { 'aria-modal': 'true', 'aria-label': 'Image viewer' });
	var closeBtn = el('button', 'lightbox__btn lightbox__close', { type: 'button', 'aria-label': 'Close image viewer' });
	var prevBtn = el('button', 'lightbox__btn lightbox__prev', { type: 'button', 'aria-label': 'Previous image' });
	var nextBtn = el('button', 'lightbox__btn lightbox__next', { type: 'button', 'aria-label': 'Next image' });
	var figure = el('figure', 'lightbox__figure');
	var image = el('img', 'lightbox__img', { alt: '' });
	var caption = el('figcaption', 'lightbox__caption');
	closeBtn.textContent = '×';
	prevBtn.textContent = '‹';
	nextBtn.textContent = '›';
	figure.appendChild(image);
	figure.appendChild(caption);
	[closeBtn, prevBtn, figure, nextBtn].forEach(function (node) { dialog.appendChild(node); });
	document.body.appendChild(dialog);

	var current = [];
	var index = 0;
	var opener = null;

	function show(i) {
		index = (i + current.length) % current.length;
		var link = current[index];
		var thumb = link.querySelector('img');
		image.src = link.href;
		image.alt = thumb ? thumb.alt : '';
		if (link.dataset.width && link.dataset.height) {
			image.width = parseInt(link.dataset.width, 10);
			image.height = parseInt(link.dataset.height, 10);
		}
		caption.textContent = link.dataset.caption || '';
		caption.hidden = !link.dataset.caption;
	}

	function open(link) {
		var group = link.dataset.galleryGroup;
		current = links.filter(function (other) { return other.dataset.galleryGroup === group; });
		prevBtn.hidden = nextBtn.hidden = current.length < 2;
		opener = link;
		show(current.indexOf(link));
		dialog.showModal();
		document.documentElement.classList.add('lightbox-open');
		closeBtn.focus();
	}

	links.forEach(function (link) {
		link.addEventListener('click', function (event) {
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }
			event.preventDefault();
			open(link);
		});
	});

	closeBtn.addEventListener('click', function () { dialog.close(); });
	prevBtn.addEventListener('click', function () { show(index - 1); });
	nextBtn.addEventListener('click', function () { show(index + 1); });

	// A click on the backdrop lands on the dialog element itself; Esc is handled natively by showModal().
	dialog.addEventListener('click', function (event) {
		if (event.target === dialog) { dialog.close(); }
	});

	dialog.addEventListener('keydown', function (event) {
		if (current.length < 2) { return; }
		if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
		if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
	});

	dialog.addEventListener('close', function () {
		document.documentElement.classList.remove('lightbox-open');
		image.removeAttribute('src');
		if (opener) { opener.focus(); }
	});
}());
