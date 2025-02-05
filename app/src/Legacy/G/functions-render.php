<?php

/*
 * This file is part of Chevereto.
 *
 * (c) Rodolfo Berrios <rodolfo@chevereto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Chevereto\Legacy\G;

use LogicException;
use function Chevereto\Legacy\headersNoCache;

/**
 * INCLUDE TAGS
 * ---------------------------------------------------------------------
 */

/**
 * @deprecate
 */
function include_theme_file($filename, $args = [])
{
    $file = PATH_PUBLIC_LEGACY_THEME . $filename;
    $override = PATH_PUBLIC_LEGACY_THEME . 'overrides/' . $filename;
    if (! file_exists($file)) {
        $file .= '.php';
        $override .= '.php';
    }
    if (file_exists($override)) {
        $file = $override;
    }
    if (file_exists($file)) {
        $GLOBALS['theme_include_args'] = $args;
        require $file;
        unset($GLOBALS['theme_include_args']);
    }
}

function get_theme_php_file(string $filename): string
{
    $filename = str_replace_last('.php', '', $filename) . '.php';
    $file = PATH_PUBLIC_LEGACY_THEME . $filename;
    $override = PATH_PUBLIC_LEGACY_THEME . 'overrides/' . $filename;
    if (file_exists($override)) {
        $file = $override;
    }
    if (! file_exists($file)) {
        return '';
    }

    return $file;
}

function require_theme_file_return(string $filename): mixed
{
    $file = get_theme_php_file($filename);
    if ($file === '') {
        throw new LogicException('Theme file not found: ' . $filename);
    }

    return require $file;
}

function require_theme_file(string $filename, array $args = []): void
{
    $file = get_theme_php_file($filename);
    if ($file === '') {
        throw new LogicException('Theme file not found: ' . $filename);
    }
    $GLOBALS['theme_include_args'] = $args;
    require $file;
    unset($GLOBALS['theme_include_args']);
}

function require_theme_header()
{
    require_theme_file('header');
}

/** @deprecate */
function include_theme_header()
{
    require_theme_header();
}

function require_theme_footer()
{
    require_theme_file('footer');
}

/** @deprecate */
function include_theme_footer()
{
    require_theme_footer();
}

function get_theme_file_contents($filename)
{
    $file = PATH_PUBLIC_LEGACY_THEME . $filename;

    return file_exists($file)
        ? file_get_contents($file)
        : null;
}

/**
 * THEME DATA FUNCTIONS
 * ---------------------------------------------------------------------
 */

function get_theme_file_url($string)
{
    return URL_APP_THEME . $string;
}

/**
 * ASSETS
 * ---------------------------------------------------------------------
 */

// Returns the HTML input with the auth token
function get_input_auth_token($name = 'auth_token')
{
    return '<input type="hidden" name="' . $name . '" value="' . Handler::getAuthToken() . '">';
}

/**
 * NON HTML OUTPUT
 * ---------------------------------------------------------------------
 */

// Outputs the REST_API array to xml
function xml_document_output($data = [])
{
    error_reporting(0);
    if (ob_get_level() === 0 && ! ob_start('ob_gzhandler')) {
        ob_start();
    }
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . 'GMT');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Content-Type:text/xml; charset=UTF-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    if ($data['status_code'] ?? false) {
        $out .= "<status_code>{$data['status_code']}</status_code>\n";
        if (! $data['status_txt']) {
            $data['status_txt'] = get_set_status_header_desc($data['status_code']);
        }
        $out .= "<status_txt>{$data['status_txt']}</status_txt>\n";
    }
    unset($data['status_code'], $data['status_txt']);
    if ($data !== []) {
        foreach ($data as $key => $array) {
            $out .= "<{$key}>\n";
            foreach ($array as $prop => $value) {
                $out .= "	<{$prop}>{$value}</{$prop}>\n";
            }
            $out .= "</{$key}>\n";
        }
    }
    echo $out;
    exit();
}

function json_document_output($data = [], $callback = null)
{
    error_reporting(0);
    if (ob_get_level() === 0 && ! ob_start('ob_gzhandler')) {
        ob_start();
    }
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . 'GMT');
    headersNoCache();
    header('Content-type: application/json; charset=UTF-8');
    if (! check_value($data) || (check_value($callback) && preg_match('/\W/', (string) $callback))) {
        set_status_header(400);
        $json_fail = [
            'status_code' => 400,
            'status_txt' => get_set_status_header_desc(400),
            'error' => [
                'message' => 'no request data present',
                'code' => null,
            ],
        ];
        echo json_encode($json_fail);
        exit(255);
    }
    if (isset($data['status_code']) && ! isset($data['status_txt'])) {
        $data['status_txt'] = get_set_status_header_desc($data['status_code']);
    }
    $flags = 0;
    if (PHP_SAPI === 'cli') {
        $flags = JSON_PRETTY_PRINT;
    }
    $json_encode = json_encode($data, $flags);
    if (! $json_encode) { // Json failed
        set_status_header(500);
        headersNoCache();
        $json_fail = [
            'status_code' => 500,
            'status_txt' => get_set_status_header_desc(500),
            'error' => [
                'message' => "data couldn't be encoded into json",
                'code' => null,
            ],
        ];
        echo json_encode($json_fail);
        exit(255);
    }
    set_status_header($data['status_code'] ?? 200);
    if ($callback !== null) {
        echo sprintf('%s(%s);', $callback, $json_encode);
    } else {
        echo $json_encode;
    }
    if (PHP_SAPI === 'cli') {
        echo "\n";
    }
    exit(255);
}
