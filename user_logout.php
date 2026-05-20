<?php
// user_logout.php
require_once __DIR__ . '/includes/session.php';
destroySession();
header('Location: user_login.php');
exit();
