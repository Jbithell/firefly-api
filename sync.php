<?php
require_once __DIR__ . '/config.php';

$users = $GLOBALS['DBLIB']->get("fireflysync_users");
foreach ($users as $user) {
	sync($user['bid_userid']);
}
?>
