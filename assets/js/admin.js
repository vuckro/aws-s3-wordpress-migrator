/* global jQuery, WKS3M */
(function ($) {
	'use strict';

	var T = WKS3M.i18n;

	/* ---------- shared AJAX helpers ---------- */

	function post(action, data) {
		return $.post(WKS3M.ajax_url, $.extend({ action: action, nonce: WKS3M.nonce }, data || {}));
	}

	function errMsg(resp) {
		return (resp && resp.data && resp.data.message) || T.error;
	}

	function esc(str) { return $('<div/>').text(str == null ? '' : str).html(); }

	function setStatus($row, status) {
		$row.find('.wks3m-status')
			.removeClass(function (_, cn) { return (cn.match(/wks3m-status-\S+/g) || []).join(' '); })
			.addClass('wks3m-status wks3m-status-' + status)
			.text(T[status] || status);
	}

	function editMediaLink(attachmentId) {
		return '<a class="button button-link" href="' +
			WKS3M.edit_post_url_tmpl.replace('%d', attachmentId) +
			'" target="_blank">' + T.view_media + '</a>';
	}

	function renderProgress($wrap, pct, label) {
		$wrap.prop('hidden', false);
		$wrap.find('.wks3m-progress-bar span').css('width', pct + '%');
		$wrap.find('.wks3m-progress-label').text(label);
	}

	/* ---------- Scan tab ---------- */

	var scan = null;

	function runScanBatch() {
		if (!scan || !scan.running) return;
		post('wks3m_scan_batch', { offset: scan.offset, limit: scan.limit })
			.done(function (resp) {
				if (!resp || !resp.success) return endScan(true);
				scan.total      = resp.data.total;
				scan.processed += resp.data.processed;
				scan.urls      += resp.data.urls_found;
				scan.offset     = resp.data.next_offset;
				updateScanUI();
				if (resp.data.processed > 0 && scan.offset < scan.total) {
					setTimeout(runScanBatch, 50);
				} else {
					endScan(false);
				}
			})
			.fail(function () { endScan(true); });
	}

	function updateScanUI() {
		var pct = scan.total > 0 ? Math.min(100, Math.round((scan.processed / scan.total) * 100)) : 0;
		renderProgress($('#wks3m-scan-progress'), pct, pct + '% — ' + scan.processed + ' / ' + scan.total);
		$('#wks3m-scan-summary').prop('hidden', false)
			.find('.processed').text(scan.processed + ' / ' + scan.total).end()
			.find('.urls-found').text(scan.urls);
	}

	function endScan(isError) {
		if (!scan) return;
		scan.running = false;
		$('#wks3m-scan-spinner').removeClass('is-active');
		$('#wks3m-scan-start').prop('disabled', false);
		if (isError) { alert(T.error); return; }
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
		renderProgress($('#wks3m-scan-progress'), 0, T.scanning);
		runScanBatch();
	}

	/* ---------- Queue: single-row actions ---------- */

	function importOptions() {
		return {
			dry_run:      $('#wks3m-dry-run').is(':checked') ? 1 : 0,
			auto_replace: $('#wks3m-auto-replace').is(':checked') ? 1 : 0
		};
	}

	function handleImport(e) {
		var $btn = $(e.currentTarget);
		var id   = parseInt($btn.data('id'), 10);
		var opts = importOptions();
		if (!opts.dry_run && !window.confirm(T.confirm_real)) return;

		$btn.prop('disabled', true).text(T.importing);
		post('wks3m_import_row', $.extend({ id: id }, opts))
			.done(function (resp) {
				var $row = $('tr[data-id="' + id + '"]');
				if (!resp || !resp.success) {
					$btn.prop('disabled', false).text(T.btn_migrate);
					alert(errMsg(resp));
					return;
				}
				if (resp.data.dry_run) {
					$btn.prop('disabled', false).text(T.btn_migrate);
					var p = resp.data.preview;
					alert(T.dry_run_tpl
						.replace('%source%', p.source_url)
						.replace('%file%',   p.would_save_as)
						.replace('%title%',  p.post_title)
						.replace('%alt%',    p.alt_text));
					return;
				}
				setStatus($row, resp.data.status);
				$row.find('.wks3m-import-btn, .wks3m-replace-btn').replaceWith(editMediaLink(resp.data.attachment_id));
			})
			.fail(function () {
				$btn.prop('disabled', false).text(T.btn_migrate);
				alert(T.error);
			});
	}

	function handleReplace(e) {
		var $btn = $(e.currentTarget);
		var id   = parseInt($btn.data('id'), 10);
		$btn.prop('disabled', true);
		post('wks3m_replace_row', { id: id })
			.done(function (resp) {
				if (!resp || !resp.success) { $btn.prop('disabled', false); alert(errMsg(resp)); return; }
				setStatus($btn.closest('tr'), 'replaced');
				$btn.replaceWith('<em>' + T.replaced + '</em>');
			})
			.fail(function () { $btn.prop('disabled', false); alert(T.error); });
	}

	/* ---------- Bulk import (parallel worker pool, stoppable) ----------
	 *
	 * N workers consume IDs from a shared queue. Each worker fires an AJAX
	 * request, waits for completion, then takes the next ID. When all workers
	 * have drained the queue, the bulk operation ends.
	 *
	 * Compared to the previous sequential loop this gives a 3–5× speedup on
	 * network-bound migrations (the bottleneck is the remote download), while
	 * keeping the server load bounded by `concurrency` (1..6).
	 */

	var bulk = null;

	function concurrency() {
		var n = parseInt(WKS3M.concurrency, 10);
		if (!n || n < 1) n = 3;
		return Math.min(6, n);
	}

	function drawBulk(prefix) {
		var done = bulk.ok + bulk.ko;
		var pct = bulk.total > 0 ? Math.round((done / bulk.total) * 100) : 0;
		var label = (prefix ? prefix + ' — ' : '') + pct + '% — ' +
			done + ' / ' + bulk.total + ' (✔ ' + bulk.ok + ' · ✖ ' + bulk.ko + ')';
		renderProgress($('#wks3m-bulk-progress'), pct, label);
	}

	function bulkWorker() {
		if (!bulk) return;
		if (!bulk.running) { bulkWorkerDone(); return; }
		if (bulk.cursor >= bulk.total) { bulkWorkerDone(); return; }

		var id = bulk.ids[bulk.cursor++];
		post('wks3m_import_row', $.extend({ id: id }, importOptions())).always(function (resp) {
			var $row = $('tr[data-id="' + id + '"]');
			if (resp && resp.success) {
				bulk.ok++;
				if ($row.length) {
					setStatus($row, (resp.data && resp.data.dry_run) ? 'pending'
						: (resp.data && resp.data.status) || 'imported');
				}
			} else {
				bulk.ko++;
			}
			drawBulk();
			// Small stagger per worker to smooth thundering herd on very fast sources.
			setTimeout(bulkWorker, 20);
		});
	}

	function bulkWorkerDone() {
		if (!bulk) return;
		bulk.active--;
		if (bulk.active <= 0) endBulk(!bulk.running);
	}

	function endBulk(stopped) {
		var summary = bulk;
		$('#wks3m-bulk-spinner').removeClass('is-active');
		$('#wks3m-bulk-stop').prop('hidden', true).prop('disabled', false).text(T.stop);
		$('#wks3m-bulk-all').prop('disabled', false);
		if (!summary) return;
		drawBulk(stopped ? T.stopped : T.done);
		bulk = null;
		if (summary.total === 0) return;
		setTimeout(function () {
			var head = (stopped ? T.stopped : T.done) + ' (✔ ' + summary.ok + ' · ✖ ' + summary.ko + ')';
			if (window.confirm(head + '\n\n' + T.reload_prompt)) location.reload();
		}, 200);
	}

	function startBulk(ids) {
		if (!ids || !ids.length) { alert(T.nothing_to_do); return; }
		if (!$('#wks3m-dry-run').is(':checked') && !window.confirm(T.confirm_bulk)) return;

		var n = Math.min(concurrency(), ids.length);
		bulk = {
			ids: ids,
			cursor: 0,
			total: ids.length,
			ok: 0, ko: 0,
			running: true,
			active: n
		};
		$('#wks3m-bulk-all').prop('disabled', true);
		$('#wks3m-bulk-stop').prop('hidden', false);
		$('#wks3m-bulk-spinner').addClass('is-active');
		drawBulk(T.bulk_progress);
		for (var i = 0; i < n; i++) bulkWorker();
	}

	function handleStop() {
		if (!bulk) return;
		bulk.running = false;
		$('#wks3m-bulk-stop').prop('disabled', true).text(T.stopping);
	}

	/* ---------- Finalize deferred thumbnails (parallel, stoppable) ---------- */

	var finalize = null;

	function drawFinalize(prefix) {
		var done = finalize.ok + finalize.ko;
		var pct = finalize.total > 0 ? Math.round((done / finalize.total) * 100) : 0;
		var label = (prefix ? prefix + ' — ' : '') + pct + '% — ' +
			done + ' / ' + finalize.total + ' (✔ ' + finalize.ok + ' · ✖ ' + finalize.ko + ')';
		renderProgress($('#wks3m-finalize-progress'), pct, label);
	}

	function finalizeWorker() {
		if (!finalize) return;
		if (!finalize.running) { finalizeWorkerDone(); return; }
		if (finalize.cursor >= finalize.total) { finalizeWorkerDone(); return; }

		var id = finalize.ids[finalize.cursor++];
		post('wks3m_finalize_thumb', { id: id }).always(function (resp) {
			if (resp && resp.success) finalize.ok++; else finalize.ko++;
			drawFinalize();
			setTimeout(finalizeWorker, 20);
		});
	}

	function finalizeWorkerDone() {
		if (!finalize) return;
		finalize.active--;
		if (finalize.active <= 0) endFinalize(!finalize.running);
	}

	function endFinalize(stopped) {
		var summary = finalize;
		$('#wks3m-finalize-spinner').removeClass('is-active');
		$('#wks3m-finalize-stop').prop('hidden', true).prop('disabled', false).text(T.stop);
		$('#wks3m-finalize-thumbs').prop('disabled', false);
		if (!summary) return;
		drawFinalize(stopped ? T.stopped : T.done);
		finalize = null;
	}

	function handleFinalize() {
		if (!window.confirm(T.confirm_finalize)) return;
		$('#wks3m-finalize-thumbs').prop('disabled', true);
		post('wks3m_pending_thumb_ids').done(function (resp) {
			var ids = (resp && resp.success && resp.data.ids) || [];
			if (!ids.length) { alert(T.finalize_none); $('#wks3m-finalize-thumbs').prop('disabled', false); return; }
			var n = Math.min(concurrency(), ids.length);
			finalize = { ids: ids, cursor: 0, total: ids.length, ok: 0, ko: 0, running: true, active: n };
			$('#wks3m-finalize-stop').prop('hidden', false);
			$('#wks3m-finalize-spinner').addClass('is-active');
			drawFinalize(T.finalize_progress);
			for (var i = 0; i < n; i++) finalizeWorker();
		}).fail(function () {
			alert(T.error);
			$('#wks3m-finalize-thumbs').prop('disabled', false);
		});
	}

	function handleFinalizeStop() {
		if (!finalize) return;
		finalize.running = false;
		$('#wks3m-finalize-stop').prop('disabled', true).text(T.stopping);
	}

	function handleBulkAll() {
		post('wks3m_pending_ids')
			.done(function (resp) {
				if (!resp || !resp.success) return alert(T.error);
				startBulk(resp.data.ids || []);
			})
			.fail(function () { alert(T.error); });
	}

	/* ---------- Queue: Transform rule (bulk ALT/title cleanup) ---------- */

	function transformRule() {
		return {
			field:              $('#wks3m-tr-field').val(),
			condition_type:     $('#wks3m-tr-cond').val(),
			condition_value:    $('#wks3m-tr-cond-value').val(),
			action_type:        $('#wks3m-tr-action').val(),
			action_value:       $('#wks3m-tr-action-value').val(),
			update_attachments: $('#wks3m-tr-update-attachments').is(':checked') ? 1 : 0
		};
	}

	function refreshTransformInputs() {
		var cond = $('#wks3m-tr-cond').val();
		$('#wks3m-tr-cond-value').prop('hidden', cond === 'empty');

		var action = $('#wks3m-tr-action').val();
		$('#wks3m-tr-action-value').prop('hidden', action !== 'set_literal' && action !== 'remove_substring');

		$('#wks3m-tr-apply-btn').prop('disabled', true);
	}

	function transformValid(rule) {
		if (!rule.field || !rule.condition_type || !rule.action_type) return false;
		if (rule.condition_type !== 'empty' && !rule.condition_value)  return false;
		if (rule.action_type === 'remove_substring' && !rule.action_value) return false;
		return true;
	}

	function renderTransformPreview(data) {
		$('#wks3m-tr-results').prop('hidden', false)
			.find('.wks3m-tr-counts').html(
				'<p><strong>' + data.rows + '</strong> ligne(s) seraient modifiées · ' +
				'<strong>' + data.attachments + '</strong> attachment(s) déjà importé(s) concerné(s).</p>'
			);
		var $sample = $('#wks3m-tr-sample');
		var $tbody  = $sample.find('tbody').empty();
		(data.sample || []).forEach(function (s) {
			$tbody.append(
				'<tr><td>#' + s.id + '</td>' +
				'<td>' + esc(s.before) + '</td>' +
				'<td><strong>' + esc(s.after) + '</strong></td></tr>'
			);
		});
		$sample.prop('hidden', !(data.sample && data.sample.length));
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

	function runTransform(action, renderer, confirmFirst) {
		var rule = transformRule();
		if (!transformValid(rule)) { alert(T.tr_invalid); return; }
		if (confirmFirst && !window.confirm(T.tr_confirm)) return;
		$('#wks3m-tr-spinner').addClass('is-active');
		if (confirmFirst) $('#wks3m-tr-apply-btn').prop('disabled', true);
		post(action, rule)
			.done(function (resp) {
				$('#wks3m-tr-spinner').removeClass('is-active');
				if (!resp || !resp.success) return alert(errMsg(resp));
				renderer(resp.data);
			})
			.fail(function () {
				$('#wks3m-tr-spinner').removeClass('is-active');
				alert(T.error);
			});
	}

	/* ---------- Alt sync: scan ---------- */

	var altScan = null;

	function runAltScanBatch() {
		if (!altScan || !altScan.running) return;
		post('wks3m_alt_scan_batch', { offset: altScan.offset, limit: altScan.limit })
			.done(function (resp) {
				if (!resp || !resp.success) return endAltScan(true);
				altScan.total      = resp.data.total;
				altScan.processed += resp.data.processed;
				altScan.imgs      += resp.data.imgs_scanned;
				altScan.diffs     += resp.data.diffs_found;
				altScan.offset    = resp.data.next_offset;
				updateAltScanUI();
				if (resp.data.processed > 0 && altScan.offset < altScan.total) {
					setTimeout(runAltScanBatch, 50);
				} else {
					endAltScan(false);
				}
			})
			.fail(function () { endAltScan(true); });
	}

	function updateAltScanUI() {
		var pct = altScan.total > 0 ? Math.min(100, Math.round((altScan.processed / altScan.total) * 100)) : 0;
		renderProgress($('#wks3m-alt-scan-progress'), pct, pct + '% — ' + altScan.processed + ' / ' + altScan.total);
		$('#wks3m-alt-scan-summary').prop('hidden', false)
			.find('.processed').text(altScan.processed + ' / ' + altScan.total).end()
			.find('.imgs').text(altScan.imgs).end()
			.find('.diffs').text(altScan.diffs);
	}

	function endAltScan(isError) {
		if (!altScan) return;
		altScan.running = false;
		$('#wks3m-alt-scan-spinner').removeClass('is-active');
		$('#wks3m-alt-scan-start').prop('disabled', false);
		if (isError) { alert(T.error); return; }
		$('#wks3m-alt-scan-done').prop('hidden', false);
	}

	function startAltScan() {
		altScan = {
			offset: 0,
			limit: parseInt($('#wks3m-alt-scan-batch').val(), 10) || 50,
			total: 0, processed: 0, imgs: 0, diffs: 0,
			running: true
		};
		$('#wks3m-alt-scan-start').prop('disabled', true);
		$('#wks3m-alt-scan-spinner').addClass('is-active');
		$('#wks3m-alt-scan-done').prop('hidden', true);
		renderProgress($('#wks3m-alt-scan-progress'), 0, T.alt_scan_progress);
		runAltScanBatch();
	}

	/* ---------- Alt sync: per-row apply ---------- */

	function handleAltApply(e) {
		var $btn = $(e.currentTarget);
		var id   = parseInt($btn.data('id'), 10);
		$btn.prop('disabled', true);
		post('wks3m_alt_apply_diff', { id: id })
			.done(function (resp) {
				var $row = $btn.closest('tr');
				if (!resp || !resp.success) { $btn.prop('disabled', false); alert(errMsg(resp)); return; }
				$row.fadeOut(200, function () { $(this).remove(); });
			})
			.fail(function () { $btn.prop('disabled', false); alert(T.error); });
	}

	/* ---------- Alt sync: bulk apply (parallel worker pool) ---------- */

	var altBulk = null;

	function drawAltBulk(prefix) {
		var done = altBulk.ok + altBulk.ko;
		var pct = altBulk.total > 0 ? Math.round((done / altBulk.total) * 100) : 0;
		var label = (prefix ? prefix + ' — ' : '') + pct + '% — ' +
			done + ' / ' + altBulk.total + ' (✔ ' + altBulk.ok + ' · ✖ ' + altBulk.ko + ')';
		renderProgress($('#wks3m-alt-bulk-progress'), pct, label);
	}

	function altBulkWorker() {
		if (!altBulk) return;
		if (!altBulk.running) { altBulkWorkerDone(); return; }
		if (altBulk.cursor >= altBulk.total) { altBulkWorkerDone(); return; }

		var id = altBulk.ids[altBulk.cursor++];
		post('wks3m_alt_apply_diff', { id: id }).always(function (resp) {
			if (resp && resp.success) altBulk.ok++; else altBulk.ko++;
			$('tr[data-diff-id="' + id + '"]').fadeOut(120, function () { $(this).remove(); });
			drawAltBulk();
			setTimeout(altBulkWorker, 20);
		});
	}

	function altBulkWorkerDone() {
		if (!altBulk) return;
		altBulk.active--;
		if (altBulk.active <= 0) endAltBulk(!altBulk.running);
	}

	function endAltBulk(stopped) {
		var summary = altBulk;
		$('#wks3m-alt-bulk-spinner').removeClass('is-active');
		$('#wks3m-alt-bulk-stop').prop('hidden', true).prop('disabled', false).text(T.stop);
		$('#wks3m-alt-bulk-apply').prop('disabled', false);
		if (!summary) return;
		drawAltBulk(stopped ? T.stopped : T.done);
		altBulk = null;
		if (summary.total === 0) return;
		setTimeout(function () {
			var head = (stopped ? T.stopped : T.done) + ' (✔ ' + summary.ok + ' · ✖ ' + summary.ko + ')';
			if (window.confirm(head + '\n\n' + T.reload_prompt)) location.reload();
		}, 200);
	}

	function startAltBulk(ids) {
		if (!ids || !ids.length) { alert(T.alt_nothing); return; }
		if (!window.confirm(T.confirm_alt_apply)) return;

		var n = Math.min(concurrency(), ids.length);
		altBulk = {
			ids: ids, cursor: 0, total: ids.length,
			ok: 0, ko: 0, running: true, active: n
		};
		$('#wks3m-alt-bulk-apply').prop('disabled', true);
		$('#wks3m-alt-bulk-stop').prop('hidden', false);
		$('#wks3m-alt-bulk-spinner').addClass('is-active');
		drawAltBulk(T.alt_apply_progress);
		for (var i = 0; i < n; i++) altBulkWorker();
	}

	function handleAltBulkApply() {
		post('wks3m_alt_diff_ids')
			.done(function (resp) {
				if (!resp || !resp.success) return alert(T.error);
				startAltBulk(resp.data.ids || []);
			})
			.fail(function () { alert(T.error); });
	}

	function handleAltBulkStop() {
		if (!altBulk) return;
		altBulk.running = false;
		$('#wks3m-alt-bulk-stop').prop('disabled', true).text(T.stopping);
	}

	/* ---------- Bind ---------- */

	$(function () {
		// Scan.
		$('#wks3m-scan-start').on('click', startScan);

		// Queue per-row.
		$(document).on('click', '.wks3m-import-btn',  handleImport);
		$(document).on('click', '.wks3m-replace-btn', handleReplace);

		// Bulk migration.
		$('#wks3m-bulk-all').on('click',  handleBulkAll);
		$('#wks3m-bulk-stop').on('click', handleStop);

		// Finalize deferred thumbnails.
		$('#wks3m-finalize-thumbs').on('click', handleFinalize);
		$('#wks3m-finalize-stop').on('click',   handleFinalizeStop);

		// Alt sync.
		$('#wks3m-alt-scan-start').on('click',   startAltScan);
		$('#wks3m-alt-bulk-apply').on('click',   handleAltBulkApply);
		$('#wks3m-alt-bulk-stop').on('click',    handleAltBulkStop);
		$(document).on('click', '.wks3m-alt-apply-btn', handleAltApply);

		// Queue: Transform (bulk ALT/title rule).
		if ($('#wks3m-tr-form').length) {
			refreshTransformInputs();
			$('#wks3m-tr-cond, #wks3m-tr-action').on('change', refreshTransformInputs);
			$('#wks3m-tr-form :input').on('input', function () {
				$('#wks3m-tr-apply-btn').prop('disabled', true);
			});
			$('#wks3m-tr-preview-btn').on('click', function () {
				runTransform('wks3m_transform_preview', renderTransformPreview, false);
			});
			$('#wks3m-tr-apply-btn').on('click', function () {
				runTransform('wks3m_transform_apply', renderTransformResult, true);
			});
		}
	});
}(jQuery));
