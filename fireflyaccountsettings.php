<?php
require_once 'config.php';
$GLOBALS['BID'] =  new BID(true);
$GLOBALS['FIREFLYSYNCUSER'] = syncuser($GLOBALS['BID']->user()['bid_userid']);
if (!$GLOBALS['FIREFLYSYNCUSER']) {
	header('Location: /');
	exit;
}
$pass = [];
foreach (str_split($_POST['pass']) as $char) {
	$pass[] = ord($char);
}
$pass = implode(",", $pass);
$GLOBALS['DBLIB']->where("fireflysync_userid",$GLOBALS['FIREFLYSYNCUSER']['fireflysync_userid']);
if ($GLOBALS['DBLIB']->update("fireflysync_users", [
	"fireflysync_firefly_username" => sanitizestring($_POST['user']),
	"fireflysync_firefly_url" => sanitizestring(rtrim($_POST['url'], "/")),
	"fireflysync_firefly_password" => sanitizestring($pass),
])) {
	header('Location: /');
	exit;
} else {
	die("Sorry - could not update settings");
}
?>
