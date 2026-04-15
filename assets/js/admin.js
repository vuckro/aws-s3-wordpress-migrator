/* global jQuery, WKS3M */
(function ($) {
	'use strict';

	/* ---------- Scan tab ---------- */

	var scanState = {
		offset: 0,
		limit: 100,
		totals: { processed: 0, urls_found: 0, already_known: 0 },
		total: 0,
		running: false
	};

	function renderScanSummary() {
		var $s = $('#wks3m-scan-summary');
		$s.find('.processed').text(scanState.totals.processed + ' / ' + scanState.total);
		$s.find('.urls-found').text(scanState.totals.urls_found);
		$s.prop('hidden', false);
	}

	function renderScanProgress() {
		var pct = scanState.total > 0 ? Math.min(100, Math.round((scanState.totals.processed / scanState.total) * 100)) : 0;
		var $p = $('#wks3m-scan-progress');
		$p.prop('hidden', false);
		$p.find('.wks3m-progress-bar span').css('width', pct + '%');
		$p.find('.wks3m-progress-label').text(pct + '% — ' + scanState.totals.processed + ' / ' + scanState.total);
	}

	function runScanBatch() {
		if (!scanState.running) return;
		$.post(WKS3M.ajax_url, {
			action: 'wks3m_scan_batch',
			nonce: WKS3M.nonce,
			offset: scanState.offset,
			limit: scanState.limit
		}).done(function (resp) {
			if (!resp || !resp.success) {
				scanState.running = false;
				$('#wks3m-scan-spinner').removeClass('is-active');
				alert(WKS3M.i18n.error);
				return;
			}
			var data = resp.data;
			scanState.total = data.total;
			scanState.totals.processed += data.processed;
			scanState.totals.urls_found += data.urls_found;
			scanState.offset = data.next_offset;
			renderScanProgress();
			renderScanSummary();

			if (data.processed > 0 && scanState.offset < scanState.total) {
				setTimeout(runScanBatch, 50);
			} else {
				scanState.running = false;
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
			scanState.running = false;
			$('#wks3m-scan-spinner').removeClass('is-active');
			alert(WKS3M.i18n.error);
		});
	}

	/* ---------- Queue: options + single import ---------- */

	function collectImportOptions() {
		return {
			dry_run: $('#wks3m-dry-run').is(':checked') ? 1 : 0,
			auto_replace: $('#wks3m-auto-replace').is(':checked') ? 1 : 0,
			use_alt_as_title: $('#wks3m-use-alt-as-title').is(':checked') ? 1 : 0,
			fill_empty_alts: $('#wks3m-fill-empty-alts').is(':checked') ? 1 : 0
		};
	}

	function handleImportClick(e) {
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		var opts = collectImportOptions();
		if (!opts.dry_run && !window.confirm(WKS3M.i18n.confirm_real)) {
			return;
		}
		$btn.prop('disabled', true).text(WKS3M.i18n.importing);
		var $row = $btn.closest('tr');
		var $status = $row.find('.wks3m-status');

		$.post(WKS3M.ajax_url, $.extend({
			action: 'wks3m_import_row',
			nonce: WKS3M.nonce,
			id: id
		}, opts)).done(function (resp) {
			if (!resp || !resp.success) {
				$btn.prop('disabled', false).text('Migrer');
				$status.text(WKS3M.i18n.import_failed);
				alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error);
				return;
			}
			if (resp.data.dry_run) {
				$btn.prop('disabled', false).text('Migrer');
				$status.text(WKS3M.i18n.dry_run_ok);
				var p = resp.data.preview;
				alert('Dry-run\n\nSource: ' + p.source_url + '\nFichier: ' + p.would_save_as + '\nTitre: ' + p.post_title + '\nAlt: ' + p.alt_text);
				return;
			}
			if (resp.data.replaced) {
				$status.removeClass().addClass('wks3m-status wks3m-status-replaced').text(WKS3M.i18n.replaced);
			} else {
				$status.removeClass().addClass('wks3m-status wks3m-status-imported').text(WKS3M.i18n.imported);
			}
			$btn.replaceWith('<a class="button button-link" href="/wp-admin/post.php?post=' + resp.data.attachment_id + '&action=edit" target="_blank">Voir média</a>');
		}).fail(function () {
			$btn.prop('disabled', false).text('Migrer');
			alert(WKS3M.i18n.error);
		});
	}

	/* ---------- Queue: replace only ---------- */

	function handleReplaceClick(e) {
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		$btn.prop('disabled', true);
		$.post(WKS3M.ajax_url, {
			action: 'wks3m_replace_row',
			nonce: WKS3M.nonce,
			id: id
		}).done(function (resp) {
			if (!resp || !resp.success) {
				$btn.prop('disabled', false);
				alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error);
				return;
			}
			var $row = $btn.closest('tr');
			$row.find('.wks3m-status').removeClass().addClass('wks3m-status wks3m-status-replaced').text(WKS3M.i18n.replaced);
			$btn.replaceWith('<em>' + WKS3M.i18n.replaced + '</em>');
		}).fail(function () {
			$btn.prop('disabled', false);
			alert(WKS3M.i18n.error);
		});
	}

	/* ---------- History: rollback ---------- */

	function handleRollbackClick(e) {
		if (!window.confirm(WKS3M.i18n.confirm_rollback)) return;
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		var deleteMedia = $btn.closest('td').find('.wks3m-rollback-delete').is(':checked');
		$btn.prop('disabled', true);
		$.post(WKS3M.ajax_url, {
			action: 'wks3m_rollback_row',
			nonce: WKS3M.nonce,
			id: id,
			delete_attachment: deleteMedia ? 1 : 0
		}).done(function (resp) {
			if (!resp || !resp.success) {
				$btn.prop('disabled', false);
				alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error);
				return;
			}
			var $row = $btn.closest('tr');
			$row.find('.wks3m-status').removeClass().addClass('wks3m-status wks3m-status-rolled_back').text(WKS3M.i18n.rolled_back);
			$btn.replaceWith('<em>' + WKS3M.i18n.rolled_back + '</em>');
		}).fail(function () {
			$btn.prop('disabled', false);
			alert(WKS3M.i18n.error);
		});
	}

	/* ---------- Bulk import ---------- */

	var bulkState = { ids: [], index: 0, running: false, errors: 0, success: 0 };

	function renderBulkProgress() {
		var total = bulkState.ids.length;
		var done = bulkState.index;
		var pct = total > 0 ? Math.round((done / total) * 100) : 0;
		var $p = $('#wks3m-bulk-progress');
		$p.prop('hidden', false);
		$p.find('.wks3m-progress-bar span').css('width', pct + '%');
		$p.find('.wks3m-progress-label').text(
			pct + '% — ' + done + ' / ' + total +
			' (✔ ' + bulkState.success + ' · ✖ ' + bulkState.errors + ')'
		);
	}

	function runBulkNext() {
		if (!bulkState.running || bulkState.index >= bulkState.ids.length) {
			bulkState.running = false;
			$('#wks3m-bulk-spinner').removeClass('is-active');
			$('#wks3m-bulk-all, #wks3m-bulk-selected').prop('disabled', false);
			renderBulkProgress();
			if (bulkState.ids.length > 0) {
				setTimeout(function () {
					if (window.confirm('Migration terminée (✔ ' + bulkState.success + ' · ✖ ' + bulkState.errors + '). Recharger la page pour voir l\'état à jour ?')) {
						location.reload();
					}
				}, 200);
			}
			return;
		}
		var id = bulkState.ids[bulkState.index];
		var opts = collectImportOptions();

		$.post(WKS3M.ajax_url, $.extend({
			action: 'wks3m_import_row',
			nonce: WKS3M.nonce,
			id: id
		}, opts)).always(function (resp) {
			if (resp && resp.success) {
				bulkState.success++;
				var status = resp.data && resp.data.replaced ? 'replaced' : (resp.data && resp.data.dry_run ? 'pending' : 'imported');
				var $row = $('tr[data-id="' + id + '"]');
				if ($row.length) {
					$row.find('.wks3m-status').removeClass().addClass('wks3m-status wks3m-status-' + status).text(status);
				}
			} else {
				bulkState.errors++;
			}
			bulkState.index++;
			renderBulkProgress();
			setTimeout(runBulkNext, 80);
		});
	}

	function startBulk(ids) {
		if (!ids || !ids.length) {
			alert('Aucune ligne à migrer.');
			return;
		}
		var opts = collectImportOptions();
		if (!opts.dry_run && !window.confirm(WKS3M.i18n.confirm_bulk)) {
			return;
		}
		bulkState = { ids: ids, index: 0, running: true, errors: 0, success: 0 };
		$('#wks3m-bulk-all, #wks3m-bulk-selected').prop('disabled', true);
		$('#wks3m-bulk-spinner').addClass('is-active');
		$('#wks3m-bulk-progress').prop('hidden', false);
		$('#wks3m-bulk-progress .wks3m-progress-label').text(WKS3M.i18n.bulk_progress);
		renderBulkProgress();
		runBulkNext();
	}

	function handleBulkAll() {
		$.post(WKS3M.ajax_url, {
			action: 'wks3m_pending_ids',
			nonce: WKS3M.nonce,
			limit: 5000
		}).done(function (resp) {
			if (!resp || !resp.success) { alert(WKS3M.i18n.error); return; }
			startBulk(resp.data.ids || []);
		}).fail(function () { alert(WKS3M.i18n.error); });
	}

	function handleBulkSelected() {
		var ids = $('.wks3m-row-check:checked').map(function () { return parseInt(this.value, 10); }).get();
		startBulk(ids);
	}

	/* ---------- Search & Replace ---------- */

	function collectSRParams() {
		var fields = $('.wks3m-sr-field:checked').map(function () { return this.value; }).get();
		return {
			find: $('#wks3m-sr-find').val(),
			replace: $('#wks3m-sr-replace').val(),
			fields: fields,
			update_attachments: $('#wks3m-sr-update-attachments').is(':checked') ? 1 : 0
		};
	}

	function renderSRResults(data, isPreview) {
		var $wrap = $('#wks3m-sr-results').prop('hidden', false);
		var $counts = $wrap.find('.wks3m-sr-counts');
		if (isPreview) {
			$counts.html(
				'<p><strong>' + data.rows + '</strong> lignes seraient modifiées · ' +
				'<strong>' + data.alt_rows + '</strong> ALT · ' +
				'<strong>' + data.title_rows + '</strong> titres · ' +
				'<strong>' + data.attachments + '</strong> attachments (postmeta ALT).</p>'
			);
			var $sample = $('#wks3m-sr-sample');
			var $tbody = $sample.find('tbody').empty();
			var replace = $('#wks3m-sr-replace').val();
			if (data.sample && data.sample.length) {
				data.sample.forEach(function (s) {
					var after = (s.after || '').split('⟶REPLACE⟵').join('<mark>' + (replace || '') + '</mark>');
					$tbody.append(
						'<tr><td>#' + s.id + '</td><td><code>' + s.field + '</code></td>' +
						'<td>' + $('<div/>').text(s.before).html() + '</td>' +
						'<td>' + after + '</td></tr>'
					);
				});
				$sample.prop('hidden', false);
			} else {
				$sample.prop('hidden', true);
			}
			$('#wks3m-sr-apply-btn').prop('disabled', !(data.rows > 0));
		} else {
			$counts.html(
				'<div class="notice notice-success inline"><p>' +
				'<strong>' + data.rows_updated + '</strong> lignes mises à jour dans la file d\'attente · ' +
				'<strong>' + data.attachments_updated + '</strong> attachments WP mis à jour.' +
				'</p></div>'
			);
			$('#wks3m-sr-sample').prop('hidden', true);
			$('#wks3m-sr-apply-btn').prop('disabled', true);
		}
	}

	function handleSRPreview() {
		var p = collectSRParams();
		if (!p.find) { alert(WKS3M.i18n.sr_no_find); return; }
		if (!p.fields.length) { alert(WKS3M.i18n.sr_no_fields); return; }
		$('#wks3m-sr-spinner').addClass('is-active');
		$.post(WKS3M.ajax_url, $.extend({
			action: 'wks3m_sr_preview',
			nonce: WKS3M.nonce
		}, p)).done(function (resp) {
			$('#wks3m-sr-spinner').removeClass('is-active');
			if (!resp || !resp.success) { alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error); return; }
			renderSRResults(resp.data, true);
		}).fail(function () {
			$('#wks3m-sr-spinner').removeClass('is-active');
			alert(WKS3M.i18n.error);
		});
	}

	function handleSRApply() {
		var p = collectSRParams();
		if (!p.find) { alert(WKS3M.i18n.sr_no_find); return; }
		if (!p.fields.length) { alert(WKS3M.i18n.sr_no_fields); return; }
		if (!window.confirm(WKS3M.i18n.sr_confirm)) return;
		$('#wks3m-sr-spinner').addClass('is-active');
		$('#wks3m-sr-apply-btn').prop('disabled', true);
		$.post(WKS3M.ajax_url, $.extend({
			action: 'wks3m_sr_apply',
			nonce: WKS3M.nonce
		}, p)).done(function (resp) {
			$('#wks3m-sr-spinner').removeClass('is-active');
			if (!resp || !resp.success) { alert((resp && resp.data && resp.data.message) || WKS3M.i18n.error); return; }
			renderSRResults(resp.data, false);
		}).fail(function () {
			$('#wks3m-sr-spinner').removeClass('is-active');
			alert(WKS3M.i18n.error);
		});
	}

	/* ---------- Bind ---------- */

	$(function () {
		$(document).on('click', '.wks3m-import-btn', handleImportClick);
		$(document).on('click', '.wks3m-replace-btn', handleReplaceClick);
		$(document).on('click', '.wks3m-rollback-btn', handleRollbackClick);

		$('#wks3m-bulk-all').on('click', handleBulkAll);
		$('#wks3m-bulk-selected').on('click', handleBulkSelected);

		$('#wks3m-select-all').on('change', function () {
			$('.wks3m-row-check').prop('checked', $(this).is(':checked'));
		});

		$('#wks3m-sr-preview-btn').on('click', handleSRPreview);
		$('#wks3m-sr-apply-btn').on('click', handleSRApply);
		$('#wks3m-sr-find, #wks3m-sr-replace').on('input', function () {
			$('#wks3m-sr-apply-btn').prop('disabled', true);
		});

		$('#wks3m-scan-start').on('click', function () {
			scanState = {
				offset: 0,
				limit: parseInt($('#wks3m-scan-batch').val(), 10) || 100,
				totals: { processed: 0, urls_found: 0, already_known: 0 },
				total: 0,
				running: true
			};
			$(this).prop('disabled', true);
			$('#wks3m-scan-spinner').addClass('is-active');
			$('#wks3m-scan-progress .wks3m-progress-bar span').css('width', '0%');
			$('#wks3m-scan-progress .wks3m-progress-label').text(WKS3M.i18n.scanning);
			$('#wks3m-scan-progress').prop('hidden', false);
			runScanBatch();
		});
	});
}(jQuery));
