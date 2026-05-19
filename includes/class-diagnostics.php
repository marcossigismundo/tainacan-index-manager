<?php
/**
 * Diagnostics service: turn raw health + indexer signals into actionable
 * plain-text findings for non-technical site managers.
 *
 * @package TainacanIndexManager
 */

namespace TainacanIndexManager;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a "what does this mean and what should I do?" report from the
 * current snapshot, indexer state and metrics. Pure read-only — never
 * persists, never raises alerts. The dashboard renders this as a top-level
 * card so a manager can decide on the next move without parsing every
 * indicator separately.
 *
 * Each rule emits one finding shaped like:
 *  - severity:    ok | info | warning | critical
 *  - title:       short label
 *  - message:     longer explanation in PT-BR
 *  - action:      what to do next (or '' for purely informational findings)
 *  - action_url:  optional admin URL to one-click into the action
 *
 * The overall report has its own severity = worst of the findings'
 * severities, and a headline derived from it. Rules run in declaration
 * order; findings appear in that order in the response.
 */
final class Diagnostics {

	private Settings $settings;
	private Health_Service $health;
	private Indexer $indexer;
	private Indexer_Metrics $metrics;
	private Collections_Monitor $collections;
	private ElasticPress_Integration $elasticpress;

	public function __construct(
		Settings $settings,
		Health_Service $health,
		Indexer $indexer,
		Indexer_Metrics $metrics,
		Collections_Monitor $collections,
		ElasticPress_Integration $elasticpress
	) {
		$this->settings     = $settings;
		$this->health       = $health;
		$this->indexer      = $indexer;
		$this->metrics      = $metrics;
		$this->collections  = $collections;
		$this->elasticpress = $elasticpress;
	}

	/**
	 * Build the diagnostic report.
	 */
	public function report(): array {
		$snapshot = $this->health->get_snapshot();
		$summary  = $this->metrics->summary( $this->indexer->queue_size(), 10 );
		$state    = $this->indexer->get_state();
		$findings = array();

		$settings_url = admin_url( 'admin.php?page=' . Admin_Page::SETTINGS_SLUG );

		/* ---- Configuration / connectivity ---- */

		if ( ! $snapshot['es_configured'] ) {
			$findings[] = $this->finding(
				'critical',
				__( 'Elasticsearch não configurado', 'tainacan-index-manager' ),
				__( 'Nenhuma URL de Elasticsearch ou OpenSearch foi informada. A busca opera em modo SQL e perde qualidade em grandes acervos.', 'tainacan-index-manager' ),
				__( 'Abra Configurações de Indexação e informe a URL e as credenciais do cluster.', 'tainacan-index-manager' ),
				$settings_url
			);
			return $this->compile( $findings );
		}

		if ( ! $snapshot['es_reachable'] ) {
			$findings[] = $this->finding(
				'critical',
				__( 'Elasticsearch indisponível', 'tainacan-index-manager' ),
				__( 'O servidor de busca não respondeu. A busca está em modo SQL temporariamente.', 'tainacan-index-manager' ),
				__( 'Verifique se o serviço está no ar, se a URL está correta e se as credenciais (Basic Auth ou API Key) seguem válidas. Use "Testar conexão" após corrigir.', 'tainacan-index-manager' ),
				$settings_url
			);
			return $this->compile( $findings );
		}

		/* ---- Index lifecycle ---- */

		if ( ! $snapshot['index_exists'] ) {
			$findings[] = $this->finding(
				'warning',
				__( 'Índice ainda não criado', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %s = index name */
					__( 'O índice "%s" não existe no Elasticsearch. Nenhum documento pode ser indexado até ele ser criado.', 'tainacan-index-manager' ),
					(string) $snapshot['index_name']
				),
				__( 'Abra Configurações de Indexação e clique em "Criar índice".', 'tainacan-index-manager' ),
				$settings_url
			);
			return $this->compile( $findings );
		}

		/* ---- Cluster ---- */

		if ( 'red' === $snapshot['cluster_status'] ) {
			$findings[] = $this->finding(
				'critical',
				__( 'Cluster em estado RED', 'tainacan-index-manager' ),
				__( 'Um ou mais shards primários não estão alocados. A busca pode estar incompleta ou indisponível.', 'tainacan-index-manager' ),
				__( 'Verifique o status do cluster com a equipe de infraestrutura e os logs do Elasticsearch antes de continuar a indexar.', 'tainacan-index-manager' ),
				''
			);
		} elseif ( 'yellow' === $snapshot['cluster_status'] ) {
			$nodes = isset( $snapshot['cluster']['number_of_nodes'] ) ? (int) $snapshot['cluster']['number_of_nodes'] : 0;
			if ( 1 === $nodes ) {
				$findings[] = $this->finding(
					'info',
					__( 'Cluster YELLOW é esperado em instalação de 1 nó', 'tainacan-index-manager' ),
					__( 'Com apenas um nó, as réplicas dos shards não têm onde ser alocadas — por isso o status fica amarelo. Em ambiente de produção institucional considere adicionar nós para alta disponibilidade.', 'tainacan-index-manager' ),
					'',
					''
				);
			} else {
				$findings[] = $this->finding(
					'warning',
					__( 'Cluster em estado YELLOW', 'tainacan-index-manager' ),
					sprintf(
						/* translators: %d = unassigned shards */
						__( 'Há %d shards não alocados. A busca segue funcional, mas sem redundância.', 'tainacan-index-manager' ),
						isset( $snapshot['cluster']['unassigned_shards'] ) ? (int) $snapshot['cluster']['unassigned_shards'] : 0
					),
					__( 'Investigue por que esses shards não estão alocados (disco, configuração de réplicas, nó offline).', 'tainacan-index-manager' ),
					''
				);
			}
		}

		/* ---- Latency ---- */

		if ( null !== $snapshot['es_ping_ms'] && $snapshot['es_ping_ms'] > 2000 ) {
			$findings[] = $this->finding(
				'warning',
				__( 'Tempo de resposta do Elasticsearch elevado', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %d = latency in ms */
					__( 'Resposta do cluster acima de 2s (atual: %d ms). Buscas podem ficar lentas para o usuário final.', 'tainacan-index-manager' ),
					(int) $snapshot['es_ping_ms']
				),
				__( 'Verifique CPU/IO do nó, número de shards e proximidade de rede entre WordPress e o cluster.', 'tainacan-index-manager' ),
				''
			);
		}

		/* ---- Coverage / divergence ---- */

		$queue_size = (int) ( $summary['queue']['size'] ?? 0 );
		$auto_on    = (bool) $this->settings->get( 'auto_indexing_enabled', false );

		if ( null !== $snapshot['divergence_pct'] && $snapshot['divergence_pct'] > $snapshot['divergence_threshold_pct'] ) {
			$is_initial = ( (int) $snapshot['index_doc_count'] === 0 && (int) $snapshot['tainacan_item_count'] > 0 );
			$findings[] = $this->finding(
				$is_initial ? 'info' : 'warning',
				$is_initial
					? __( 'Indexação inicial em andamento (ou pendente)', 'tainacan-index-manager' )
					: __( 'Cobertura do índice abaixo do esperado', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %1$s coverage, %2$s threshold */
					__( 'Cobertura atual: %1$s%% — limite aceitável: %2$s%%.', 'tainacan-index-manager' ),
					number_format_i18n( (float) $snapshot['coverage_pct'], 2 ),
					number_format_i18n( $snapshot['divergence_threshold_pct'] )
				),
				$queue_size > 0
					? ( $auto_on
						? __( 'Há itens na fila e o processamento automático está ligado — aguarde os lotes rodarem.', 'tainacan-index-manager' )
						: __( 'Há itens na fila. Habilite "processamento automático" em Configurações ou clique em "Processar lote" repetidamente.', 'tainacan-index-manager' ) )
					: __( 'Clique em "Indexar tudo" para enfileirar a reindexação total.', 'tainacan-index-manager' ),
				$queue_size > 0 ? '' : $settings_url
			);
		}

		/* ---- Queue / auto-indexing ---- */

		if ( $queue_size > 0 && ! $auto_on ) {
			$findings[] = $this->finding(
				'info',
				__( 'Processamento automático está desligado', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %s = queue size formatted */
					__( 'A fila tem %s itens, mas o cron de indexação só roda quando o automático está ativo. Caso contrário, é preciso clicar manualmente em "Processar lote" para drenar.', 'tainacan-index-manager' ),
					number_format_i18n( $queue_size )
				),
				__( 'Abra Configurações de Indexação e marque "Habilitar processamento automático de lotes".', 'tainacan-index-manager' ),
				$settings_url
			);
		}

		/* ---- Recent failure rate ---- */

		$success_rate = isset( $summary['window']['success_rate_pct'] ) ? $summary['window']['success_rate_pct'] : null;
		if ( null !== $success_rate && $success_rate < 80 ) {
			$last_err = $summary['last_error_summary'] ?? array();
			$most     = '';
			if ( is_array( $last_err ) && ! empty( $last_err ) ) {
				$keys = array_keys( $last_err );
				$most = (string) $keys[0];
			}
			$findings[] = $this->finding(
				$success_rate < 50 ? 'critical' : 'warning',
				__( 'Alta taxa de falha nos últimos lotes', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %1$s rate, %2$s error type */
					__( 'Apenas %1$s%% dos itens recentes foram indexados com sucesso. Tipo de erro mais comum: %2$s.', 'tainacan-index-manager' ),
					number_format_i18n( (float) $success_rate, 2 ),
					'' !== $most ? $most : __( 'desconhecido', 'tainacan-index-manager' )
				),
				__( 'Veja "Motivo das falhas no último lote" abaixo. Costuma ser mapping/parsing de um campo específico do documento.', 'tainacan-index-manager' ),
				''
			);
		}

		/* ---- ElasticPress posture (informational only) ---- */

		if ( $this->elasticpress->is_active() ) {
			$findings[] = $this->finding(
				'info',
				__( 'ElasticPress detectado', 'tainacan-index-manager' ),
				__( 'O ElasticPress está ativo. Este plugin opera em modo somente leitura sobre ele para evitar conflitos de índice.', 'tainacan-index-manager' ),
				'',
				''
			);
		} else {
			$findings[] = $this->finding(
				'info',
				__( 'Indexador próprio em operação', 'tainacan-index-manager' ),
				__( 'O ElasticPress não foi detectado — este é um cenário válido. O plugin está usando seu próprio indexador, com mappings otimizados para português brasileiro e adequados a repositórios Tainacan.', 'tainacan-index-manager' ),
				'',
				''
			);
		}

		/* ---- Indexer state ---- */

		if ( Indexer::STATE_PAUSED === $state ) {
			$findings[] = $this->finding(
				'warning',
				__( 'Indexador pausado pelo administrador', 'tainacan-index-manager' ),
				__( 'A fila não está sendo processada. Itens novos/alterados continuam sendo enfileirados.', 'tainacan-index-manager' ),
				__( 'Clique em "Retomar" para voltar a processar a fila.', 'tainacan-index-manager' ),
				$settings_url
			);
		}

		/* ---- All clear ---- */

		if ( empty( $findings ) || $this->only_severity( $findings, array( 'info' ) ) ) {
			$findings[] = $this->finding(
				'ok',
				__( 'Busca operacional e índice saudável', 'tainacan-index-manager' ),
				sprintf(
					/* translators: %1$s coverage, %2$s indexed count, %3$s tainacan count */
					__( 'Cobertura em %1$s%% (%2$s de %3$s itens indexados). Nenhuma ação necessária no momento.', 'tainacan-index-manager' ),
					null === $snapshot['coverage_pct'] ? '—' : number_format_i18n( (float) $snapshot['coverage_pct'], 2 ),
					number_format_i18n( (int) ( $snapshot['index_doc_count'] ?? 0 ) ),
					number_format_i18n( (int) ( $snapshot['tainacan_item_count'] ?? 0 ) )
				),
				'',
				''
			);
		}

		return $this->compile( $findings );
	}

	/**
	 * Compose a finding row.
	 */
	private function finding( string $severity, string $title, string $message, string $action, string $action_url ): array {
		return array(
			'severity'   => $severity,
			'title'      => $title,
			'message'    => $message,
			'action'     => $action,
			'action_url' => $action_url,
		);
	}

	/**
	 * Roll the findings up into a single severity + headline.
	 */
	private function compile( array $findings ): array {
		$rank = array( 'ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3 );
		$max  = 'ok';
		foreach ( $findings as $f ) {
			$s = $f['severity'] ?? 'ok';
			if ( ( $rank[ $s ] ?? 0 ) > ( $rank[ $max ] ?? 0 ) ) {
				$max = $s;
			}
		}

		switch ( $max ) {
			case 'critical':
				$headline = __( 'Ação imediata recomendada', 'tainacan-index-manager' );
				break;
			case 'warning':
				$headline = __( 'Atenção do gestor recomendada', 'tainacan-index-manager' );
				break;
			case 'info':
				$headline = __( 'Operação normal — fique de olho nos pontos abaixo', 'tainacan-index-manager' );
				break;
			default:
				$headline = __( 'Tudo certo — busca operacional', 'tainacan-index-manager' );
		}

		return array(
			'severity'     => $max,
			'headline'     => $headline,
			'findings'     => array_values( $findings ),
			'generated_at' => time(),
		);
	}

	private function only_severity( array $findings, array $allowed ): bool {
		foreach ( $findings as $f ) {
			if ( ! in_array( $f['severity'] ?? '', $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}
}
