/* Osaka clock + calendar for the front page. */
(function () {
	'use strict';

	var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
	var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

	function partsIn(tz, date) {
		var out = {};
		new Intl.DateTimeFormat('en-US', {
			timeZone: tz, hourCycle: 'h23',
			year: 'numeric', month: 'numeric', day: 'numeric',
			hour: 'numeric', minute: 'numeric', second: 'numeric'
		}).formatToParts(date).forEach(function (p) {
			if (p.type !== 'literal') { out[p.type] = parseInt(p.value, 10); }
		});
		return out;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function renderCalendar(el, y, m, d) {
		var first = new Date(Date.UTC(y, m - 1, 1)).getUTCDay();
		var last = new Date(Date.UTC(y, m, 0)).getUTCDate();
		var html = '<p class="goto-cal__title">' + MONTHS[m - 1] + ' ' + y + '</p><table class="goto-cal__grid"><thead><tr>';
		DAYS.forEach(function (w, i) {
			html += '<th class="' + (i === 0 ? 'sun' : i === 6 ? 'sat' : '') + '">' + w + '</th>';
		});
		html += '</tr></thead><tbody><tr>';
		var col = 0, i;
		for (i = 0; i < first; i++, col++) { html += '<td></td>'; }
		for (i = 1; i <= last; i++, col++) {
			if (col > 0 && col % 7 === 0) { html += '</tr><tr>'; }
			var cls = [];
			if (col % 7 === 0) { cls.push('sun'); }
			if (col % 7 === 6) { cls.push('sat'); }
			if (i === d) { cls.push('today'); }
			html += '<td' + (cls.length ? ' class="' + cls.join(' ') + '"' : '') + (i === d ? ' aria-current="date"' : '') + '>' + i + '</td>';
		}
		while (col % 7 !== 0) { html += '<td></td>'; col++; }
		el.innerHTML = html + '</tr></tbody></table>';
	}

	function init(root) {
		var tz = root.getAttribute('data-tz') || 'Asia/Tokyo';
		var offset = root.getAttribute('data-offset') || '';
		var h = root.querySelector('.hand--h');
		var mi = root.querySelector('.hand--m');
		var s = root.querySelector('.hand--s');
		var time = root.querySelector('.goto-clock__time');
		var cal = root.querySelector('.goto-cal');
		var shownDay = '';

		function tick() {
			var now = new Date();
			var p = partsIn(tz, now);
			var sec = p.second + now.getMilliseconds() / 1000;
			h.setAttribute('transform', 'rotate(' + ((p.hour % 12) * 30 + p.minute * 0.5) + ' 100 100)');
			mi.setAttribute('transform', 'rotate(' + (p.minute * 6 + p.second * 0.1) + ' 100 100)');
			s.setAttribute('transform', 'rotate(' + (Math.floor(sec) * 6) + ' 100 100)');
			time.textContent = pad(p.hour) + ':' + pad(p.minute) + ':' + pad(p.second) + (offset ? ' (' + offset + ')' : '');
			var key = p.year + '-' + p.month + '-' + p.day;
			if (key !== shownDay) {
				shownDay = key;
				renderCalendar(cal, p.year, p.month, p.day);
			}
			setTimeout(tick, 1000 - now.getMilliseconds() + 5);
		}
		tick();
	}

	function start() {
		Array.prototype.forEach.call(document.querySelectorAll('.goto-clock'), init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
