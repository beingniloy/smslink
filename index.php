<?php
/**
 * SMSLink - Router
 * Routes to setup or dashboard based on installation status.
 */

$installedLock = file_exists(__DIR__ . '/config/installed.lock');
$credFile = file_exists(__DIR__ . '/config/db_credentials.php');

if ($installedLock && $credFile) {
    try {
        require_once __DIR__ . '/config/database.php';
        $pdo = getDbConnection();
        header('Location: dashboard/');
        exit;
    } catch (Exception $e) {
        header('Location: install/');
        exit;
    }
} else {
    header('Location: install/');
    exit;
}
