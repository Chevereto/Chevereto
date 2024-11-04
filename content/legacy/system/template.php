<?php

use Chevereto\Legacy\Classes\Settings;
use Chevereto\Legacy\G\Handler;
use function Chevereto\Legacy\G\absolute_to_url;
use function Chevereto\Legacy\G\get_base_url;
use function Chevereto\Legacy\get_static_url;
use function Chevereto\Legacy\get_system_image_url;
use function Chevereto\Legacy\get_translation_table;
use function Chevereto\Legacy\versionize_src;

// @phpstan-ignore-next-line
if (! defined('ACCESS') || ! ACCESS) {
    exit('This file cannot be directly accessed.');
} ?>
<!DOCTYPE HTML>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo $doctitle ?? 'Chevereto'; ?></title>
  <link rel="stylesheet" href="<?php echo absolute_to_url(PATH_PUBLIC_CONTENT_LEGACY_SYSTEM . 'style.min.css'); ?>">
  <link rel="shortcut icon" href="<?php echo absolute_to_url(PATH_PUBLIC_CONTENT_LEGACY_SYSTEM . 'favicon.png'); ?>">
  <link rel="stylesheet" href="<?php echo get_static_url(PATH_PUBLIC_CONTENT_LEGACY_THEMES_PEAFOWL_LIB . 'font-awesome-6/css/all.min.css'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
      body {
          background-size: cover;
          background-image: url(<?php echo get_system_image_url('default/home_cover.jpg'); ?>);
      }
  </style>
</head>

<body class="body--flex">
    <main>
        <div class="screen animate animate--slow">
            <div class="flex-box flex-item">
                <div><?php echo $html ?? ''; ?></div>
            </div>
        </div>
    </main>
</body>
<?php
if (method_exists(Settings::class, 'getChevereto')) {
    echo '<script>var CHEVERETO = ' . json_encode(Settings::getChevereto()) . '</script>' . "\n";
}
?>
<script defer data-cfasync="false" src="<?php echo versionize_src(get_static_url(PATH_PUBLIC_CONTENT_LEGACY_THEMES_PEAFOWL_LIB . 'chevereto-all.min.js')); ?>" id="chevereto-js"></script>
<script data-cfasync="false">
document.addEventListener("DOMContentLoaded", function() {
  PF.obj.devices = window.devices;
  PF.obj.config.base_url = "<?php echo get_base_url(); ?>";
  PF.obj.config.json_api = "<?php echo get_base_url('update'); ?>/";
  PF.obj.l10n = <?php echo json_encode(get_translation_table()); ?>;
  PF.obj.config.auth_token = "<?php echo Handler::getAuthToken(); ?>";
});
</script>
</html>
