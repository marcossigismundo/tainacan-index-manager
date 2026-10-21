<?php

namespace TainacanStack\Check\Rules;

use TainacanStack\Check\Finding;
use TainacanStack\Check\Rule;
use TainacanStack\Check\SourceFile;

/** TNC-B01: o arquivo PHP precisa passar no `php -l` do mesmo binário que roda o verificador. */
final class PhpLint extends Rule
{
    const ID = 'TNC-B01';
    const TITLE = 'Erro de sintaxe PHP (php -l)';
    const SEVERITY = Finding::BLOCK;
    const CATEGORY = 'base';

    /** @var bool desligado com --no-lint */
    public static $enabled = true;

    protected function check(SourceFile $f)
    {
        if (!self::$enabled || !$f->isPhp()) {
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tnc');
        file_put_contents($tmp, $f->text);
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -l ' . escapeshellarg($tmp) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        @unlink($tmp);
        if ($code === 0) {
            return;
        }
        $msg = trim(implode(' ', $out));
        $line = 1;
        if (preg_match('/on line (\d+)/', $msg, $m)) {
            $line = (int) $m[1];
        }
        $msg = preg_replace('/ in \S+ on line \d+/', '', $msg);
        $msg = preg_replace('/\s*Errors parsing .*$/', '', $msg);
        $this->report($f, $line, 'php -l falhou: ' . $msg, 'Corrija a sintaxe antes de qualquer outra verificação.');
    }
}
