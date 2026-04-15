/* global jQuery, WKS3M */
(function ($) {
	'use strict';

	/* ---------- shared helpers ---------- */

	function post(action, data) {
		return $.post(WKS3M.ajax_url, $.extend({ action: action, nonce: WKS3M.nonce }, data || {}));
	}

	function errOf(resp) {
		return (resp && resp.data && resp.data.message) || WKS3M.i18n.error;
	}

	function setStatusCell($row, status) {
		var label = WKS3M.i18n[status] || status;
		$row.find('.wks3m-status')
			.removeClass(function (_, cn) {
				return (cn.match(/wks3m-status-\S+/g) || []).join(' ');
			})
			.addClass('wks3m-status wks3m-status-' + status)
			.text(label);
	}

	/* ---------- Scan tab ---------- */

	var scan = { offset: 0, limit: 100, total: 0, processed: 0, urls: 0, running: false };

	function renderScanProgress() {
		var pct = scan.total > 0 ? Math.min(100, Math.round((scan.processed / scan.total) * 100)) : 0;
		var $p = $('#wks3m-scan-progress').prop('hidden', false);
		$p.find('.wks3m-progress-bar span').css('width', pct + '%');
		$p.find('.wks3m-progress-label').text(pct + '% — ' + scan.processed + ' / ' + scan.total);
	}

	function renderScanSummary() {
		var $s = $('#wks3m-scan-summary').prop('hidden', false);
		$s.find('.processed').text(scan.processed + ' / ' + scan.total);
		$s.find('.urls-found').text(scan.urls);
	}

	function runScanBatch() {
		if (!scan.running) return;
		post('wks3m_scan_batch', { offset: scan.offset, limit: scan.limit })
			.done(function (resp) {
				if (!resp || !resp.success) return finishScan(true);
				scan.total = resp.data.total;
				scan.processed += resp.data.processed;
				scan.urls += resp.data.urls_found;
				scan.offset = resp.data.next_offset;
				renderScanProgress(); renderScanSummary();
				if (resp.data.processed > 0 && scan.offset < scan.total) {
					setTimeout(runScanBatch, 50);
				} else {
					finishScan(false);
				}
			})
			.fail(function () { finishScan(true); });
	}

	function finishScan(isError) {
		scan.running = false;
		$('#wks3m-scan-spinner').removeClass('is-active');
		$('#wks3m-scan-start').prop('disabled', false);
		if (isError) { alert(WKS3M.i18n.error); return; }
		$('#wks3m-scan-done').prop('hidden', false);
		post('wks3m_scan_secondary').done(function (r) {
			if (r && r.success) {
				$('#wks3m-scan-summary .postmeta-hits').text(r.data.postmeta);
				$('#wks3m-scan-summary .options-hits').text(r.data.options);
			}
		});
	}

	function startScan() {
		scan = {
			offset: 0,
			limit: parseInt($('#wks3m-scan-batch').val(), 10) || 100,
			total: 0, processed: 0, urls: 0,
			running: true
		};
		$('#wks3m-scan-start').prop('disabled', true);
		$('#wks3m-scan-spinner').addClass('is-active');
		$('#wks3m-scan-progress .wks3m-progress-bar span').css('width', '0%');
		$('#wks3m-scan-progress .wks3m-progress-label').text(WKS3M.i18n.scanning);
		$('#wks3m-scan-progress').prop('hidden', false);
		runScanBatch();
	}

	/* ---------- Queue tab: single + bulk import, replace, rollback ---------- */

	function importOptions() {
		return {
			dry_run:      $('#wks3m-dry-run').is(':checked') ? 1 : 0,
			auto_replace: $('#wks3m-auto-replace').is(':checked') ? 1 : 0
		};
	}

	function swapRowActionLinkToMedia($row, attachmentId) {
		$row.find('.wks3m-import-btn, .wks3m-replace-btn').replaceWith(
			'<a class="button button-link" href="/wp-admin/post.php?post=' + attachmentId + '&action=edit" target="_blank">' +
			WKS3M.i18n.view_media + '</a>'
		);
	}

	function handleImport(e) {
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		var opts = importOptions();
		if (!opts.dry_run && !window.confirm(WKS3M.i18n.confirm_real)) return;

		$btn.prop('disabled', true).text(WKS3M.i18n.importing);
		post('wks3m_import_row', $.extend({ id: id }, opts))
			.done(function (resp) {
				if (!resp || !resp.success) {
					$btn.prop('disabled', false).text('Migrer');
					alert(errOf(resp));
					return;
				}
				var $row = $('tr[data-id="' + id + '"]');
				if (resp.data.dry_run) {
					$btn.prop('disabled', false).text('Migrer');
					setStatusCell($row, 'pending');
					var p = resp.data.preview;
					alert('Dry-run\n\nSource: ' + p.source_url + '\nFichier: ' + p.would_save_as +
						'\nTitre: ' + p.post_title + '\nAlt: ' + p.alt_text);
					return;
				}
				setStatusCell($row, resp.data.replaced ? 'replaced' : 'imported');
				swapRowActionLinkToMedia($row, resp.data.attachment_id);
			})
			.fail(function () {
				$btn.prop('disabled', false).text('Migrer');
				alert(WKS3M.i18n.error);
			});
	}

	function handleReplace(e) {
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		$btn.prop('disabled', true);
		post('wks3m_replace_row', { id: id })
			.done(function (resp) {
				if (!resp || !resp.success) { $btn.prop('disabled', false); alert(errOf(resp)); return; }
				var $row = $('tr[data-id="' + id + '"]');
				setStatusCell($row, 'replaced');
				$btn.replaceWith('<em>' + WKS3M.i18n.replaced + '</em>');
			})
			.fail(function () { $btn.prop('disabled', false); alert(WKS3M.i18n.error); });
	}

	function handleRollback(e) {
		if (!window.confirm(WKS3M.i18n.confirm_rollback)) return;
		var $btn = $(e.currentTarget);
		var id = parseInt($btn.data('id'), 10);
		var deleteMedia = $btn.closest('td').find('.wks3m-rollback-delete').is(':checked');
		$btn.prop('disabled', true);
		post('wks3m_rollback_row', { id: id, delete_attachment: deleteMedia ? 1 : 0 })
			.done(function (resp) {
				if (!resp || !resp.success) { $btn.prop('disabled', false); alert(errOf(resp)); return; }
				var $row = $('tr[data-id="' + id + '"]');
				setStatusCell($row, 'rolled_back');
				$btn.replaceWith('<em>' + WKS3M.i18n.rolled_back + '</em>');
			})
			.fail(function () { $btn.prop('disabled', false); alert(WKS3M.i18n.error); });
	}

	/* ---------- Queue: bulk driver (batched + concurrent) ---------- */

	var BULK_BATCH_SIZE  = 5;  // ids per HTTP request
	var BULK_CONCURRENCY = 3;  // parallel HTTP requests in flight

	var bulk = { batches: [], cursor: 0, done: 0, total: 0, ok: 0, ko: 0, active: 0, running: false };

	function chunk(arr, size) {
		var out = [];
		for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
		return out;
	}

	function renderBulkProgress() {
		var pct = bulk.total > 0 ? Math.round((bulk.done / bulk.total) * 100) : 0;
		var $p = $('#wks3m-bulk-progress').prop('hidden', false);
		$p.find('.wks3m-progress-bar span').css('width', pct + '%');
		$p.find('.wks3m-progress-label').text(
			pct + '% — ' + bulk.done + ' / ' + bulk.total +
			' (✔ ' + bulk.ok + ' · ✖ ' + bulk.ko + ')'
		);
	}

	function applyBatchResults(results) {
		results.forEach(function (r) {
			bulk.done++;
			if (r.ok) {
				bulk.ok++;
				var $row = $('tr[data-id="' + r.id + '"]');
				if ($row.length) setStatusCell($row, r.status);
			} else {
				bulk.ko++;
			}
		});
	}

	function drainBulk() {
		if (!bulk.running) return;
		while (bulk.active < BULK_CONCURRENCY && bulk.cursor < bulk.batches.length) {
			var batch = bulk.batches[bulk.cursor++];
			bulk.active++;
			post('wks3m_import_batch', $.extend({ ids: batch }, importOptions()))
				.done(function (resp) {
					if (resp && resp.success && Array.isArray(resp.data.results)) {
						applyBatchResults(resp.data.results);
					} else {
						// Whole-batch failure: count every id as KO.
						bulk.ko += batch.length;
						bulk.done += batch.length;
					}
				})
				.fail(function () {
					bulk.ko += batch.length;
					bulk.done += batch.length;
				})
				.always(function () {
					bulk.active--;
					renderBulkProgress();
					if (bulk.cursor < bulk.batches.length) {
						drainBulk();
					} else if (bulk.active === 0) {
						finishBulk();
					}
				});
		}
	}

	function finishBulk() {
		bulk.running = false;
		$('#wks3m-bulk-spinner').removeClass('is-active');
		$('#wks3m-bulk-all, #wks3m-bulk-selected').prop('disabled', false);
		renderBulkProgress();
		if (bulk.total > 0) {
			setTimeout(function () {
				if (window.confirm('Migration terminée (✔ ' + bulk.ok + ' · ✖ ' + bulk.ko +
					'). Recharger la page ?')) {
					location.reload();
				}
			}, 200);
		}
	}

	function startBulk(ids) {
		if (!ids || !ids.length) { alert('Aucune ligne à migrer.'); return; }
		if (!$('#wks3m-dry-run').is(':checked') && !window.confirm(WKS3M.i18n.confirm_bulk)) return;
		bulk = {
			batches: chunk(ids, BULK_BATCH_SIZE),
			cursor: 0, done: 0, total: ids.length,
			ok: 0, ko: 0, active: 0, running: true
		};
		$('#wks3m-bulk-all, #wks3m-bulk-selected').prop('disabled', true);
		$('#wks3m-bulk-spinner').addClass('is-active');
		$('#wks3m-bulk-progress .wks3m-progress-label').text(WKS3M.i18n.bulk_progress);
		$('#wks3m-bulk-progress').prop('hidden', false);
		renderBulkProgress();
		drainBulk();
	}

	function handleBulkAll() {
		post('wks3m_pending_ids', { limit: 5000 })
			.done(function (resp) {
				if (!resp || !resp.success) return alert(WKS3M.i18n.error);
				startBulk(resp.data.ids || []);
			})
			.fail(function () { alert(WKS3M.i18n.error); });
	}

	function handleBulkSelected() {
		var ids = $('.wks3m-row-check:checked').map(function () { return parseInt(this.value, 10); }).get();
		startBulk(ids);
	}

	/* ---------- Settings: Transform rule ---------- */

	function transformRule() {
		return {
			field:           $('#wks3m-tr-field').val(),
			condition_type:  $('#wks3m-tr-cond').val(),
			condition_value: $('#wks3m-tr-cond-value').val(),
			action_type:     $('#wks3m-tr-action').val(),
			action_value:    $('#wks3m-tr-action-value').val(),
			update_attachments: $('#wks3m-tr-update-attachments').is(':checked') ? 1 : 0
		};
	}

	function refreshTransformInputs() {
		var cond = $('#wks3m-tr-cond').val();
		$('#wks3m-tr-cond-value').prop('hidden', cond === 'empty').prop('required', cond !== 'empty');

		var action = $('#wks3m-tr-action').val();
		var needsValue = (action === 'set_literal' || action === 'remove_substring');
		$('#wks3m-tr-action-value').prop('hidden', !needsValue);

		$('#wks3m-tr-apply-btn').prop('disabled', true); // requires fresh preview
	}

	function transformValid(rule) {
		if (!rule.field || !rule.condition_type || !rule.action_type) return false;
		if (rule.condition_type !== 'empty' && !rule.condition_value) return false;
		if ((rule.action_type === 'set_literal') && rule.action_value === '') { /* allowed, == clear */ }
		if (rule.action_type === 'remove_substring' && !rule.action_value) return false;
		return true;
	}

	function renderTransformPreview(data) {
		var $wrap = $('#wks3m-tr-results').prop('hidden', false);
		$wrap.find('.wks3m-tr-counts').html(
			'<p><strong>' + data.rows + '</strong> ligne(s) seraient modifiées · ' +
			'<strong>' + data.attachments + '</strong> attachments déjà importés concerné(s).</p>'
		);
		var $sample = $('#wks3m-tr-sample');
		var $tbody = $sample.find('tbody').empty();
		if (data.sample && data.sample.length) {
			data.sample.forEach(function (s) {
				$tbody.append(
					'<tr><td>#' + s.id + '</td>' +
					'<td>' + $('<div/>').text(s.before).html() + '</td>' +
					'<td><strong>' + $('<div/>').text(s.after).html() + '</strong></td></tr>'
				);
			});
			$sample.prop('hidden', false);
		} else {
			$sample.prop('hidden', true);
		}
		$('#wks3m-tr-apply-btn').prop('disabled', data.rows === 0);
	}

	function renderTransformResult(data) {
		$('#wks3m-tr-results').prop('hidden', false)
			.find('.wks3m-tr-counts').html(
				'<div class="notice notice-success inline"><p>' +
				'<strong>' + data.rows_updated + '</strong> lignes mises à jour · ' +
				'<strong>' + data.attachments_updated + '</strong> attachments synchronisés.' +
				'</p></div>'
			);
		$('#wks3m-tr-sample').prop('hidden', true);
		$('#wks3m-tr-apply-btn').prop('disabled', true);
	}

	function handleTransformPreview() {
		var rule = transformRule();
		if (!transformValid(rule)) { alert(WKS3M.i18n.tr_invalid); return; }
		$('#wks3m-tr-spinner').addClass('is-active');
		post('wks3m_transform_preview', rule)
			.done(function (resp) {
				$('#wks3m-tr-spinner').removeClass('is-active');
				if (!resp || !resp.success) return alert(errOf(resp));
				renderTransformPreview(resp.data);
			})
			.fail(function () {
				$('#wks3m-tr-spinner').removeClass('is-active');
				alert(WKS3M.i18n.error);
			});
	}

	function handleTransformApply() {
		var rule = transformRule();
		if (!transformValid(rule)) { alert(WKS3M.i18n.tr_invalid); return; }
		if (!window.confirm(WKS3M.i18n.tr_confirm)) return;
		$('#wks3m-tr-spinner').addClass('is-active');
		$('#wks3m-tr-apply-btn').prop('disabled', true);
		post('wks3m_transform_apply', rule)
			.done(function (resp) {
				$('#wks3m-tr-spinner').removeClass('is-active');
				if (!resp || !resp.success) return alert(errOf(resp));
				renderTransformResult(resp.data);
			})
			.fail(function () {
				$('#wks3m-tr-spinner').removeClass('is-active');
				alert(WKS3M.i18n.error);
			});
	}

	/* ---------- Bind ---------- */

	$(function () {
		// Scan
		$('#wks3m-scan-start').on('click', startScan);

		// Queue
		$(document).on('click', '.wks3m-import-btn',   handleImport);
		$(document).on('click', '.wks3m-replace-btn',  handleReplace);
		$(document).on('click', '.wks3m-rollback-btn', handleRollback);
		$('#wks3m-bulk-all').on('click', handleBulkAll);
		$('#wks3m-bulk-selected').on('click', handleBulkSelected);
		$('#wks3m-select-all').on('change', function () {
			$('.wks3m-row-check').prop('checked', $(this).is(':checked'));
		});

		// Transform
		if ($('#wks3m-tr-form').length) {
			refreshTransformInputs();
			$('#wks3m-tr-cond, #wks3m-tr-action').on('change', refreshTransformInputs);
			$('#wks3m-tr-form :input').on('input', function () {
				$('#wks3m-tr-apply-btn').prop('disabled', true);
			});
			$('#wks3m-tr-preview-btn').on('click', handleTransformPreview);
			$('#wks3m-tr-apply-btn').on('click',   handleTransformApply);
		}
	});
}(jQuery));
