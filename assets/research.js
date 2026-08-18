(function () {
    'use strict';

    function boot() {
        var cfg = window.TL_AI_VM_RESEARCH || {};
        var boxes = document.querySelectorAll('.tl-ai-vm-research-box');
        if (!boxes.length) return;

        function post(box, action, data) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', box.getAttribute('data-research-nonce') || cfg.nonce || '');
            Object.keys(data || {}).forEach(function (key) { body.append(key, data[key] == null ? '' : data[key]); });
            return fetch(cfg.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (r) {
                return r.text().then(function (text) {
                    var json;
                    try { json = JSON.parse(text); }
                    catch (e) { throw new Error('AJAX پاسخ معتبر برنگرداند. HTTP ' + r.status + '.'); }
                    if (!r.ok) throw new Error((json && json.data && json.data.message) || 'HTTP ' + r.status);
                    return json;
                });
            });
        }

        function init(box) {
            var start = box.querySelector('.tl-ai-vm-start-research');
            var wrap = box.querySelector('.tl-ai-vm-progress-wrap');
            var bar = box.querySelector('.tl-ai-vm-progress-bar span');
            var text = box.querySelector('.tl-ai-vm-progress-text');
            var stage = box.querySelector('.tl-ai-vm-progress-stage');
            var stats = box.querySelector('.tl-ai-vm-progress-stats');
            var errors = box.querySelector('.tl-ai-vm-progress-errors');
            var cancel = box.querySelector('.tl-ai-vm-cancel-research');
            var vehicleSelect = box.querySelector('.tl-ai-vm-dashboard-vehicle');
            var onlyEmpty = box.querySelector('.tl-ai-vm-only-empty');
            var timer = null;

            if (!start || !wrap || !bar || !text || !stage || !stats || !errors || !cancel) return;

            function vehicleId() { return vehicleSelect ? String(vehicleSelect.value || '') : String(box.getAttribute('data-vehicle-id') || ''); }
            function key() { return 'tl_ai_vm_research_job_' + vehicleId(); }
            function showError(msg) { errors.textContent = msg || 'Unknown error.'; errors.style.display = 'block'; }
            function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (m) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; }); }
            function valueText(v) { if (Array.isArray(v)) return v.join(', '); if (v && typeof v === 'object') return JSON.stringify(v); return v == null ? '' : String(v); }
            function selectedGroups() { return Array.prototype.map.call(box.querySelectorAll('.tl-ai-vm-research-group:checked'), function (el) { return el.value; }); }

            function renderResults(results) {
                if (!results || !results.length) return;
                var target = box.querySelector('.tl-ai-vm-live-results');
                if (!target) return;
                var threshold = parseInt(cfg.autoThreshold || 90, 10);
                var html = '<div class="tl-ai-vm-live-results-inner"><div class="tl-ai-vm-live-results-head"><strong>Research Results</strong><button type="button" class="button button-secondary tl-ai-vm-approve-high">Approve all ≥ ' + threshold + '%</button></div><div class="tl-ai-vm-results-table-wrap"><table class="widefat striped tl-ai-vm-results-table"><thead><tr><th>Field</th><th>Found value</th><th>Confidence</th><th>Decision</th><th>Source</th><th>Action</th></tr></thead><tbody>';
                results.forEach(function (r) {
                    var c = parseInt(r.confidence || 0, 10), status = r.status || '', decision = r.decision || 'review';
                    var action = '';
                    if (status !== 'approved' && status !== 'rejected' && r.id) { action = '<div class="tl-ai-vm-result-actions"><button type="button" class="button button-small button-primary tl-ai-vm-approve-one" data-result-id="' + esc(r.id) + '">✓ Approve & Write</button> <button type="button" class="button button-small tl-ai-vm-correct-one" data-result-id="' + esc(r.id) + '">✎ Correct</button> <button type="button" class="button button-small tl-ai-vm-reject-one" data-result-id="' + esc(r.id) + '">✕ Reject</button><div class="tl-ai-vm-correct-form" data-result-id="' + esc(r.id) + '" style="display:none;margin-top:8px;"><input type="text" class="regular-text tl-ai-vm-correct-value" value="' + esc(valueText(r.value)) + '" placeholder="Correct value" /><input type="text" class="regular-text tl-ai-vm-correct-source" placeholder="Preferred source/domain (optional)" /><input type="text" class="regular-text tl-ai-vm-correct-note" placeholder="Why / field rule (optional)" /><button type="button" class="button button-small button-primary tl-ai-vm-save-correction">Save correction</button></div></div>'; }
                    else if (status === 'approved' || status === 'corrected') action = '<span class="tl-ai-vm-status-success">✓ Written</span>';
                    else if (status === 'rejected') action = '<span class="tl-ai-vm-status-rejected">✕ Rejected</span>';
                    var src = '';
                    if (r.sources && r.sources.length && r.sources[0].url) src = '<a href="' + esc(r.sources[0].url) + '" target="_blank" rel="noopener">Source</a>';
                    html += '<tr><td>' + esc(r.field_label || r.field_key || 'Field') + '</td><td><strong>' + esc(valueText(r.value)) + '</strong></td><td>' + c + '%</td><td>' + esc(decision) + ' / ' + esc(status) + '</td><td>' + src + '</td><td>' + action + '</td></tr>';
                });
                html += '</tbody></table></div></div>';
                target.innerHTML = html;
                target.style.display = 'block';
                bindActions(target);
            }

            function loadResults() {
                var id = vehicleId(); if (!id) return;
                post(box, 'tl_ai_vm_research_results', { vehicle_id: id }).then(function (res) {
                    if (res && res.success && res.data) renderResults(res.data.results || []);
                }).catch(function () {});
            }

            function bindActions(target) {
                var high = target.querySelector('.tl-ai-vm-approve-high');
                if (high) high.addEventListener('click', function () {
                    high.disabled = true;
                    post(box, 'tl_ai_vm_approve_high_confidence', { vehicle_id: vehicleId() }).then(function (res) {
                        if (!res.success) throw new Error(res.data && res.data.message || 'Approval failed.');
                        loadResults();
                    }).catch(function (e) { showError(e.message); high.disabled = false; });
                });
                target.querySelectorAll('.tl-ai-vm-correct-one').forEach(function (btn) { btn.addEventListener('click', function () { var f=target.querySelector('.tl-ai-vm-correct-form[data-result-id="'+btn.getAttribute('data-result-id')+'"]'); if(f) f.style.display=f.style.display==='none'?'block':'none'; }); });
                target.querySelectorAll('.tl-ai-vm-save-correction').forEach(function (btn) { btn.addEventListener('click', function () { var f=btn.closest('.tl-ai-vm-correct-form'); var id=f.getAttribute('data-result-id'); var value=f.querySelector('.tl-ai-vm-correct-value').value; var source=f.querySelector('.tl-ai-vm-correct-source').value; var note=f.querySelector('.tl-ai-vm-correct-note').value; if(!value){showError('Correct value is required.');return;} btn.disabled=true; post(box,'tl_ai_vm_correct_result',{result_id:id,value:value,source:source,note:note}).then(function(res){if(!res.success)throw new Error(res.data&&res.data.message||'Correction failed.');loadResults();}).catch(function(e){showError(e.message);}).finally(function(){btn.disabled=false;}); }); });
                target.querySelectorAll('.tl-ai-vm-reject-one').forEach(function (btn) { btn.addEventListener('click', function () { var note=window.prompt('دلیل رد این نتیجه (اختیاری):',''); btn.disabled=true; post(box,'tl_ai_vm_reject_result',{result_id:btn.getAttribute('data-result-id'),note:note||''}).then(function(res){if(!res.success)throw new Error(res.data&&res.data.message||'Reject failed.');loadResults();}).catch(function(e){showError(e.message);btn.disabled=false;}); }); });
                target.querySelectorAll('.tl-ai-vm-approve-one').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        post(box, 'tl_ai_vm_approve_result', { result_id: btn.getAttribute('data-result-id') }).then(function (res) {
                            if (!res.success) throw new Error(res.data && res.data.message || 'Approval failed.');
                            loadResults();
                        }).catch(function (e) { showError(e.message); btn.disabled = false; });
                    });
                });
            }

            function renderActivity(data) {
                var target = box.querySelector('.tl-ai-vm-live-activity');
                if (!target) return;
                var fields = data.active_fields || [];
                var queries = data.batch_queries || [];
                var sources = data.batch_sources || [];
                if (!fields.length && !queries.length && !sources.length) { target.style.display='none'; return; }
                var html = '<div class=\"tl-ai-vm-live-activity-inner\"><div class=\"tl-ai-vm-live-activity-title\"><strong>🔎 Live Research Monitor</strong><span>5 research lanes</span></div>';
                if (fields.length) {
                    html += '<div class=\"tl-ai-vm-active-grid\">';
                    fields.forEach(function(f, i){
                        var status = f.status || 'queued';
                        var cls = status === 'done' ? 'done' : (status === 'processing' || status === 'researching' || status === 'validating' ? 'active' : 'waiting');
                        var sourcesText = '';
                        if (f.sources && f.sources.length) sourcesText = '<div class=\"tl-ai-vm-lane-sources\">' + f.sources.slice(0,3).map(function(src){ return '<a href=\"'+esc(src.url||'#')+'\" target=\"_blank\" rel=\"noopener\">'+esc(src.domain||src.title||'source')+'</a>'; }).join(' · ') + '</div>';
                        html += '<div class=\"tl-ai-vm-research-lane '+cls+'\"><div class=\"tl-ai-vm-lane-head\"><span class=\"tl-ai-vm-lane-number\">'+(i+1)+'</span><strong>'+esc(f.label||'Field')+'</strong><span class=\"tl-ai-vm-lane-status\">'+esc(status)+'</span></div><div class=\"tl-ai-vm-lane-stage\">'+esc(f.message||f.stage||'Waiting…')+'</div>'+(f.value?'<div class=\"tl-ai-vm-lane-value\">Candidate: <b>'+esc(valueText(f.value))+'</b></div>':'')+sourcesText+'</div>';
                    });
                    html += '</div>';
                }
                if (queries.length) { html += '<details class=\"tl-ai-vm-research-details\"><summary>Queries being checked ('+queries.length+')</summary><ul>'+queries.map(function(q){return '<li>'+esc(q)+'</li>';}).join('')+'</ul></details>'; }
                if (sources.length) { html += '<details class=\"tl-ai-vm-research-details\"><summary>Sources being compared ('+sources.length+')</summary><ul>'+sources.map(function(src){return '<li><a href=\"'+esc(src.url||'#')+'\" target=\"_blank\" rel=\"noopener\">'+esc(src.title||src.domain||src.url||'Source')+'</a></li>';}).join('')+'</ul></details>'; }
                html += '</div>';
                target.innerHTML=html; target.style.display='block';
            }

            function render(data) {
                renderActivity(data);
                var p = Math.max(0, Math.min(100, parseInt(data.progress || 0, 10)));
                wrap.style.display = 'block'; bar.style.width = p + '%';
                text.textContent = (data.message || 'Working…') + ' ' + p + '%';
                stage.textContent = data.stage ? 'Stage: ' + data.stage : '';
                var s = data.stats || {};
                stats.textContent = 'Results: ' + (s.created || 0) + ' · Auto approved: ' + (s.auto_written || 0) + ' · Review: ' + (s.review || 0) + ' · Failed fields: ' + (s.failed || 0);
                if (data.errors && data.errors.length) { showError(data.errors.map(function (e) { return (e.field ? e.field + ': ' : '') + e.error; }).join(' | ')); }
                if (data.results && data.results.length) renderResults(data.results);
                if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled') {
                    start.disabled = false;
                    start.textContent = data.status === 'completed' ? '✓ Research Completed — Run Again' : (data.status === 'failed' ? 'Retry Research' : 'Research Cancelled — Start Again');
                    cancel.style.display = 'none';
                    if (timer) clearTimeout(timer);
                    if (data.status === 'completed') localStorage.setItem(key(), data.job_id || localStorage.getItem(key()) || '');
                    else localStorage.removeItem(key());
                    loadResults();
                }
            }

            function poll(jobId) {
                post(box, 'tl_ai_vm_research_tick', { job_id: jobId }).then(function (res) {
                    if (!res || !res.success) throw new Error(res && res.data && res.data.message || 'Research step failed.');
                    render(res.data);
                    if (res.data.status !== 'completed' && res.data.status !== 'failed' && res.data.status !== 'cancelled') timer = setTimeout(function () { poll(jobId); }, parseInt(cfg.pollMs || 1200, 10));
                }).catch(function (e) { showError(e.message); timer = setTimeout(function () { poll(jobId); }, 4000); });
            }

            start.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (start.disabled) return;
                var id = vehicleId();
                errors.style.display = 'none';
                if (!id) { showError('ابتدا یک خودرو انتخاب کنید.'); return; }
                start.disabled = true; start.textContent = 'Starting…';
                post(box, 'tl_ai_vm_start_research_async', { vehicle_id: id, only_empty: onlyEmpty && onlyEmpty.checked ? '1' : '0', selected_groups: selectedGroups().join(',') }).then(function (res) {
                    if (!res || !res.success) throw new Error(res && res.data && res.data.message || 'Could not start research.');
                    var jobId = res.data.job_id;
                    if (!jobId) throw new Error('Research job ID was not returned by server.');
                    localStorage.setItem(key(), jobId);
                    render({ progress: 0, status: 'running', stage: 'prepare', message: 'Research job created…', stats: {}, job_id: jobId });
                    cancel.style.display = 'inline-block';
                    poll(jobId);
                }).catch(function (e) { start.disabled = false; start.textContent = '🔍 Research This Vehicle'; showError(e.message); });
            });

            if (vehicleSelect) vehicleSelect.addEventListener('change', function () {
                box.setAttribute('data-vehicle-id', vehicleId());
                wrap.style.display = 'none'; errors.style.display = 'none'; start.disabled = false; start.textContent = '🔍 Research This Vehicle'; loadResults();
            });
            cancel.addEventListener('click', function () {
                var id = localStorage.getItem(key()); if (!id) return;
                cancel.disabled = true;
                post(box, 'tl_ai_vm_cancel_research', { job_id: id }).then(function () { localStorage.removeItem(key()); cancel.style.display = 'none'; cancel.disabled = false; start.disabled = false; start.textContent = 'Research Cancelled — Start Again'; if (timer) clearTimeout(timer); }).catch(function (e) { cancel.disabled = false; showError(e.message); });
            });

            if (vehicleId()) loadResults();
            var saved = localStorage.getItem(key());
            if (saved) {
                start.disabled = true; start.textContent = 'Research in progress…'; cancel.style.display = 'inline-block';
                post(box, 'tl_ai_vm_research_status', { job_id: saved }).then(function (res) {
                    if (res && res.success) { render(res.data); if (res.data.status !== 'completed' && res.data.status !== 'failed' && res.data.status !== 'cancelled') poll(saved); }
                    else { localStorage.removeItem(key()); start.disabled = false; cancel.style.display = 'none'; }
                }).catch(function () { poll(saved); });
            } else cancel.style.display = 'none';
        }

        Array.prototype.forEach.call(boxes, init);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
