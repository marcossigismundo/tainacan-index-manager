<?php

namespace TainacanStack\Check\Rules;

use TainacanStack\Check\Finding;
use TainacanStack\Check\Rule;
use TainacanStack\Check\SourceFile;

/**
 * TNC-S02: leitura de $_GET/$_POST/$_REQUEST/$_COOKIE sem sanitização na
 * mesma expressão. Comparação direta, isset/empty e verificação de nonce
 * não contam como uso. Versão leve da regra do WPCS
 * (WordPress.Security.ValidatedSanitizedInput), para quem não roda o PHPCS.
 */
final class SuperglobalUnsanitized extends Rule
{
    const ID = 'TNC-S02';
    const TITLE = 'Entrada HTTP usada sem sanitização';
    const SEVERITY = Finding::WARN;
    const CATEGORY = 'seguranca';

    const GLOBALS = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE'];

    const SANITIZERS = [
        'sanitize_text_field', 'sanitize_textarea_field', 'sanitize_key', 'sanitize_email', 'sanitize_title',
        'sanitize_title_with_dashes', 'sanitize_file_name', 'sanitize_user', 'sanitize_url', 'sanitize_hex_color',
        'sanitize_html_class', 'sanitize_mime_type', 'sanitize_option', 'sanitize_meta', 'sanitize_term_field',
        'esc_url_raw', 'esc_url', 'esc_html', 'esc_attr', 'esc_textarea', 'esc_sql', 'esc_js',
        'absint', 'intval', 'floatval', 'boolval', 'rest_sanitize_boolean', 'wp_validate_boolean',
        'wp_kses', 'wp_kses_post', 'wp_kses_data', 'filter_var', 'filter_input', 'map_deep', 'array_map',
        'isset', 'empty', 'array_key_exists', 'in_array', 'is_numeric', 'ctype_digit', 'preg_match',
        'wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer', 'wp_safe_redirect', 'is_array',
        'count', 'json_encode', 'wp_json_encode', 'hash_equals', 'sanitize_textarea', 'tainacan_sanitize',
    ];

    protected function check(SourceFile $f)
    {
        $n = $f->count();
        $safe = array_flip(self::SANITIZERS);
        for ($i = 0; $i < $n; $i++) {
            if ($f->type($i) !== T_VARIABLE || !in_array($f->val($i), self::GLOBALS, true)) {
                continue;
            }
            $name = $f->val($i);
            // fim do acesso: $_POST['a']['b']
            $end = $i;
            while ($f->type($end + 1) === '[') {
                $m = $f->match($end + 1);
                if ($m < 0) {
                    break;
                }
                $end = $m;
            }
            $after = $f->type($end + 1);
            $before = $f->type($i - 1);
            if ($after === '=' || $after === T_COALESCE_EQUAL) {
                continue; // escrita
            }
            if ($this->isComparison($after) || $this->isComparison($before)) {
                continue;
            }
            if (in_array($before, [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true)) {
                continue;
            }
            $chain = $f->enclosingCalls($i);
            $ok = false;
            foreach ($chain as $fn) {
                if (isset($safe[$fn]) || strpos($fn, 'sanitize') !== false || strpos($fn, 'validate') !== false) {
                    $ok = true;
                    break;
                }
            }
            if ($ok) {
                continue;
            }
            // $x = isset( $_GET['a'] ) ? $_GET['a'] : '' ainda é uso; ternário com sanitização na mesma linha é aceito
            $stmt = $f->text($f->statementStart($i), $end);
            if (preg_match('/sanitize_|absint|intval|esc_|wp_kses/i', substr($stmt, strrpos($stmt, '=') ?: 0)) && $f->type($end + 1) === ':' ) {
                continue;
            }
            $key = $end > $i ? $f->text($i, $end) : $name;
            $msg = '`' . $key . '` usado sem sanitização.';
            if ($name === '$_REQUEST') {
                $msg .= ' Prefira $_GET ou $_POST: $_REQUEST mistura cookies.';
            }
            $this->report($f, $f->line($i), $msg, 'Envolva com sanitize_text_field( wp_unslash( ... ) ), absint(), sanitize_key() ou a função adequada ao tipo.');
            $i = $end;
        }
    }

    private function isComparison($t)
    {
        return in_array($t, [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, '<', '>', T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP], true);
    }
}
