/**
 * Settings -> Beaver Press -> Instructions: Try it (unsaved instructions) and Redo a language.
 */
(function ($) {
	var cfg = window.BP_INS || {}, t = cfg.i18n || {};
	var $try = $('#bp-ins-try');
	if (!$try.length) return;
	function form() {
		var data = {};
		$('#bp-ins textarea[name^="bp_ins"]').each(function () { data[this.name] = this.value; });
		return data;
	}
	$try.on('click', '.bp-ins-try__go', function () {
		var $b = $(this).prop('disabled', true), $msg = $try.find('.bp-ins-try__msg').removeClass('bp-status--error').text(t.working);
		$.post(cfg.ajax, $.extend({ action: 'bp_try_instructions', nonce: cfg.nonce, language: $try.find('.bp-ins-try__lang').val(), text: $try.find('.bp-ins-try__text').val() }, form()))
			.done(function (r) {
				if (r && r.success) { $try.find('.bp-ins-try__out').prop('hidden', false).text(r.data.translation); $msg.text(t.took.replace('%s', r.data.seconds)); }
				else { $msg.addClass('bp-status--error').text((r && r.data && r.data.message) || t.failed); }
			}).fail(function () { $msg.addClass('bp-status--error').text(t.failed); })
			.always(function () { $b.prop('disabled', false); });
	});
	$('.bp-ins-redo').on('click', '.bp-ins-redo__go', function () {
		var $b = $(this);
		if (!window.confirm($b.data('confirm'))) return;
		$b.prop('disabled', true);
		$.post(cfg.ajax, { action: 'bp_redo_language', nonce: cfg.nonce, language: $b.data('language') }).done(function (r) {
			$('.bp-ins-redo__msg').toggleClass('bp-status--error', !(r && r.success)).text((r && r.data && r.data.message) || t.failed);
		});
	});
})(jQuery);

/* Names to keep: search, tick groups, AI suggestions, find missed names. */
(function ($) {
	var $card = $('#bp-names');
	if (!$card.length) return;
	var cfg = window.BP_INS || {}, t = cfg.i18n || {}, $msg = $card.find('.bp-names__msg');
	function say(text, error) { $msg.toggleClass('bp-status--error', !!error).text(text || ''); }

	$card.on('click', '.bp-names__all, .bp-names__none', function () {
		$(this).closest('.bp-names__group').find('.bp-names__list input').prop('checked', $(this).hasClass('bp-names__all'));
	});

	// Search: show matching names, open their groups; tick or untick what is shown.
	var $groups = $card.find('.bp-names__group');
	$card.on('input', '.bp-names__search', function () {
		var q = $.trim(this.value).toLowerCase();
		$card.find('.bp-names__shown').prop('hidden', !q);
		$groups.each(function () {
			var $g = $(this), hits = 0;
			$g.find('.bp-names__list label').each(function () {
				var hit = !q || String($(this).data('name')).indexOf(q) !== -1;
				$(this).prop('hidden', !hit);
				hits += hit ? 1 : 0;
			});
			$g.prop('hidden', q && !hits);
			if (q) { $g.prop('open', hits > 0); }
		});
	});
	$card.on('click', '.bp-names__tick-shown, .bp-names__untick-shown', function () {
		var on = $(this).hasClass('bp-names__tick-shown');
		$groups.not('[hidden]').find('.bp-names__list label').not('[hidden]').find('input').prop('checked', on);
	});

	// The rule.
	$card.on('click', '.bp-names__reset', function () {
		var $r = $('#bp-names-rule');
		$r.val($r.data('default'));
	});

	// AI tags.
	function tag($label, keep, reason) {
		$label.find('.bp-names__ai').remove();
		$('<span class="bp-names__ai"/>').addClass(keep ? 'bp-names__ai--keep' : 'bp-names__ai--translate')
			.attr({ 'data-keep': keep ? '1' : '0', title: reason || '' })
			.text(keep ? t.aiKeep : t.aiTranslate).appendTo($label);
	}
	function apply($scope) {
		var n = 0;
		$scope.find('.bp-names__ai').each(function () {
			$(this).closest('label').find('input').prop('checked', $(this).attr('data-keep') === '1');
			n++;
		});
		say(n ? t.applied.replace('%s', n) : t.noTags);
	}
	$card.on('click', '.bp-names__apply', function () { apply($(this).closest('.bp-names__group')); });
	$card.on('click', '.bp-names__apply-all', function () { apply($card); });

	$card.on('click', '.bp-names__suggest', function () {
		var $b = $(this).prop('disabled', true);
		say(t.sorting);
		$.post(cfg.ajax, { action: 'bp_names_suggest', nonce: cfg.nonce, rule: $('#bp-names-rule').val() })
			.done(function (r) {
				if (!(r && r.success)) { say((r && r.data && r.data.message) || t.failed, true); return; }
				$.each(r.data.tags || {}, function (hash, row) {
					$card.find('.bp-names__list input[value="' + hash + '"]').each(function () { tag($(this).closest('label'), row.keep, row.reason); });
				});
				$card.find('.bp-names__apply-all').prop('hidden', false);
				say(r.data.message);
			}).fail(function () { say(t.failed, true); })
			.always(function () { $b.prop('disabled', false); });
	});

	// Find names I missed: click one to add it to "Your own names".
	var $found = $card.find('.bp-names__found');
	$card.on('click', '.bp-names__find', function () {
		var $b = $(this).prop('disabled', true);
		say(t.finding);
		$.post(cfg.ajax, { action: 'bp_names_find', nonce: cfg.nonce, rule: $('#bp-names-rule').val() })
			.done(function (r) {
				if (!(r && r.success)) { say((r && r.data && r.data.message) || t.failed, true); return; }
				$found.empty().prop('hidden', !(r.data.names || []).length);
				$.each(r.data.names || [], function (i, row) {
					$('<button type="button" class="button button-small bp-names__add"/>').text('+ ' + row.name)
						.attr({ title: row.reason || '', 'data-name': row.name }).appendTo($found);
				});
				say(r.data.message);
			}).fail(function () { say(t.failed, true); })
			.always(function () { $b.prop('disabled', false); });
	});
	$card.on('click', '.bp-names__add', function () {
		var $own = $('#bp-names-own'), name = $(this).attr('data-name'), lines = $own.val().split(/\r?\n/).map($.trim).filter(Boolean);
		if (lines.indexOf(name) === -1) { lines.push(name); }
		$own.val(lines.join('\n'));
		$(this).remove();
		if (!$found.children().length) { $found.prop('hidden', true); }
	});
})(jQuery);
