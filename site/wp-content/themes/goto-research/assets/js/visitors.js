/* GoatCounter page views + visitor total. */
(function () {
	'use strict';
	var cfg = window.gotoVisitors || {};
	if (!cfg.code) { return; }
	var base = 'https://' + cfg.code + '.goatcounter.com';

	// Count page views only on the public site, not on the Local copy.
	if (location.hostname === cfg.publicHost) {
		var s = document.createElement('script');
		s.async = true;
		s.src = 'https://gc.zgo.at/count.js';
		s.setAttribute('data-goatcounter', base + '/count');
		document.head.appendChild(s);
	}

	// Show the site-wide total wherever [goto_visitors] was placed.
	var boxes = document.querySelectorAll('.visitor-count');
	if (!boxes.length || !window.fetch) { return; }
	fetch(base + '/counter/TOTAL.json')
		.then(function (r) { return r.ok ? r.json() : null; })
		.then(function (data) {
			if (!data || data.count == null) { return; }
			Array.prototype.forEach.call(boxes, function (box) {
				box.querySelector('.visitor-count__num').textContent = String(data.count).replace(/\s/g, ',');
				box.hidden = false;
			});
		})
		.catch(function () { /* leave hidden */ });
})();
