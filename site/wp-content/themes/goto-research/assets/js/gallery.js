/*
 * Gallery: give every photo the same visible area.
 * A 4:3 landscape photo fills its column; narrower (portrait) photos are
 * shrunk so that width x height stays the same, and centred in the column.
 */
(function () {
	'use strict';
	var REF_RATIO = 4 / 3; // landscape reference (fills the column)

	function fit(img) {
		var w = img.naturalWidth, h = img.naturalHeight;
		if (!w || !h) { return; }
		var scale = Math.min(1, Math.sqrt((w / h) / REF_RATIO));
		var pct = (scale * 100).toFixed(2) + '%';
		img.style.width = pct;
		img.style.marginInline = 'auto';
		var fig = img.closest('figure');
		var cap = fig && fig.querySelector('figcaption');
		if (cap) {
			cap.style.width = pct;
			cap.style.left = ((100 - scale * 100) / 2).toFixed(2) + '%';
		}
	}

	function start() {
		var imgs = document.querySelectorAll('.wp-block-gallery figure.wp-block-image img');
		Array.prototype.forEach.call(imgs, function (img) {
			if (img.complete) { fit(img); } else { img.addEventListener('load', function () { fit(img); }); }
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
