/* global jQuery, CXS3M */
(function ($) {
	'use strict';

	var state = {
		offset: 0,
		limit: 100,
		totals: { processed: 0, urls_found: 0, base_keys: 0, already_known: 0 },
		matches: {},
		total: 0,
		running: false
	};

	function el(id) { return document.getElementById(id); }

	function renderSummary() {
		var $s = $('#cxs3m-scan-summary');
		$s.find('.processed').text(state.totals.processed + ' / ' + state.total);
		$s.find('.base-keys').text(Object.keys(state.matches).length);
		$s.find('.urls-found').text(state.totals.urls_found);
		$s.find('.already-known').text(state.totals.already_known);
		$s.prop('hidden', false);
	}

	function renderProgress() {
		var pct = state.total > 0 ? Math.min(100, Math.round((state.totals.processed / state.total) * 100)) : 0;
		var $p = $('#cxs3m-scan-progress');
		$p.prop('hidden', false);
		$p.find('.cxs3m-progress-bar span').css('width', pct + '%');
		$p.find('.cxs3m-progress-label').text(pct + '% — ' + state.totals.processed + ' / ' + state.total);
	}

	function renderResultsTable() {
		var $tbody = $('#cxs3m-scan-results tbody').empty();
		var keys = Object.keys(state.matches).sort();
		keys.forEach(function (k) {
			var m = state.matches[k];
			var variantsHtml = (m.variants || []).map(function (u) {
				return '<code class="cxs3m-url">' + $('<div/>').text(u).html() + '</code>';
			}).join('<br>');
			var postsHtml = (m.post_ids || []).map(function (pid) {
				var url = '/wp-admin/post.php?post=' + pid + '&action=edit';
				return '<a href="' + url + '" target="_blank">#' + pid + '</a>';
			}).join(', ');
			var status = m.already_known
				? '<span class="cxs3m-badge cxs3m-badge-known">' + 'déjà connue' + '</span>'
				: '<span class="cxs3m-badge cxs3m-badge-new">' + 'nouvelle' + '</span>';
			var tr = '<tr>'
				+ '<td><code>' + $('<div/>').text(k).html() + '</code></td>'
				+ '<td>' + variantsHtml + '</td>'
				+ '<td>' + postsHtml + '</td>'
				+ '<td>' + status + '</td>'
				+ '</tr>';
			$tbody.append(tr);
		});
		$('#cxs3m-scan-results').prop('hidden', keys.length === 0);
	}

	function mergeBatch(data) {
		state.total = data.total;
		state.totals.processed += data.processed;
		state.totals.urls_found += data.urls_found;
		Object.keys(data.matches || {}).forEach(function (k) {
			var incoming = data.matches[k];
			var current = state.matches[k] || { variants: [], post_ids: [], already_known: !!incoming.already_known };
			incoming.variants.forEach(function (v) {
				if (current.variants.indexOf(v) === -1) current.variants.push(v);
			});
			incoming.post_ids.forEach(function (p) {
				if (current.post_ids.indexOf(p) === -1) current.post_ids.push(p);
			});
			current.already_known = current.already_known || !!incoming.already_known;
			state.matches[k] = current;
		});
		state.totals.already_known = Object.keys(state.matches).filter(function (k) {
			return state.matches[k].already_known;
		}).length;
	}

	function runBatch() {
		if (!state.running) return;
		$.post(CXS3M.ajax_url, {
			action: 'cxs3m_scan_batch',
			nonce: CXS3M.nonce,
			offset: state.offset,
			limit: state.limit
		}).done(function (resp) {
			if (!resp || !resp.success) {
				state.running = false;
				$('#cxs3m-scan-spinner').removeClass('is-active');
				alert(CXS3M.i18n.error);
				return;
			}
			var data = resp.data;
			mergeBatch(data);
			state.offset = data.next_offset;
			renderProgress();
			renderSummary();
			renderResultsTable();

			if (data.processed > 0 && state.offset < state.total) {
				setTimeout(runBatch, 50);
			} else {
				state.running = false;
				$('#cxs3m-scan-spinner').removeClass('is-active');
				$('#cxs3m-scan-start').prop('disabled', false);
				// Fetch secondary source counts.
				$.post(CXS3M.ajax_url, {
					action: 'cxs3m_scan_secondary',
					nonce: CXS3M.nonce
				}).done(function (r2) {
					if (r2 && r2.success) {
						$('#cxs3m-scan-summary .postmeta-hits').text(r2.data.postmeta);
						$('#cxs3m-scan-summary .options-hits').text(r2.data.options);
					}
				});
			}
		}).fail(function () {
			state.running = false;
			$('#cxs3m-scan-spinner').removeClass('is-active');
			alert(CXS3M.i18n.error);
		});
	}

	$(function () {
		$('#cxs3m-scan-start').on('click', function () {
			state = {
				offset: 0,
				limit: parseInt($('#cxs3m-scan-batch').val(), 10) || 100,
				totals: { processed: 0, urls_found: 0, base_keys: 0, already_known: 0 },
				matches: {},
				total: 0,
				running: true
			};
			$(this).prop('disabled', true);
			$('#cxs3m-scan-spinner').addClass('is-active');
			$('#cxs3m-scan-progress .cxs3m-progress-bar span').css('width', '0%');
			$('#cxs3m-scan-progress .cxs3m-progress-label').text(CXS3M.i18n.scanning);
			$('#cxs3m-scan-progress').prop('hidden', false);
			runBatch();
		});
	});
}(jQuery));
