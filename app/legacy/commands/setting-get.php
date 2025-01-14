<?php

/*
 * This file is part of Chevereto.
 *
 * (c) Rodolfo Berrios <rodolfo@chevereto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Chevereto\Legacy\Classes\Settings;
use function Chevere\Parameter\getType;

$opts = getopt('C:k:t::') ?: [];
if (! isset($opts['k'])) {
    echo "[ERROR] Missing setting key\n";
    exit(255);
}
if (! Settings::hasKey($opts['k'])) {
    echo "[ERROR] Setting key doesn't exists\n";
    exit(255);
}
function toItalic(string $text): string
{
    return "\e[3m{$text}\e[0m";
}
$value = Settings::get($opts['k']);
$echoValue = match (getType($value)) {
    'null' => toItalic('null'),
    'boolean' => toItalic($value ? 'true' : 'false'),
    default => $value,
};
echo "{$echoValue}\n";
if (isset($opts['t'])) {
    $typeset = Settings::getTypeset($opts['k']);
    echo "%({$typeset})\n";
}
exit(0);
