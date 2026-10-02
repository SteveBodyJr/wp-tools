/**
 * Beaver AI panel on TranslatePress -> Automatic Translation.
 * Provider switch, model suggestions, "Load models", "Test connection".
 */
(function ($) {
	var cfg = window.BP_ADMIN || {};
	var $provider = $('#bp-provider');
	if (!$provider.length) return;

	function provider() { return $provider.val(); }

	var OTHER = '__other';
	var $model = $('#bp-model'), $pick = $('#bp-model-select');

	/* Model dropdown: the key's loaded models, the preset suggestions and the current model
	   (always listed and selected), plus "Other" for any name. The text box is what is saved;
	   it shows only for "Other" or when there is nothing to choose from. */
	function fillModels() {
		var p = provider(), current = $.trim($model.val()), seen = {}, names = [];
		(cfg.cached[p] || []).concat((cfg.presets[p] || {}).models || []).forEach(function (m) {
			if (m && !seen[m]) { seen[m] = true; names.push(m); }
		});
		if (current && !seen[current]) { names.unshift(current); seen[current] = true; }
		$pick.empty();
		names.forEach(function (m) { $pick.append($('<option>').val(m).text(m)); });
		$pick.append($('<option>').val(OTHER).text(cfg.i18n.other));
		var typing = !names.length || (current === '' && names.length === 0);
		$pick.val(current && seen[current] ? current : (names.length ? names[0] : OTHER));
		if (!current && names.length) { $model.val(names[0]); }
		$('.bp-model-pick').toggle(names.length > 0);
		$model.toggle(typing || $pick.val() === OTHER);
	}

	$pick.on('change', function () {
		if ($pick.val() === OTHER) {
			$model.show().val('').trigger('focus');
		} else {
			$model.val($pick.val()).hide();
		}
		$('#bp-test-result').text('').removeClass('bp-status--ok bp-status--error');
	});

	function sync() {
		var p = provider();
		$('.bp-key-row').hide().filter('[data-provider="' + p + '"]').show();
		$('.bp-only-custom').toggle(p === 'custom');
		$('.bp-only-claude').toggle(p === 'claude');
		$('.bp-not-deepl').toggle(p !== 'deepl');
		fillModels();
	}

	/* TranslatePress's own "Test API" popup prints request headers (the key); ours never does. */
	function hideCoreTest() {
		if ($('#trp-translation-engines').val() === cfg.engine) { $('#trp-test-api-key').hide(); }
	}

	$provider.on('change', function () {
		var model = $('#bp-model');
		var defaults = Object.keys(cfg.presets).map(function (k) { return cfg.presets[k].model; });
		if (!model.val() || defaults.indexOf(model.val()) !== -1) { model.val((cfg.presets[provider()] || {}).model || ''); }
		$('#bp-test-result').text('').removeClass('bp-status--ok bp-status--error');
		$('#bp-models-status').text('');
		sync();
	});

	$('#trp-translation-engines').on('change', function () { setTimeout(hideCoreTest, 0); });

	function payload(action) {
		var p = provider();
		return {
			action: action,
			nonce: cfg.nonce,
			provider: p,
			model: $('#bp-model').val(),
			endpoint: $('#bp-endpoint').val(),
			effort: $('#bp-effort').val(),
			key: $('.bp-key-row[data-provider="' + p + '"] .bp-key').val() || ''
		};
	}

	$('#bp-load-models').on('click', function () {
		var button = $(this).prop('disabled', true);
		var status = $('#bp-models-status').text(cfg.i18n.loading);
		$.post(cfg.ajax, payload('bp_list_models'))
			.done(function (r) {
				if (r && r.success) {
					cfg.cached[provider()] = r.data.models;
					fillModels();
					status.text(r.data.models.length + ' ' + cfg.i18n.models);
					$pick.trigger('focus');
				} else {
					status.text((r && r.data && r.data.message) || cfg.i18n.failed);
				}
			})
			.fail(function () { status.text(cfg.i18n.failed); })
			.always(function () { button.prop('disabled', false); });
	});

	$('#bp-test').on('click', function () {
		var button = $(this).prop('disabled', true);
		var result = $('#bp-test-result').removeClass('bp-status--ok bp-status--error').text(cfg.i18n.testing);
		$.post(cfg.ajax, payload('bp_test_connection'))
			.done(function (r) {
				var ok = !!(r && r.success);
				result.addClass(ok ? 'bp-status--ok' : 'bp-status--error').text((r && r.data && r.data.message) || cfg.i18n.failed);
			})
			.fail(function () { result.addClass('bp-status--error').text(cfg.i18n.failed); })
			.always(function () { button.prop('disabled', false); });
	});

	sync();
	hideCoreTest();
})(jQuery);
