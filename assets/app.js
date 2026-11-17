(function () {
	'use strict';
	var root = document.getElementById('spot-ai-root');
	if (!root || !window.SPOT_AI) { return; }
	var CFG = window.SPOT_AI;
	var REDIRECT = root.getAttribute('data-mode') === 'redirect';
	var PLACE = root.getAttribute('data-place') || '';        // 'home' | 'results' | '' (stand-alone)
	var STORE = 'spot_ai_state';
	var state = null;      // canonical criteria, owned by the server, echoed back each request
	var busy = false;

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') { n.className = attrs[k]; }
			else if (k === 'text') { n.textContent = attrs[k]; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), attrs[k]); }
			else { n.setAttribute(k, attrs[k]); }
		});
		(kids || []).forEach(function (c) { if (c) { n.appendChild(c); } });
		return n;
	}
	function saved() { try { return JSON.parse(sessionStorage.getItem(STORE) || 'null'); } catch (e) { return null; } }
	function save(v) { try { if (v) { sessionStorage.setItem(STORE, JSON.stringify(v)); } else { sessionStorage.removeItem(STORE); } } catch (e) { /* storage unavailable */ } }

	// Match the live site: take the accent from Houzez's own search button and the ink from its header.
	(function syncTheme() {
		function bg(sel) {
			var e = document.querySelector(sel);
			var c = e ? getComputedStyle(e).backgroundColor : '';
			return c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent' ? c : null;
		}
		// Works across themes: Houzez buttons first, then the theme's own primary colour variable, then any solid submit button.
		function cssVar(names) {
			var cs = getComputedStyle(document.documentElement);
			for (var i = 0; i < names.length; i++) {
				var v = cs.getPropertyValue(names[i]).trim();
				if (v) { return v; }
			}
			return null;
		}
		function solid(sel) {
			var c = bg(sel);
			return c && c !== 'rgb(255, 255, 255)' && c !== 'rgb(0, 0, 0)' ? c : null;
		}
		var accent = bg('.houzez-search-button') || bg('.btn-search') || bg('.btn-primary')
			|| cssVar(['--primary-color', '--primary', '--accent-color', '--theme-color', '--main-color', '--e-global-color-primary', '--wp--preset--color--primary'])
			|| solid('.button.border') || solid('input[type=submit]:not(.spot-ai__go)') || solid('a.button');
		var ink = bg('.header-desktop') || bg('.header-mobile');
		if (!ink) {   // Dark body text makes a good ink when the header is transparent.
			var bc = getComputedStyle(document.body).color.match(/\d+/g);
			if (bc && (+bc[0] + +bc[1] + +bc[2]) / 3 < 110) { ink = getComputedStyle(document.body).color; }
		}
		if (accent) { document.documentElement.style.setProperty('--spot-accent', accent); }
		if (ink) { document.documentElement.style.setProperty('--spot-ink', ink); }
	})();

	// Floating mode (every Houzez page): a button bottom-right reveals the search panel.
	var FLOAT = PLACE === 'float';
	var mount = root, panel = null, fab = null;
	var onResults = FLOAT && /[?&]spot_ai=1/.test(location.search);
	if (FLOAT) {
		root.hidden = false;
		panel = el('div', { class: 'spot-ai__panel', role: 'dialog', 'aria-label': 'AI property search', hidden: 'hidden' }, [
			el('button', { type: 'button', class: 'spot-ai__close', 'aria-label': 'Close AI search', onclick: function () { closePanel(true); } }, [el('span', { 'aria-hidden': 'true', text: '×' })])
		]);
		fab = el('button', { type: 'button', class: 'spot-ai__fab', 'aria-expanded': 'false', onclick: function () { if (panel.hidden) { openPanel(); } else { closePanel(true); } } }, [
			el('span', { 'aria-hidden': 'true', text: '✨' }), el('span', { text: ' AI Search' })
		]);
		root.appendChild(panel);
		root.appendChild(fab);
		mount = panel;
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { closePanel(true); } });
	}
	// Other plugins often park their own floating button bottom-right (chat, AI assistants...). If something sits on
	// top of ours, move ours up until it is clear.
	if (FLOAT) {
		var avoid = function () {
			var r = fab.getBoundingClientRect();
			var pts = [[r.left + 8, r.top + 8], [r.right - 8, r.top + 8], [r.left + 8, r.bottom - 8], [r.right - 8, r.bottom - 8], [r.left + r.width / 2, r.top + r.height / 2]];
			var hit = pts.some(function (p) { var e = document.elementFromPoint(p[0], p[1]); return e && !root.contains(e); });
			var b = parseInt(root.style.bottom, 10) || 20;
			if (hit && b < 300) { root.style.bottom = (b + 56) + 'px'; }
		};
		var tries = 0, iv = setInterval(function () { avoid(); if (++tries > 40) { clearInterval(iv); } }, 1000);   // other widgets can appear late
	}
	function openPanel() {
		if (!panel) { return; }
		panel.hidden = false;
		fab.setAttribute('aria-expanded', 'true');
		fab.classList.add('is-open');
		setTimeout(function () { input.focus(); }, 40);
	}
	function closePanel(refocus) {
		if (!panel) { return; }
		stopListening();
		panel.hidden = true;
		fab.setAttribute('aria-expanded', 'false');
		fab.classList.remove('is-open');
		if (refocus) { fab.focus(); }
	}
	if (onResults) {
		var s = saved();
		if (s && s.criteria) { state = s.criteria; }
	}

	// ---- static shell ---------------------------------------------------
	var input = el('input', { type: 'text', id: 'spot-ai-q', class: 'spot-ai__input', maxlength: '500', autocomplete: 'off',
		placeholder: onResults ? 'Change something, e.g. "make it 4 bedrooms"' : (CFG.placeholder || 'Describe your dream home…') });
	var micBtn = el('button', { type: 'button', class: 'spot-ai__mic', 'aria-label': 'Search by voice', title: 'Search by voice' }, [el('span', { 'aria-hidden': 'true', text: '🎤' })]);
	var goBtn = el('button', { type: 'submit', class: 'spot-ai__go', text: onResults ? 'Refine' : 'Search' });
	var micMsg = el('p', { class: 'spot-ai__hint', role: 'status', 'aria-live': 'polite' });
	var form = el('form', { class: 'spot-ai__form', role: 'search' }, [
		el('label', { for: 'spot-ai-q', class: FLOAT ? 'spot-ai__label spot-ai__label--sr' : 'spot-ai__label', text: onResults ? 'Refine your AI search' : 'Describe your ideal home' }),
		el('div', { class: 'spot-ai__row' }, [input, micBtn, goBtn])
	]);
	var examples = el('div', { class: 'spot-ai__examples' }, CFG.examples.map(function (t) {
		return el('button', { type: 'button', class: 'spot-ai__chip spot-ai__chip--ex', text: t, onclick: function () { input.value = t; submit(); } });
	}));
	var status = el('div', { class: 'spot-ai__status', role: 'status', 'aria-live': 'polite' });
	var chipsBox = el('div', { class: 'spot-ai__chips' });
	var resultsBox = el('div', { class: 'spot-ai__results' });
	var resetBtn = el('button', { type: 'button', class: 'spot-ai__link', text: 'New search', onclick: reset });

	if (!FLOAT) {   // stand-alone page only; the floating panel has no heading or intro text
		mount.appendChild(el('h2', { class: 'spot-ai__title', text: 'Find your home' }));
	}
	mount.appendChild(form);
	mount.appendChild(micMsg);
	if (!onResults) { mount.appendChild(examples); }
	mount.appendChild(status);
	mount.appendChild(chipsBox);
	mount.appendChild(resultsBox);
	if (!REDIRECT) { mount.appendChild(el('p', { class: 'spot-ai__fine', text: 'Demo notice: listings shown are fictional sample data, not available homes.' })); }

	form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });
	if (onResults) { var s0 = saved(); if (s0) { renderMeta(s0); } }

	// Houzez always starts its price slider at the full range, ignoring min-price/max-price in the URL.
	// Move the handles to the AI budget so the slider matches the search.
	if (onResults) {
		var qs = new URLSearchParams(location.search);
		var pMin = parseInt(qs.get('min-price'), 10), pMax = parseInt(qs.get('max-price'), 10);
		if (!isNaN(pMin) || !isNaN(pMax)) {
			window.addEventListener('load', function () {
				// Newer Houzez versions use two <input type="range"> fields instead of a jQuery UI slider.
				var fromI = document.querySelector('.hz-price-range-from'), toI = document.querySelector('.hz-price-range-to');
				if (fromI && toI) {
					var rlo = parseFloat(fromI.min) || 0, rhi = parseFloat(toI.max) || Infinity;
					var ra = isNaN(pMin) ? parseFloat(fromI.value) : Math.max(rlo, pMin), rb = isNaN(pMax) ? parseFloat(toI.value) : Math.min(rhi, pMax);
					fromI.value = ra; toI.value = rb;
					fromI.dispatchEvent(new Event('input', { bubbles: true }));
					toI.dispatchEvent(new Event('input', { bubbles: true }));
					Array.prototype.forEach.call(document.querySelectorAll('.min-price-range'), function (n) { n.textContent = '$' + ra.toLocaleString('en-US'); });
					Array.prototype.forEach.call(document.querySelectorAll('.max-price-range'), function (n) { n.textContent = '$' + rb.toLocaleString('en-US'); });
					return;
				}
				var $ = window.jQuery;
				if (!$ || !$.fn || !$.fn.slider) { return; }
				var s = $('.price-range');
				if (!s.length || !s.hasClass('ui-slider')) { return; }
				var lo = s.slider('option', 'min'), hi = s.slider('option', 'max');
				var a = isNaN(pMin) ? lo : Math.max(lo, pMin), b = isNaN(pMax) ? hi : Math.min(hi, pMax);
				s.slider('values', [a, b]);
				$('.min-price-range-hidden').val(a);
				$('.max-price-range-hidden').val(b);
				$('.min-price-range').text('$' + a.toLocaleString('en-US'));
				$('.max-price-range').text('$' + b.toLocaleString('en-US'));
			});
		}
	}

	// On the Houzez results page the AI criteria ride along as hidden spot_* fields. As soon as the
	// visitor edits a Houzez filter themselves, drop them so Houzez's own filters take over.
	if (onResults) {
		var spotFlag = document.querySelector('input[type=hidden][name=spot_ai]');
		var hform = spotFlag && spotFlag.form;
		if (hform) {
			var lastTouch = 0, released = false;
			var release = function () {
				if (released || Date.now() - lastTouch > 4000) { return; }
				released = true;
				Array.prototype.slice.call(hform.querySelectorAll('input[type=hidden][name^=spot_]')).forEach(function (n) { n.parentNode.removeChild(n); });
				state = null; save(null);
				try {   // a reload must not bring the AI criteria back
					var u = new URL(location.href);
					Array.from(u.searchParams.keys()).filter(function (k) { return k.indexOf('spot_') === 0; }).forEach(function (k) { u.searchParams.delete(k); });
					history.replaceState(null, '', u.toString());
				} catch (e) { /* ignore */ }
				chipsBox.textContent = '';
				status.textContent = '';
				status.appendChild(el('p', { class: 'spot-ai__note', text: 'Filters changed: your selection is now used.' }));
			};
			['mousedown', 'keydown', 'touchstart'].forEach(function (ev) { hform.addEventListener(ev, function () { lastTouch = Date.now(); }, true); });
			hform.addEventListener('change', release, true);
			if (window.jQuery) { window.jQuery(hform).on('changed.bs.select change slidestop', release); }
		}
	}

	// Add an "AI Search" button next to the Houzez search button(s) so it lives with the existing search.
	// Placed under the form (not inside its narrow submit column) so it never squeezes the layout.
	if (FLOAT && !onResults) {
		Array.prototype.forEach.call(document.querySelectorAll('form.houzez-search-form-js'), function (f) {
			if (f.querySelector('.spot-ai__inline-btn')) { return; }
			var ai = el('button', { type: 'button', class: 'spot-ai__inline-btn', onclick: openPanel }, [el('span', { 'aria-hidden': 'true', text: '✨' }), el('span', { text: 'Or describe your dream home with AI Search' })]);
			(f.querySelector('.elementor-form-fields-wrapper') || f).appendChild(ai);   // inside the white search card
		});
	}

	// Coming back (browser Back) restores this page from memory: start clean instead of piling onto the last search.
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted || onResults) { return; }
		state = null; busy = false; goBtn.disabled = false;
		chipsBox.textContent = ''; resultsBox.textContent = ''; status.textContent = ''; input.value = '';
		if (FLOAT) { closePanel(false); }
	});

	// ---- API ------------------------------------------------------------
	// The nonce is fetched fresh (never baked into a cached page), and refreshed once if the server says it expired.
	var nonce = null;
	function getNonce(force) {
		if (nonce && !force) { return Promise.resolve(nonce); }
		return fetch(CFG.restUrl + 'nonce?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
			.then(function (r) { return r.json(); })
			.then(function (j) { nonce = j.nonce; return nonce; });
	}
	function api(path, payload, retried) {
		return getNonce(!!retried).then(function (n) {
			return fetch(CFG.restUrl + path, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': n },
				body: JSON.stringify(payload)
			});
		}).then(function (r) {
			return r.json().then(function (j) {
				if (r.status === 403 && !retried) { return api(path, payload, true); }
				if (!r.ok) { throw new Error(j && j.message ? j.message : 'Request failed'); }
				return j;
			}, function () { throw new Error('Unexpected server response.'); });
		});
	}

	function send(payload) {
		if (busy) { return; }
		stopListening();
		busy = true;
		goBtn.disabled = true;
		status.className = 'spot-ai__status';
		status.textContent = 'Searching…';
		payload.criteria = state;
		api('search', payload).then(function (res) {
			state = res.criteria;
			save({ criteria: res.criteria, chips: res.chips, notes: res.notes });
			render(res);
		}).catch(function (err) {
			status.className = 'spot-ai__status spot-ai__status--error';
			status.textContent = err.message + ' Please try again.';
		}).then(function () { busy = false; goBtn.disabled = false; });
	}

	function submit() {
		var q = input.value.trim();
		if (!q) { input.focus(); return; }
		send({ message: q });
		input.value = '';
	}

	function reset() {
		state = null; save(null);
		if (onResults) { location.href = CFG.resultsUrl; return; }
		chipsBox.textContent = ''; resultsBox.textContent = ''; status.textContent = '';
		input.value = ''; input.focus();
	}

	// ---- rendering ------------------------------------------------------
	function renderMeta(res) {
		chipsBox.textContent = '';
		if (res.chips && res.chips.length) {
			chipsBox.appendChild(el('span', { class: 'spot-ai__chips-label', text: 'AI search:' }));
			res.chips.forEach(function (c) {
				chipsBox.appendChild(el('button', { type: 'button', class: 'spot-ai__chip', 'aria-label': 'Remove filter ' + c.label, onclick: function () { send({ patch: c.patch }); } }, [
					el('span', { text: c.label }), el('span', { 'aria-hidden': 'true', text: ' ×' })
				]));
			});
			chipsBox.appendChild(resetBtn);
		}
		if (onResults) {
			status.textContent = '';
			(res.notes || []).forEach(function (n) { status.appendChild(el('p', { class: 'spot-ai__note', text: n })); });
		}
	}

	function render(res) {
		status.className = 'spot-ai__status';
		status.textContent = '';
		if (res.interpreter === 'fallback') {
			status.appendChild(el('p', { class: 'spot-ai__banner', text: res.ai_error === 'not_configured'
				? 'AI interpretation is not configured on this server, so a basic keyword parser was used. Results may miss nuance.'
				: 'The AI service is unavailable right now, so a basic keyword parser was used instead.' }));
		}
		if (res.clarification) { status.appendChild(el('p', { class: 'spot-ai__ask', text: res.clarification })); }
		(res.notes || []).forEach(function (n) { status.appendChild(el('p', { class: 'spot-ai__note', text: n })); });
		(res.warnings || []).forEach(function (n) { status.appendChild(el('p', { class: 'spot-ai__note', text: n })); });
		renderMeta(res);
		if (onResults) { (res.notes || []).forEach(function () {}); }

		resultsBox.textContent = '';
		if (res.intent === 'sell' && res.seller_cta) {
			resultsBox.appendChild(el('aside', { class: 'spot-ai__seller' }, [
				el('strong', { text: 'Thinking about selling your home?' }),
				el('p', { text: res.seller_cta.text || 'See how much you could keep when you sell.' }),
				el('a', { class: 'spot-ai__btn', href: res.seller_cta.url, target: '_blank', rel: 'noopener', text: res.seller_cta.label })
			]));
		}
		if (res.intent === 'other' || res.clarification) { return; }
		if (res.intent === 'sell') {
			// A seller message is not a buyer search: show only the seller route (plus a link back to any earlier search).
			if (res.total && res.chips && res.chips.length) {
				resultsBox.appendChild(el('a', { class: 'spot-ai__btn spot-ai__btn--ghost', href: res.results_url, text: 'View my earlier search (' + res.total + ' ' + (res.noun ? res.noun[1] : 'homes') + ')' }));
			}
			return;
		}

		if (!res.total) {
			// "Reality check": say what could not be found, then offer concrete trade-offs with exact counts.
			var comp = res.compromise;
			var box = el('div', { class: 'spot-ai__empty' });
			box.appendChild(el('p', { class: 'spot-ai__headline', text: comp && comp.headline ? comp.headline : 'No exact match.' }));
			if (comp && comp.options && comp.options.length) {
				if (comp.question) { box.appendChild(el('p', { class: 'spot-ai__question', text: comp.question })); }
				comp.options.forEach(function (o) {
					box.appendChild(el('button', { type: 'button', class: 'spot-ai__opt', onclick: function () { send({ patch: o.patch }); } }, [
						el('span', { class: 'spot-ai__opt-label', text: o.label }),
						el('span', { class: 'spot-ai__opt-count', text: o.count + ' ' + (res.noun ? res.noun[o.count === 1 ? 0 : 1] : (o.count === 1 ? 'home' : 'homes')) })
					]));
				});
			} else {
				box.appendChild(el('p', { class: 'spot-ai__question', text: 'Nothing is close. Remove a filter or start a new search.' }));
			}
			resultsBox.appendChild(box);
			return;
		}

		if (REDIRECT && !(res.chips && res.chips.length)) {
			// Nothing usable was understood: stay here so the explanation above is not lost.
			status.appendChild(el('p', { class: 'spot-ai__ask', text: 'Add a place, price or number of bedrooms.' }));
			return;
		}

		if (REDIRECT && res.results_mode === 'inline') {
			renderInline(res);
			return;
		}

		if (REDIRECT) {
			// Show the matches on the stock Houzez results page.
			if (res.intent === 'sell') {
				resultsBox.appendChild(el('a', { class: 'spot-ai__btn spot-ai__btn--ghost', href: res.results_url, text: 'View ' + res.total + ' matching homes' }));
				return;
			}
			if (res.total === 1 && res.results[0] && res.results[0].url) {
				status.appendChild(el('p', { class: 'spot-ai__note', text: 'Opening the property…' }));
				state = null; location.href = res.results[0].url;   // a single match goes straight to its detail page
				return;
			}
			status.appendChild(el('p', { class: 'spot-ai__note', text: res.total + ' ' + (res.noun ? res.noun[1] : 'homes') + ' found…' }));
			state = null; location.href = res.results_url;
			return;
		}

		resultsBox.appendChild(el('p', { class: 'spot-ai__count', text: res.total + (res.total === 1 ? ' home' : ' homes') + (res.total > res.results.length ? ' (showing ' + res.results.length + ')' : '') }));
		var grid = el('div', { class: 'spot-ai__grid' });
		res.results.forEach(function (p) { grid.appendChild(card(p)); });
		resultsBox.appendChild(grid);
	}

	// Compact result list inside the floating panel (used when the theme has no results page we can hook into).
	function renderInline(res) {
		var noun = res.noun || ['home', 'homes'];
		resultsBox.appendChild(el('p', { class: 'spot-ai__count', text: res.total + ' ' + noun[res.total === 1 ? 0 : 1] + (res.total > res.results.length ? ' (showing ' + res.results.length + ')' : '') }));
		var list = el('div', { class: 'spot-ai__list' });
		res.results.forEach(function (p) {
			var meta = [p.type, p.city].filter(Boolean).join(' · ');
			var facts = [];
			if (p.beds) { facts.push(p.beds + ' bd'); }
			if (p.baths) { facts.push(p.baths + ' ba'); }
			var body = [el('a', { class: 'spot-ai__item-title', href: p.url, text: p.title })];
			if (p.price) { body.push(el('span', { class: 'spot-ai__item-price', text: p.price })); }
			if (meta || facts.length) { body.push(el('span', { class: 'spot-ai__item-meta', text: [meta, facts.join(' · ')].filter(Boolean).join(' · ') })); }
			if (p.matched && p.matched.length) { body.push(el('span', { class: 'spot-ai__item-ok', text: '✓ ' + p.matched.join(', ') })); }
			list.appendChild(el('article', { class: 'spot-ai__item' }, [
				p.image ? el('a', { class: 'spot-ai__item-img', href: p.url, tabindex: '-1', 'aria-hidden': 'true' }, [el('img', { src: p.image, alt: '', loading: 'lazy' })]) : el('span', { class: 'spot-ai__item-img spot-ai__item-img--none', 'aria-hidden': 'true' }),
				el('div', { class: 'spot-ai__item-body' }, body)
			]));
		});
		resultsBox.appendChild(list);
	}

	function card(p) {
		var media = p.image ? el('img', { src: p.image, alt: p.title, loading: 'lazy' }) : el('div', { class: 'spot-ai__ph', 'aria-hidden': 'true', text: p.type || 'Home' });
		var facts = [p.beds + ' bd', p.baths + ' ba'];
		if (p.sqft) { facts.push(p.sqft.toLocaleString() + ' sq ft'); }
		var body = [
			el('p', { class: 'spot-ai__price', text: p.price }),
			el('p', { class: 'spot-ai__facts', text: facts.join(' · ') + (p.type ? ' · ' + p.type : '') }),
			el('p', { class: 'spot-ai__addr', text: [p.address || p.city, p.address ? '' : p.state].filter(Boolean).join(', ') })
		];
		if (p.matched.length) { body.push(el('ul', { class: 'spot-ai__match' }, p.matched.map(function (m) { return el('li', { text: '✓ ' + m }); }))); }
		if (p.missed.length) { body.push(el('p', { class: 'spot-ai__miss', text: 'Not listed: ' + p.missed.join(', ') })); }
		body.push(el('div', { class: 'spot-ai__actions' }, [
			el('a', { class: 'spot-ai__btn', href: p.url, text: 'View details' }),
			el('button', { type: 'button', class: 'spot-ai__btn spot-ai__btn--ghost', text: 'Request a showing', onclick: function () { openInquiry(p, 'showing'); } })
		]));
		return el('article', { class: 'spot-ai__card' }, [
			el('div', { class: 'spot-ai__media' }, [media, p.is_sample ? el('span', { class: 'spot-ai__tag', text: 'Sample data' }) : null]),
			el('div', { class: 'spot-ai__body' }, body)
		]);
	}

	// ---- inquiry dialog (stand-alone mode) --------------------------------
	function openInquiry(p, action) {
		var prev = document.activeElement;
		var name = el('input', { type: 'text', id: 'spot-ai-n', required: 'required', autocomplete: 'name' });
		var email = el('input', { type: 'email', id: 'spot-ai-e', required: 'required', autocomplete: 'email' });
		var phone = el('input', { type: 'tel', id: 'spot-ai-p', autocomplete: 'tel' });
		var msg = el('textarea', { id: 'spot-ai-m', rows: '3', maxlength: '1000' });
		var hp = el('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'spot-ai__hp', 'aria-hidden': 'true' });
		var consent = el('input', { type: 'checkbox', id: 'spot-ai-c', required: 'required' });
		var err = el('p', { class: 'spot-ai__status spot-ai__status--error', role: 'alert' });
		var submitBtn = el('button', { type: 'submit', class: 'spot-ai__btn', text: 'Send request' });
		var dlg = el('dialog', { class: 'spot-ai__dialog', 'aria-labelledby': 'spot-ai-dt' });
		var f = el('form', { method: 'dialog' }, [
			el('h3', { id: 'spot-ai-dt', text: (action === 'showing' ? 'Request a showing' : 'Ask about this home') }),
			el('p', { class: 'spot-ai__facts', text: p.title + ' · ' + p.price }),
			el('label', { for: 'spot-ai-n', text: 'Name' }), name,
			el('label', { for: 'spot-ai-e', text: 'Email' }), email,
			el('label', { for: 'spot-ai-p', text: 'Phone (optional)' }), phone,
			el('label', { for: 'spot-ai-m', text: 'Message (optional)' }), msg, hp,
			el('label', { class: 'spot-ai__consent', for: 'spot-ai-c' }, [consent, el('span', { text: ' I agree to be contacted about this request. Demo: your request is stored locally and nothing is emailed to you.' })]),
			err,
			el('div', { class: 'spot-ai__actions' }, [submitBtn, el('button', { type: 'button', class: 'spot-ai__btn spot-ai__btn--ghost', text: 'Cancel', onclick: function () { dlg.close(); } })])
		]);
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			err.textContent = '';
			submitBtn.disabled = true;
			api('inquiry', { action: action, property_id: p.id, name: name.value, email: email.value, phone: phone.value, message: msg.value,
				website: hp.value, consent: consent.checked === true, criteria: state, source_url: location.href })
				.then(function (r) {
					f.textContent = '';
					f.appendChild(el('p', { text: r.message }));
					f.appendChild(el('button', { type: 'button', class: 'spot-ai__btn', text: 'Close', onclick: function () { dlg.close(); } }));
				})
				.catch(function (e2) { err.textContent = e2.message; submitBtn.disabled = false; });
		});
		dlg.appendChild(f);
		dlg.addEventListener('close', function () { dlg.remove(); if (prev && prev.focus) { prev.focus(); } });
		document.body.appendChild(dlg);
		if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
		name.focus();
	}

	// ---- voice -----------------------------------------------------------
	// Browser recognition ends itself after a short pause. We keep it alive (continuous +
	// automatic restart) and accumulate the transcript until the user taps the mic again,
	// presses Search, or ~15 s pass with no speech.
	var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
	var rec = null, wantListening = false, finalText = '', idleTimer = null, restarts = 0;

	function stopListening(msg) {
		wantListening = false;
		clearTimeout(idleTimer);
		micBtn.classList.remove('is-on');
		micBtn.setAttribute('aria-pressed', 'false');
		micBtn.setAttribute('aria-label', 'Search by voice');
		if (rec) { try { rec.stop(); } catch (e) { /* already stopped */ } }
		if (typeof msg === 'string') { micMsg.textContent = msg; }
	}
	function armIdle() {
		clearTimeout(idleTimer);
		idleTimer = setTimeout(function () { stopListening('Stopped listening. Review the text, then press Search.'); input.focus(); }, 15000);
	}
	function startRec() {
		rec = new SR();
		rec.lang = 'en-US'; rec.continuous = true; rec.interimResults = true; rec.maxAlternatives = 1;
		rec.onresult = function (e) {
			var interim = '';
			for (var i = e.resultIndex; i < e.results.length; i++) {
				var t = e.results[i][0].transcript;
				if (e.results[i].isFinal) { finalText += (finalText && !/\s$/.test(finalText) ? ' ' : '') + t.trim(); } else { interim += t; }
			}
			input.value = (finalText + (interim ? ' ' + interim : '')).trim();
			restarts = 0;
			armIdle();
		};
		rec.onerror = function (e) {
			if (e.error === 'no-speech' || e.error === 'aborted') { return; } // handled by onend restart
			var m = { 'not-allowed': 'Microphone permission was denied. Allow it in your browser settings to use voice.', 'service-not-allowed': 'Microphone permission was denied.', 'audio-capture': 'No microphone was found.', 'network': 'Voice recognition needs a network connection.' };
			stopListening(m[e.error] || 'Voice input failed. You can type your request instead.');
		};
		rec.onend = function () {
			if (!wantListening) { micMsg.textContent = micMsg.textContent || (input.value ? 'Review or edit the text, then press Search.' : ''); return; }
			if (++restarts > 20) { stopListening('Stopped listening.'); return; }
			setTimeout(function () { if (wantListening) { try { startRec(); } catch (x) { stopListening('Voice input could not restart.'); } } }, 150);
		};
		rec.start();
	}
	if (!SR) {
		micBtn.disabled = true;
		micBtn.title = 'Voice input is not supported in this browser (try Chrome, Edge or Safari).';
		micBtn.setAttribute('aria-label', micBtn.title);
	} else {
		micBtn.setAttribute('aria-pressed', 'false');
		micBtn.addEventListener('click', function () {
			if (wantListening) { stopListening(input.value ? 'Review or edit the text, then press Search.' : ''); input.focus(); return; }
			finalText = input.value.trim(); restarts = 0; wantListening = true;
			micBtn.classList.add('is-on');
			micBtn.setAttribute('aria-pressed', 'true');
			micBtn.setAttribute('aria-label', 'Stop listening');
			micMsg.textContent = 'Listening… keep talking, tap the mic again when done.';
			try { startRec(); armIdle(); } catch (x) { stopListening('Voice input could not start.'); }
		});
	}
})();
