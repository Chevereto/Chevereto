<?php

/*
 * This file is part of Chevereto.
 *
 * (c) Rodolfo Berrios <rodolfo@chevereto.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Chevereto\Legacy;

use Chevere\Filesystem\FilePhpReturn;
use Chevere\Parameter\Interfaces\CastInterface;
use Chevere\Regex\Regex;
use Chevere\Writer\StreamWriter;
use Chevere\Writer\WritersInstance;
use Chevere\xrDebug\PHP\Xr;
use Chevere\xrDebug\PHP\XrInstance;
use Chevereto\Config\Config;
use Chevereto\Legacy\Classes\AssetStorage;
use Chevereto\Legacy\Classes\DB;
use Chevereto\Legacy\Classes\L10n;
use Chevereto\Legacy\Classes\Login;
use Chevereto\Legacy\Classes\Mailer;
use Chevereto\Legacy\Classes\Settings;
use Chevereto\Legacy\Classes\StorageApis;
use Chevereto\Legacy\Classes\Upload;
use Chevereto\Legacy\Classes\Variable;
use Chevereto\Legacy\G\Handler;
use Chevereto\Vars\CookieVar;
use Chevereto\Vars\EnvVar;
use Chevereto\Vars\FilesVar;
use Chevereto\Vars\GetVar;
use Chevereto\Vars\PostVar;
use Chevereto\Vars\RequestVar;
use Chevereto\Vars\ServerVar;
use Chevereto\Vars\SessionVar;
use ErrorException;
use Exception;
use Intervention\Image\ImageManagerStatic;
use LogicException;
use OutOfBoundsException;
use OverflowException;
use PDO;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;
use Throwable;
use function Chevere\Filesystem\filePhpForPath;
use function Chevere\Message\message;
use function Chevere\Parameter\cast;
use function Chevere\Parameter\getType;
use function Chevere\Writer\streamFor;
use function Chevere\Writer\writers;
use function Chevereto\Legacy\G\absolute_to_url;
use function Chevereto\Legacy\G\bytes_to_mb;
use function Chevereto\Legacy\G\datetimegmt;
use function Chevereto\Legacy\G\extension_to_mime;
use function Chevereto\Legacy\G\fetch_url;
use function Chevereto\Legacy\G\get_app_version;
use function Chevereto\Legacy\G\get_base_url;
use function Chevereto\Legacy\G\get_bytes;
use function Chevereto\Legacy\G\get_client_ip;
use function Chevereto\Legacy\G\get_current_url;
use function Chevereto\Legacy\G\get_file_extension;
use function Chevereto\Legacy\G\get_image_fileinfo as GGet_image_fileinfo;
use function Chevereto\Legacy\G\get_ini_bytes;
use function Chevereto\Legacy\G\get_public_url;
use function Chevereto\Legacy\G\hasEnvDbInfo;
use function Chevereto\Legacy\G\is_url;
use function Chevereto\Legacy\G\is_url_web;
use function Chevereto\Legacy\G\is_valid_timezone;
use function Chevereto\Legacy\G\linkify_safe;
use function Chevereto\Legacy\G\random_string;
use function Chevereto\Legacy\G\redirect;
use function Chevereto\Legacy\G\safe_html;
use function Chevereto\Legacy\G\sanitize_path_slashes;
use function Chevereto\Legacy\G\starts_with;
use function Chevereto\Legacy\G\unlinkIfExists;
use function Chevereto\Vars\cookie;
use function Chevereto\Vars\env;
use function Chevereto\Vars\post;
use function Chevereto\Vars\server;
use function Chevereto\Vars\session;
use function Chevereto\Vars\sessionVar;
use function Safe\openssl_cipher_iv_length;

function getIdFromURLComponent(string $component): int
{
    $explode = explode('.', $component);
    $encodedId = array_pop($explode);

    return decodeID($encodedId);
}

function time_elapsed_string(string $datetime, bool $full = false): string
{
    $now = new \DateTime(datetimegmt());
    $ago = new \DateTime($datetime);
    $diff = $now->diff($ago);
    $diffWeek = floor($diff->d / 7);
    $diff->d -= intval($diffWeek * 7);
    $string = [
        'y' => _n('year', 'years', 1),
        'm' => _n('month', 'months', 1),
        'w' => _n('week', 'weeks', 1),
        'd' => _n('day', 'days', 1),
        'h' => _n('hour', 'hours', 1),
        'i' => _n('minute', 'minutes', 1),
        's' => _n('second', 'seconds', 1),
    ];
    foreach ($string as $k => &$v) {
        $elapsed = $k === 'w'
            ? $diffWeek
            : $diff->{$k};
        if ($elapsed > 0) {
            $times = [
                'y' => _n('year', 'years', $elapsed),
                'm' => _n('month', 'months', $elapsed),
                'w' => _n('week', 'weeks', $elapsed),
                'd' => _n('day', 'days', $elapsed),
                'h' => _n('hour', 'hours', $elapsed),
                'i' => _n('minute', 'minutes', $elapsed),
                's' => _n('second', 'seconds', $elapsed),
            ];

            $v = $elapsed . ' ' . $times[$k];
        } else {
            unset($string[$k]);
        }
    }
    if (! $full) {
        $string = array_slice($string, 0, 1);
    }

    return count($string) > 0
        ? _s('%s ago', implode(', ', $string))
        : _s('moments ago');
}

function missing_values_to_exception(object $object, string $exception, array $values_array, int $code = 100): void
{
    for ($i = 0; $i < count((array) $values_array); ++$i) {
        if (! property_exists($object, $values_array[$i])) {
            throw new $exception('Missing $' . $values_array[$i], ($code + $i));
        }
    }
}

function system_notification_email(array $args = []): void
{
    $subject = 'System notification: ' . $args['subject'] . ' [' . get_public_url() . ']';
    $report = $args['message'];
    send_mail(getSetting('email_incoming_email'), $subject, $report);
}

function send_mail($to, $subject, $body): bool
{
    $email_from_email = getSetting('email_from_email') ?? 'from@chevereto.internal';
    $email_from_name = getSetting('email_from_name') ?? 'Chevereto';
    $args = ['to', 'subject', 'body'];
    foreach (func_get_args() as $k => $v) {
        if (! $v) {
            throw new Exception('Missing $' . $args[$k] . '', 600);
        }
    }
    if (is_array($to)) {
        $aux = $to;
        $to = $aux['to'];
        $from = $aux['from'];
        $reply_to = $aux['reply-to'];
    } else {
        /** @var array<string> */
        $from = [$email_from_email, $email_from_name];
        $reply_to = null;
        if (str_ends_with($from[0], '@chevereto.com')) {
            throw new Exception('Forbidden to send email from ' . $from[0], 600);
        }
    }
    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid to email', 100);
    }
    $writer = new StreamWriter(streamFor('php://temp', 'r+'));
    $body = trim($body);
    $mail = new Mailer();
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function ($str, $level) use ($writer) {
        $writer->write("{$str} \n");
    };
    $alt_body = $mail->html2text($body);
    $mail->CharSet = 'UTF-8';
    if (getSetting('email_mode') === 'smtp') {
        $mail->isSMTP();
        $mail->Username = getSetting('email_smtp_server_username') ?? '';
        $mail->Password = getSetting('email_smtp_server_password') ?? '';
        $mail->SMTPAuth = $mail->Username !== '' || $mail->Password !== '';
        $mail->SMTPSecure = in_array(getSetting('email_smtp_server_security'), ['ssl', 'tls'], true)
            ? getSetting('email_smtp_server_security')
            : '';
        $mail->SMTPAutoTLS = in_array(getSetting('email_smtp_server_security'), ['ssl', 'tls'], true);
        $mail->Port = getSetting('email_smtp_server_port');
        $mail->Host = getSetting('email_smtp_server');
    }
    $mail->Timeout = 30;
    $mail->Subject = $subject;
    if ($body !== $alt_body) {
        $mail->IsHTML(true);
        $mail->Body = $mail->normalizeBreaks($body);
        $mail->AltBody = $mail->normalizeBreaks($alt_body);
    } else {
        $mail->Body = $body;
    }
    $mail->addAddress($to);
    if ($reply_to && is_array($reply_to)) {
        foreach ($reply_to as $v) {
            $mail->addReplyTo($v);
        }
    }
    $mail->setFrom($from[0], $from[1]);
    if ($mail->Send()) {
        return true;
    }
    $mailerWrap = "\n----------- MAILER DEBUG -----------\n\n";
    $error = str_replace('-', '>', $mailerWrap)
        . $writer->__toString()
        . str_replace('-', '<', $mailerWrap);
    writers()->error()
        ->write($error);
    xr(mailer: $error);

    throw new Exception($mail->ErrorInfo, 606);
}

function get_chevereto_version(bool $full = true): string
{
    return get_app_version($full);
}

function getSettings(bool $safe = false): array
{
    $settings = Settings::get();

    return $safe ? safe_html($settings) : $settings;
}
function get_chv_default_settings(bool $safe = false): array
{
    $defaults = Settings::getDefaults();

    return $safe ? safe_html($defaults) : $defaults;
}

function getSetting(string $key = '', bool $safe = false): mixed
{
    $return = Settings::get($key);

    return $safe ? safe_html($return) : $return;
}

function get_chv_default_setting(string $value = '', bool $safe = false): mixed
{
    $return = get_chv_default_settings()[$value];

    return $safe ? safe_html($return) : $return;
}

function getStorages(): array|bool
{
    $where = [];
    if (version_compare(cheveretoVersionInstalled(), '4.2.0', '>=')) {
        $where = [
            'deleted_at' => null,
        ];
    }
    $storages = DB::get(
        table: 'storages',
        where: $where,
    );
    if ($storages) {
        foreach ($storages as $k => $v) {
            $storages[$k] = DB::formatRow($v);
        }
        $return = $storages;
    } else {
        $return = false;
    }

    return $return;
}

function get_banner_code(string $banner, bool $safe_html = true): string
{
    if (! str_starts_with($banner, 'banner_')) {
        $banner = 'banner_' . $banner;
    }
    $banner_code = Settings::get($banner);
    if ($safe_html) {
        $banner_code = safe_html($banner_code);
    }
    if ($banner_code) {
        return $banner_code;
    }

    return '';
}

function getSystemNotices(): array
{
    $installed = cheveretoVersionInstalled();
    $system_notices = [];
    if (env()['CHEVERETO_CONTEXT'] !== 'saas') {
        $notified = getVariable('update_check_notified_release')->nullString() ?? '';
        if (getSetting('update_check_display_notification')
            && (
                version_compare($notified, $installed, '>')
                && version_compare($notified, APP_VERSION, '>')
            )
        ) {
            $system_notices[] = _s('There is an update available for your system.')
                . ' '
                . _s(
                    'Go to %s to download and install this update.',
                    '<a href="'
                    . get_base_url('dashboard?checkUpdates')
                    . '"><i class="fas fa-tachometer-alt margin-right-5"></i>'
                    . _s('Dashboard')
                    . '</a>'
                );
        }
        if (version_compare(APP_VERSION, $installed, '>')) {
            $system_notices[] = _s('System database is outdated.')
                . ' '
                . _s(
                    'You need to %s.',
                    '<a href="'
                    . get_base_url('update')
                    . '"><i class="fas fa-arrow-alt-circle-up margin-right-5"></i>'
                    . _s('Update')
                    . '</a>'
                );
        }
        $dbServer = 'MySQL';
        $sqlServerVersion = DB::getInstance()->getAttr(PDO::ATTR_SERVER_VERSION);
        $cteRequiresVersion = '8.0.1'; // https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-1.html
        if (stripos($sqlServerVersion, 'MariaDB') !== false) {
            $dbServer = 'MariaDB';
            $cteRequiresVersion = '10.2.2'; // https://mariadb.com/kb/en/mariadb-1022-release-notes/
            $explodeSqlVersion = explode('-', $sqlServerVersion, 2);
            foreach ($explodeSqlVersion as $pos => $ver) {
                if (str_starts_with($ver, 'MariaDB')) {
                    continue;
                }
                $sqlServerVersion = $ver;
            }
        }
        if (version_compare($sqlServerVersion, $cteRequiresVersion, '<')) {
            $system_notices[] = _s('The %s database server version outdated.', $dbServer)
                . ' '
                . _s(
                    'You need to upgrade to %s or higher.',
                    $cteRequiresVersion
                );
        }
    }
    if (getSetting('maintenance')) {
        $system_notices[] = _s('Website is in maintenance mode.')
            . ' '
            . _s(
                'To revert this setting go to %s.',
                '<a href="'
                . get_base_url('dashboard/settings/system')
                . '"><i class="fas fa-server margin-right-035em"></i>'
                . _s('System')
                . '</a>'
            );
    }
    if (preg_match('/@chevereto\.example/', getSetting('email_from_email'))
        || preg_match('/@chevereto\.example/', getSetting('email_incoming_email'))
    ) {
        $system_notices[] = _s(
            "You haven't changed the default email settings. Go to %emailSettings% to fix this.",
            [
                '%emailSettings%' => '<a href="'
                    . get_base_url('dashboard/settings/email')
                    . '"><i class="fas fa-at margin-right-035em"></i>'
                    . _s('%s settings', _s('Email')) . '</a>',
            ]
        );
    }
    $minActiveStorages = (int) env()['CHEVERETO_MIN_STORAGES_ACTIVE'];
    $storagesActive = getVariable('storages_active')->nullInt() ?? 0;
    if ($minActiveStorages !== 0 && $storagesActive < $minActiveStorages) {
        $system_notices[] = _s(
            'You need to activate %s to upload files.',
            [
                // '%c' => $minActiveStorages,
                '%s' => '<a href="'
                        . get_base_url('dashboard/settings/external-storage')
                        . '"><i class="fas fa-hdd margin-right-035em"></i>'
                        . _s('External storage')
                        . '</a>',
            ]
        );
    }
    $defaultAsset = [
        'asset_storage_bucket' => getSetting('asset_storage_bucket') === null,
        'asset_storage_url' => getSetting('asset_storage_url') === null,
        'asset_storage_api_id' => getSetting('asset_storage_api_id') === null,
    ];
    if (! (bool) env()['CHEVERETO_ENABLE_LOCAL_STORAGE']
        && in_array(true, $defaultAsset, true)
    ) {
        $system_notices[] = _s(
            'You need to configure %s to upload website assets.',
            '<a href="'
            . get_base_url('dashboard/settings/asset-storage')
            . '"><i class="fas fa-hdd margin-right-035em"></i>'
            . _s('Asset storage')
            . '</a>'
        );
    }

    return $system_notices;
}

function hashed_token_info(string $public_token_format): array
{
    $explode = explode(':', $public_token_format);

    return [
        'id' => decodeID($explode[0]),
        'id_encoded' => $explode[0],
        'token' => $explode[1],
    ];
}

function generate_hashed_token(int $id, string $token = ''): array
{
    $token = random_string(random_int(128, 256));
    $hash = password_hash($token, PASSWORD_BCRYPT);

    return [
        'token' => $token,
        'hash' => $hash,
        'public_token_format' => encodeID((int) $id) . ':' . $token,
    ];
}

function check_hashed_token(string $hash, string $public_token_format): bool
{
    $public_token = hashed_token_info($public_token_format);

    return password_verify($public_token['token'], $hash);
}

function captcha_check(): object
{
    if (getSetting('captcha_api') == '3') {
        return (object) [
            'is_valid' => sessionVar()->hasKey('isHuman')
                ? (bool) session()['isHuman']
                : false,
        ];
    }
    $endpoint = match (getSetting('captcha_api')) {
        '2' => 'https://www.recaptcha.net/recaptcha/api/siteverify',
        'hcaptcha' => 'https://hcaptcha.com/siteverify',
        default => throw new LogicException(message('Invalid captcha API')),
    };
    $params = [
        'secret' => getSetting('captcha_secret'),
        'response' => post()['g-recaptcha-response']
            ?? post()['h-captcha-response']
            ?? '',
        'remoteip' => get_client_ip(),
    ];
    $fetch = fetch_url(
        url: $endpoint,
        options: [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]
    );
    $object = json_decode($fetch);

    return (object) [
        'is_valid' => (bool) $object->success,
    ];
}

function must_use_captcha(int $val, ?int $max = null): bool
{
    if ($max === null) {
        $max = (int) (getSetting('captcha_threshold') ?? 5);
    }

    return $val >= $max;
}

function is_max_invalid_request(int|string $val, ?int $max = null): bool
{
    $val = (int) $val;
    if ($max === null) {
        $max = Config::limit()->invalidRequestsPerDay();
    }

    return $val > $max;
}

function get_translation_table(): array
{
    return L10n::getTranslation();
}

function get_language_used(): array
{
    return get_available_languages()[L10n::getStatic('locale')];
}

function get_available_languages(): array
{
    return L10n::getAvailableLanguages();
}

function get_enabled_languages(): array
{
    if (! getSetting('language_chooser_enable')) {
        return [];
    }

    return L10n::getEnabledLanguages();
}

function get_disabled_languages(): array
{
    return L10n::getDisabledLanguages();
}

/*
 * cheveretoID
 * Encode/decode an id
 *
 * @author   Kevin van Zonneveld <kevin@vanzonneveld.net>
 * @author   Simon Franz
 * @author   Deadfish
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id: alphaID.inc.php 344 2009-06-10 17:43:59Z kevin $
 * @link   http://kevin.vanzonneveld.net/
 *
 * http://kvz.io/blog/2009/06/10/create-short-ids-with-php-like-youtube-or-tinyurl/
 *
 * @deprecate V4
 */

function cheveretoID(string|int $in, string $action = 'encode'): string|int
{
    global $cheveretoID;
    $index = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $salt = getVariable('crypt_salt')->nullString() ?? '';
    $id_padding = getVariable('id_padding')->nullInt() ?? 0;
    // Use a stock version of the hashed values (faster execution)
    if (isset($cheveretoID)) {
        $passhash = $cheveretoID['passhash'];
        $p = $cheveretoID['p'];
        $i = $cheveretoID['i'];
    } else {
        for ($n = 0; $n < strlen($index); ++$n) {
            $i[] = substr($index, $n, 1);
        }
        $passhash = hash('sha256', $salt);
        $passhash = (strlen($passhash) < strlen($index)) ? hash('sha512', $salt) : $passhash;
        for ($n = 0; $n < strlen($index); ++$n) {
            $p[] = substr($passhash, $n, 1);
        }
        $cheveretoID = [
            'passhash' => $passhash,
            'p' => $p,
            'i' => $i,
        ];
    }
    array_multisort($p, SORT_DESC, $i);
    $index = implode('', $i);
    $base = strlen($index);
    if ($action === 'decode') {
        $out = 0;
        $len = strlen($in) - 1;
        for ($t = 0; $t <= $len; ++$t) {
            $bcpow = bcpow((string) $base, (string) ($len - $t));
            $out = $out + strpos($index, substr($in, $t, 1)) * $bcpow;
        }
        if ($id_padding > 0) {
            $out = $out / $id_padding;
        }
        $out = (int) sprintf('%s', $out);
    } else {
        if ($id_padding > 0) {
            $in = $in * $id_padding;
        }
        $out = '';
        for ($t = floor(log((float) $in, $base)); $t >= 0; --$t) {
            $bcp = bcpow((string) $base, (string) $t);
            $a = floor($in / $bcp) % $base;
            $out = $out . substr($index, $a, 1);
            $in = $in - ($a * $bcp);
        }
    }

    return $out;
}

function encodeID(int $var): string
{
    return cheveretoID($var, 'encode');
}

function decodeID(string $var): int
{
    return cheveretoID($var, 'decode');
}

function linkify_redirector(string $text): string
{
    return linkify_safe(
        $text,
        [
            'callback' => function (string $url, string $caption, array $options) {
                $url = match (true) {
                    filter_var($url, FILTER_VALIDATE_EMAIL) !== false => "mailto:{$url}",
                    default => get_redirect_url($url)
                };
                $attributes = $options['attr'];

                return
                    <<<HTML
                <a href="{$url}"{$attributes}>{$caption}</a>
                HTML;
            },
        ]
    );
}

function get_redirect_url(string $url): string
{
    if (! is_url_web($url)) {
        return '#';
    }

    return get_base_url(
        'redirect/?to='
        . rawurlencode(encryptString($url))
        . '&auth_token='
        . Handler::getAuthToken()
    );
}

function sessionCrypt(string $string, bool $encrypt = true): string|bool
{
    if (! (session()['crypt'] ?? false)) {
        $cipher = 'AES-128-CBC';
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        sessionVar()->put('crypt', [
            'cipher' => $cipher,
            'ivlen' => $ivlen,
            'iv' => $iv,
        ]);
    }
    $session = session()['crypt'] ?? [];
    extract($session);
    /**
     * @var string $cipher
     * @var int $ivlen
     * @var string $iv
     */
    $fn = 'openssl_' . ($encrypt ? 'encrypt' : 'decrypt');
    $secret = md5(
        getVariable('crypt_salt')->nullString() ?? ''
    );

    return $fn($string, $cipher, $secret, 0, $iv);
}

function encryptString(string $string): string|bool
{
    return sessionCrypt($string, true);
}

function decryptString(string $string): string|bool
{
    return sessionCrypt($string, false);
}

function getLocalUrl(): string
{
    $url = Config::host()->hostnamePath();
    if (defined('URL_APP_PUBLIC_STATIC')) {
        $url = URL_APP_PUBLIC === URL_APP_PUBLIC_STATIC
            ? Config::host()->hostnamePath()
            : URL_APP_PUBLIC_STATIC; // @phpstan-ignore-line
    }

    return $url;
}

function get_content_url(string $sub): string
{
    $dirname = dirname($sub);
    $local = getLocalUrl();
    $url = AssetStorage::getStorage()['url'] ?? $local;
    if (basename($dirname) === 'default') {
        $url = $local;
    }

    return absolute_to_url(PATH_PUBLIC_CONTENT . $sub, $url);
}

function get_system_image_url(?string $filename): string
{
    return get_content_url('images/system/' . ($filename ?? ''));
}

function get_users_image_url(string $filename): string
{
    return get_content_url('images/users/' . $filename);
}

function get_fileinfo(string $file): array
{
    $extension = get_file_extension($file);
    $return = [
        'filename' => basename($file), // image.jpg
        'name' => basename($file, '.' . $extension), // image
        'mime' => extension_to_mime($extension),
        'extension' => $extension,
        'url' => is_url($file) ? $file : absolute_to_url($file),
    ];
    if (! is_url($file)) {
        $return['url'] = preg_replace('#' . URL_APP_PUBLIC . '#', URL_APP_PUBLIC_STATIC, $return['url'], 1);
    }

    return $return;
}

function upload_to_content_images(array $source, string $what): void
{
    $remove_old = true;
    $localPath = PATH_PUBLIC_CONTENT_IMAGES_SYSTEM;
    $storagePath = 'content/images/system/';
    $typeArr = [
        'favicon_image' => [
            'name' => 'favicon',
            'type' => 'image',
        ],
        'logo_vector' => [
            'name' => 'logo',
            'type' => 'file',
        ],
        'logo_image' => [
            'name' => 'logo',
            'type' => 'image',
        ],
        'watermark_image' => [
            'name' => 'watermark',
            'type' => 'image',
        ],
        'consent_screen_cover_image' => [
            'name' => 'consent-screen_cover',
            'type' => 'image',
        ],
        'homepage_cover_image' => [
            'name' => 'home_cover',
            'type' => 'image',
        ],
    ];
    if (! isset($typeArr[$what]) && ! starts_with('homepage_cover_image_', $what)) {
        throw new OutOfBoundsException(sprintf('Invalid key %s', $what), 600);
    }
    if (starts_with('homepage_cover_image_', $what)) {
        $cover_handle = str_replace('homepage_cover_image_', '', $what);
        if ($cover_handle === 'add') {
            $remove_old = false;
        } else {
            $db_filename = getSetting('homepage_cover_images')[$cover_handle]['basename'];
        }
        $typeArr[$what] = $typeArr['homepage_cover_image'];
    }
    foreach (['logo_vector', 'logo_image'] as $k) {
        $typeArr[$k . '_homepage'] = array_merge($typeArr[$k], [
            'name' => 'logo_homepage',
        ]);
    }
    foreach ($typeArr as $k => &$v) {
        $v['name'] .= '_'
            . number_format(round(microtime(true) * 1000), 0, '', '')
            . '_'
            . random_string(6);
    }
    $name = $typeArr[$what]['name'];
    if ($typeArr[$what]['type'] === 'image') {
        $fileinfo = GGet_image_fileinfo($source['tmp_name']);
        switch ($what) {
            case 'favicon_image':
                if (! $fileinfo['ratio']) {
                    throw new Exception('Invalid favicon image', 200);
                }
                if ($fileinfo['ratio'] != 1) {
                    throw new Exception('Must use a square image for favicon', 210);
                }

                break;
            case 'watermark_image':
                if ($fileinfo['extension'] !== 'png') {
                    throw new Exception('Invalid watermark image', 200);
                }

                break;
        }
        $upload = new Upload();
        $upload->setSource($source);
        $upload->setDestination($localPath);
        $upload->setFilename($name);
        if (in_array($what, ['homepage_cover_image_add', 'homepage_cover_image', 'consent_screen_cover_image'], true)) {
            $upload->setOption('max_size', Settings::get('true_upload_max_filesize'));
        }
        if ($what === 'watermark_image') {
            $upload->setOption('max_size', get_bytes('64 KB'));
        }
        if ($what !== 'watermark_image') {
            $upload->setStorageId(0);
        }
        $upload->exec();
        $uploaded = $upload->uploaded();
    } else {
        switch ($source['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new Exception('No file sent', 600);
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('Exceeded filesize limit', 601);
            default:
                throw new Exception('Unknown errors', 602);
        }
        $file_contents = file_get_contents($source['tmp_name']);
        if (! $file_contents) {
            throw new Exception("Can't read uploaded file content", 600);
        }
        if (! str_contains($file_contents, '<!DOCTYPE svg PUBLIC')
            && ! str_contains($file_contents, '<svg')) {
            throw new Exception("Uploaded file isn't SVG.", 300);
        }
        $filename = $name . random_string(8) . '.svg';
        $uploaded = [
            'file' => $source['tmp_name'],
            'filename' => $filename,
            'fileinfo' => [
                'extension' => 'svg',
                'filename' => $filename,
                'mime' => 'image/svg+xml',
            ],
        ];
    }
    $filename = $name . '.' . $uploaded['fileinfo']['extension'];
    $toStorage = [
        [
            'file' => $uploaded['file'],
            'filename' => $filename,
            'mime' => $uploaded['fileinfo']['mime'],
        ],
    ];
    AssetStorage::uploadFiles($toStorage, [
        'keyprefix' => $storagePath,
    ]);
    if (! isset($db_filename) || empty($db_filename)) {
        $db_filename = getSetting($what);
    }
    if ($remove_old) {
        if ($what === 'watermark_image') {
            unlinkIfExists(PATH_PUBLIC_CONTENT_IMAGES_SYSTEM . $db_filename);
        } else {
            AssetStorage::deleteFiles([
                'key' => $storagePath . $db_filename,
            ]);
        }
    }
    $home_cover_images = [];
    if (isset($cover_handle)) {
        $what = 'homepage_cover_image';
        $homepage_cover_image = getSetting($what);
        if ($cover_handle === 'add') {
            $filename = (
                $homepage_cover_image
                    ?? getSetting('homepage_cover_images')[0]['basename']
            ) . ',' . $filename;
        } else {
            $filename = isset($homepage_cover_image)
                ? str_replace($db_filename, $filename, getSetting('homepage_cover_image'))
                : $filename;
        }
        $filename = trim($filename, ',');

        foreach (explode(',', $filename) as $v) {
            $home_cover_images[] = [
                'basename' => $v,
                'url' => get_system_image_url($v),
            ];
        }
    }
    Settings::update([
        $what => $filename,
    ]);
    if (isset($cover_handle)) {
        Settings::setValue('homepage_cover_images', $home_cover_images);
    }
    if ($what === 'watermark_image') {
        $fp = fopen($uploaded['file'], 'rb');
        $assetsDb = DB::get('assets', [
            'key' => $what,
        ]);
        $dbArray = [
            'md5' => md5_file($uploaded['file']),
            'filename' => $filename,
            'file_path' => $storagePath,
            'blob' => $fp,
        ];
        if (empty($assetsDb)) {
            $dbArray['key'] = $what;
            DB::insert('assets', $dbArray);
        } else {
            DB::update('assets', $dbArray, [
                'key' => $what,
            ]);
        }
    }
    if (! AssetStorage::isLocalLegacy()) {
        unlinkIfExists($uploaded['file']);
    }
}

function isSafeToExecute(?int $max_execution_time = null, array $options = []): bool
{
    if ($max_execution_time === null) {
        $max_execution_time = (int) ini_get('max_execution_time');
    }
    if ($max_execution_time === 0) {
        return true;
    }
    $executed_time = microtime(true) - TIME_EXECUTION_START;
    $options = array_merge([
        'safe_time' => 5,
    ], $options);
    if (($max_execution_time - $executed_time) > $options['safe_time']) {
        return true;
    }

    return false;
}

function checkUpdates(): void
{
    $CHEVERETO = Settings::getChevereto();
    $update = fetch_url($CHEVERETO['api']['get']['info']);
    if ($update) {
        $json = json_decode($update);
        if ($json === null) {
            return;
        }
        $release_notes = $json->software->release_notes;
        $pos = (int) strpos($release_notes, 'Affected files and folders');
        $release_notes = trim(substr($release_notes, 0, $pos));
        $latest_release = $json->software->current_version;
        $notified = getVariable('update_check_notified_release')->nullString();
        if ($notified === null
            || (version_compare($latest_release, APP_VERSION, '>')
            && version_compare($latest_release, $notified, '>'))
        ) {
            Variable::set('update_check_notified_release', $latest_release);
            Variable::set('update_check_latest_release', $latest_release);
        }
        Variable::set('update_check_datetimegmt', datetimegmt());
    }
}

function updateCheveretoNews()
{
    try {
        $cheveretoNews = G\fetch_url('https://blog.chevereto.com/feed.json');
        $cheveretoNews = (array) json_decode($cheveretoNews)->items;
        Variable::set('chevereto_news', $cheveretoNews);
        Variable::set('news_check_datetimegmt', datetimegmt());
    } catch (Throwable) {
        $cheveretoNews = [];
    }

    return $cheveretoNews;
}

function obfuscate(string $string): string
{
    $len = strlen($string);
    $return = '';
    for ($i = 0; $i < $len; ++$i) {
        $return .= '&#' . ord($string[$i]) . ';';
    }

    return $return;
}

function isShowEmbedContent(): bool
{
    return match (getSetting('theme_show_embed_content_for')) {
        'none' => false,
        'users' => Login::isLoggedUser(),
        default => true,
    };
}

function getCheveretoEnv(): array
{
    $env = getenv();

    return array_filter($env, function ($key) {
        return strpos($key, 'CHEVERETO_') === 0;
    }, ARRAY_FILTER_USE_KEY);
}

/**
 * Process the server context and returns the handler file to load.
 */
function loaderHandler(
    array $_cookie,
    array $_env,
    array $_files,
    array $_get,
    array $_post,
    array $_request,
    array $_server,
    array $_session,
): string {
    $isHttps = strtolower($_server['HTTPS'] ?? '') === 'on'
        || ($_server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || preg_match('#https#i', $_server['HTTP_CF_VISITOR'] ?? '');
    $imageLibrary = '';
    if (extension_loaded('gd') && function_exists('gd_info')) {
        $imageLibrary = 'gd';
    }
    if (extension_loaded('imagick')) {
        $imageLibrary = 'imagick';
    }
    setlocale(LC_ALL, 'en_US.UTF8');
    define('APP_NAME', 'Chevereto');
    define('PATH_PUBLIC', dirname(__DIR__, 3) . '/');
    define('PATH_APP_CACHE', PATH_PUBLIC . 'app/.cache/');
    define('PATH_APP_LEGACY', PATH_PUBLIC . 'app/legacy/');
    define('PATH_APP_LEGACY_LOAD', PATH_APP_LEGACY . 'load/');
    define('PATH_APP_LEGACY_ROUTES', PATH_APP_LEGACY . 'routes/');
    define('PATH_APP_LEGACY_ROUTES_OVERRIDES', PATH_APP_LEGACY_ROUTES . 'overrides/');
    define('PATH_APP_COMPONENTS_LEGACY', PATH_PUBLIC . 'app/src/Components/Legacy/');
    define('PATH_APP', PATH_PUBLIC . 'app/');
    define('PATH_APP_LICENSE_KEY', PATH_APP . 'CHEVERETO_LICENSE_KEY.php');
    define('PATH_APP_LEGACY_INSTALL', PATH_APP_LEGACY . 'install/');
    define('PATH_APP_CONTENT', PATH_APP . 'content/');
    define('PATH_APP_LANGUAGES', PATH_APP . 'languages/');
    define('PATH_PUBLIC_CONTENT', PATH_PUBLIC . 'content/');
    define('PATH_PUBLIC_CONTENT_LEGACY_SYSTEM', PATH_PUBLIC_CONTENT . 'legacy/system/');
    define('PATH_PUBLIC_CONTENT_IMAGES_SYSTEM', PATH_PUBLIC_CONTENT . 'images/system/');
    define('PATH_PUBLIC_CONTENT_IMAGES_USERS', PATH_PUBLIC_CONTENT . 'images/users/');
    define('PATH_PUBLIC_CONTENT_PAGES', PATH_PUBLIC_CONTENT . 'pages/');
    define('PATH_PUBLIC_CONTENT_LEGACY_THEMES_PEAFOWL_LIB', PATH_PUBLIC_CONTENT . 'legacy/themes/Peafowl/lib/');
    define('PATH_PUBLIC_CONTENT_LEGACY_THEMES', PATH_PUBLIC_CONTENT . 'legacy/themes/');
    /** @var array $envDefault */
    $envDefault = require PATH_APP . 'env-default.php';
    $envDefault = array_merge($envDefault, [
        'CHEVERETO_HOSTNAME' => $_server['SERVER_NAME'] ?? gethostname(),
        'CHEVERETO_HTTPS' => (string) (int) $isHttps,
        'CHEVERETO_IMAGE_LIBRARY' => $imageLibrary,
    ]);
    $env = [];
    $envFile = filePhpForPath(PATH_APP . 'env.php');
    if ($envFile->file()->exists()) {
        $filePhpReturn = new FilePhpReturn($envFile);
        $env = $filePhpReturn->cast()->array();
    }
    $envVar = array_merge($envDefault, $env, $_env);
    $envVar['CHEVERETO_ID'] = $_env['CHEVERETO_ID']
        ?? $env['CHEVERETO_ID']
        ?? $_server['CHEVERETO_ID']
        ?? '';
    $envVar['CHEVERETO_ID_HANDLE'] = '';
        $envVar = array_merge($envVar, array (
      'CHEVERETO_EDITION' => 'free',
      'CHEVERETO_ENABLE_API_GUEST' => '0',
      'CHEVERETO_ENABLE_BANNERS' => '0',
      'CHEVERETO_ENABLE_CAPTCHA' => '0',
      'CHEVERETO_ENABLE_CONSENT_SCREEN' => '0',
      'CHEVERETO_ENABLE_COOKIE_COMPLIANCE' => '0',
      'CHEVERETO_ENABLE_EXPOSE_PAID_FEATURES' => '1',
      'CHEVERETO_ENABLE_EXTERNAL_SERVICES' => '0',
      'CHEVERETO_ENABLE_EXTERNAL_STORAGE_PROVIDERS' => '0',
      'CHEVERETO_ENABLE_FAVICON' => '0',
      'CHEVERETO_ENABLE_FOLLOWERS' => '0',
      'CHEVERETO_ENABLE_FORCE_POWERED_BY_FOOTER' => '1',
      'CHEVERETO_ENABLE_IP_BANS' => '0',
      'CHEVERETO_ENABLE_LANGUAGE_CHOOSER' => '0',
      'CHEVERETO_ENABLE_LIKES' => '0',
      'CHEVERETO_ENABLE_LOCAL_STORAGE' => '1',
      'CHEVERETO_ENABLE_LOGIN_PROVIDERS' => '0',
      'CHEVERETO_ENABLE_LOGO_CUSTOM' => '1',
      'CHEVERETO_ENABLE_MODERATION' => '0',
      'CHEVERETO_ENABLE_NOTIFICATIONS' => '0',
      'CHEVERETO_ENABLE_PAGES' => '0',
      'CHEVERETO_ENABLE_ROUTING' => '0',
      'CHEVERETO_ENABLE_SEO_ALBUM_URL' => '0',
      'CHEVERETO_ENABLE_SEO_IMAGE_URL' => '0',
      'CHEVERETO_ENABLE_SERVICE_AKISMET' => '0',
      'CHEVERETO_ENABLE_SERVICE_MODERATECONTENT' => '0',
      'CHEVERETO_ENABLE_SERVICE_PROJECTARACHNID' => '0',
      'CHEVERETO_ENABLE_SERVICE_STOPFORUMSPAM' => '0',
      'CHEVERETO_ENABLE_STOPWORDS' => '0',
      'CHEVERETO_ENABLE_UPLOAD_FLOOD_PROTECTION' => '0',
      'CHEVERETO_ENABLE_UPLOAD_PLUGIN' => '1',
      'CHEVERETO_ENABLE_UPLOAD_WATERMARK' => '0',
      'CHEVERETO_ENABLE_USERS' => '0',
      'CHEVERETO_MAX_ADMINS' => '1',
      'CHEVERETO_MAX_MANAGERS' => '1',
      'CHEVERETO_MAX_PAGES' => '-1',
      'CHEVERETO_MAX_USERS' => '1',
    ));
    $iniToChevereto = [
        'error_log' => 'CHEVERETO_ERROR_LOG',
        'max_execution_time' => 'CHEVERETO_MAX_EXECUTION_TIME_SECONDS',
        'memory_limit' => 'CHEVERETO_MAX_MEMORY_SIZE',
        'post_max_size' => 'CHEVERETO_MAX_POST_SIZE',
        'session.save_handler' => 'CHEVERETO_SESSION_SAVE_HANDLER',
        'session.save_path' => 'CHEVERETO_SESSION_SAVE_PATH',
        // 'upload_max_filesize' => 'CHEVERETO_MAX_UPLOAD_SIZE', // INI_PERDIR
    ];
    $iniToSkip = [
        'max_execution_time' => PHP_SAPI === 'cli',
    ];
    foreach ($iniToChevereto as $iniOption => $envName) {
        if (($iniToSkip[$iniOption] ?? false) === true) {
            continue;
        }
        if (! function_exists('ini_get')
            || ! function_exists('ini_set')
        ) {
            continue;
        }
        $iniGet = ini_get($iniOption);
        if ($iniGet === false) {
            continue;
        }
        $iniWant = $envVar[$envName] ?? '';
        if ($iniGet === $iniWant) {
            continue;
        }
        ini_set($iniOption, $iniWant);
    }
    $errorLog = 'CHEVERETO_ERROR_LOG';
    if (PHP_SAPI === 'cli' && $envVar['CHEVERETO_ERROR_LOG_CLI'] !== '') {
        $errorLog = 'CHEVERETO_ERROR_LOG_CLI';
    }
    if (ACCESS === 'cron' && $envVar['CHEVERETO_ERROR_LOG_CRON'] !== '') {
        $errorLog = 'CHEVERETO_ERROR_LOG_CRON';
    }
    ini_set('error_log', $envVar[$errorLog]);
    if (ACCESS === 'web') {
        $script_name = $_server['SCRIPT_NAME'] ?? '';
        if (str_ends_with($script_name, 'index.php')) {
            $relative_root = sanitize_path_slashes(
                dirname($script_name)
                . '/'
            );
            $envVar['CHEVERETO_HOSTNAME_PATH'] = $relative_root;
        }
    }
    if (($envVar['CHEVERETO_ENVIRONMENT'] ?? '') === 'dev') {
        $envVar['CHEVERETO_ENABLE_XRDEBUG'] = '1';
        if (($envVar['CHEVERETO_XRDEBUG_HOST'] ?? '') === 'localhost'
            && ($envVar['CHEVERETO_SERVICING'] ?? '') === 'docker'
        ) {
            $envVar['CHEVERETO_XRDEBUG_HOST'] = 'host.docker.internal';
        }
    }
    foreach ($envVar as $envName => &$envValue) {
        if (! is_string($envValue)) {
            try {
                $envValue = (string) $envValue;
            } catch (ErrorException) {
                $type = getType($envValue);
                $envValue = match ($type) {
                    'array' => '[]',
                    default => '',
                };
            }
        }
    }
    new EnvVar($envVar);
    new ServerVar(array_merge($envDefault, $env, $_server));
    new CookieVar($_cookie);
    new RequestVar($_request);
    new PostVar($_post);
    new GetVar($_get);
    new FilesVar($_files);
    require_once PATH_APP . 'configurator.php';
    if ($_session === []) {
        $session_start = false;
        $session_options = [
            'save_handler' => Config::system()->sessionSaveHandler(),
            'save_path' => Config::system()->sessionSavePath(),
            'cookie_path' => Config::host()->hostnamePath(),
            'cookie_domain' => Config::host()->hostname(),
            'cookie_secure' => Config::host()->isHttps(),
            'cookie_httponly' => true,
        ];

        try {
            $session_start = session_start($session_options);
        } catch (Throwable) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $session_start = true;
            }
        }
        if (! $session_start) {
            throw new RuntimeException(
                'Sessions not working (session_start)',
                600
            );
        }
        $_session = $_SESSION;
    }
    new SessionVar($_session);
    register_shutdown_function(function () {
        $_SESSION = session();
        $_COOKIE = cookie();
        session_write_close();
    });
    if (! array_key_exists('crypt', session())) {
        $cipherAlgo = 'AES-128-CBC';
        $ivLength = openssl_cipher_iv_length($cipherAlgo);
        sessionVar()->put('crypt', [
            'cipher' => $cipherAlgo,
            'ivlen' => $ivLength,
            'iv' => openssl_random_pseudo_bytes($ivLength),
        ]);
    }
    if (! in_array(Config::system()->errorLog(), ['php://stderr', '/dev/stderr', '', 'syslog'], true)) {
        new WritersInstance(
            writers()->withError(
                new StreamWriter(
                    streamFor(Config::system()->errorLog(), 'a')
                )
            )
        );
    }
    define('HTTP_APP_PROTOCOL', Config::host()->isHttps() ? 'https' : 'http');
    $httpPort = ! in_array(server()['SERVER_PORT'] ?? '80', ['80', '443'], false)
        ? ':' . server()['SERVER_PORT']
        : '';
    define('URL_APP_PUBLIC', HTTP_APP_PROTOCOL . '://' . Config::host()->hostname() . $httpPort . Config::host()->hostnamePath());
    phpCheck(Config::system());
    if (hasEnvDbInfo()) {
        DB::fromEnv();
    }
    new Settings();
    new L10n(
        defaultLanguage: Settings::get('default_language'),
        autoLanguage: Settings::get('auto_language'),
    );
    foreach (Settings::SEMANTICS as $messages) {
        $aux = 0;
        $singularKey = null;
        foreach ($messages as $key => $message) {
            $aux++;
            $value = Settings::get($key);
            if ($value === null) {
                continue;
            }
            $value = safe_html($value);
            L10n::setOverride($message, $value);
            if (count($messages) === 2 && $aux === 1) {
                $singularKey = $message;
                $singular = $value;
            }
            if (isset($singularKey, $singular)) {
                L10n::setPluralOverride($singularKey, $singular, $value);
            }
        }
    }

    try {
        $xrArguments = [
            'isEnabled' => (bool) (env()['CHEVERETO_ENABLE_XRDEBUG']),
            'isHttps' => (bool) (env()['CHEVERETO_XRDEBUG_HTTPS']),
            'host' => (string) (env()['CHEVERETO_XRDEBUG_HOST']),
            'port' => (int) (env()['CHEVERETO_XRDEBUG_PORT']),
            'key' => (string) (env()['CHEVERETO_XRDEBUG_KEY']),
        ];

        new XrInstance(new Xr(...$xrArguments));
    } catch (Throwable) {
        // Silent failover
    }
    $uploadImageFolder = cheveretoVersionInstalled() !== ''
            ? Settings::get('upload_image_path')
            : 'images';
    $urlAppPublicStatic = Settings::get('cdn')
        ? Settings::get('cdn_url') ?? URL_APP_PUBLIC
        : URL_APP_PUBLIC;
    define('URL_APP_PUBLIC_STATIC', $urlAppPublicStatic);
    define(
        'PATH_PUBLIC_LEGACY_THEME',
        PATH_PUBLIC_CONTENT_LEGACY_THEMES
            . (Settings::get('theme') ?? 'Peafowl')
            . '/'
    );
    define('URL_APP_THEME', absolute_to_url(PATH_PUBLIC_LEGACY_THEME, URL_APP_PUBLIC_STATIC));
    define('CHV_PATH_IMAGES', PATH_PUBLIC . '' . $uploadImageFolder . '/');
    filesystemPermissionsCheck();
    if (cheveretoVersionInstalled() !== '') {
        error_reporting(0);
        if (is_valid_timezone(Settings::get('default_timezone'))) {
            date_default_timezone_set(Settings::get('default_timezone'));
        }
        if (ACCESS === 'web') {
            $upload_max_filesize_mb_db = Settings::get('upload_max_filesize_mb');
            $upload_max_filesize_mb_bytes = get_bytes($upload_max_filesize_mb_db . 'MB');
            $ini_upload_max_filesize = get_ini_bytes(ini_get('upload_max_filesize'));
            $ini_post_max_size = ((int) ini_get('post_max_size')) === 0
                ? $ini_upload_max_filesize
                : get_ini_bytes(
                    ini_get('post_max_size')
                );
            Settings::setValue(
                'true_upload_max_filesize',
                min($ini_upload_max_filesize, $ini_post_max_size)
            );
            if (Settings::get('true_upload_max_filesize') < $upload_max_filesize_mb_bytes) {
                Settings::update([
                    'upload_max_filesize_mb' => bytes_to_mb((int) Settings::get('true_upload_max_filesize')),
                ]);
            }
        }
        ImageManagerStatic::configure([
            'driver' => Config::system()->imageLibrary(),
        ]);

        try {
            new AssetStorage(
                StorageApis::getAnon(
                    ...AssetStorage::getDbSettings()
                )
            );
        } catch (Throwable) {
            // Silent failover
        }
        $homepage_cover_image = getSetting('homepage_cover_image');
        $homeCovers = [];
        if (isset($homepage_cover_image)) {
            foreach (explode(',', $homepage_cover_image) as $vv) {
                $homeCovers[] = [
                    'basename' => $vv,
                    'url' => get_system_image_url($vv),
                ];
            }
        }
        Settings::setValue('homepage_cover_images', $homeCovers);
        shuffle($homeCovers);
        Settings::setValue('homepage_cover_images_shuffled', $homeCovers);
        define(
            'IMAGE_FORMATS_FAILING',
            getFailingImageFormats(Config::system()->imageLibrary())
        );
        if (IMAGE_FORMATS_FAILING !== []) {
            $formats = explode(',', Settings::get('upload_enabled_image_formats'));
            $formatsDiff = array_diff($formats, IMAGE_FORMATS_FAILING);
            if ($formatsDiff !== $formats) {
                Settings::update([
                    'upload_enabled_image_formats' => implode(',', $formatsDiff),
                ]);
            }
        }
    }
    define('STOP_WORDS', preg_split("/\r\n|\n|\r/", getSetting('stop_words') ?? ''));
    $handler = PATH_APP_LEGACY_LOAD;
    // @phpstan-ignore-next-line
    if (ACCESS !== 'web') {
        $handler .= '../commands/';
    }
    $handler .= ACCESS . '.php';
    if (! stream_resolve_include_path($handler)) {
        throw new LogicException(
            (string) message('Missing handler for `%access%`', access: ACCESS),
            600
        );
    }

    return $handler;
}

function virtualRouteHandleRedirect(string $route, string $base, ?string $virtual = null): void
{
    $virtual ??= $route;
    if (getSetting('root_route') === $route
        && $base === getSetting('route_' . $virtual)) {
        $target = preg_replace(
            '#/' . getSetting('route_' . $virtual) . '/#',
            '/',
            get_current_url(),
            1
        );
        headersNoCache();
        redirect($target, 301);
    }
}

function feedback(string $message)
{
    echo $message . "\n";
}

function feedbackAlert(string $message)
{
    echo "[!] {$message}\n";
}

function feedbackSeparator()
{
    echo '--' . "\n";
}

function feedbackStep(string $doing, string $target)
{
    feedback("* {$doing} {$target}");
}

function isDebug(): bool
{
    $environment = getenv('CHEVERETO_ENVIRONMENT');
    $debugLevel = $environment === false
        ? ''
        : $environment;

    try {
        return $debugLevel === 'dev'
            || (getSetting('debug_errors') && Login::isAdmin());
    } catch (Throwable) {
        return false;
    }
}

function getPreCodeHtml(string $body): string
{
    return '<pre style="overflow:auto;word-break:break-all;white-space:pre-wrap;"><code>'
        . $body
        . '</code></pre>';
}

function isStopWords(string ...$message): bool
{
    if (! (bool) env()['CHEVERETO_ENABLE_STOPWORDS'] || ! defined('STOP_WORDS')) {
        return false;
    }
    foreach ($message as $subject) {
        if ($subject === '') {
            continue;
        }
        $subject = strtolower($subject);
        foreach (STOP_WORDS as $word) {
            if ($word === '') {
                continue;
            }
            $pattern = '/' . $word . '/';
            $regex = new Regex($pattern);
            if ($regex->match($subject) === []) {
                continue;
            }

            return true;
        }
    }

    return false;
}

function assertNotStopWords(string ...$message): void
{
    if (isStopWords(...$message)) {
        throw new LogicException(
            message: message('Stop words found'),
            code: 400
        );
    }
}

/**
 * Increases or decreases the brightness of a color by a percentage of the current brightness.
 *
 * @param   string  $hexCode        Supported formats: `#FFF`, `#FFFFFF`, `FFF`, `FFFFFF`
 * @param   float   $adjustPercent  A number between -1 and 1. E.g. 0.3 = 30% lighter; -0.4 = 40% darker.
 *
 * @return  string
 *
 * @author  maliayas
 */
function adjustBrightness(string $hexCode, float $adjustPercent)
{
    $hexCode = ltrim($hexCode, '#');
    if (strlen($hexCode) === 3) {
        $hexCode = $hexCode[0] . $hexCode[0] . $hexCode[1] . $hexCode[1] . $hexCode[2] . $hexCode[2];
    }
    $hexCode = array_map('hexdec', str_split($hexCode, 2));
    foreach ($hexCode as &$color) {
        $adjustableLimit = $adjustPercent < 0 ? $color : 255 - $color;
        $adjustAmount = ceil($adjustableLimit * $adjustPercent);

        $color = str_pad(dechex(intval($color + $adjustAmount)), 2, '0', STR_PAD_LEFT);
    }

    return '#' . implode('', $hexCode);
}

function getLicenseKey(): string
{
    $licenseKey = env()['CHEVERETO_LICENSE_KEY'] ?? '';
    if ($licenseKey === '' && file_exists(PATH_APP_LICENSE_KEY)) {
        /** @var string $licenseKey */
        $licenseKey = require PATH_APP_LICENSE_KEY;
    }

    return $licenseKey;
}

function editionCombo(): array
{
    return [
        'free' => ['free'],
        'lite' => ['free', 'lite'],
        'pro' => ['free', 'lite', 'pro'],
        'enterprise' => ['free', 'lite', 'pro', 'enterprise'],
    ];
}

/**
 * Strip tags with the content in tag also removed.
 */
function strip_tags_content(string $text, string $tags = '')
{
    preg_match_all('/<(.+?)[\s]*\/?[\s]*>/si', trim($tags), $tags);
    /** @var array $tags */
    $tags = array_unique($tags[1]);

    if ($tags !== []) {
        $return = preg_replace(
            '@<(?!(?:' . implode('|', $tags) . ')\b)(\w+)\b.*?>.*?</\1>@si',
            '',
            $text
        );

        return strip_tags($return);
    }

    $return = preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $text);

    return strip_tags($return);
}

function assertMaxCount(string $table): void
{
    $tablesToEnv = [
        'categories' => 'CHEVERETO_MAX_CATEGORIES',
        'pages' => 'CHEVERETO_MAX_PAGES',
        'storages' => 'CHEVERETO_MAX_STORAGES',
        'albums' => 'CHEVERETO_MAX_ALBUMS',
        'login_providers' => 'CHEVERETO_MAX_LOGIN_PROVIDERS',
        'tags' => 'CHEVERETO_MAX_TAGS',
    ];
    if (! array_key_exists($table, $tablesToEnv)) {
        throw new LogicException(
            message: message('Invalid table `%table%`', table: $table),
            code: 400
        );
    }
    $maxLimit = (int) (env()[$tablesToEnv[$table]] ?? 0);
    if ($maxLimit === 0) {
        return;
    }
    $query = sprintf(
        'SELECT COUNT(*) AS total FROM `%s`',
        DB::getTable($table)
    );
    if ($table === 'storages') {
        $query .= ' WHERE storage_deleted_at IS NULL';
    }
    $fetch = DB::queryFetchSingle($query);
    $count = $fetch['total'] ?? 0;
    if (($count + 1) > $maxLimit) {
        throw new OverflowException(
            message(
                'Maximum number of %t% reached (limit %s%).',
                t: $table,
                s: strval($maxLimit),
            ),
            999
        );
    }
}

function fetchVariable(string $name): CastInterface
{
    return cast(
        Variable::fetch($name)
    );
}

function getVariable(string $name): CastInterface
{
    return cast(
        Variable::get($name)
    );
}

/**
 * @return string Version, empty string if not installed
 */
function cheveretoVersionInstalled(): string
{
    try {
        $variable = getVariable('chevereto_version_installed')->nullString() ?? '';
        if ($variable !== '') {
            return $variable;
        }

        return getSetting('chevereto_version_installed') ?? '';
    } catch (Throwable) {
        return '';
    }
}

function headersNoCache(): void
{
    // if (headers_sent()) {
    //     return;
    // }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function headersResetCache(): void
{
    // if (headers_sent()) {
    //     return;
    // }
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
}

function isPublicHost(string $host): bool
{
    $ip = gethostbyname($host);
    $typePub = \IPLib\Range\Type::getName(\IPLib\Range\Type::T_PUBLIC);
    $address = \IPLib\Factory::parseAddressString($ip);
    $type = $address->getRangeType();
    $typeName = \IPLib\Range\Type::getName($type);

    return $typeName === $typePub;
}
