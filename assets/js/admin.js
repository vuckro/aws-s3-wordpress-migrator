/* global jQuery, WKS3M */
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

	function renderSummary() {
		var $s = $('#wks3m-scan-summary');
		$s.find('.processed').text(state.totals.processed + ' / ' + state.total);
		$s.find('.base-keys').text(Object.keys(state.matches).length);
		$s.find('.urls-found').text(state.totals.urls_found);
		$s.find('.already-known').text(state.totals.already_known);
		$s.prop('hidden', false);
	}

	function renderProgress() {
		var pct = state.total > 0 ? Math.min(100, Math.round((state.totals.processed / state.total) * 100)) : 0;
		var $p = $('#wks3m-scan-progress');
		$p.prop('hidden', false);
		$p.find('.wks3m-progress-bar span').css('width', pct + '%');
		$p.find('.wks3m-progress-label').text(pct + '% — ' + state.totals.processed + ' / ' + state.total);
	}

	function renderResultsTable() {
		var $tbody = $('#wks3m-scan-results tbody').empty();
		var keys = Object.keys(state.matches).sort();
		keys.forEach(function (k) {
			var m = state.matches[k];
			var baseKey = m.base_key || k.split('|').pop();
			var host = m.host || k.split('|')[0];
			var variantsHtml = (m.variants || []).map(function (u) {
				return '<code class="wks3m-url">' + $('<div/>').text(u).html() + '</code>';
			}).join('<br>');
			var postsHtml = (m.post_ids || []).map(function (pid) {
				var url = '/wp-admin/post.php?post=' + pid + '&action=edit';
				return '<a href="' + url + '" target="_blank">#' + pid + '</a>';
			}).join(', ');
			var status = m.already_known
				? '<span class="wks3m-badge wks3m-badge-known">' + WKS3M.i18n.badge_known + '</span>'
				: '<span class="wks3m-badge wks3m-badge-new">' + WKS3M.i18n.badge_new + '</span>';
			var tr = '<tr>'
				+ '<td><code>' + $('<div/>').text(baseKey).html() + '</code></td>'
				+ '<td><code>' + $('<div/>').text(host).html() + '</code></td>'
				+ '<td>' + variantsHtml + '</td>'
				+ '<td>' + postsHtml + '</td>'
				+ '<td>' + status + '</td>'
				+ '</tr>';
			$tbody.append(tr);
		});
		$('#wks3m-scan-results').prop('hidden', keys.length === 0);
	}

	function mergeBatch(data) {
		state.total = data.total;
		state.totals.processed += data.processed;
		state.totals.urls_found += data.urls_found;
		Object.keys(data.matches || {}).forEach(function (k) {
			var incoming = data.matches[k];
			var current = state.matches[k] || {
				variants: [],
				post_ids: [],
				host: incoming.host,
				base_key: incoming.base_key,
				already_known: !!incoming.already_known
			};
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
		$.post(WKS3M.ajax_url, {
			action: 'wks3m_scan_batch',
			nonce: WKS3M.nonce,
			offset: state.offset,
			limit: state.limit
		}).done(function (resp) {
			if (!resp || !resp.success) {
				state.running = false;
				$('#wks3m-scan-spinner').removeClass('is-active');
				alert(WKS3M.i18n.error);
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
				$('#wks3m-scan-spinner').removeClass('is-active');
				$('#wks3m-scan-start').prop('disabled', false);
				$('#wks3m-scan-done').prop('hidden', false);
				$.post(WKS3M.ajax_url, {
					action: 'wks3m_scan_secondary',
					nonce: WKS3M.nonce
				}).done(function (r2) {
					if (r2 && r2.success) {
						$('#wks3m-scan-summary .postmeta-hits').text(r2.data.postmeta);
						$('#wks3m-scan-summary .options-hits').text(r2.data.options);
					}
				});
			}
		}).fail(function () {
			state.running = false;
			$('#wks3m-scan-spinner').removeClass('is-active');
			alert(WKS3M.i18n.error);
		});
	}

	function handleImportClick(e) {
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		var dryRun = $('#wks3m-dry-run').is(':checked');
		if (!dryRun && !window.confirm(WKS3M.i18n.confirm_real)) {
			return;
		}
		$btn.prop('disabled', true).text(WKS3M.i18n.importing);
		var $row = $btn.closest('tr');
		var $status = $row.find('.wks3m-status');

		$.post(WKS3M.ajax_url, {
			action: 'wks3m_import_row',
			nonce: WKS3M.nonce,
			id: id,
			dry_run: dryRun ? 1 : 0
		}).done(function (resp) {
			if (!resp || !resp.success) {
				$btn.prop('disabled', false).text('Importer');
				$status.text(WKS3M.i18n.import_failed).addClass('wks3m-status-failed');
				alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error);
				return;
			}
			if (resp.data.dry_run) {
				$btn.prop('disabled', false).text('Importer');
				$status.text(WKS3M.i18n.dry_run_ok);
				var p = resp.data.preview;
				alert(
					'Dry-run\n\n' +
					'Source: ' + p.source_url + '\n' +
					'Fichier: ' + p.would_save_as + '\n' +
					'Titre: ' + p.post_title + '\n' +
					'Alt: ' + p.alt_text
				);
			} else {
				$status.text(WKS3M.i18n.imported).removeClass().addClass('wks3m-status wks3m-status-imported');
				$btn.replaceWith('<a class="button button-link" href="/wp-admin/post.php?post=' + resp.data.attachment_id + '&action=edit" target="_blank">Voir média</a>');
			}
		}).fail(function () {
			$btn.prop('disabled', false).text('Importer');
			alert(WKS3M.i18n.error);
		});
	}

	$(function () {
		$(document).on('click', '.wks3m-import-btn', handleImportClick);

		$('#wks3m-scan-start').on('click', function () {
			state = {
				offset: 0,
				limit: parseInt($('#wks3m-scan-batch').val(), 10) || 100,
				totals: { processed: 0, urls_found: 0, base_keys: 0, already_known: 0 },
				matches: {},
				total: 0,
				running: true
			};
			$(this).prop('disabled', true);
			$('#wks3m-scan-spinner').addClass('is-active');
			$('#wks3m-scan-progress .wks3m-progress-bar span').css('width', '0%');
			$('#wks3m-scan-progress .wks3m-progress-label').text(WKS3M.i18n.scanning);
			$('#wks3m-scan-progress').prop('hidden', false);
			runBatch();
		});
	});
}(jQuery));
