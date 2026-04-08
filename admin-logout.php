<?php
session_start();
session_destroy();
// Redirect relative to the current script's directory
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header('Location: ' . $basePath . '/admin-login.html');
exit;
?>
