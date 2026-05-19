/*
 * Tainacan Index Manager — Admin SPA (Vue 3).
 *
 * Single Vue app mounted on #tainacan-idxmgr-app inside Tainacan's
 * native page chrome (tainacan-page-container-content + tainacan-fixed-subheader).
 * The `data-view` attribute on the root element selects between "dashboard"
 * and "settings" views. All server interaction goes through the plugin's
 * REST namespace, authenticated via cookie + X-WP-Nonce.
 */
(function () {
	'use strict';

	if (typeof window.Vue === 'undefined') {
		console.error('[Tainacan Index Manager] Vue não carregado.');
		return;
	}
	if (typeof window.TIMConfig === 'undefined') {
		console.error('[Tainacan Index Manager] TIMConfig ausente.');
		return;
	}

	var root = document.getElementById('tainacan-idxmgr-app');
	if (!root) return;

	var i18n = window.TIMConfig.i18n || {};
	var initialView = root.getAttribute('data-view') || window.TIMConfig.view || 'dashboard';

	function api(method, path, body) {
		var url = window.TIMConfig.restRoot.replace(/\/$/, '') + path;
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.TIMConfig.restNonce
			}
		};
		if (body) opts.body = JSON.stringify(body);
		return fetch(url, opts).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					var err = new Error((data && data.message) || ('HTTP ' + res.status));
					err.data = data;
					throw err;
				}
				return data;
			});
		});
	}

	function fmtNumber(n) {
		if (n === null || typeof n === 'undefined') return '—';
		try { return Number(n).toLocaleString('pt-BR'); } catch (e) { return String(n); }
	}

	function fmtBytes(b) {
		if (!b && b !== 0) return '—';
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;
		while (b >= 1024 && i < units.length - 1) { b /= 1024; i++; }
		return b.toFixed(1) + ' ' + units[i];
	}

	function fmtDate(ts) {
		if (!ts) return i18n.never || 'Never';
		try { return new Date(ts * 1000).toLocaleString('pt-BR'); } catch (e) { return String(ts); }
	}

	function fmtMs(n) {
		if (n === null || typeof n === 'undefined') return '—';
		if (n < 1000) return n + ' ms';
		return (n / 1000).toFixed(2) + ' s';
	}

	function fmtFloat(n, d) {
		if (n === null || typeof n === 'undefined') return '—';
		try { return Number(n).toLocaleString('pt-BR', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 2 }); }
		catch (e) { return String(n); }
	}

	function statusToClass(s) {
		if (s === 'green' || s === 'ok')   return 'tim-green';
		if (s === 'yellow' || s === 'warning') return 'tim-yellow';
		if (s === 'red' || s === 'critical')   return 'tim-red';
		return 'tim-unknown';
	}

	function cardClass(s) {
		if (s === 'ok')       return 'is-ok';
		if (s === 'warning')  return 'is-warning';
		if (s === 'critical') return 'is-critical';
		return 'is-unknown';
	}

	function engineLabel(e) {
		switch (e) {
			case 'elasticpress':    return i18n.elasticpress;
			case 'own_indexer':     return i18n.own_indexer;
			case 'sql_fallback':    return i18n.sql_fallback;
			case 'engine_disabled': return i18n.engine_disabled;
		}
		return e || '—';
	}

	/**
	 * Tiny inline sparkline component. Pure canvas (no chart library);
	 * keeps bundle small and respects the "no CDN" rule from CLAUDE.md.
	 */
	var Sparkline = {
		props: ['values', 'color', 'height'],
		template: '<canvas ref="cv" class="tim-sparkline" :style="{ height: (height || 36) + \'px\' }"></canvas>',
		mounted: function () { this.draw(); },
		watch: { values: function () { this.draw(); } },
		methods: {
			draw: function () {
				var cv = this.$refs.cv;
				if (!cv) return;
				var dpr = window.devicePixelRatio || 1;
				var w = cv.clientWidth;
				var h = this.height || 36;
				cv.width = w * dpr; cv.height = h * dpr;
				var ctx = cv.getContext('2d');
				ctx.scale(dpr, dpr);
				ctx.clearRect(0, 0, w, h);
				var v = this.values || [];
				if (v.length === 0) return;
				var max = Math.max.apply(null, v); if (max === 0) max = 1;
				var min = Math.min.apply(null, v);
				var range = (max - min) || 1;
				var stepX = w / Math.max(1, v.length - 1);
				ctx.strokeStyle = this.color || '#298596';
				ctx.lineWidth = 2;
				ctx.lineJoin = 'round';
				ctx.lineCap = 'round';
				ctx.beginPath();
				v.forEach(function (val, i) {
					var x = i * stepX;
					var y = h - ((val - min) / range) * (h - 4) - 2;
					if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
				});
				ctx.stroke();
				// Soft fill under the line.
				var fill = ctx.createLinearGradient(0, 0, 0, h);
				fill.addColorStop(0, (this.color || '#298596') + '44');
				fill.addColorStop(1, (this.color || '#298596') + '00');
				ctx.lineTo(w, h); ctx.lineTo(0, h); ctx.closePath();
				ctx.fillStyle = fill;
				ctx.fill();
			}
		}
	};

	var DashboardView = {
		components: { Sparkline: Sparkline },
		template: '\
		<div>\
			<div v-if="initialLoading" class="tim-notice is-info"><span class="tim-loading"></span> {{ i18n.refresh }}…</div>\
			<div v-else-if="errorMsg" class="tim-notice is-error">{{ errorMsg }}</div>\
			<template v-else>\
				<section :class="[\'tim-diagnostic\', \'is-\' + (diagnostics.severity || \'unknown\')]" v-if="diagnostics && diagnostics.findings && diagnostics.findings.length">\
					<header class="tim-diagnostic-header">\
						<span :class="[\'tim-status-pill\', diagPillClass(diagnostics.severity)]">{{ diagSeverityLabel(diagnostics.severity) }}</span>\
						<h2>{{ diagnostics.headline }}</h2>\
					</header>\
					<ul class="tim-diagnostic-list">\
						<li v-for="(f, idx) in diagnostics.findings" :key="idx" :class="\'is-\' + f.severity">\
							<div class="tim-diagnostic-line">\
								<span :class="[\'tim-status-pill\', diagPillClass(f.severity)]">{{ diagSeverityShort(f.severity) }}</span>\
								<strong>{{ f.title }}</strong>\
							</div>\
							<p class="tim-diagnostic-msg">{{ f.message }}</p>\
							<p class="tim-diagnostic-action" v-if="f.action">\
								<span class="tim-action-label">Ação:</span> {{ f.action }}\
								<a v-if="f.action_url" :href="f.action_url" class="tim-btn is-secondary tim-action-cta">Abrir</a>\
							</p>\
						</li>\
					</ul>\
				</section>\
\
				<nav class="tim-tabs" role="tablist">\
					<button v-for="t in tabs" :key="t.id" :class="[\'tim-tab\', { \'is-active\': activeTab === t.id }]" @click="activeTab = t.id" role="tab" :aria-selected="activeTab === t.id">\
						{{ t.label }}<span class="tim-tab-badge" v-if="t.badge">{{ t.badge }}</span>\
					</button>\
				</nav>\
\
				<section v-if="activeTab === \'overview\'" role="tabpanel">\
				<div class="tim-cards">\
					<div :class="[\'tim-card\', cardClass(snapshot.overall_status)]">\
						<span class="tim-card-label">{{ i18n.overview }}</span>\
						<span class="tim-card-value">\
							<span :class="[\'tim-status-pill\', statusClass(snapshot.overall_status)]">{{ overallLabel(snapshot.overall_status) }}</span>\
						</span>\
						<span class="tim-card-sub">{{ snapshot.overall_message }}</span>\
					</div>\
					<div :class="[\'tim-card\', clusterClass(snapshot.cluster_status)]">\
						<span class="tim-card-label">{{ i18n.cluster }}</span>\
						<span class="tim-card-value">\
							<span :class="[\'tim-status-pill\', statusClass(snapshot.cluster_status)]">{{ (snapshot.cluster_status || \'—\').toUpperCase() }}</span>\
						</span>\
						<span class="tim-card-sub" v-if="snapshot.cluster">{{ snapshot.cluster.number_of_nodes }} nós · {{ snapshot.cluster.active_shards }} shards · {{ snapshot.cluster.unassigned_shards }} unassigned</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.response_time }}</span>\
						<span class="tim-card-value">{{ snapshot.es_ping_ms !== null ? snapshot.es_ping_ms + \' ms\' : \'—\' }}</span>\
						<span class="tim-card-sub">{{ snapshot.es_reachable ? i18n.connection_ok : i18n.connection_failed }}</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.effective_engine }}</span>\
						<span class="tim-card-value">{{ engineLabel(snapshot.effective_engine) }}</span>\
						<span class="tim-card-sub" v-if="snapshot.fallback_active">Fallback ativo</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.tainacan_total }}</span>\
						<span class="tim-card-value">{{ fmtNumber(snapshot.tainacan_item_count) }}</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.indexed_total }}</span>\
						<span class="tim-card-value">{{ fmtNumber(snapshot.index_doc_count) }}</span>\
						<span class="tim-card-sub" v-if="snapshot.index_size_bytes !== null">{{ fmtBytes(snapshot.index_size_bytes) }}</span>\
					</div>\
					<div :class="[\'tim-card\', coverageCardClass()]">\
						<span class="tim-card-label">{{ i18n.coverage }}</span>\
						<span class="tim-card-value">{{ snapshot.coverage_pct !== null ? snapshot.coverage_pct + \'%\' : \'—\' }}</span>\
						<div class="tim-bar" v-if="snapshot.coverage_pct !== null"><div class="tim-bar-fill" :style="{ width: Math.min(100, Math.max(0, snapshot.coverage_pct)) + \'%\' }"></div></div>\
						<span class="tim-card-sub">{{ i18n.divergence }}: {{ snapshot.divergence_pct !== null ? snapshot.divergence_pct + \'%\' : \'—\' }} (limite {{ snapshot.divergence_threshold_pct }}%)</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.last_check }}</span>\
						<span class="tim-card-value tim-card-value-small">{{ fmtDate(snapshot.last_health_check_ts) }}</span>\
						<span class="tim-card-sub">{{ i18n.last_index }}: {{ fmtDate(snapshot.last_index_run_ts) }}</span>\
					</div>\
				</div>\
\
				</section>\
\
				<section v-if="activeTab === \'indexing\'" role="tabpanel">\
				<div class="tim-section">\
					<div class="tim-section-header">\
						<h2>{{ i18n.metrics }}</h2>\
						<div class="tim-section-actions">\
							<button class="tim-btn is-secondary" @click="refresh(true)" :disabled="loading"><span class="tim-loading" v-if="loading"></span>{{ i18n.refresh }}</button>\
							<button class="tim-btn is-secondary" @click="processBatch" :disabled="loading || !snapshot.es_reachable">{{ i18n.process_batch }}</button>\
							<button class="tim-btn is-secondary" @click="resetMetrics">{{ i18n.reset_metrics }}</button>\
						</div>\
					</div>\
					<div class="tim-cards tim-cards-compact">\
						<div class="tim-card">\
							<span class="tim-card-label">{{ i18n.throughput }}</span>\
							<span class="tim-card-value">{{ fmtFloat(metrics.window && metrics.window.throughput_ips, 2) }}</span>\
							<span class="tim-card-sub">{{ metrics.window && metrics.window.runs }} execuções (janela {{ windowLabel }})</span>\
						</div>\
						<div class="tim-card">\
							<span class="tim-card-label">{{ i18n.eta }}</span>\
							<span class="tim-card-value">{{ etaLabel }}</span>\
							<span class="tim-card-sub">{{ i18n.queue_size }}: {{ fmtNumber(currentQueueSize) }} · {{ i18n.queue_peak }}: {{ fmtNumber(effectivePeak) }}</span>\
						</div>\
						<div :class="[\'tim-card\', successCardClass()]">\
							<span class="tim-card-label">{{ i18n.success_rate }}</span>\
							<span class="tim-card-value">{{ successRateLabel }}</span>\
							<span class="tim-card-sub">{{ fmtNumber(metrics.window && metrics.window.indexed) }} sucessos · {{ fmtNumber(metrics.window && metrics.window.failed) }} falhas</span>\
						</div>\
						<div class="tim-card">\
							<span class="tim-card-label">{{ i18n.avg_batch_ms }}</span>\
							<span class="tim-card-value">{{ fmtMs(metrics.window && metrics.window.avg_batch_ms) }}</span>\
							<span class="tim-card-sub">{{ i18n.avg_batch_size }}: {{ fmtFloat(metrics.window && metrics.window.avg_batch_size, 1) }}</span>\
						</div>\
						<div class="tim-card">\
							<span class="tim-card-label">{{ i18n.lifetime_indexed }}</span>\
							<span class="tim-card-value">{{ fmtNumber(metrics.summary_lifetime_indexed) }}</span>\
							<span class="tim-card-sub">{{ i18n.lifetime_batches }}: {{ fmtNumber(metrics.summary_lifetime_batches) }}</span>\
						</div>\
						<div class="tim-card">\
							<span class="tim-card-label">{{ i18n.lifetime_failed }}</span>\
							<span class="tim-card-value">{{ fmtNumber(metrics.summary_lifetime_failed) }}</span>\
							<span class="tim-card-sub">Dropped (max retries): {{ fmtNumber(metrics.summary_lifetime_dropped) }}</span>\
						</div>\
					</div>\
\
					<div class="tim-charts">\
						<div class="tim-chart-block">\
							<h3>Itens indexados (últimas 50 runs)</h3>\
							<sparkline :values="sparkIndexed" color="#298596" :height="56"></sparkline>\
						</div>\
						<div class="tim-chart-block">\
							<h3>Falhas por lote</h3>\
							<sparkline :values="sparkFailed" color="#e01b24" :height="56"></sparkline>\
						</div>\
						<div class="tim-chart-block">\
							<h3>Duração do lote (ms)</h3>\
							<sparkline :values="sparkDuration" color="#f5c211" :height="56"></sparkline>\
						</div>\
						<div class="tim-chart-block">\
							<h3>Tamanho da fila</h3>\
							<sparkline :values="sparkQueue" color="#6e6e74" :height="56"></sparkline>\
						</div>\
					</div>\
\
					<div class="tim-notice is-error" v-if="lastErrorRows.length">\
						<strong>Motivo das falhas no último lote</strong>\
						<ul class="tim-error-list">\
							<li v-for="er in lastErrorRows" :key="er.type">\
								<strong>{{ er.type }}</strong> &mdash; {{ er.count }} ocorrência(s)\
								<details v-if="er.sample_reason"><summary>ver detalhe do Elasticsearch</summary><pre class="tim-code">{{ er.sample_reason }}</pre></details>\
							</li>\
						</ul>\
					</div>\
\
					<div class="tim-row tim-row-2">\
						<div class="tim-stack-bar" v-if="metrics.distribution_total > 0">\
							<h3>Distribuição (lifetime)</h3>\
							<div class="tim-stackbar">\
								<div class="tim-stackbar-seg tim-seg-ok"     :style="{ width: pctOf(\'indexed\') + \'%\' }"     :title="\'Indexados: \' + fmtNumber(metrics.summary_lifetime_indexed)"></div>\
								<div class="tim-stackbar-seg tim-seg-fail"   :style="{ width: pctOf(\'failed\') + \'%\' }"      :title="\'Falhas: \' + fmtNumber(metrics.summary_lifetime_failed)"></div>\
								<div class="tim-stackbar-seg tim-seg-drop"   :style="{ width: pctOf(\'dropped\') + \'%\' }"     :title="\'Dropped: \' + fmtNumber(metrics.summary_lifetime_dropped)"></div>\
								<div class="tim-stackbar-seg tim-seg-skip"   :style="{ width: pctOf(\'skipped\') + \'%\' }"     :title="\'Skipped: \' + fmtNumber(metrics.summary_lifetime_skipped)"></div>\
							</div>\
							<ul class="tim-legend">\
								<li><span class="dot tim-seg-ok"></span> Indexados: {{ fmtNumber(metrics.summary_lifetime_indexed) }} ({{ pctOf(\'indexed\') }}%)</li>\
								<li><span class="dot tim-seg-fail"></span> Falhas: {{ fmtNumber(metrics.summary_lifetime_failed) }} ({{ pctOf(\'failed\') }}%)</li>\
								<li><span class="dot tim-seg-drop"></span> Dropped: {{ fmtNumber(metrics.summary_lifetime_dropped) }} ({{ pctOf(\'dropped\') }}%)</li>\
								<li><span class="dot tim-seg-skip"></span> Skipped: {{ fmtNumber(metrics.summary_lifetime_skipped) }} ({{ pctOf(\'skipped\') }}%)</li>\
							</ul>\
						</div>\
\
						<div v-if="failureTop.length">\
							<h3>{{ i18n.failure_top }}</h3>\
							<table class="tim-table">\
								<thead><tr><th>Item ID</th><th>Falhas</th></tr></thead>\
								<tbody>\
									<tr v-for="f in failureTop" :key="f.id">\
										<td><a :href="\'post.php?post=\' + f.id + \'&action=edit\'" target="_blank">#{{ f.id }}</a></td>\
										<td>{{ f.count }}</td>\
									</tr>\
								</tbody>\
							</table>\
						</div>\
					</div>\
				</div>\
\
				<div class="tim-notice is-warning" v-if="autoIndexingHint">{{ autoIndexingHint }}</div>\
				<div class="tim-notice is-success" v-if="lastActionMsg">{{ lastActionMsg }}</div>\
				</section>\
\
				<section v-if="activeTab === \'collections\'" role="tabpanel">\
				<div class="tim-section">\
					<h2>{{ i18n.collections }}</h2>\
					<table class="tim-table" v-if="collections.rows && collections.rows.length">\
						<thead>\
							<tr>\
								<th>ID</th><th>Coleção</th><th>Tainacan</th><th>Indexado</th><th>{{ i18n.coverage }}</th><th>{{ i18n.divergence }}</th><th></th>\
							</tr>\
						</thead>\
						<tbody>\
							<tr v-for="row in collections.rows" :key="row.collection_id" :class="{ \'is-over-threshold\': row.over_threshold }">\
								<td>{{ row.collection_id }}</td>\
								<td>{{ row.collection_name }}</td>\
								<td>{{ fmtNumber(row.tainacan_count) }}</td>\
								<td>\
									<span v-if="row.indexed_count !== null">{{ fmtNumber(row.indexed_count) }}</span>\
									<span v-else-if="row.error" :title="row.error" style="color:#e01b24;cursor:help">erro</span>\
									<span v-else>—</span>\
								</td>\
								<td>{{ row.coverage_pct === null ? \'—\' : row.coverage_pct + \'%\' }}</td>\
								<td>{{ row.divergence_pct === null ? \'—\' : row.divergence_pct + \'%\' }}</td>\
								<td><button class="tim-btn is-secondary" @click="reindexCollection(row.collection_id)" :disabled="loading">{{ i18n.reindex_collection }}</button></td>\
							</tr>\
						</tbody>\
					</table>\
					<p class="tim-muted" v-else>{{ collections.message || \'Sem coleções para exibir.\' }}</p>\
				</div>\
				</section>\
\
				<section v-if="activeTab === \'alerts\'" role="tabpanel">\
				<div class="tim-section">\
					<h2>{{ i18n.alerts }} <span class="tim-muted">({{ alerts.length }})</span></h2>\
					<ul class="tim-alerts" v-if="alerts.length">\
						<li v-for="a in alerts" :key="a.code">\
							<span :class="[\'tim-status-pill\', statusClass(a.severity === \'critical\' ? \'red\' : (a.severity === \'warning\' ? \'yellow\' : \'unknown\'))]">{{ a.severity }}</span>\
							<strong style="margin-left:.4rem">{{ a.code }}</strong> — {{ a.message }}\
							<span class="tim-muted"> (visto {{ a.count }}x)</span>\
						</li>\
					</ul>\
					<p class="tim-muted" v-else>Sem alertas ativos.</p>\
				</div>\
				</section>\
\
				<section v-if="activeTab === \'integrations\'" role="tabpanel">\
				<div class="tim-section">\
					<h2>{{ i18n.elasticpress }}</h2>\
					<div v-if="!elasticpress.active" class="tim-muted">\
						<p>O ElasticPress <strong>não está instalado/ativo</strong> neste site — <em>este é um cenário suportado</em>.</p>\
						<p>O Tainacan Index Manager está operando com o indexador próprio, com mappings otimizados para português brasileiro e cobertura específica de metadados Tainacan. Se um dia o ElasticPress for ativado, este plugin detecta automaticamente e passa a operar em modo somente leitura sobre ele.</p>\
					</div>\
					<div v-else>\
						<p><strong>Versão:</strong> {{ elasticpress.version || \'—\' }}</p>\
						<p><strong>Host:</strong> <code>{{ elasticpress.host || \'—\' }}</code></p>\
						<p><strong>Estado:</strong> {{ elasticpress.sync_state }}</p>\
						<p><strong>Última sync:</strong> {{ fmtDate(elasticpress.last_sync_ts) }}</p>\
						<button class="tim-btn is-secondary" @click="epSync">{{ i18n.sync_now }}</button>\
					</div>\
				</div>\
				</section>\
\
				<section v-if="activeTab === \'logs\'" role="tabpanel">\
				<div class="tim-section">\
					<h2>{{ i18n.logs }}</h2>\
					<div class="tim-actions">\
						<button class="tim-btn is-secondary" @click="loadLogs">{{ i18n.refresh }}</button>\
						<button class="tim-btn is-danger" @click="clearLogs">{{ i18n.clear_logs }}</button>\
					</div>\
					<table class="tim-table" v-if="logs.length">\
						<thead><tr><th>Data</th><th>Nível</th><th>Canal</th><th>Mensagem</th></tr></thead>\
						<tbody>\
							<tr v-for="l in logs" :key="l.id">\
								<td>{{ l.created_at }} UTC</td>\
								<td><span :class="[\'tim-status-pill\', logLevelClass(l.level)]">{{ l.level }}</span></td>\
								<td>{{ l.channel }}</td>\
								<td>{{ l.message }}</td>\
							</tr>\
						</tbody>\
					</table>\
					<p class="tim-muted" v-else>Sem registros.</p>\
				</div>\
				</section>\
			</template>\
		</div>',
		data: function () {
			return {
				activeTab: 'overview',
				initialLoading: true,
				loading: false,
				errorMsg: '',
				lastActionMsg: '',
				snapshot: {},
				collections: { rows: [] },
				logs: [],
				alerts: [],
				elasticpress: { active: false },
				diagnostics: { severity: 'unknown', headline: '', findings: [] },
				metrics: { window: {}, queue: {}, sparkline: { indexed: [], failed: [], duration: [], queue: [] }, summary_lifetime_indexed: 0, summary_lifetime_failed: 0, summary_lifetime_skipped: 0, summary_lifetime_dropped: 0, summary_lifetime_batches: 0, distribution_total: 0 },
				failureTop: [],
				poller: null,
				windowLabel: '10 runs',
				i18n: i18n
			};
		},
		computed: {
			tabs: function () {
				return [
					{ id: 'overview',     label: 'Visão geral' },
					{ id: 'indexing',     label: 'Indexação' },
					{ id: 'collections',  label: 'Coleções' },
					{ id: 'alerts',       label: 'Alertas', badge: this.alerts.length || '' },
					{ id: 'logs',         label: 'Logs' },
					{ id: 'integrations', label: 'Integrações' }
				];
			},
			sparkIndexed:  function () { return (this.metrics && this.metrics.sparkline && this.metrics.sparkline.indexed)  || []; },
			sparkFailed:   function () { return (this.metrics && this.metrics.sparkline && this.metrics.sparkline.failed)   || []; },
			sparkDuration: function () { return (this.metrics && this.metrics.sparkline && this.metrics.sparkline.duration) || []; },
			sparkQueue:    function () { return (this.metrics && this.metrics.sparkline && this.metrics.sparkline.queue)    || []; },
			etaLabel: function () {
				var q = this.metrics && this.metrics.queue;
				if (!q) return '—';
				if (q.size === 0) return '—';
				if (q.eta_human) return q.eta_human;
				return 'sem amostra';
			},
			successRateLabel: function () {
				var s = this.metrics && this.metrics.window && this.metrics.window.success_rate_pct;
				return (s === null || typeof s === 'undefined') ? '—' : (s + '%');
			},
			currentQueueSize: function () {
				return (this.metrics && this.metrics.queue && this.metrics.queue.size) || 0;
			},
			effectivePeak: function () {
				// Peak only gets recorded when batches run; show the truth
				// even before the first batch by max'ing against current.
				var peak = (this.metrics && this.metrics.queue && this.metrics.queue.peak_observed) || 0;
				return Math.max(peak, this.currentQueueSize);
			},
			lastErrorRows: function () {
				var sum = this.metrics && this.metrics.last_error_summary;
				if (!sum || typeof sum !== 'object') return [];
				return Object.keys(sum).map(function (type) {
					var info = sum[type] || {};
					return {
						type: type,
						count: Number(info.count) || 0,
						sample_reason: info.sample_reason || '',
						sample_id: Number(info.sample_id) || 0
					};
				}).sort(function (a, b) { return b.count - a.count; });
			},
			autoIndexingHint: function () {
				if (this.currentQueueSize <= 0) return '';
				if (this.snapshot && this.snapshot.last_index_run_ts > 0) return '';
				// Queue has items but no batch has ever run — likely auto-indexing is off.
				return 'Há ' + fmtNumber(this.currentQueueSize) + ' itens na fila, mas nenhum lote foi processado ainda. Clique em "Processar lote" ou habilite o processamento automático em Configurações de Indexação.';
			}
		},
		mounted: function () {
			var self = this;
			this.refresh(false).then(function () { self.startPolling(); });
		},
		beforeUnmount: function () { this.stopPolling(); },
		methods: {
			fmtNumber: fmtNumber,
			fmtBytes: fmtBytes,
			fmtDate: fmtDate,
			fmtMs: fmtMs,
			fmtFloat: fmtFloat,
			statusClass: statusToClass,
			cardClass: cardClass,
			engineLabel: engineLabel,
			overallLabel: function (s) {
				if (s === 'ok') return 'OK';
				if (s === 'warning') return 'ATENÇÃO';
				if (s === 'critical') return 'CRÍTICO';
				return '—';
			},
			clusterClass: function (s) {
				if (s === 'red')    return 'is-critical';
				if (s === 'yellow') return 'is-warning';
				if (s === 'green')  return 'is-ok';
				return 'is-unknown';
			},
			coverageCardClass: function () {
				if (this.snapshot.divergence_pct === null) return '';
				return this.snapshot.divergence_pct > this.snapshot.divergence_threshold_pct ? 'is-warning' : 'is-ok';
			},
			successCardClass: function () {
				var s = this.metrics && this.metrics.window && this.metrics.window.success_rate_pct;
				if (s === null || typeof s === 'undefined') return '';
				if (s >= 95) return 'is-ok';
				if (s >= 80) return 'is-warning';
				return 'is-critical';
			},
			diagPillClass: function (s) {
				if (s === 'ok')       return 'tim-green';
				if (s === 'info')     return 'tim-unknown';
				if (s === 'warning')  return 'tim-yellow';
				if (s === 'critical') return 'tim-red';
				return 'tim-unknown';
			},
			diagSeverityLabel: function (s) {
				if (s === 'ok')       return 'TUDO CERTO';
				if (s === 'info')     return 'INFORMATIVO';
				if (s === 'warning')  return 'ATENÇÃO';
				if (s === 'critical') return 'AÇÃO IMEDIATA';
				return '—';
			},
			diagSeverityShort: function (s) {
				if (s === 'ok')       return 'OK';
				if (s === 'info')     return 'INFO';
				if (s === 'warning')  return 'ATENÇÃO';
				if (s === 'critical') return 'CRÍTICO';
				return '—';
			},
			logLevelClass: function (l) {
				if (l === 'critical' || l === 'error') return 'tim-red';
				if (l === 'warning') return 'tim-yellow';
				return 'tim-green';
			},
			pctOf: function (key) {
				var total = this.metrics.distribution_total || 0;
				if (total <= 0) return 0;
				var v = 0;
				if (key === 'indexed') v = this.metrics.summary_lifetime_indexed;
				if (key === 'failed')  v = this.metrics.summary_lifetime_failed;
				if (key === 'dropped') v = this.metrics.summary_lifetime_dropped;
				if (key === 'skipped') v = this.metrics.summary_lifetime_skipped;
				return Math.round((v / total) * 1000) / 10;
			},
			refresh: function (force) {
				// Sequenced so /alerts and /metrics never read state the
				// /health refresh hasn't finished writing. Without this,
				// Promise.all() races and we get zombie alerts.
				var self = this;
				this.loading = true;
				this.errorMsg = '';
				var qs = force ? '?refresh=1' : '';
				return api('GET', '/health' + qs).then(function (snap) {
					self.snapshot = snap || {};
					return Promise.all([
						api('GET', '/collections' + qs),
						api('GET', '/alerts'),
						api('GET', '/elasticpress'),
						api('GET', '/logs?per_page=15'),
						api('GET', '/metrics?window=10'),
						api('GET', '/diagnostics')
					]);
				}).then(function (results) {
					self.collections  = results[0] || { rows: [] };
					self.alerts       = (results[1] && results[1].alerts) || [];
					self.elasticpress = results[2] || { active: false };
					self.logs         = (results[3] && results[3].rows) || [];
					self.applyMetrics(results[4] || {});
					self.diagnostics  = results[5] || self.diagnostics;
				}).catch(function (err) {
					self.errorMsg = err.message || 'Erro ao carregar dados.';
				}).finally(function () {
					self.loading = false;
					self.initialLoading = false;
				});
			},

			pollTick: function () {
				// Lightweight tick: hits cached endpoints so the dashboard
				// stays current without slamming Elasticsearch. The 60s
				// health-snapshot transient absorbs the load on the server.
				var self = this;
				Promise.all([
					api('GET', '/health'),
					api('GET', '/alerts'),
					api('GET', '/metrics?window=10'),
					api('GET', '/index/state'),
					api('GET', '/diagnostics')
				]).then(function (results) {
					self.snapshot = results[0] || self.snapshot;
					self.alerts   = (results[1] && results[1].alerts) || [];
					self.applyMetrics(results[2] || {});
					if (results[3] && typeof results[3].queue_size === 'number') {
						self.metrics.queue = self.metrics.queue || {};
						self.metrics.queue.size = results[3].queue_size;
					}
					self.diagnostics = results[4] || self.diagnostics;
				}).catch(function () { /* silent: next tick retries */ });
			},
			applyMetrics: function (payload) {
				var s = (payload && payload.summary) || {};
				var lifetime = s.lifetime || {};
				var total = (lifetime.indexed || 0) + (lifetime.failed || 0) + (lifetime.dropped || 0) + (lifetime.skipped || 0);
				this.metrics = {
					window: s.window || {},
					queue: Object.assign({ size: payload.queue_size || 0 }, s.queue || {}),
					sparkline: s.sparkline || { indexed: [], failed: [], duration: [], queue: [] },
					last_error_summary: s.last_error_summary || {},
					last_error_ts: s.last_error_ts || 0,
					summary_lifetime_indexed: lifetime.indexed || 0,
					summary_lifetime_failed:  lifetime.failed || 0,
					summary_lifetime_skipped: lifetime.skipped || 0,
					summary_lifetime_dropped: lifetime.dropped || 0,
					summary_lifetime_batches: lifetime.batches || 0,
					distribution_total: total
				};
				var top = s.failure_top || {};
				this.failureTop = Object.keys(top).map(function (id) { return { id: Number(id), count: Number(top[id]) }; }).sort(function (a, b) { return b.count - a.count; }).slice(0, 10);
			},
			startPolling: function () {
				if (this.poller) return;
				var self = this;
				this.poller = window.setInterval(function () { self.pollTick(); }, 7000);
			},
			stopPolling: function () { if (this.poller) { window.clearInterval(this.poller); this.poller = null; } },
			testConnection: function () {
				var self = this;
				this.loading = true;
				api('POST', '/test-connection').then(function (res) {
					var authLabel = ({
						api_key:            'API Key',
						basic_auth:         'Basic Auth (campos próprios)',
						basic_auth_inline:  'Basic Auth (credenciais detectadas na URL)',
						none:               'sem autenticação'
					})[res.auth] || res.auth || '—';
					var ctx = ' · URL: ' + (res.url || '—') + ' · Auth: ' + authLabel + (res.auth_user ? ' (' + res.auth_user + ')' : '');
					self.lastActionMsg = res.ok
						? (i18n.connection_ok + ' (' + res.ms + ' ms)' + ctx)
						: (i18n.connection_failed + ': ' + (res.error || ('HTTP ' + res.code)) + ctx);
				}).catch(function (e) { self.lastActionMsg = e.message; })
				.finally(function () { self.loading = false; });
			},
			processBatch: function () {
				var self = this;
				this.loading = true;
				api('POST', '/index/process-batch').then(function (res) {
					self.lastActionMsg = res.message || 'Lote processado.';
					self.refresh(true);
				}).catch(function (e) { self.lastActionMsg = e.message; })
				.finally(function () { self.loading = false; });
			},
			resetMetrics: function () {
				if (!window.confirm('Zerar histórico de métricas? A indexação continua intacta.')) return;
				var self = this;
				api('POST', '/metrics/reset').then(function () { self.refresh(true); });
			},
			reindexCollection: function (id) {
				var self = this;
				this.loading = true;
				api('POST', '/index/reindex-collection', { collection_id: id }).then(function (res) {
					self.lastActionMsg = res.enqueued + ' itens enfileirados para reindexação.';
				}).catch(function (e) { self.lastActionMsg = e.message; })
				.finally(function () { self.loading = false; });
			},
			epSync: function () {
				var self = this;
				api('POST', '/elasticpress/sync').then(function (res) {
					self.lastActionMsg = res.ok
						? 'Sincronização do ElasticPress acionada.'
						: 'ElasticPress não pôde ser acionado automaticamente (rode pela interface do EP).';
				});
			},
			loadLogs: function () {
				var self = this;
				api('GET', '/logs?per_page=15').then(function (res) {
					self.logs = (res && res.rows) || [];
				});
			},
			clearLogs: function () {
				if (!window.confirm('Apagar todos os logs?')) return;
				var self = this;
				api('POST', '/logs/clear').then(function () { self.logs = []; });
			}
		}
	};

	var SettingsView = {
		template: '\
		<div>\
			<div v-if="loading" class="tim-notice is-info"><span class="tim-loading"></span> Carregando…</div>\
			<div v-else>\
				<div v-if="msg" :class="[\'tim-notice\', msgClass]">{{ msg }}</div>\
\
				<div class="tim-section">\
					<h2>Mecanismo de busca</h2>\
					<div class="tim-form">\
						<div class="tim-field">\
							<label>Modo</label>\
							<select v-model="form.engine">\
								<option value="auto">Auto (preferir ElasticPress, caso contrário indexador próprio)</option>\
								<option value="elasticpress">ElasticPress</option>\
								<option value="own_indexer">Indexador próprio</option>\
								<option value="disabled">Desativado (somente SQL)</option>\
							</select>\
						</div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Elasticsearch / OpenSearch</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label>URL</label><input type="url" v-model="form.es_url" placeholder="https://exemplo:9200"><span class="tim-help">URL completa, incluindo protocolo e porta.</span></div>\
						<div class="tim-field"><label>Usuário (Basic)</label><input type="text" v-model="form.es_username"></div>\
						<div class="tim-field"><label>Senha (Basic)</label><input type="password" v-model="form.es_password" placeholder="••••"></div>\
						<div class="tim-field"><label>API Key (alternativa)</label><input type="password" v-model="form.es_api_key" placeholder="••••"></div>\
						<div class="tim-field"><label>Nome do índice</label><input type="text" v-model="form.index_name"></div>\
						<div class="tim-field"><label>Timeout (segundos)</label><input type="number" min="1" max="60" v-model.number="form.es_timeout"></div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Indexação</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label><input type="checkbox" v-model="form.auto_indexing_enabled"> Habilitar processamento automático de lotes</label></div>\
						<div class="tim-field"><label>Tamanho do lote</label><input type="number" min="1" max="1000" v-model.number="form.batch_size"></div>\
						<div class="tim-field"><label>Intervalo entre lotes (segundos)</label><input type="number" min="0" max="600" v-model.number="form.batch_interval_seconds"></div>\
						<div class="tim-field"><label>Tentativas máximas por item</label><input type="number" min="0" max="10" v-model.number="form.max_retries"></div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Monitoramento</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label>Frequência da verificação automática</label>\
							<select v-model="form.auto_check_frequency">\
								<option value="tim_15min">A cada 15 minutos</option>\
								<option value="tim_30min">A cada 30 minutos</option>\
								<option value="hourly">A cada hora</option>\
								<option value="tim_6hours">A cada 6 horas</option>\
								<option value="daily">Diário</option>\
							</select>\
						</div>\
						<div class="tim-field"><label>Limite aceitável de divergência (%)</label><input type="number" min="0" max="100" v-model.number="form.divergence_threshold_pct"></div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Alertas</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label><input type="checkbox" v-model="form.alert_dashboard_enabled"> Mostrar alertas no painel admin</label></div>\
						<div class="tim-field"><label><input type="checkbox" v-model="form.alert_email_enabled"> Enviar alertas por e-mail</label></div>\
						<div class="tim-field"><label>E-mail para alertas</label><input type="email" v-model="form.alert_email_address"></div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Geral</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label><input type="checkbox" v-model="form.fallback_enabled"> Habilitar fallback automático para busca SQL quando o ES falhar</label></div>\
						<div class="tim-field"><label>Retenção dos logs (dias)</label><input type="number" min="1" max="365" v-model.number="form.log_retention_days"></div>\
					</div>\
				</div>\
\
				<div class="tim-actions">\
					<button class="tim-btn" @click="save" :disabled="saving"><span class="tim-loading" v-if="saving"></span>{{ i18n.save }}</button>\
					<button class="tim-btn is-secondary" @click="testConnection" :disabled="saving">{{ i18n.test_connection }}</button>\
					<button class="tim-btn is-secondary" @click="createIndex">{{ i18n.create_index }}</button>\
					<button class="tim-btn is-secondary" @click="recreateIndex">{{ i18n.recreate_index }}</button>\
					<button class="tim-btn is-danger" @click="deleteIndex">{{ i18n.delete_index }}</button>\
				</div>\
\
				<div class="tim-section">\
					<h2>Reindexação</h2>\
					<div class="tim-actions">\
						<button class="tim-btn" @click="reindexAll">{{ i18n.reindex_all }}</button>\
						<button class="tim-btn is-secondary" @click="reindexPending">{{ i18n.reindex_pending }}</button>\
						<button class="tim-btn is-secondary" @click="processBatch">{{ i18n.process_batch }}</button>\
						<button class="tim-btn is-secondary" @click="pause">{{ i18n.pause }}</button>\
						<button class="tim-btn is-secondary" @click="resume">{{ i18n.resume }}</button>\
						<button class="tim-btn is-danger" @click="cancel">{{ i18n.cancel }}</button>\
					</div>\
					<p class="tim-muted">Estado: <strong>{{ state.state }}</strong> · Fila: <strong>{{ state.queue_size }}</strong> · Falhas: <strong>{{ state.failures }}</strong></p>\
				</div>\
			</div>\
		</div>',
		data: function () {
			return {
				loading: true,
				saving: false,
				msg: '',
				msgClass: 'is-info',
				form: {},
				state: { state: '—', queue_size: 0, failures: 0 },
				i18n: i18n
			};
		},
		mounted: function () { this.load(); this.refreshState(); },
		methods: {
			load: function () {
				var self = this;
				api('GET', '/settings').then(function (res) {
					self.form = res || {};
					self.loading = false;
				}).catch(function (e) { self.msg = e.message; self.msgClass = 'is-error'; self.loading = false; });
			},
			refreshState: function () {
				var self = this;
				api('GET', '/index/state').then(function (res) { self.state = res; });
			},
			save: function () {
				var self = this;
				this.saving = true;
				api('POST', '/settings', self.form).then(function (res) {
					if (res.settings) { self.form = res.settings; }
					self.msg = 'Configurações salvas.';
					if (res.migrated_inline_credentials) {
						self.msg += ' Detectamos credenciais embutidas na URL e migramos para os campos "Usuário" e "Senha" — a URL agora aparece limpa.';
					}
					self.msgClass = 'is-success';
				}).catch(function (e) { self.msg = e.message; self.msgClass = 'is-error'; })
				.finally(function () { self.saving = false; });
			},
			testConnection: function () {
				var self = this;
				api('POST', '/test-connection').then(function (res) {
					var authLabel = ({
						api_key:            'API Key',
						basic_auth:         'Basic Auth (campos próprios)',
						basic_auth_inline:  'Basic Auth (credenciais detectadas na URL)',
						none:               'sem autenticação'
					})[res.auth] || res.auth || '—';
					var ctx = ' · URL testada: ' + (res.url || '—') + ' · Auth: ' + authLabel + (res.auth_user ? ' (' + res.auth_user + ')' : '');
					self.msg = res.ok ? (i18n.connection_ok + ' (' + res.ms + ' ms)' + ctx) : (i18n.connection_failed + ': ' + (res.error || ('HTTP ' + res.code)) + ctx);
					self.msgClass = res.ok ? 'is-success' : 'is-error';
					// Inline credentials may have been migrated to es_username/es_password — reload form.
					self.load();
				});
			},
			createIndex: function () {
				var self = this;
				api('POST', '/index/create').then(function (res) {
					self.msg = res.ok ? 'Índice criado.' : ('Erro: ' + (res.error || ''));
					self.msgClass = res.ok ? 'is-success' : 'is-error';
				});
			},
			deleteIndex: function () {
				if (!window.confirm('Apagar o índice atual? Isto removerá todos os documentos indexados.')) return;
				var self = this;
				api('POST', '/index/delete').then(function (res) {
					self.msg = res.ok ? 'Índice apagado.' : ('Erro: ' + (res.error || ''));
					self.msgClass = res.ok ? 'is-success' : 'is-error';
				});
			},
			recreateIndex: function () {
				if (!window.confirm('Recriar o índice apaga todos os documentos. Continuar?')) return;
				var self = this;
				api('POST', '/index/recreate').then(function (res) {
					self.msg = res.ok ? 'Índice recriado.' : ('Erro: ' + (res.error || ''));
					self.msgClass = res.ok ? 'is-success' : 'is-error';
				});
			},
			reindexAll: function () {
				if (!window.confirm('Enfileirar TODOS os itens do Tainacan para reindexação?')) return;
				var self = this;
				api('POST', '/index/reindex-all').then(function (res) {
					self.msg = res.enqueued + ' itens enfileirados.';
					self.msgClass = 'is-success';
					self.refreshState();
				});
			},
			reindexPending: function () {
				var self = this;
				api('POST', '/index/enqueue-pending').then(function (res) {
					self.msg = res.enqueued + ' itens pendentes enfileirados.';
					self.msgClass = 'is-info';
					self.refreshState();
				});
			},
			processBatch: function () {
				var self = this;
				api('POST', '/index/process-batch').then(function (res) {
					self.msg = res.message || 'Lote processado.';
					self.msgClass = res.ok ? 'is-success' : 'is-error';
					self.refreshState();
				});
			},
			pause:  function () { var s = this; api('POST', '/index/pause').then(function () { s.refreshState(); }); },
			resume: function () { var s = this; api('POST', '/index/resume').then(function () { s.refreshState(); }); },
			cancel: function () { if (!window.confirm('Cancelar e esvaziar a fila atual?')) return; var s = this; api('POST', '/index/cancel').then(function () { s.refreshState(); }); }
		}
	};

	var app = window.Vue.createApp({
		data: function () { return { view: initialView }; },
		components: { DashboardView: DashboardView, SettingsView: SettingsView },
		template: '<dashboard-view v-if="view === \'dashboard\'" /><settings-view v-else />'
	});

	app.mount('#tainacan-idxmgr-app');
})();
