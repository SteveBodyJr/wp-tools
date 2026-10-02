/**
 * Settings -> Beaver Press -> Pages: filter pages, tick pages and languages, add them to the run,
 * and follow the run (page it is on, pages done per language, cells refreshed).
 */
(function ($) {
	var $box = $('#bp-pages');
	if (!$box.length) return;
	var cfg = window.BP_RUN || {}, t = cfg.i18n || {};
	var $rows = $box.find('tbody tr');

	function fmt(n) { return (n || 0).toLocaleString(); }
	function post(action, data) { return $.post(cfg.ajax, $.extend({ action: action, nonce: cfg.nonce }, data || {})); }

	function filter() {
		var q = $.trim($box.find('.bp-pages__search').val()).toLowerCase();
		var type = $box.find('.bp-pages__type').val(), state = $box.find('.bp-pages__state').val(), shown = 0;
		$rows.each(function () {
			var $r = $(this), ok = true;
			if (q && $r.data('title').toString().indexOf(q) < 0 && $r.data('url').toString().toLowerCase().indexOf(q) < 0) ok = false;
			if (type && $r.data('type') !== type) ok = false;
			if (state) {
				var done = $r.find('.bp-cell--done').length === $r.find('.bp-pages__cell').length;
				if ((state === 'complete') !== done) ok = false;
			}
			$r.toggle(ok); if (ok) shown++;
		});
		$box.find('.bp-pages__count').text((t.shown || '%1$s / %2$s').replace('%1$s', fmt(shown)).replace('%2$s', fmt($rows.length)));
	}
	$box.on('input change', '.bp-pages__search, .bp-pages__type, .bp-pages__state', filter);
	$box.on('change', '.bp-pages__all', function () { $rows.filter(':visible').find('.bp-pages__pick').prop('checked', this.checked); });

	function languages() { return $box.find('.bp-pages__lang:checked').map(function () { return this.value; }).get(); }
	function add(urls, $btn) {
		var langs = languages(), $msg = $box.find('.bp-pages__msg');
		if (!urls.length || !langs.length) { $msg.text(t.pick); return; }
		$btn.prop('disabled', true);
		post('bp_run_pages', { urls: urls, languages: langs }).done(function (r) {
			if (r && r.success) {
				$msg.removeClass('bp-status--error').text(t.added);
				urls.forEach(function (u) { langs.forEach(function (l) { $rows.filter(function () { return $(this).data('url') === u; }).find('.bp-pages__cell[data-lang="' + l + '"]').addClass('is-queued'); }); });
				showProgress(r.data); step();
			} else { $msg.addClass('bp-status--error').text((r && r.data && r.data.message) || ''); }
		}).always(function () { $btn.prop('disabled', false); });
	}
	$box.on('click', '.bp-pages__go', function () {
		add($rows.filter(':visible').has('.bp-pages__pick:checked').map(function () { return $(this).data('url'); }).get(), $(this));
	});
	$box.on('click', '.bp-pages__one', function () { add([$(this).closest('tr').data('url')], $(this)); });

	// Draft translated addresses for the ticked pages.
	$box.on('click', '.bp-pages__draft', function () {
		var $btn = $(this), $msg = $box.find('.bp-pages__msg'), langs = languages();
		var urls = $rows.filter(':visible').has('.bp-pages__pick:checked').map(function () { return $(this).data('url'); }).get();
		if (!urls.length || !langs.length) { $msg.text(t.pick); return; }
		$btn.prop('disabled', true);
		post('bp_slugs_draft', { urls: urls, languages: langs, machine: 1 }).done(function (r) {
			$msg.toggleClass('bp-status--error', !(r && r.success)).text((r && r.data && r.data.message) || '');
		}).always(function () { $btn.prop('disabled', false); });
	});

	// Free progress check: ticked pages, or every page shown when none is ticked.
	$box.on('click', '.bp-pages__check', function () {
		var $btn = $(this), $msg = $box.find('.bp-pages__msg'), langs = languages();
		var $picked = $rows.filter(':visible').has('.bp-pages__pick:checked');
		var urls = ($picked.length ? $picked : $rows.filter(':visible')).map(function () { return $(this).data('url'); }).get();
		if (!urls.length || !langs.length) { $msg.text(t.pick); return; }
		$btn.prop('disabled', true);
		(function next(offset) {
			post('bp_pages_check', { urls: urls, languages: langs, offset: offset }).done(function (r) {
				if (!r || !r.success) { $btn.prop('disabled', false); return; }
				showMap(r.data.map || {}, r.data.texts);
				if (r.data.offset < r.data.total) {
					$msg.text(t.checking.replace('%1$s', fmt(r.data.offset)).replace('%2$s', fmt(r.data.total)));
					next(r.data.offset);
				} else { $msg.text(t.checked); $btn.prop('disabled', false); }
			}).fail(function () { $btn.prop('disabled', false); });
		})(0);
	});

	function cell(missing, href, texts) {
		var $a = $('<a target="_blank" rel="noopener">').attr('href', href);
		if (missing === undefined || missing === null) return $('<span class="bp-cell bp-cell--none">').text(t.notVisited);
		if (+missing === 0) return $a.addClass('bp-cell bp-cell--done').text('✓ ' + t.complete);
		var label = t.missing.replace('%s', fmt(+missing));
		if (!texts || !texts.length) return $a.addClass('bp-cell bp-cell--missing').text(label);
		var $ul = $('<ul>');
		$.each(texts, function (i, row) {
			var $li = $('<li>').text(row[0]).appendTo($ul);
			if (row[1]) { $li.append(' ', $('<span class="bp-miss__again">').text(t.again)); }
		});
		return $('<details class="bp-miss">').append($('<summary class="bp-cell bp-cell--missing">').text(label), $ul,
			$a.text(t.openPage), document.createTextNode(' · ' + t.useTranslate));
	}
	function showMap(map, texts) {
		texts = texts || {};
		$rows.each(function () {
			var key = $(this).data('key');
			$(this).find('.bp-pages__cell').each(function () {
				var $c = $(this), lang = $c.data('lang'), m = map[lang] ? map[lang][key] : undefined;
				$c.empty().append(cell(m, $c.data('href'), texts[lang] ? texts[lang][key] : null));
			});
		});
		filter();
	}
	function showProgress(p) {
		var $now = $box.find('.bp-pages__now').empty();
		$rows.removeClass('is-current');
		if (p && p.status === 'running') {
			var parts = [];
			$.each(p.per_language || {}, function (code, x) { parts.push((t.perLang || '%1$s %2$s / %3$s').replace('%1$s', x.name).replace('%2$s', fmt(x.done)).replace('%3$s', fmt(x.total))); });
			$now.append($('<p class="bp-pages__line">').text((t.running || '') + ' ' + fmt(p.pos) + ' / ' + fmt(p.total) + (parts.length ? ' · ' + parts.join(' · ') : '')));
			if (p.current) {
				$now.append($('<p>').text(t.now.replace('%1$s', p.current.url).replace('%2$s', p.current.name)));
				$rows.filter('[data-key="' + p.current.key + '"]').addClass('is-current');
			}
		} else if (p && p.status && p.status !== 'idle') {
			$now.append($('<p>').text((t[p.status] || p.status) + (p.message ? ': ' + p.message : '')));
		}
	}
	// Keep the run moving while this page is open (a lock stops double work), and refresh.
	var busy = false;
	function step() {
		if (busy) return; busy = true;
		post('bp_run_step').always(function () { busy = false; refresh(); });
	}
	function refresh() {
		post('bp_pages_status').done(function (r) {
			if (!r || !r.success) return;
			showMap(r.data.map || {}, r.data.texts); showProgress(r.data.progress);
			if (r.data.progress && r.data.progress.status === 'running') { setTimeout(step, 1500); }
		});
	}
	filter(); showProgress(cfg.progress || {});
	if (cfg.progress && cfg.progress.status === 'running') { step(); }
})(jQuery);
