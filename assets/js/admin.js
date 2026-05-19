/*
 * Tainacan Index Manager — Admin SPA (Vue 3).
 *
 * Single Vue app mounted on #tainacan-idxmgr-app. The `data-view`
 * attribute on the root element selects between "dashboard" and
 * "settings" views. All server interaction goes through the plugin's
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
	var initialView = root.getAttribute('data-view') || 'dashboard';

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

	var DashboardView = {
		template: '\
		<div>\
			<h1 class="tim-title">{{ i18n.dashboard }}</h1>\
			<div v-if="initialLoading" class="tim-notice is-info"><span class="tim-loading"></span> {{ i18n.refresh }}…</div>\
			<div v-else-if="errorMsg" class="tim-notice is-error">{{ errorMsg }}</div>\
			<template v-else>\
				<div class="tim-cards">\
					<div :class="[\'tim-card\', cardClass(snapshot.overall_status)]">\
						<span class="tim-card-label">{{ i18n.overview }}</span>\
						<span class="tim-card-value">\
							<span :class="[\'tim-status-pill\', statusClass(snapshot.overall_status)]">{{ overallLabel(snapshot.overall_status) }}</span>\
						</span>\
						<span class="tim-card-sub">{{ snapshot.overall_message }}</span>\
					</div>\
					<div :class="[\'tim-card\', cardClass(snapshot.cluster_status === \'red\' ? \'critical\' : (snapshot.cluster_status === \'yellow\' ? \'warning\' : (snapshot.cluster_status === \'green\' ? \'ok\' : \'unknown\')))]">\
						<span class="tim-card-label">{{ i18n.cluster }}</span>\
						<span class="tim-card-value">\
							<span :class="[\'tim-status-pill\', statusClass(snapshot.cluster_status)]">{{ (snapshot.cluster_status || \'—\').toUpperCase() }}</span>\
						</span>\
						<span class="tim-card-sub" v-if="snapshot.cluster">{{ snapshot.cluster.number_of_nodes }} nós · {{ snapshot.cluster.active_shards }} shards ativos · {{ snapshot.cluster.unassigned_shards }} unassigned</span>\
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
					<div :class="[\'tim-card\', snapshot.divergence_pct !== null && snapshot.divergence_pct > snapshot.divergence_threshold_pct ? \'is-warning\' : \'is-ok\']">\
						<span class="tim-card-label">{{ i18n.coverage }}</span>\
						<span class="tim-card-value">{{ snapshot.coverage_pct !== null ? snapshot.coverage_pct + \'%\' : \'—\' }}</span>\
						<div class="tim-bar" v-if="snapshot.coverage_pct !== null"><div class="tim-bar-fill" :style="{ width: Math.min(100, Math.max(0, snapshot.coverage_pct)) + \'%\' }"></div></div>\
						<span class="tim-card-sub">{{ i18n.divergence }}: {{ snapshot.divergence_pct !== null ? snapshot.divergence_pct + \'%\' : \'—\' }} (limite {{ snapshot.divergence_threshold_pct }}%)</span>\
					</div>\
					<div class="tim-card">\
						<span class="tim-card-label">{{ i18n.last_check }}</span>\
						<span class="tim-card-value" style="font-size:1rem">{{ fmtDate(snapshot.last_health_check_ts) }}</span>\
						<span class="tim-card-sub">{{ i18n.last_index }}: {{ fmtDate(snapshot.last_index_run_ts) }}</span>\
					</div>\
				</div>\
\
				<div class="tim-actions">\
					<button class="tim-btn" @click="refresh(true)" :disabled="loading"><span class="tim-loading" v-if="loading"></span>{{ i18n.refresh }}</button>\
					<button class="tim-btn is-secondary" @click="testConnection" :disabled="loading">{{ i18n.test_connection }}</button>\
					<button class="tim-btn is-secondary" @click="processBatch" :disabled="loading || !snapshot.es_reachable">{{ i18n.process_batch }}</button>\
					<a class="tim-btn is-secondary" :href="settingsUrl">{{ i18n.settings }}</a>\
				</div>\
\
				<div class="tim-notice is-success" v-if="lastActionMsg">{{ lastActionMsg }}</div>\
\
				<div class="tim-section">\
					<h2>{{ i18n.collections }}</h2>\
					<table class="tim-table" v-if="collections.rows && collections.rows.length">\
						<thead>\
							<tr>\
								<th>ID</th><th>Coleção</th><th>Tainacan</th><th>Indexado</th><th>{{ i18n.coverage }}</th><th>{{ i18n.divergence }}</th>\
								<th></th>\
							</tr>\
						</thead>\
						<tbody>\
							<tr v-for="row in collections.rows" :key="row.collection_id" :class="{ \'is-over-threshold\': row.over_threshold }">\
								<td>{{ row.collection_id }}</td>\
								<td>{{ row.collection_name }}</td>\
								<td>{{ fmtNumber(row.tainacan_count) }}</td>\
								<td>{{ row.indexed_count === null ? \'—\' : fmtNumber(row.indexed_count) }}</td>\
								<td>{{ row.coverage_pct === null ? \'—\' : row.coverage_pct + \'%\' }}</td>\
								<td>{{ row.divergence_pct === null ? \'—\' : row.divergence_pct + \'%\' }}</td>\
								<td><button class="tim-btn is-secondary" @click="reindexCollection(row.collection_id)" :disabled="loading">{{ i18n.reindex_collection }}</button></td>\
							</tr>\
						</tbody>\
					</table>\
					<p class="tim-muted" v-else>{{ collections.message || \'Sem coleções para exibir.\' }}</p>\
				</div>\
\
				<div class="tim-section">\
					<h2>{{ i18n.alerts }} <span class="tim-muted">({{ alerts.length }})</span></h2>\
					<ul v-if="alerts.length">\
						<li v-for="a in alerts" :key="a.code">\
							<span :class="[\'tim-status-pill\', statusClass(a.severity === \'critical\' ? \'red\' : (a.severity === \'warning\' ? \'yellow\' : \'unknown\'))]">{{ a.severity }}</span>\
							<strong style="margin-left:.4rem">{{ a.code }}</strong> — {{ a.message }}\
							<span class="tim-muted"> (visto {{ a.count }}x)</span>\
						</li>\
					</ul>\
					<p class="tim-muted" v-else>Sem alertas ativos.</p>\
				</div>\
\
				<div class="tim-section">\
					<h2>{{ i18n.elasticpress }}</h2>\
					<p v-if="!elasticpress.active" class="tim-muted">ElasticPress não está ativo neste site.</p>\
					<div v-else>\
						<p><strong>Versão:</strong> {{ elasticpress.version || \'—\' }}</p>\
						<p><strong>Host:</strong> <code>{{ elasticpress.host || \'—\' }}</code></p>\
						<p><strong>Estado:</strong> {{ elasticpress.sync_state }}</p>\
						<p><strong>Última sync:</strong> {{ fmtDate(elasticpress.last_sync_ts) }}</p>\
						<button class="tim-btn is-secondary" @click="epSync">{{ i18n.sync_now }}</button>\
					</div>\
				</div>\
\
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
								<td><span :class="[\'tim-status-pill\', statusClass(l.level === \'critical\' ? \'red\' : (l.level === \'warning\' ? \'yellow\' : (l.level === \'error\' ? \'red\' : \'green\')))]">{{ l.level }}</span></td>\
								<td>{{ l.channel }}</td>\
								<td>{{ l.message }}</td>\
							</tr>\
						</tbody>\
					</table>\
					<p class="tim-muted" v-else>Sem registros.</p>\
				</div>\
			</template>\
		</div>',
		data: function () {
			return {
				initialLoading: true,
				loading: false,
				errorMsg: '',
				lastActionMsg: '',
				snapshot: {},
				collections: { rows: [] },
				logs: [],
				alerts: [],
				elasticpress: { active: false },
				settingsUrl: window.TIMConfig.settingsUrl,
				i18n: i18n
			};
		},
		mounted: function () { this.refresh(false); },
		methods: {
			fmtNumber: fmtNumber,
			fmtBytes: fmtBytes,
			fmtDate: fmtDate,
			statusClass: statusToClass,
			cardClass: cardClass,
			engineLabel: engineLabel,
			overallLabel: function (s) {
				if (s === 'ok') return 'OK';
				if (s === 'warning') return 'ATENÇÃO';
				if (s === 'critical') return 'CRÍTICO';
				return '—';
			},
			refresh: function (force) {
				var self = this;
				this.loading = true;
				this.errorMsg = '';
				var qs = force ? '?refresh=1' : '';
				return Promise.all([
					api('GET', '/health' + qs),
					api('GET', '/collections' + qs),
					api('GET', '/alerts'),
					api('GET', '/elasticpress'),
					api('GET', '/logs?per_page=15')
				]).then(function (results) {
					self.snapshot     = results[0] || {};
					self.collections  = results[1] || { rows: [] };
					self.alerts       = (results[2] && results[2].alerts) || [];
					self.elasticpress = results[3] || { active: false };
					self.logs         = (results[4] && results[4].rows) || [];
				}).catch(function (err) {
					self.errorMsg = err.message || 'Erro ao carregar dados.';
				}).finally(function () {
					self.loading = false;
					self.initialLoading = false;
				});
			},
			testConnection: function () {
				var self = this;
				this.loading = true;
				api('POST', '/test-connection').then(function (res) {
					self.lastActionMsg = res.ok
						? (i18n.connection_ok + ' (' + res.ms + ' ms)')
						: (i18n.connection_failed + ': ' + (res.error || ('HTTP ' + res.code)));
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
			<h1 class="tim-title">{{ i18n.settings }}</h1>\
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
						<div class="tim-field"><label>API Key (alternativa ao usuário/senha)</label><input type="password" v-model="form.es_api_key" placeholder="••••"></div>\
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
					self.msgClass = 'is-success';
				}).catch(function (e) { self.msg = e.message; self.msgClass = 'is-error'; })
				.finally(function () { self.saving = false; });
			},
			testConnection: function () {
				var self = this;
				api('POST', '/test-connection').then(function (res) {
					self.msg = res.ok ? (i18n.connection_ok + ' (' + res.ms + ' ms)') : (i18n.connection_failed + ': ' + (res.error || ('HTTP ' + res.code)));
					self.msgClass = res.ok ? 'is-success' : 'is-error';
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
