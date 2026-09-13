<?php
// SMSLink Application Version Definition
if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'v1.0.0');
}
if (!defined('APP_BUILD_DATE')) {
    define('APP_BUILD_DATE', '2026-09-05');
}
if (!defined('APP_REPO')) {
    define('APP_REPO', 'beingniloy/smslink');
}
if (!defined('APP_APK_VERSION')) {
    define('APP_APK_VERSION', 'v1.0.0');
}
if (!defined('APP_APK_URL')) {
    define('APP_APK_URL', 'https://github.com/' . APP_REPO . '/releases/download/' . APP_APK_VERSION . '/SMSLink-' . APP_APK_VERSION . '.apk');
}
return [
    'version' => APP_VERSION,
    'build_date' => APP_BUILD_DATE,
    'repo' => APP_REPO,
    'apk_version' => APP_APK_VERSION,
    'apk_url' => APP_APK_URL
];
