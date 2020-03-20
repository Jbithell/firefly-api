<?php
require_once 'config.php';
$GLOBALS['BID'] =  new BID(true);
$GLOBALS['FIREFLYSYNCUSER'] = syncuser($GLOBALS['BID']->user()['bid_userid']);
//Startup Stuff
if (isset($_GET['code'])) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,"https://todoist.com/oauth/access_token");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS,
				"client_id=" . $GLOBALS['PROJECT']['DATA']['TODOIST']['CLIENT'] . '&client_secret=' . $GLOBALS['PROJECT']['DATA']['TODOIST']['SECRET'] . '&code=' . $_GET['code']);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$server_output = curl_exec ($ch);
	curl_close ($ch);
	$data = json_decode($server_output, true);
	if (isset($data["access_token"])) {
		if ($GLOBALS['DBLIB']->insert("fireflysync_users", ["bid_userid" => $GLOBALS['BID']->user()['bid_userid'], "todoist_api_token" => sanitizestring($data["access_token"])])) {
			header('Location: ?');
			exit;
		}
		else die("Error adding you to our database");
	}
	else die("Sorry - there was an error communicating with Todoist");
}
//END Startup Stuff
if (!$GLOBALS['FIREFLYSYNCUSER']) {
	header('Location: ' . "https://todoist.com/oauth/authorize?scope=data:read_write&client_id=" . $GLOBALS['PROJECT']['DATA']['TODOIST']['CLIENT'] . "&state=" . md5(time()));
}
?>
<title>Firefly Sync</title>
<center>
	<h1>Firefly Sync</h1>
	<i>Syncing your Firefly account with a Todoist account</i>
	<hr/>
	<h2>Your Todoist account is connected</h2>
	<hr/>
	<?php $fireflyconn = (fireflycall($GLOBALS['BID']->user()['bid_userid']) != false ? true : false) ?>
	<h2><?=($fireflyconn ? 'Your Firefly Account is connected' : 'You Firefly Account is not connected, please setup below')?></h2>
	<form method="POST" action="fireflyaccountsettings.php">
		<input type="text" placeholder="URL of the Firefly Site" value="<?=$GLOBALS['FIREFLYSYNCUSER']['fireflysync_firefly_url']?>" name="url" /><br/>
		<p><i>Format it like: <pre>firefly.yourschool.org</pre> - with no slashes or http:// or https://</i></p><br/>
		<input type="username" placeholder="Firefly Username" value="<?=$GLOBALS['FIREFLYSYNCUSER']['fireflysync_firefly_username']?>" name="user" /><br/>
		<input type="password" placeholder="Firefly Password" value="<?=$GLOBALS['FIREFLYSYNCUSER']['fireflysync_firefly_password']?>" name="pass" /><br/>
		<input type="submit" value="Save Settings" />
	</form>
	<hr />
	<h2><?=($fireflyconn ? 'You account will now sync!' : 'Cannot sync until Firefly account is setup')?></h2>
</center>
