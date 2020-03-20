<?php
$PROJECT = 11;
require_once __DIR__ . "/../../../config.php";


function syncuser($userid) {
	global $GLOBALS;
	$GLOBALS['DBLIB']->where("bid_userid", $userid);
	$syncuser = $GLOBALS['DBLIB']->getone("fireflysync_users");
	if (isset($syncuser['fireflysync_firefly_password'])) {
		$passarray = explode(",",$syncuser['fireflysync_firefly_password']);
		$syncuser['fireflysync_firefly_password'] = '';
		foreach ($passarray as $char) {
			$syncuser['fireflysync_firefly_password'] .= chr($char);
		}
		unset($passarray);
	}
	return $syncuser;
}

function fireflycall($userid, $archive = false, $completetask = false) {
	global $GLOBALS;
	$syncuser = syncuser($userid);
	$username = $syncuser['fireflysync_firefly_username'];
	$password = $syncuser['fireflysync_firefly_password'];
	$url = $syncuser['fireflysync_firefly_url'];

	$userAgent = "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/536.5 (KHTML, like Gecko) Chrome/19.0.1084.56 Safari/536.5";
	$authcallcurl = curl_init();
	curl_setopt($authcallcurl, CURLOPT_URL,"https://" . $url . "/login/login.aspx?prelogin=" . urlencode("https://" .$url . "/") ."tasks%3foutput%3dxml&kr=ActiveDirectoryKeyRing");
	curl_setopt($authcallcurl, CURLOPT_POST, 1);
	curl_setopt($authcallcurl, CURLOPT_POSTFIELDS,"username=" . urlencode($username) . "&password=" . urlencode($password));
	curl_setopt($authcallcurl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($authcallcurl, CURLOPT_USERAGENT, $userAgent);
	//curl_setopt($authcallcurl, CURLOPT_COOKIEJAR, $cookieJar);
	//curl_setopt($authcallcurl, CURLOPT_COOKIEFILE, $cookieJar);
	curl_setopt($authcallcurl, CURLOPT_HEADER, 1);

	$authcall = curl_exec ($authcallcurl);

	$end = strpos($authcall, "Content-Type");
	$start = strpos($authcall, "Set-Cookie");
	$parts = explode("Set-Cookie: ",substr($authcall, $start, $end-$start));
	$cookies = array();
	foreach ($parts as $co) {
		$cd = explode(";",$co);
		if (!empty($cd[0])) $cookies[] = $cd[0];
	}

	curl_close ($authcallcurl);

	if (strpos($authcall, 'Login') !== false) {
		return false; //Auth Fail
	}

	$fireflych = curl_init();

	curl_setopt($fireflych, CURLOPT_URL,"https://" .$url . "/tasks?view=xml" . ($archive ? '&status=archive' : null));
	curl_setopt($fireflych, CURLOPT_RETURNTRANSFER, true);

	curl_setopt($fireflych, CURLOPT_USERAGENT, $userAgent);

	curl_setopt($fireflych, CURLOPT_COOKIE, implode(";",$cookies));

	if ($completetask) curl_setopt($fireflych, CURLOPT_POSTFIELDS, "action=done&notice_id=" . $completetask . "&ajax=on");

	//curl_setopt($fireflych, CURLOPT_COOKIEFILE, $cookieJar);
	$data = curl_exec ($fireflych);

	curl_close ($fireflych);

	if ($completetask) {
		if ($data) return true;
		else return false;
	} else {
		$xmlobjectdata = simplexml_load_string($data);
		$dataarray = (array) $xmlobjectdata;

		if (!isset($dataarray["pageplugins"]->output)) return false; //Possibly an auth fail, or other error
		$tasks = (array) $dataarray["pageplugins"]->output;

		if (!isset($tasks['tasks'])) $tasks = []; //If there are no tasks;
			else {
			$tasks = (array) $tasks["tasks"];
			$tasks = $tasks['notice'];
			$tasks = (array) $tasks;
			if (isset($tasks['message'])) $tasks = [0 => $tasks]; //Add support for there only being one task
		}
		$tasksnicearray = array();
		foreach ($tasks as $task) {
			$task = (array) $task;
			$tasksnicearray[] = ["data" => $task["@attributes"], "task" => $task['message']];
		}
		return $tasksnicearray;
	}

}

function todoistapi($syncuser, $payload = false, $resource = false) {
	global $GLOBALS;
	$token = $syncuser["todoist_api_token"];
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,"https://todoist.com/API/v6/sync");
	curl_setopt($ch, CURLOPT_POST, 1);
	if ($payload) curl_setopt($ch, CURLOPT_POSTFIELDS, "token=" . $token . "&commands=" . json_encode($payload));
	else curl_setopt($ch, CURLOPT_POSTFIELDS, "token=" . $token . "&seq_no=0&resource_types=" . json_encode($resource ? $resource : ['all']));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$server_output = curl_exec ($ch);
	curl_close ($ch);
	$data = json_decode($server_output, true);
	if (isset($data['error'])) return false;
	else return $data;
}

function userprojectid($userid, $syncuser) {
	global $GLOBALS;
	//Work out the user's firefly project
	$syncuser['FIREFLYPROJECT'] = false;
	$userprojects = todoistapi($syncuser, false,["projects"]);
	if ($userprojects) {
		foreach ($userprojects["Projects"] as $project) {
			if ($project['name'] == "Firefly") {
				$syncuser['FIREFLYPROJECT'] = $project['id'];
				continue;
			}
		}
		if (!$syncuser['FIREFLYPROJECT']) {
			todoistapi($syncuser, [[
							"uuid" => md5(time() . rand(1,9999999999999999999)),
							"type" => "project_add",
							"temp_id" => md5(time() . rand(1,9999999999999999999)),
							"args" =>
									["name" => "Firefly"],
						]]);
			foreach (todoistapi($syncuser, false,["projects"])["Projects"] as $project) {
				if ($project['name'] == "Firefly") {
					$syncuser['FIREFLYPROJECT'] = $project['id'];
					continue;
				}
			}
		}
		if (!$syncuser['FIREFLYPROJECT']) {
			return false;
		}
	} else {
		return false;
	}

	return $syncuser['FIREFLYPROJECT'];
}
function sync($userid) {
	global $GLOBALS;
	$syncuser = syncuser($userid);
	if (!$syncuser) return false;

	$projectid =  userprojectid($userid, $syncuser);
	if (!$projectid) return false;
	$usertodoisttasks = todoistapi($syncuser, false,["items"])["Items"];

	//Get current increment
	$GLOBALS['DBLIB']->where("bid_userid", $userid);
	$thisincrement = $GLOBALS['DBLIB']->getValue ("fireflysync_tasks", "MAX(fireflysync_task_updateincrement)");
	if ($thisincrement == null or !$thisincrement) $thisincrement = 0;
	$thisincrement += 1;



	$fireflytasks = fireflycall($userid);
	if ($fireflytasks) {
		foreach ($fireflytasks as $task) {
			if (!isset($task['task']) or !isset($task['data']["from"])) continue; //Some very basic error handling

			$GLOBALS['DBLIB']->where("firefly_taskid", $task['data']['id']);
			$taskdb = $GLOBALS['DBLIB']->getone("fireflysync_tasks");
			if (!$taskdb) {

				$tasktodoist = (todoistapi($syncuser, [[
								"uuid" => md5(time() . rand(1,9999999999999999999)),
								"temp_id" => "tempidid",
								"type" => "item_add",
								"args" =>
										["content" => ($task['task'] . ' [' . $task['data']["from"] . (isset($task['data']["to"]) ? ' | ' . $task['data']["to"] : null) . ']'), "project_id" => $projectid, "date_string" => (date("d F Y", strtotime($task['data']["isoduedate"])) . " 9am")],
							]]));
				if (!isset($tasktodoist["TempIdMapping"]['tempidid'])) {
					echo "ERROR WITH TASK";
					var_dump($tasktodoist);
					continue; //Issue with next task
				}
				$GLOBALS['DBLIB']->insert("fireflysync_tasks", ["bid_userid" => $userid,
																"firefly_taskid" => $task['data']['id'],
																"firefly_done" => 0,
																"fireflysync_task_updateincrement" => $thisincrement,
																"todoist_taskid" => $tasktodoist["TempIdMapping"]['tempidid'],
																"todoist_done" => 0,
														]);
			} else {
				$task['STILL_IN_TODOIST'] = false;
				foreach ($usertodoisttasks as $taskdata) {
					if ($taskdata['id'] == $taskdb['todoist_taskid']) {
						$task['STILL_IN_TODOIST'] = true;
						break;
					}
				}
				if ($task['STILL_IN_TODOIST']) {
					//The task is still in todoist so we will leave it where it is!
					$GLOBALS['DBLIB']->where('fireflysync_task_id', $taskdb['fireflysync_task_id']);
					$GLOBALS['DBLIB']->update("fireflysync_tasks", ["fireflysync_task_updateincrement" => $thisincrement]);
				} else {
					//The task has been removed from todoist, thus we need to complete it on Firefly

					//fireflycall($userid, false, $taskdb["firefly_taskid"]);

					$GLOBALS['DBLIB']->where('fireflysync_task_id', $taskdb['fireflysync_task_id']);
					$GLOBALS['DBLIB']->update("fireflysync_tasks", ["fireflysync_task_updateincrement" => $thisincrement, "todoist_done" => 1, "firefly_done" => 1]);
				}
			}
		}
	}

	//Get tasks completed on Firefly, for completion on todoist
	$GLOBALS['DBLIB']->where("bid_userid", $userid);
	$GLOBALS['DBLIB']->where("todoist_done", 0);
	$GLOBALS['DBLIB']->where("fireflysync_task_updateincrement != " . $thisincrement);
	$taskstocomplete = $GLOBALS['DBLIB']->get("fireflysync_tasks");
	if ($taskstocomplete) {
		foreach ($taskstocomplete as $task) {
			//This task has been completed on Firefly, and now needs completing on todoist
				todoistapi($syncuser, [[
							"uuid" => md5(time() . rand(1,9999999999999999999)),
							"type" => "item_complete",
							"args" =>
									["ids" => [$task['todoist_taskid']]],
						]]);
			$GLOBALS['DBLIB']->where('fireflysync_task_id', $task['fireflysync_task_id']);
			$GLOBALS['DBLIB']->update("fireflysync_tasks", ["fireflysync_task_updateincrement" => $thisincrement, "todoist_done" => 1, "firefly_done" => 1]);
		}
		return True;
	} else return True;
}
?>
