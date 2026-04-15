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

	function handleRollback(e) {
		if (!window.confirm(T.confirm_rollback)) return;
		var $btn = $(e.currentTarget);
		var id   = parseInt($btn.data('id'), 10);
		$btn.prop('disabled', true);
		post('wks3m_rollback_row', { id: id })
			.done(function (resp) {
				if (!resp || !resp.success) { $btn.prop('disabled', false); alert(errMsg(resp)); return; }
				setStatus($btn.closest('tr'), 'rolled_back');
				$btn.replaceWith('<em>' + T.rolled_back + '</em>');
			})
			.fail(function () { $btn.prop('disabled', false); alert(T.error); });
	}

	/* ---------- Bulk driver (sequential, stoppable, shared between migrate & rollback) ---------- */

	var bulk = null;

	function bulkSummary(prefix) {
		var pct = bulk.total > 0 ? Math.round((bulk.index / bulk.total) * 100) : 0;
		return (prefix ? prefix + ' — ' : '') + pct + '% — ' +
			bulk.index + ' / ' + bulk.total + ' (✔ ' + bulk.ok + ' · ✖ ' + bulk.ko + ')';
	}

	function drawBulk(prefix) {
		var pct = bulk.total > 0 ? Math.round((bulk.index / bulk.total) * 100) : 0;
		renderProgress($('#wks3m-bulk-progress'), pct, bulkSummary(prefix));
	}

	function bulkNext() {
		if (!bulk) return;
		if (!bulk.running)               return endBulk(true);
		if (bulk.index >= bulk.total)    return endBulk(false);

		var id = bulk.ids[bulk.index];
		var isRollback = bulk.kind === 'rollback';
		var action  = isRollback ? 'wks3m_rollback_row' : 'wks3m_import_row';
		var payload = isRollback ? { id: id } : $.extend({ id: id }, importOptions());

		post(action, payload).always(function (resp) {
			var $row = $('tr[data-id="' + id + '"]');
			if (resp && resp.success) {
				bulk.ok++;
				if ($row.length) {
					setStatus($row, isRollback
						? 'rolled_back'
						: ((resp.data && resp.data.dry_run) ? 'pending'
							: (resp.data && resp.data.status) || 'imported'));
				}
			} else {
				bulk.ko++;
			}
			bulk.index++;
			drawBulk();
			setTimeout(bulkNext, 50);
		});
	}

	function endBulk(stopped) {
		var summary = bulk;
		$('#wks3m-bulk-spinner').removeClass('is-active');
		$('#wks3m-bulk-stop').prop('hidden', true).prop('disabled', false).text(T.stop);
		$('#wks3m-bulk-all, #wks3m-bulk-selected, #wks3m-rollback-all').prop('disabled', false);
		bulk = null;
		if (!summary) return;
		bulk = summary;                    // keep for drawBulk label
		drawBulk(stopped ? T.stopped : T.done);
		bulk = null;
		if (summary.total === 0) return;
		setTimeout(function () {
			var head = (stopped ? T.stopped : T.done) + ' (✔ ' + summary.ok + ' · ✖ ' + summary.ko + ')';
			if (window.confirm(head + '\n\n' + T.reload_prompt)) location.reload();
		}, 200);
	}

	function startBulk(kind, ids, confirmKey) {
		if (!ids || !ids.length) { alert(T.nothing_to_do); return; }
		if (confirmKey && !window.confirm(T[confirmKey])) return;

		bulk = { kind: kind, ids: ids, index: 0, total: ids.length, ok: 0, ko: 0, running: true };
		$('#wks3m-bulk-all, #wks3m-bulk-selected, #wks3m-rollback-all').prop('disabled', true);
		$('#wks3m-bulk-stop').prop('hidden', false);
		$('#wks3m-bulk-spinner').addClass('is-active');
		drawBulk(kind === 'rollback' ? T.rollback_progress : T.bulk_progress);
		bulkNext();
	}

	function handleStop() {
		if (!bulk) return;
		bulk.running = false;
		$('#wks3m-bulk-stop').prop('disabled', true).text(T.stopping);
	}

	function handleBulkAll() {
		var needsConfirm = $('#wks3m-dry-run').is(':checked') ? null : 'confirm_bulk';
		post('wks3m_pending_ids')
			.done(function (resp) {
				if (!resp || !resp.success) return alert(T.error);
				startBulk('import', resp.data.ids || [], needsConfirm);
			})
			.fail(function () { alert(T.error); });
	}

	function handleBulkSelected() {
		var needsConfirm = $('#wks3m-dry-run').is(':checked') ? null : 'confirm_bulk';
		var ids = $('.wks3m-row-check:checked').map(function () { return parseInt(this.value, 10); }).get();
		startBulk('import', ids, needsConfirm);
	}

	function handleRollbackAll() {
		post('wks3m_rollbackable_ids')
			.done(function (resp) {
				if (!resp || !resp.success) return alert(T.error);
				startBulk('rollback', resp.data.ids || [], 'confirm_rollback_all');
			})
			.fail(function () { alert(T.error); });
	}

	/* ---------- Settings: Transform rule ---------- */

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

	/* ---------- Bind ---------- */

	$(function () {
		// Scan.
		$('#wks3m-scan-start').on('click', startScan);

		// Queue / History per-row.
		$(document).on('click', '.wks3m-import-btn',   handleImport);
		$(document).on('click', '.wks3m-replace-btn',  handleReplace);
		$(document).on('click', '.wks3m-rollback-btn', handleRollback);

		// Bulk.
		$('#wks3m-bulk-all').on('click',       handleBulkAll);
		$('#wks3m-bulk-selected').on('click',  handleBulkSelected);
		$('#wks3m-rollback-all').on('click',   handleRollbackAll);
		$('#wks3m-bulk-stop').on('click',      handleStop);
		$('#wks3m-select-all').on('change', function () {
			$('.wks3m-row-check').prop('checked', $(this).is(':checked'));
		});

		// Transform.
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
