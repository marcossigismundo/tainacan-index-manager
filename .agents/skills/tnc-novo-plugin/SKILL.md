---
name: tnc-novo-plugin
description: Cria a estrutura de um plugin novo que estende o Tainacan, pronta para a stack, para o WordPress.org e para conviver com outros plugins: cabeçalho, carregamento, namespace e prefixos, i18n, assets, REST, desinstalação, testes, .tnc-check.json e AGENTS.md do projeto. Use para "crie um plugin", "comece um módulo novo para o Tainacan", "scaffold".
---

# tnc-novo-plugin

## Contrato antes de criar

Defina com quem pediu: slug (minúsculas e hífens; será a pasta e o Text Domain), nome, prefixo curto para globais (ex.: `tnx_`), namespace PHP, PHP e WordPress mínimos (o Tainacan 1.4 pede PHP 7.4 e WP 6.5), versão mínima do Tainacan, licença (GPL-2.0-or-later ou GPL-3.0, compatível com o Tainacan), e o que o plugin faz em uma frase. Sem isso, pergunte.

## Estrutura

```
<slug>/
  <slug>.php            cabeçalho + constantes + carregamento
  uninstall.php         remove opções, tabelas e eventos (se o projeto quiser)
  readme.txt            padrão WordPress.org
  CHANGELOG.md
  AGENTS.md             seção do projeto (o instalador da stack acrescenta o bloco da stack)
  .tnc-check.json
  includes/             classes (namespace) ou src/ com autoload
  assets/css  assets/js
  languages/
  tests/
```

## Arquivo principal

```php
<?php
/**
 * Plugin Name:       <Nome>
 * Description:       <Uma frase>
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  tainacan
 * Author:            <Autor>
 * License:           GPL-3.0-or-later
 * Text Domain:       <slug>
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'TNX_VERSION', '0.1.0' );
define( 'TNX_FILE', __FILE__ );
define( 'TNX_DIR', plugin_dir_path( __FILE__ ) );

require_once TNX_DIR . 'includes/class-plugin.php';

// Tipos de metadado/filtro: registrar no nível de cima (ver tnc-estender-tainacan).
add_action( 'plugins_loaded', [ '\Tnx\Plugin', 'boot' ] );
register_activation_hook( __FILE__, [ '\Tnx\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\Tnx\Plugin', 'deactivate' ] );
```

## Regras do esqueleto

- `boot()` confere o Tainacan (`class_exists( '\Tainacan\Repositories\Items' )`) e mostra aviso de admin se faltar, sem fatal.
- Todo arquivo PHP com `defined( 'ABSPATH' ) || exit;` (TNC-B06).
- Assets só nas telas que usam, com handle prefixado e versão `TNX_VERSION`.
- Strings com `__( '...', '<slug>' )`; `load_plugin_textdomain` só se houver `languages/` próprio.
- Desativação limpa cron (`wp_clear_scheduled_hook`). Ativação cria tabelas com `dbDelta` e guarda a versão do esquema em opção.
- Opções em um array por grupo; se houver telas com abas no mesmo grupo, cada aba salva só os próprios campos (o `options.php` regrava o grupo inteiro).
- `.tnc-check.json` inicial:
  ```json
  { "text_domain": "<slug>", "exclude": ["vendor/", "node_modules/", "build/"] }
  ```

## Depois de criar

1. `git init`, primeiro commit.
2. Instalar a stack: `php <stack>/tainacan-stack/bin/tnc-stack instalar . --ferramentas=claude,codex`.
3. Preencher a seção do projeto no `AGENTS.md`: o que é, estado, ambiente de testes, comandos, pegadinhas, "não regredir".
4. `tnc-validacao` e `tnc-check --release` sem bloqueio.
