<?php

namespace TainacanStack\Check\Rules;

use TainacanStack\Check\Finding;
use TainacanStack\Check\Rule;
use TainacanStack\Check\SourceFile;

/** TNC-B04: sobras de depuração (var_dump, print_r sem retorno, console.log, debugger). */
final class DebugLeftovers extends Rule
{
    const ID = 'TNC-B04';
    const TITLE = 'Sobra de depuração';
    const SEVERITY = Finding::WARN;
    const CATEGORY = 'base';
    const EXTENSIONS = ['php', 'inc', 'js', 'jsx', 'ts', 'tsx', 'vue'];

    protected function check(SourceFile $f)
    {
        if ($f->isPhp()) {
            $this->checkPhp($f);
            return;
        }
        $this->checkJs($f);
    }

    private function checkPhp(SourceFile $f)
    {
        foreach ($f->calls(['var_dump', 'debug_print_backtrace', 'debug_zval_dump', 'dd', 'dump']) as $c) {
            $this->report($f, $c['line'], '`' . $c['name'] . '()` deixado no código.', 'Remova ou troque por error_log() condicionado a WP_DEBUG.');
        }
        foreach ($f->calls(['print_r', 'var_export']) as $c) {
            if (count($c['args']) >= 2) {
                continue; // print_r($x, true) devolve string
            }
            // sem o segundo argumento, imprime na resposta mesmo dentro de error_log()
            $inLog = in_array('error_log', $f->enclosingCalls($c['i']), true);
            $this->report($f, $c['line'], '`' . $c['name'] . '()` sem o segundo argumento imprime na resposta' . ($inLog ? ' (dentro de error_log() ele imprime e o log recebe true/null)' : '') . '.', 'Use ' . $c['name'] . '( $x, true ) dentro de error_log(), ou remova.');
        }
    }

    private function checkJs(SourceFile $f)
    {
        $inBlock = false;
        foreach ($f->lines as $n => $line) {
            $code = $this->stripJsComments($line, $inBlock);
            if (preg_match('/(^|[^\w.])debugger\s*;?/', $code)) {
                $this->report($f, $n, '`debugger` deixado no código.', null, null, Finding::BLOCK);
            }
            if (preg_match('/\bconsole\.(log|debug|dir|table)\s*\(/', $code, $m)) {
                $this->report($f, $n, '`console.' . $m[1] . '()` deixado no código.', 'Remova, ou mantenha só console.error/warn para falhas reais.');
            }
        }
    }

    private function stripJsComments($line, &$inBlock)
    {
        $out = '';
        $i = 0;
        $len = strlen($line);
        while ($i < $len) {
            if ($inBlock) {
                $end = strpos($line, '*/', $i);
                if ($end === false) {
                    return $out;
                }
                $inBlock = false;
                $i = $end + 2;
                continue;
            }
            if (substr($line, $i, 2) === '/*') {
                $inBlock = true;
                $i += 2;
                continue;
            }
            if (substr($line, $i, 2) === '//') {
                return $out;
            }
            $out .= $line[$i];
            $i++;
        }
        return $out;
    }
}
