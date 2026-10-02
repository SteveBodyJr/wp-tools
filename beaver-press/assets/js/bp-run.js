/**
 * Settings -> Beaver Press: drive the Translate-site run while the page is open.
 * Each step fetches pages for up to ~20 s; WP-Cron continues if the page is closed.
 */
(function ($) {
	var cfg = window.BP_RUN || {};
	var $box = $('#bp-run');
	if (!$box.length) return;
	var looping = false;
	var t = cfg.i18n;

	function fmt(n) { return (n || 0).toLocaleString(); }

	function render(p) {
		var status = p.status || 'idle';
		var total = p.total || 0, pos = p.pos || 0;
		$box.find('.bp-progress__status').text(t[status] || status).attr('data-status', status);
		$box.find('.bp-bar span').css('width', total ? Math.round(pos / total * 100) + '%' : '0');
		$box.find('.bp-progress__pages').text(total ? fmt(pos) + ' / ' + fmt(total) + ' ' + t.pages : '');
		var list = $box.find('.bp-progress__strings').empty();
		(p.strings || []).forEach(function (s) {
			list.append($('<li>').text(s.name + ': ' + fmt(s.done) + ' / ' + fmt(s.total) + ' ' + t.strings));
		});
		var u = p.usage || {};
		var usage = '';
		if (u.requests) {
			usage = t.usage.replace('%1$s', fmt(u.requests)).replace('%2$s', fmt(u.chars)).replace('%3$s', fmt(u.in)).replace('%4$s', fmt(u.out));
			if (u.priced && u.cost > 0) { usage += ', ~$' + (u.cost < 0.01 ? '0.01' : u.cost.toFixed(2)); }
			usage += '.';
		}
		$box.find('.bp-progress__usage').text(usage);
		var msg = p.message || '';
		if (p.rejected) { msg += (msg ? ' ' : '') + fmt(p.rejected) + ' ' + t.rejected + '.'; }
		if (status === 'running') { msg += (msg ? ' ' : '') + t.closed; }
		$box.find('.bp-progress__message').text(msg).toggleClass('bp-status--error', status === 'error');
		var failed = p.failed || [];
		var $f = $box.find('.bp-progress__failed').prop('hidden', !failed.length);
		$f.find('summary').text(t.failed + ' (' + failed.length + ')');
		var $ul = $f.find('ul').empty();
		failed.forEach(function (f) { $ul.append($('<li>').text(f.url + ' - ' + f.reason)); });

		$('#bp-run-start').toggle(status !== 'running' && status !== 'paused' && status !== 'error');
		$('#bp-run-pause').toggle(status === 'running');
		$('#bp-run-resume').toggle(status === 'paused' || status === 'error');
		$('#bp-run-cancel').toggle(status !== 'idle');
		$box.find('.bp-langs, .bp-estimate, .bp-estimate + .description, .bp-budget').toggle(status !== 'running' && status !== 'paused' && status !== 'error');
		if (status === 'running') { loop(); }
	}

	function post(action, data) {
		return $.post(cfg.ajax, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
	}

	function loop() {
		if (looping) return;
		looping = true;
		(function next() {
			post('bp_run_step').done(function (r) {
				looping = false;
				if (r && r.success) { render(r.data); } // render() calls loop() again while running
			}).fail(function () {
				$box.find('.bp-progress__message').text(t.network);
				setTimeout(function () { looping = false; loop(); }, 5000);
			});
		})();
	}

	$('#bp-run-start').on('click', function () {
		var langs = $box.find('.bp-langs input:checked').map(function () { return this.value; }).get();
		var $b = $(this).prop('disabled', true);
		post('bp_run_start', { languages: langs, budget: $('#bp-run-budget').val() || 0 }).done(function (r) {
			if (r && r.success) { render(r.data); }
			else { $box.find('.bp-progress__message').addClass('bp-status--error').text((r && r.data && r.data.message) || ''); }
		}).always(function () { $b.prop('disabled', false); });
	});

	function control(action) {
		post('bp_run_control', { run_action: action }).done(function (r) { if (r && r.success) { render(r.data); } });
	}
	$('#bp-run-pause').on('click', function () { control('pause'); });
	$('#bp-run-resume').on('click', function () { control('resume'); });
	$('#bp-run-cancel').on('click', function () { if (window.confirm(t.confirm)) { control('cancel'); } });

	render(cfg.progress || {});
})(jQuery);

/* "Prepare forms" card: open form pages with each result in each language, a slice at a time. */
(function ($) {
	var cfg = window.BP_RUN || {}, t = cfg.i18n || {};
	$('#bp-forms').on('click', '.bp-forms__go', function () {
		var $btn = $(this).prop('disabled', true), $msg = $('#bp-forms .bp-forms__msg').removeClass('bp-status--error');
		(function next(offset) {
			$.post(cfg.ajax, { action: 'bp_forms_prepare', nonce: cfg.nonce, offset: offset }).done(function (r) {
				if (!r || !r.success) { $msg.addClass('bp-status--error').text((r && r.data && r.data.message) || ''); $btn.prop('disabled', false); return; }
				if (r.data.offset < r.data.total) {
					$msg.text((t.preparing || '%1$s / %2$s').replace('%1$s', r.data.offset.toLocaleString()).replace('%2$s', r.data.total.toLocaleString()));
					next(r.data.offset);
				} else { $msg.text(t.prepared); $btn.prop('disabled', false); }
			}).fail(function () { $btn.prop('disabled', false); });
		})(0);
	});
})(jQuery);
