<?php

namespace TainacanStack\Check;

/**
 * Dados do projeto (plugin, tema ou o próprio core) a que um arquivo
 * pertence: raiz, configuração .tnc-check.json, cabeçalho e domínio de texto.
 */
final class ProjectContext
{
    public $root;
    public $slug;
    public $config = [];
    public $mainFile;
    public $header = [];
    public $textDomain;
    public $isTainacanCore = false;

    const DEFAULT_EXCLUDES = [
        '.git/', 'vendor/', 'node_modules/', 'bower_components/', 'build/', 'dist/',
        '.agents/tainacan-stack/', '.claude/', '.codex/', '.github/skills/',
        'tests/fixtures/', 'languages/', 'assets/vendor/', 'lib/vendor/',
    ];

    /** @var array<string,ProjectContext> */
    private static $cache = [];

    public static function forRoot($root)
    {
        $key = rtrim(str_replace('\\', '/', $root), '/');
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = new self($key);
        }
        return self::$cache[$key];
    }

    public function __construct($root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->slug = basename($this->root);
        $cfg = $this->root . '/.tnc-check.json';
        if (is_file($cfg)) {
            $data = json_decode((string) file_get_contents($cfg), true);
            if (is_array($data)) {
                $this->config = $data;
            }
        }
        $this->detectHeader();
        if (!empty($this->config['text_domain'])) {
            $this->textDomain = $this->config['text_domain'];
        }
    }

    private function detectHeader()
    {
        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            $head = (string) @file_get_contents($file, false, null, 0, 8192);
            if (!preg_match('/^[ \t\/*#@]*Plugin Name\s*:/mi', $head)) {
                continue;
            }
            $this->mainFile = str_replace('\\', '/', $file);
            foreach (['Plugin Name', 'Version', 'Text Domain', 'Requires PHP', 'Requires at least', 'Requires Plugins', 'Domain Path'] as $key) {
                if (preg_match('/^[ \t\/*#@]*' . preg_quote($key, '/') . '\s*:\s*(.+)$/mi', $head, $m)) {
                    $this->header[$key] = trim($m[1]);
                }
            }
            break;
        }
        if (isset($this->header['Text Domain'])) {
            $this->textDomain = $this->header['Text Domain'];
        }
        $this->isTainacanCore = $this->textDomain === 'tainacan'
            || ($this->mainFile !== null && basename($this->mainFile) === 'tainacan.php');
    }

    public function isPlugin()
    {
        return $this->mainFile !== null;
    }

    /** @return string[] */
    public function excludes()
    {
        $extra = isset($this->config['exclude']) && is_array($this->config['exclude']) ? $this->config['exclude'] : [];
        return array_merge(self::DEFAULT_EXCLUDES, $extra);
    }

    public function isExcluded($relPath)
    {
        $rel = ltrim(str_replace('\\', '/', $relPath), '/');
        if (preg_match('/\.min\.(js|css)$/', $rel)) {
            return true;
        }
        foreach ($this->excludes() as $ex) {
            $ex = ltrim($ex, '/');
            if ($ex === '') {
                continue;
            }
            if (substr($ex, -1) === '/') {
                if (strpos($rel, $ex) === 0 || strpos($rel, '/' . $ex) !== false) {
                    return true;
                }
            } elseif (fnmatch($ex, $rel)) {
                return true;
            }
        }
        return false;
    }

    /** Severidade configurada para a regra: 'off', 'warn', 'block' ou null. */
    public function ruleSetting($id)
    {
        return isset($this->config['rules'][$id]) ? $this->config['rules'][$id] : null;
    }

    /**
     * Sobe a partir de um caminho até a raiz do projeto: o diretório com
     * .tnc-check.json ou .git, ou o mais alto com cabeçalho de plugin.
     */
    public static function findRoot($path)
    {
        $dir = is_dir($path) ? $path : dirname($path);
        $real = realpath($dir);
        $cur = str_replace('\\', '/', $real ?: $dir);
        while (true) {
            if (is_file($cur . '/.tnc-check.json') || file_exists($cur . '/.git')) {
                return $cur;
            }
            foreach (glob($cur . '/*.php') ?: [] as $file) {
                $head = (string) @file_get_contents($file, false, null, 0, 4096);
                if (preg_match('/^[ \t\/*#@]*Plugin Name\s*:/mi', $head)) {
                    return $cur;
                }
            }
            $parent = dirname($cur);
            if ($parent === $cur || $parent === '.' || $parent === '') {
                break;
            }
            $cur = $parent;
        }
        return str_replace('\\', '/', $real ?: $dir);
    }
}
