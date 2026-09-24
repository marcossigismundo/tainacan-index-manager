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
			case 'elasticsearch':       return i18n.engine_elasticsearch;
			case 'sql':                return i18n.engine_sql;
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
					<div :class="[\'tim-card\', clusterClass(shardStatus)]">\
						<span class="tim-card-label">{{ i18n.cluster }}</span>\
						<span class="tim-card-value">\
							<span :class="[\'tim-status-pill\', statusClass(shardStatus)]">{{ (shardStatus || \'—\').toUpperCase() }}</span>\
						</span>\
						<span class="tim-card-sub" v-if="snapshot.cluster">{{ snapshot.cluster.number_of_nodes }} nós · {{ snapshot.cluster.active_shards }} shards · {{ snapshot.cluster.unassigned_shards }} unassigned<template v-if="snapshot.index_status && snapshot.cluster_status !== snapshot.index_status"> · cluster {{ snapshot.cluster_status.toUpperCase() }} (outros sistemas)</template></span>\
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
				diagnostics: { severity: 'unknown', headline: '', findings: [] },
				metrics: { window: {}, queue: {}, sparkline: { indexed: [], failed: [], duration: [], queue: [] }, summary_lifetime_indexed: 0, summary_lifetime_failed: 0, summary_lifetime_skipped: 0, summary_lifetime_dropped: 0, summary_lifetime_batches: 0, distribution_total: 0 },
				failureTop: [],
				poller: null,
				windowLabel: '10 runs',
				i18n: i18n
			};
		},
		computed: {
			// Status of the index this plugin manages, not of the whole cluster:
			// the same Elasticsearch usually carries indices of other systems,
			// whose unassigned replicas are not a Tainacan problem. A single-node
			// cluster can never allocate a replica, so yellow there is benign —
			// same rule as Health_Service::shard_status() on the PHP side.
			shardStatus: function () {
				var s = this.snapshot || {};
				var status = s.index_status || s.cluster_status || '';
				if ('yellow' === status && s.single_node_cluster) {
					return 'green';
				}
				return status;
			},
			tabs: function () {
				return [
					{ id: 'overview',     label: 'Visão geral' },
					{ id: 'indexing',     label: 'Indexação' },
					{ id: 'collections',  label: 'Coleções' },
					{ id: 'alerts',       label: 'Alertas', badge: this.alerts.length || '' },
					{ id: 'logs',         label: 'Logs' }
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
						api('GET', '/logs?per_page=15'),
						api('GET', '/metrics?window=10'),
						api('GET', '/diagnostics')
					]);
				}).then(function (results) {
					self.collections  = results[0] || { rows: [] };
					self.alerts       = (results[1] && results[1].alerts) || [];
					self.logs         = (results[2] && results[2].rows) || [];
					self.applyMetrics(results[3] || {});
					self.diagnostics  = results[4] || self.diagnostics;
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
								<option value="elasticsearch">Elasticsearch (indexado por este plugin)</option>\
								<option value="sql">SQL (sem indexação)</option>\
							</select>\
						</div>\
						<div class="tim-field"><label><input type="checkbox" v-model="form.search_typo_tolerance"> Tolerar erros de digitação na busca por texto</label><span class="tim-help">Aceita uma ou duas letras trocadas ("fotgrafia" encontra "fotografia"). A primeira letra precisa estar certa. Veja também o <a :href="vocabularyUrl">Vocabulário da busca</a>.</span></div>\
					</div>\
				</div>\
\
				<div class="tim-section">\
					<h2>Menu</h2>\
					<div class="tim-form">\
						<div class="tim-field"><label>Onde o painel aparece no menu do Tainacan</label>\
							<select v-model="form.menu_location">\
								<option value="other">Em "Outros" (padrão)</option>\
								<option value="root">No menu principal</option>\
							</select>\
							<span class="tim-help">Vale depois de salvar e recarregar a página. O ponto colorido ao lado de "Gestão da Indexação" repete a cor do semáforo.</span>\
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
				vocabularyUrl: window.TIMConfig.vocabularyUrl,
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

	/* ------------------------------------------------------------------ */
	/* Status light                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Traffic light for "is the search running on Elasticsearch?". The colour
	 * and the checks come from Traffic_Light on the server, so the admin menu
	 * dot, the cron and this component never disagree.
	 */
	var TrafficLight = {
		props: { light: Object, loading: Boolean, compact: Boolean, error: String },
		emits: ['refresh'],
		data: function () { return { expanded: false, now: Date.now(), clock: null }; },
		mounted: function () {
			var self = this;
			this.clock = window.setInterval(function () { self.now = Date.now(); }, 10000);
		},
		beforeUnmount: function () { window.clearInterval(this.clock); },
		computed: {
			color: function () { return (this.light && this.light.color) || 'unknown'; },
			title: function () {
				if (this.light) return this.light.title;
				return this.error ? 'Não foi possível verificar' : 'Verificando…';
			},
			message: function () {
				if (this.light) return this.light.message;
				return this.error || 'Consultando o Elasticsearch e o índice.';
			},
			checks: function () { return (this.light && this.light.checks) || []; },
			showChecks: function () { return !this.compact || this.expanded; },
			ago: function () {
				if (!this.light || !this.light.checked_at) return '';
				var s = Math.max(0, Math.round(this.now / 1000 - this.light.checked_at));
				if (s < 60) return 'agora há pouco';
				var m = Math.round(s / 60);
				if (m < 60) return 'há ' + m + ' min';
				return 'há ' + Math.round(m / 60) + ' h';
			}
		},
		methods: {
			icon: function (state) {
				return ({ ok: '✓', warn: '!', fail: '✕', off: '–', info: 'i' })[state] || '·';
			}
		},
		template: [
			'<section :class="[\'tim-light\', \'is-\' + color, { \'is-compact\': compact }]">',
			'  <div class="tim-light-housing" role="img" :aria-label="\'Semáforo: \' + title">',
			'    <span :class="[\'tim-lamp\', \'tim-lamp-red\', { \'is-on\': color === \'red\' }]"></span>',
			'    <span :class="[\'tim-lamp\', \'tim-lamp-yellow\', { \'is-on\': color === \'yellow\' }]"></span>',
			'    <span :class="[\'tim-lamp\', \'tim-lamp-green\', { \'is-on\': color === \'green\' }]"></span>',
			'  </div>',
			'  <div class="tim-light-body" aria-live="polite">',
			'    <p class="tim-light-kicker">Estado do Elasticsearch</p>',
			'    <h2 class="tim-light-title">{{ title }}</h2>',
			'    <p class="tim-light-msg">{{ message }}</p>',
			'    <ul class="tim-light-checks" v-if="showChecks && checks.length">',
			'      <li v-for="c in checks" :key="c.key" :class="\'is-\' + c.state">',
			'        <span class="tim-check-icon" aria-hidden="true">{{ icon(c.state) }}</span>',
			'        <span class="tim-check-label">{{ c.label }}</span>',
			'        <span class="tim-check-detail">{{ c.detail }}</span>',
			'      </li>',
			'    </ul>',
			'    <div class="tim-light-foot">',
			'      <span v-if="ago" class="tim-muted">Verificado {{ ago }}</span>',
			'      <button type="button" class="tim-link-btn" @click="$emit(\'refresh\')" :disabled="loading"><span class="tim-loading" v-if="loading"></span>Verificar agora</button>',
			'      <button type="button" class="tim-link-btn" v-if="compact && checks.length" @click="expanded = !expanded" :aria-expanded="expanded ? \'true\' : \'false\'">{{ expanded ? \'Ocultar detalhes\' : \'Ver detalhes\' }}</button>',
			'    </div>',
			'  </div>',
			'</section>'
		].join('')
	};

	/* ------------------------------------------------------------------ */
	/* Search vocabulary                                                   */
	/* ------------------------------------------------------------------ */

	var VOCAB_LISTS = [
		{
			key: 'synonyms',
			label: 'Sinônimos',
			icon: '≈',
			lead: 'Palavras diferentes com o mesmo sentido.',
			how: 'Uma equivalência por linha, com os termos separados por vírgula. Quem buscar qualquer um deles encontra também os itens descritos com os outros.',
			example: 'quadro, pintura, tela\nfotografia, retrato\ncartão-postal, bilhete postal, postal',
			tip: 'Não cadastre plurais nem acentos: a busca já trata “fotografia”, “fotografias” e “fotográfia” como a mesma palavra.',
			template: '# Sinônimos — uma equivalência por linha, termos separados por vírgula.\n# Quem buscar qualquer um dos termos encontra os itens que usam os outros.\n# Linhas que começam com # são comentários.\nquadro, pintura, tela\nfotografia, retrato\ncartão-postal, bilhete postal, postal\n'
		},
		{
			key: 'variants',
			label: 'Grafias e variantes',
			icon: 'Aa',
			lead: 'Ortografia antiga, formas estrangeiras, abreviações e siglas.',
			how: 'Mesmo formato dos sinônimos. É o que mais rende em acervo histórico: o registro diz “pharmacia”, o público digita “farmácia”.',
			example: 'pharmacia, farmácia\nphotographia, fotografia\nBrazil, Brasil\nIBRAM, Instituto Brasileiro de Museus',
			tip: 'Siglas valem nos dois sentidos: buscar a sigla encontra o nome por extenso, e o contrário também.',
			template: '# Grafias antigas, variantes, abreviações e siglas — mesmo formato dos sinônimos.\npharmacia, farmácia\nphotographia, fotografia\nBrazil, Brasil\nIBRAM, Instituto Brasileiro de Museus\n'
		},
		{
			key: 'corrections',
			label: 'Correções e parônimos',
			icon: '→',
			lead: 'Erros comuns de escrita e palavras parecidas que o público confunde.',
			how: 'Uma regra por linha, com seta: o que a pessoa digita => o que a busca deve procurar também. A forma digitada continua valendo — a seta só acrescenta.',
			example: 'excessão => exceção\nprevilégio => privilégio\nbeneficiente => beneficente',
			tip: 'Parônimos (descrição/discrição, eminente/iminente) têm sentidos diferentes: cadastre só se a catalogação do acervo costuma trocá-los, senão a busca mistura assuntos. Para erros de uma letra qualquer, use a busca aproximada, mais abaixo.',
			template: '# Correções e parônimos — o que a pessoa digita => o que a busca deve procurar também.\n# A forma digitada continua valendo; a seta só acrescenta.\n# Em planilha (CSV): 1ª coluna = o que se digita, demais colunas = o que procurar.\nexcessão => exceção\nprevilégio => privilégio\nbeneficiente => beneficente\n'
		},
		{
			key: 'stopwords',
			label: 'Palavras ignoradas',
			icon: '∅',
			lead: 'Palavras tão comuns no acervo que só atrapalham a busca.',
			how: 'Uma palavra por linha. Elas são tiradas da pergunta antes de buscar: com “acervo” na lista, “acervo de arte sacra” vira “arte sacra”.',
			example: 'acervo\ncoleção',
			tip: 'Cuidado: quem buscar apenas palavras desta lista não encontra nada. Artigos e preposições (“de”, “da”, “o”, “e”) já são ignorados automaticamente.',
			template: '# Palavras ignoradas — uma por linha.\n# Quem buscar SOMENTE palavras desta lista não encontra nada: use com parcimônia.\nacervo\n'
		}
	];

	/** Minimal RFC-4180-ish CSV reader; the delimiter is sniffed from the first line. */
	function parseCsv(text) {
		var first = (text.split(/\r?\n/)[0] || '');
		var delim = ';';
		var counts = { ';': (first.match(/;/g) || []).length, ',': (first.match(/,/g) || []).length, '\t': (first.match(/\t/g) || []).length };
		if (counts[','] > counts[delim]) delim = ',';
		if (counts['\t'] > counts[delim]) delim = '\t';

		var rows = [], row = [], cell = '', quoted = false;
		for (var i = 0; i < text.length; i++) {
			var ch = text[i];
			if (quoted) {
				if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
				else if (ch === '"') { quoted = false; }
				else { cell += ch; }
				continue;
			}
			if (ch === '"') { quoted = true; }
			else if (ch === delim) { row.push(cell); cell = ''; }
			else if (ch === '\n' || ch === '\r') {
				if (ch === '\r' && text[i + 1] === '\n') i++;
				row.push(cell); rows.push(row); row = []; cell = '';
			} else { cell += ch; }
		}
		if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
		return rows.map(function (r) { return r.map(function (c) { return c.trim(); }).filter(function (c) { return c !== ''; }); })
			.filter(function (r) { return r.length; });
	}

	/** Turn an uploaded file into lines in the format of one list. */
	function fileToLines(listKey, text, isCsv) {
		if (!isCsv) {
			return text.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(function (l) { return l !== ''; });
		}
		var out = [];
		parseCsv(text).forEach(function (cells) {
			if (cells[0].charAt(0) === '#') { out.push(cells.join(' ')); return; }
			if (listKey === 'stopwords') { cells.forEach(function (c) { out.push(c); }); return; }
			if (cells.length === 1) { out.push(cells[0]); return; }
			if (listKey === 'corrections') { out.push(cells[0] + ' => ' + cells.slice(1).join(', ')); return; }
			out.push(cells.join(', '));
		});
		return out;
	}

	/** Decode a file as UTF-8, falling back to Windows-1252 (Excel's usual CSV). */
	function decodeFile(buffer) {
		try { return new TextDecoder('utf-8', { fatal: true }).decode(buffer).replace(/^﻿/, ''); }
		catch (e) { return new TextDecoder('windows-1252').decode(buffer); }
	}

	function downloadText(name, text) {
		var blob = new Blob(['﻿' + text], { type: 'text/plain;charset=utf-8' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		document.body.appendChild(a);
		a.click();
		window.setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
	}

	var VocabularyView = {
		emits: ['applied'],
		data: function () {
			return {
				metas: VOCAB_LISTS,
				active: 'synonyms',
				lists: { synonyms: '', variants: '', corrections: '', stopwords: '' },
				report: null,
				status: {},
				applied: {},
				indexName: '',
				loading: true,
				saving: false,
				applying: false,
				dirty: false,
				msg: '',
				msgClass: 'is-info',
				result: null,
				fileMode: 'append',
				validateTimer: null,
				testText: '',
				testing: false,
				testResult: null,
				typo: false,
				typoSaving: false,
				settingsUrl: window.TIMConfig.settingsUrl
			};
		},
		computed: {
			meta: function () {
				var a = this.active;
				return VOCAB_LISTS.filter(function (m) { return m.key === a; })[0];
			},
			activeErrors: function () {
				var a = this.active;
				return ((this.report && this.report.errors) || []).filter(function (e) { return e.list === a || e.list === ''; });
			},
			totalRules: function () {
				var c = (this.report && this.report.counts) || {};
				return (c.synonyms || 0) + (c.variants || 0) + (c.corrections || 0);
			}
		},
		mounted: function () {
			var self = this;
			this.load();
			this.onBeforeUnload = function (e) {
				if (self.dirty) { e.preventDefault(); e.returnValue = ''; }
			};
			window.addEventListener('beforeunload', this.onBeforeUnload);
		},
		beforeUnmount: function () { window.removeEventListener('beforeunload', this.onBeforeUnload); },
		methods: {
			fmtDate: fmtDate,
			fmtNumber: fmtNumber,
			fmtMs: fmtMs,
			count: function (key) { return (this.report && this.report.counts && this.report.counts[key]) || 0; },
			errorCount: function (key) {
				return ((this.report && this.report.errors) || []).filter(function (e) { return e.list === key; }).length;
			},
			applyState: function (st) {
				if (!st) return;
				this.lists = Object.assign({ synonyms: '', variants: '', corrections: '', stopwords: '' }, st.lists || {});
				this.report = st.report || null;
				this.status = st.status || {};
				this.applied = st.applied || {};
				this.indexName = st.index_name || '';
			},
			load: function () {
				var self = this;
				this.loading = true;
				Promise.all([api('GET', '/vocabulary'), api('GET', '/settings')]).then(function (res) {
					self.applyState(res[0]);
					self.typo = !!(res[1] && res[1].search_typo_tolerance);
					self.dirty = false;
				}).catch(function (e) {
					self.msg = e.message; self.msgClass = 'is-error';
				}).finally(function () { self.loading = false; });
			},
			onInput: function () {
				var self = this;
				this.dirty = true;
				window.clearTimeout(this.validateTimer);
				this.validateTimer = window.setTimeout(function () {
					api('POST', '/vocabulary/validate', { lists: self.lists }).then(function (rep) { self.report = rep; }).catch(function () {});
				}, 700);
			},
			save: function () {
				var self = this;
				this.saving = true;
				return api('POST', '/vocabulary', { lists: this.lists }).then(function (res) {
					self.applyState(res.state);
					self.dirty = false;
					self.msg = res.report && res.report.ok
						? 'Rascunho salvo. Ele só passa a valer na busca depois de “Aplicar ao Elasticsearch”.'
						: 'Rascunho salvo, mas há linhas a corrigir antes de aplicar.';
					self.msgClass = res.report && res.report.ok ? 'is-success' : 'is-warning';
				}).catch(function (e) { self.msg = e.message; self.msgClass = 'is-error'; })
				.finally(function () { self.saving = false; });
			},
			apply: function () {
				var text = 'Aplicar o vocabulário ao Elasticsearch?\n\n'
					+ '1. As listas são conferidas e ensaiadas num índice temporário.\n'
					+ '2. O índice “' + this.indexName + '” é fechado por alguns segundos para trocar o analisador de busca — nesse intervalo, as buscas do site são respondidas pelo banco de dados (mais lento, mas sem erro).\n'
					+ '3. O índice é reaberto e conferido.\n\n'
					+ 'Não é preciso reindexar.';
				if (!window.confirm(text)) return;
				var self = this;
				this.applying = true;
				this.result = null;
				this.msg = '';
				api('POST', '/vocabulary/apply', { lists: this.lists }).then(function (res) {
					self.result = res;
					self.applyState(res.state);
					if (res.report) self.report = res.report;
					self.dirty = false;
					self.$emit('applied');
					window.setTimeout(function () {
						var el = document.getElementById('tim-apply-result');
						if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}, 50);
				}).catch(function (e) {
					self.result = { ok: false, message: e.message, steps: [] };
				}).finally(function () { self.applying = false; });
			},
			pickFile: function (mode) {
				this.fileMode = mode;
				this.$refs.file.value = '';
				this.$refs.file.click();
			},
			onFile: function (ev) {
				var file = ev.target.files && ev.target.files[0];
				if (!file) return;
				if (file.size > 2 * 1024 * 1024) {
					this.msg = 'Arquivo maior que 2 MB. Divida a lista em partes.'; this.msgClass = 'is-error';
					return;
				}
				var self = this;
				var key = this.active;
				var isCsv = /\.csv$/i.test(file.name) || file.type === 'text/csv';
				file.arrayBuffer().then(function (buf) {
					var lines = fileToLines(key, decodeFile(buf), isCsv);
					var current = self.lists[key].replace(/\s+$/, '');
					self.lists[key] = (self.fileMode === 'append' && current ? current + '\n' : '') + lines.join('\n') + '\n';
					self.msg = lines.length + ' linha(s) de “' + file.name + '” ' + (self.fileMode === 'append' ? 'acrescentadas' : 'carregadas') + ' em ' + self.meta.label + '. Confira abaixo e depois aplique.';
					self.msgClass = 'is-info';
					self.onInput();
				}).catch(function (e) { self.msg = 'Não foi possível ler o arquivo: ' + e.message; self.msgClass = 'is-error'; });
			},
			downloadTemplate: function () { downloadText('modelo-' + this.meta.key + '.txt', this.meta.template); },
			downloadCurrent: function () { downloadText(this.meta.key + '.txt', this.lists[this.active] || ''); },
			stepIcon: function (s) { return ({ ok: '✓', fail: '✕', skip: '–' })[s] || '·'; },
			listLabel: function (key) {
				var m = VOCAB_LISTS.filter(function (x) { return x.key === key; })[0];
				return m ? m.label : '';
			},
			runTest: function () {
				var self = this;
				if (!this.testText.trim()) return;
				this.testing = true;
				api('POST', '/vocabulary/test', { text: this.testText }).then(function (res) { self.testResult = res; })
				.catch(function (e) { self.testResult = { ok: false, message: e.message }; })
				.finally(function () { self.testing = false; });
			},
			toggleTypo: function () {
				var self = this;
				this.typoSaving = true;
				api('POST', '/settings', { search_typo_tolerance: this.typo }).then(function (res) {
					self.typo = !!(res.settings && res.settings.search_typo_tolerance);
					self.msg = self.typo ? 'Busca aproximada ligada: já vale para as próximas buscas.' : 'Busca aproximada desligada.';
					self.msgClass = 'is-success';
				}).catch(function (e) { self.msg = e.message; self.msgClass = 'is-error'; self.typo = !self.typo; })
				.finally(function () { self.typoSaving = false; });
			}
		},
		template: [
			'<div class="tim-vocab">',
			'  <div v-if="loading" class="tim-notice is-info"><span class="tim-loading"></span> Carregando…</div>',
			'  <template v-else>',
			'  <section class="tim-section tim-explain">',
			'    <h2>Ensine a busca a falar a língua do acervo</h2>',
			'    <p>O público busca “quadro”, o registro diz “pintura”. A ficha antiga escreve “photographia”, a pessoa digita “fotografia”. Aqui você cadastra essas equivalências e o Elasticsearch passa a encontrar os dois lados.</p>',
			'    <ol class="tim-steps">',
			'      <li><span class="tim-step-n">1</span><div><strong>Escreva ou carregue as listas</strong><span>Digite nas caixas abaixo ou carregue um arquivo <code>.txt</code> ou <code>.csv</code> (planilha). Os erros aparecem enquanto você digita.</span></div></li>',
			'      <li><span class="tim-step-n">2</span><div><strong>Aplique ao Elasticsearch</strong><span>O plugin ensaia tudo num índice temporário e, se o Elasticsearch aceitar, troca o analisador de busca do índice real em poucos segundos.</span></div></li>',
			'      <li><span class="tim-step-n">3</span><div><strong>Teste</strong><span>No fim da página, digite uma palavra e veja o que a busca procura e quantos itens encontra, com e sem o vocabulário.</span></div></li>',
			'    </ol>',
			'    <ul class="tim-facts">',
			'      <li><strong>Não precisa reindexar.</strong> As regras agem na hora da busca, não nos documentos guardados.</li>',
			'      <li><strong>Vale para a busca por texto</strong> (a caixa de busca do Tainacan). Filtros e facetas continuam exatos.</li>',
			'      <li><strong>Plurais, maiúsculas e acentos já são tratados</strong> automaticamente: cadastre só o que muda de palavra.</li>',
			'    </ul>',
			'  </section>',
			'',
			'  <div :class="[\'tim-vocab-status\', status.pending ? \'is-pending\' : (applied.at ? \'is-live\' : \'is-empty\')]">',
			'    <span v-if="applied.at" class="tim-status-pill tim-green">Em uso</span>',
			'    <span v-else class="tim-status-pill tim-unknown">Nada aplicado</span>',
			'    <span v-if="applied.at">{{ fmtNumber(applied.rules) }} regras e {{ fmtNumber(applied.stopwords) }} palavras ignoradas, aplicadas em {{ fmtDate(applied.at) }} no índice <code>{{ applied.index }}</code>.</span>',
			'    <span v-else>O vocabulário ainda não foi enviado ao Elasticsearch.</span>',
			'    <span v-if="status.pending || dirty" class="tim-status-pill tim-yellow">Alterações não aplicadas</span>',
			'  </div>',
			'',
			'  <div v-if="msg" :class="[\'tim-notice\', msgClass]">{{ msg }}</div>',
			'',
			'  <section class="tim-section tim-vocab-editor">',
			'    <div class="tim-vocab-tabs" role="tablist" aria-label="Listas do vocabulário">',
			'      <button v-for="m in metas" :key="m.key" type="button" role="tab" :aria-selected="active === m.key ? \'true\' : \'false\'" :class="[\'tim-vocab-tab\', { \'is-active\': active === m.key }]" @click="active = m.key">',
			'        <span class="tim-vocab-tab-icon" aria-hidden="true">{{ m.icon }}</span>',
			'        <span class="tim-vocab-tab-text"><strong>{{ m.label }}</strong><small>{{ count(m.key) }} {{ m.key === \'stopwords\' ? \'palavras\' : \'regras\' }}<template v-if="errorCount(m.key)"> · <em>{{ errorCount(m.key) }} a corrigir</em></template></small></span>',
			'      </button>',
			'    </div>',
			'    <div class="tim-vocab-pane" role="tabpanel">',
			'      <aside class="tim-vocab-help">',
			'        <p class="tim-vocab-lead">{{ meta.lead }}</p>',
			'        <p>{{ meta.how }}</p>',
			'        <p class="tim-vocab-label">Exemplo</p>',
			'        <pre class="tim-code">{{ meta.example }}</pre>',
			'        <p class="tim-vocab-tip">{{ meta.tip }}</p>',
			'      </aside>',
			'      <div class="tim-vocab-edit">',
			'        <div class="tim-vocab-toolbar">',
			'          <button type="button" class="tim-btn is-secondary" @click="pickFile(\'append\')">Acrescentar de arquivo</button>',
			'          <button type="button" class="tim-btn is-secondary" @click="pickFile(\'replace\')">Substituir por arquivo</button>',
			'          <button type="button" class="tim-link-btn" @click="downloadTemplate">Baixar modelo</button>',
			'          <button type="button" class="tim-link-btn" @click="downloadCurrent">Baixar esta lista</button>',
			'        </div>',
			'        <label class="screen-reader-text" :for="\'tim-vocab-\' + active">{{ meta.label }}</label>',
			'        <textarea :id="\'tim-vocab-\' + active" class="tim-vocab-textarea" v-model="lists[active]" @input="onInput" rows="14" spellcheck="false" :placeholder="meta.example"></textarea>',
			'        <p class="tim-muted">Arquivos aceitos: <code>.txt</code> (uma regra por linha) e <code>.csv</code> de planilha (cada linha vira uma regra; em Correções, a 1ª coluna é o que se digita). Linhas que começam com <code>#</code> são comentários.</p>',
			'        <ul class="tim-vocab-errors" v-if="activeErrors.length">',
			'          <li v-for="(e, i) in activeErrors" :key="i"><strong v-if="e.line">Linha {{ e.line }}</strong><code v-if="e.text">{{ e.text }}</code><span>{{ e.message }}</span></li>',
			'        </ul>',
			'      </div>',
			'    </div>',
			'    <input type="file" ref="file" accept=".txt,.csv,text/plain,text/csv" class="tim-hidden" @change="onFile">',
			'  </section>',
			'',
			'  <section class="tim-section tim-typo">',
			'    <div>',
			'      <h2>Busca aproximada (erros de digitação)</h2>',
			'      <p>Aceita uma ou duas letras trocadas em cada palavra: “fotgrafia” encontra “fotografia”, “pintrua” encontra “pintura”. A primeira letra precisa estar certa. Complementa as listas acima: elas resolvem palavras diferentes, a busca aproximada resolve a mesma palavra escrita errado.</p>',
			'    </div>',
			'    <label class="tim-switch"><input type="checkbox" v-model="typo" @change="toggleTypo" :disabled="typoSaving"><span class="tim-switch-track" aria-hidden="true"></span><span>{{ typo ? \'Ligada\' : \'Desligada\' }}</span></label>',
			'  </section>',
			'',
			'  <div class="tim-vocab-actions">',
			'    <span class="tim-muted">{{ fmtNumber(totalRules) }} regras e {{ fmtNumber(count(\'stopwords\')) }} palavras ignoradas no rascunho<template v-if="report && !report.ok"> · <strong class="tim-text-danger">{{ report.errors_total }} linha(s) a corrigir</strong></template></span>',
			'    <button type="button" class="tim-btn is-secondary" @click="save" :disabled="saving || applying"><span class="tim-loading" v-if="saving"></span>Salvar rascunho</button>',
			'    <button type="button" class="tim-btn" @click="apply" :disabled="applying || saving || (report && !report.ok)"><span class="tim-loading" v-if="applying"></span>{{ applying ? \'Aplicando…\' : \'Aplicar ao Elasticsearch\' }}</button>',
			'  </div>',
			'',
			'  <section v-if="result" id="tim-apply-result" :class="[\'tim-section\', \'tim-apply-result\', result.ok ? \'is-ok\' : \'is-fail\']">',
			'    <h2>{{ result.ok ? \'Vocabulário aplicado\' : \'O vocabulário não foi aplicado\' }}</h2>',
			'    <p>{{ result.message }}</p>',
			'    <ol class="tim-apply-steps" v-if="result.steps && result.steps.length">',
			'      <li v-for="s in result.steps" :key="s.key" :class="\'is-\' + s.status"><span class="tim-check-icon" aria-hidden="true">{{ stepIcon(s.status) }}</span><strong>{{ s.label }}</strong><span>{{ s.detail }} <em class="tim-muted" v-if="s.ms">({{ fmtMs(s.ms) }})</em></span></li>',
			'    </ol>',
			'    <div v-if="applied.samples && applied.samples.length && result.ok">',
			'      <h3>Como a busca entende algumas regras agora</h3>',
			'      <ul class="tim-samples">',
			'        <li v-for="(s, i) in applied.samples" :key="i"><code>{{ s.text }}</code> <span aria-hidden="true">→</span> <span v-for="(f, j) in s.forms" :key="j" class="tim-chipset"><span v-for="w in f" :key="w" class="tim-chip">{{ w }}</span></span></li>',
			'      </ul>',
			'      <p class="tim-muted">Os termos aparecem como radicais (“fotograf”), a forma que o índice guarda: é assim que plurais e variações de gênero são encontrados juntos.</p>',
			'    </div>',
			'    <div v-if="applied.notes && applied.notes.length">',
			'      <h3>Ajustes feitos automaticamente</h3>',
			'      <ul class="tim-notes"><li v-for="(n, i) in applied.notes" :key="i"><span class="tim-muted">{{ listLabel(n.list) }}<template v-if="n.line">, linha {{ n.line }}</template>:</span> {{ n.message }}</li></ul>',
			'    </div>',
			'  </section>',
			'',
			'  <section class="tim-section tim-vocab-test">',
			'    <h2>Teste uma busca</h2>',
			'    <p class="tim-muted">Mostra o que o Elasticsearch procura para uma palavra ou expressão, usando o vocabulário que está <strong>em uso</strong> (aplicado), e quantos itens publicados ela encontra.</p>',
			'    <form class="tim-test-form" @submit.prevent="runTest">',
			'      <label class="screen-reader-text" for="tim-vocab-test">Palavra ou expressão</label>',
			'      <input id="tim-vocab-test" type="search" v-model="testText" placeholder="ex.: fotografias do rio de janeiro">',
			'      <button type="submit" class="tim-btn" :disabled="testing || !testText.trim()"><span class="tim-loading" v-if="testing"></span>Testar</button>',
			'    </form>',
			'    <div v-if="testResult" class="tim-test-result">',
			'      <div v-if="!testResult.ok" class="tim-notice is-error">{{ testResult.message }}</div>',
			'      <template v-else>',
			'        <div class="tim-test-counts">',
			'          <div><span class="tim-card-label">Sem o vocabulário</span><span class="tim-card-value">{{ fmtNumber(testResult.count_without) }}</span><span class="tim-muted">itens</span></div>',
			'          <span class="tim-test-arrow" aria-hidden="true">→</span>',
			'          <div><span class="tim-card-label">Com o vocabulário</span><span class="tim-card-value">{{ fmtNumber(testResult.count_with) }}</span><span class="tim-muted">itens</span></div>',
			'        </div>',
			'        <p class="tim-vocab-label">A busca procura por</p>',
			'        <div class="tim-test-forms">',
			'          <template v-for="(f, i) in testResult.forms" :key="i"><span v-if="i" class="tim-test-and">e</span><span class="tim-chipset"><span v-for="w in f" :key="w" class="tim-chip">{{ w }}</span></span></template>',
			'          <span v-if="!testResult.forms.length" class="tim-muted">nada — todas as palavras são ignoradas pela busca.</span>',
			'        </div>',
			'        <p class="tim-muted">Cada grupo é uma palavra da busca; os termos dentro do grupo são alternativas (basta um). Radicais como “fotograf” cobrem plural e variações.</p>',
			'      </template>',
			'    </div>',
			'  </section>',
			'  </template>',
			'</div>'
		].join('\n')
	};

	/* ------------------------------------------------------------------ */
	/* Shell: page navigation + status light around every view             */
	/* ------------------------------------------------------------------ */

	var app = window.Vue.createApp({
		data: function () {
			return {
				view: initialView,
				light: null,
				lightLoading: false,
				lightError: '',
				lightTimer: null,
				urls: {
					dashboard: window.TIMConfig.dashUrl,
					vocabulary: window.TIMConfig.vocabularyUrl,
					settings: window.TIMConfig.settingsUrl
				}
			};
		},
		components: { DashboardView: DashboardView, VocabularyView: VocabularyView, SettingsView: SettingsView, TrafficLight: TrafficLight },
		mounted: function () {
			var self = this;
			this.loadLight(false);
			// The server caches the health snapshot for 60 s, so polling is cheap.
			this.lightTimer = window.setInterval(function () {
				if (!document.hidden) self.loadLight(false);
			}, 20000);
		},
		beforeUnmount: function () { window.clearInterval(this.lightTimer); },
		methods: {
			loadLight: function (refresh) {
				var self = this;
				this.lightLoading = true;
				return api('GET', '/status' + (refresh ? '?refresh=1' : '')).then(function (res) {
					self.light = res;
					self.lightError = '';
				}).catch(function (e) {
					self.lightError = e.message || 'Erro ao consultar o estado.';
				}).finally(function () { self.lightLoading = false; });
			}
		},
		template: [
			'<div class="tim-shell">',
			'  <nav class="tim-pagenav" aria-label="Seções da gestão da indexação">',
			'    <a :href="urls.dashboard" :class="{ \'is-active\': view === \'dashboard\' }" :aria-current="view === \'dashboard\' ? \'page\' : null">Painel</a>',
			'    <a :href="urls.vocabulary" :class="{ \'is-active\': view === \'vocabulary\' }" :aria-current="view === \'vocabulary\' ? \'page\' : null">Vocabulário da busca</a>',
			'    <a :href="urls.settings" :class="{ \'is-active\': view === \'settings\' }" :aria-current="view === \'settings\' ? \'page\' : null">Configurações</a>',
			'  </nav>',
			'  <traffic-light :light="light" :loading="lightLoading" :error="lightError" :compact="view !== \'dashboard\'" @refresh="loadLight(true)"></traffic-light>',
			'  <dashboard-view v-if="view === \'dashboard\'"></dashboard-view>',
			'  <vocabulary-view v-else-if="view === \'vocabulary\'" @applied="loadLight(true)"></vocabulary-view>',
			'  <settings-view v-else></settings-view>',
			'</div>'
		].join('')
	});

	app.mount('#tainacan-idxmgr-app');
})();
