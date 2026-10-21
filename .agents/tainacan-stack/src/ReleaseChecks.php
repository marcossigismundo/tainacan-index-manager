<?php

namespace TainacanStack\Check;

/**
 * Conferências de release (--release): versão igual em todos os lugares,
 * readme.txt completo, changelog com a versão atual, domínio de texto igual
 * ao slug, dependência do Tainacan declarada e, se o plugin agenda cron,
 * alguma chamada de limpeza (wp_clear_scheduled_hook/unschedule) no código.
 */
final class ReleaseChecks
{
    /** @return Finding[] */
    public static function run($dir)
    {
        $root = ProjectContext::findRoot($dir);
        $p = ProjectContext::forRoot($root);
        $out = [];
        if (!$p->isPlugin()) {
            $out[] = new Finding('TNC-R00', Finding::BLOCK, '.', 0, 'Nenhum arquivo com cabeçalho "Plugin Name:" na raiz de ' . $root . '.');
            return $out;
        }
        $main = Runner::relative($root, $p->mainFile);
        $mainText = (string) file_get_contents($p->mainFile);
        $versions = [];
        if (isset($p->header['Version'])) {
            $versions['cabeçalho ' . $main] = $p->header['Version'];
        }
        if (preg_match_all('/define\s*\(\s*[\'"]([A-Z0-9_]*VERSION[A-Z0-9_]*)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $mainText, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (preg_match('/^\d/', $hit[2])) {
                    $versions['constante ' . $hit[1]] = $hit[2];
                }
            }
        }
        if (preg_match_all('/const\s+(VERSION|[A-Z_]*VERSION)\s*=\s*[\'"]([^\'"]+)[\'"]/', $mainText, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $versions['const ' . $hit[1]] = $hit[2];
            }
        }
        $readme = is_file($root . '/readme.txt') ? (string) file_get_contents($root . '/readme.txt') : null;
        if ($readme !== null && preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $m) && strtolower($m[1]) !== 'trunk') {
            $versions['readme.txt Stable tag'] = $m[1];
        }
        foreach (['package.json', 'composer.json'] as $json) {
            if (is_file($root . '/' . $json)) {
                $data = json_decode((string) file_get_contents($root . '/' . $json), true);
                if (isset($data['version'])) {
                    $versions[$json] = $data['version'];
                }
            }
        }
        $distinct = array_unique(array_values($versions));
        if (count($distinct) > 1) {
            $list = [];
            foreach ($versions as $where => $v) {
                $list[] = $where . ' = ' . $v;
            }
            $out[] = new Finding('TNC-R01', Finding::BLOCK, $main, 1, 'Versões divergentes: ' . implode('; ', $list) . '.', 'Atualize todas no mesmo commit da release.');
        }
        $version = isset($p->header['Version']) ? $p->header['Version'] : null;
        if ($readme === null) {
            $out[] = new Finding('TNC-R02', Finding::WARN, 'readme.txt', 0, 'Plugin sem readme.txt.', 'Necessário para o WordPress.org e útil para quem instala pelo ZIP.');
        } else {
            foreach (['Requires at least', 'Tested up to', 'Requires PHP', 'License'] as $field) {
                if (!preg_match('/^' . preg_quote($field, '/') . ':/mi', $readme)) {
                    $out[] = new Finding('TNC-R02', Finding::WARN, 'readme.txt', 1, 'readme.txt sem o campo "' . $field . '".');
                }
            }
        }
        if ($version) {
            $changelog = '';
            foreach (['CHANGELOG.md', 'changelog.md', 'CHANGELOG.txt', 'changelog.txt'] as $c) {
                if (is_file($root . '/' . $c)) {
                    $changelog .= (string) file_get_contents($root . '/' . $c);
                }
            }
            if ($readme !== null && preg_match('/== Changelog ==(.*?)(\n== |\z)/s', $readme, $m)) {
                $changelog .= $m[1];
            }
            if ($changelog === '') {
                $out[] = new Finding('TNC-R03', Finding::WARN, $main, 0, 'Nenhum changelog (CHANGELOG.md ou seção == Changelog == do readme.txt).');
            } elseif (strpos($changelog, $version) === false) {
                $out[] = new Finding('TNC-R03', Finding::WARN, $main, 0, 'O changelog não menciona a versão ' . $version . '.', 'Descreva o que mudou antes de publicar.');
            }
        }
        if ($p->textDomain && !$p->isTainacanCore && $p->textDomain !== $p->slug) {
            $out[] = new Finding('TNC-R04', Finding::WARN, $main, 1, 'Text Domain "' . $p->textDomain . '" diferente do slug da pasta "' . $p->slug . '".', 'O WordPress.org exige que sejam iguais; as traduções do translate.wordpress.org usam o slug.');
        }
        if (!$p->isTainacanCore && self::usesTainacan($root)) {
            $req = isset($p->header['Requires Plugins']) ? $p->header['Requires Plugins'] : '';
            if (stripos($req, 'tainacan') === false) {
                $out[] = new Finding('TNC-R05', Finding::WARN, $main, 1, 'O plugin usa classes do Tainacan, mas o cabeçalho não declara "Requires Plugins: tainacan".', 'Desde o WordPress 6.5 o cabeçalho impede ativar sem o Tainacan e evita erro fatal.');
            }
        }
        $all = self::allPhp($root);
        if (preg_match('/wp_schedule_(single_)?event\s*\(/', $all) && !preg_match('/wp_clear_scheduled_hook|wp_unschedule_event|wp_unschedule_hook/', $all)) {
            $out[] = new Finding('TNC-R06', Finding::WARN, $main, 0, 'O plugin agenda eventos de cron, mas nunca os remove.', 'Chame wp_clear_scheduled_hook() no register_deactivation_hook.');
        }
        return $out;
    }

    private static function usesTainacan($root)
    {
        return (bool) preg_match('/\\\\?Tainacan\\\\(Repositories|Entities|Metadata_Types|Filter_Types|Importer|Exporter)|tainacan_register_admin_hook|tainacan_get_item/', self::allPhp($root));
    }

    private static $cache = [];

    private static function allPhp($root)
    {
        if (isset(self::$cache[$root])) {
            return self::$cache[$root];
        }
        $text = '';
        foreach (Runner::collect($root) as $e) {
            if (substr($e['rel'], -4) === '.php') {
                $text .= (string) @file_get_contents($e['abs']) . "\n";
            }
        }
        return self::$cache[$root] = $text;
    }
}
