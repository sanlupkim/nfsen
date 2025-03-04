<?php
/*
 *  Copyright (c) 2022 Peter Haag
 *  Copyright (c) 2004, SWITCH - Teleinformatikdienste fuer Lehre und Forschung
 *  All rights reserved.
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 *
 *   * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *   * Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *   * Neither the name of SWITCH nor the names of its contributors may be
 *     used to endorse or promote products derived from this software without
 *     specific prior written permission.
 *
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 *  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 *  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 *  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 *  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 *  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *  POSSIBILITY OF SUCH DAMAGE.
 * 
 */

// The very first function to call
session_start();

$expected_version = "1.3.10";

// Session check
if ( array_key_exists('backend_version', $_SESSION ) && $_SESSION['backend_version'] !=  $expected_version ) {
	session_destroy();
	session_start();
	$_SESSION['version'] = $expected_version;
	print "<h1>Frontend - Backend version mismatch!</h1>\n";
}

include ("conf.php");
include ("nfsenutil.php");
include ("navigator.php");

$TabList	= array ( 'Home', 'Graphs', 'Details', 'Alerts', 'Stats', 'Plugins');
$GraphTabs	= array ( 'Flows', 'Packets', 'Traffic');

// these session vars are packed into the bookmark
$BookmarkVars =  array ( 'tab', 'sub_tab', 'profileswitch', 'channellist', 'detail_opts/proto', 'detail_opts/type', 
						 'detail_opts/wsize', 'detail_opts/cursor_mode', 'tend', 'tleft', 'tright', 
						 'detail_opts/logscale', 'detail_opts/ratescale', 'detail_opts/linegraph');

// All available options 


$self = $_SERVER['PHP_SELF'];

//
// Function definitions
//

function SendHeader ($established) {

	global $self;
	global $TabList;
	global $CYCLETIME;

	header("Content-type: text/html; charset=ISO-8859-1");
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
	<meta HTTP-EQUIV="Cache-Control" content="no-cache">
	<meta HTTP-EQUIV="Pragma" CONTENT="no-cache">
	<link rel="stylesheet" type="text/css" href="css/nfsen.css">

<?php
	if ( !$established ) 
		return;

	$_tab =  array_key_exists('tab', $_SESSION) ? $_SESSION['tab'] : 0;
	if ( array_key_exists('tleft', $_SESSION ) ) {
		$datestr = date('M d Y - H:i T', $_SESSION['tleft']);
    $str = $TabList[$_tab] == 'Details' ? $datestr : 'Overview';
	} else {
		$str = '';
	}
?>
	<title>NFSEN - Profile <?php echo $_SESSION['profile'] . " $str";?></title>
<?php

	$refresh = $_SESSION['refresh'];
	if ( $TabList[$_tab] != 'Details' && $refresh > 0 ) {
		print "<meta HTTP-EQUIV='Refresh' CONTENT='$refresh; URL=$self?bookmark=" . $_SESSION['bookmark'] . "'>\n";
	}

	if ( $TabList[$_tab] == 'Details' ) { ?>
	<link rel="stylesheet" type="text/css" href="css/detail.css">
<?php } 
	if ( $TabList[$_tab] == 'Stats' ) { ?>
	<link rel="stylesheet" type="text/css" href="css/profileadmin.css">
<?php } 
	if ( $TabList[$_tab] == 'Alerts' ) { ?>
	<link rel="stylesheet" type="text/css" href="css/alerting.css">
<?php } ?>

	<script language="Javascript" src="js/global.js" type="text/javascript">
	</script>
	<script language="Javascript" src="js/menu.js" type="text/javascript">
	</script>
	<script language="Javascript" type="text/javascript">
		var CYCLETIME  = <?php echo $CYCLETIME;?>;
	</script>

</head>

<body>
<?php

} // End of SendHeader

function OpenLogFile () {
	global $log_handle;
	global $DEBUG;

	if ( $DEBUG ) {
		$log_handle = @fopen("/var/tmp/nfsen-log", "a");
		$_d = date("Y-m-d-H:i:s");
		ReportLog("\n=========================\nRun at $_d\n"); 
	} else 
		$log_handle = null;

} // End of OpenLogFile

function CloseLogFile () {
	global $log_handle;

	if ( $log_handle )
		fclose($log_handle);

} // End of CloseLogFile

function ReportLog($message) {
	global $log_handle;

	if ( $log_handle )
		fwrite($log_handle, "$message\n");

} // End of ReportLog

/* 	opts: 
 * POST_varname => array( 
 *	"required" 	=> 1, 						, 0 or 1 must exists in $_POST, must not be NULL
 *	"allow_null"=> 1, 						, 0 or 1 allow value to be NULL
 *	"default"  	=> NULL, 					, if not exists or not defined use this default, maybe NULL
 *	"match" 	=> "/[^A-Za-z0-9\-+_]+/" 	, value must satisfy this reges, may be NULL
 *	"validate" 	=> NULL),					, additional validate function to call for further processing, may be NULL
 */
function ParseForm($parse_opts) {
	$form_data = array();
	$has_errors = 0;
	foreach ( $parse_opts as $varname => $param_opts ) {
		// set the default
		$value = $parse_opts[$varname]['default'];
		if ( !array_key_exists($varname, $_POST) ) {
			if ( $parse_opts[$varname]['required'] == 1 ) {
				SetMessage('error', "Missing '$varname'");
				$has_errors = 1;
				$form_data[$varname] = $parse_opts[$varname]['default'];
				continue;
			} // else default value
		} else {
			$value = $_POST[$varname] == '' ? NULL : $_POST[$varname];
		}
		if ( is_null($value) ) {
			if ( $parse_opts[$varname]['allow_null'] ) {
				$form_data[$varname] = $value;
			} else {
				SetMessage('error', "No value for '$varname'");
				$form_data[$varname] = $parse_opts[$varname]['default'];
				$has_errors = 1;
				continue;
			}
		} else
		// the value is set here
		if ( !is_null($parse_opts[$varname]['match']) && !is_null($value) && !is_array($value)) {
			if ( is_array($parse_opts[$varname]['match']) ) {
				$matched = 0;
				foreach ( $parse_opts[$varname]['match'] as $item ) {
					if ( $item == $value ) 
						$matched = 1;
				}
				if ( $matched == 0 ) {
					SetMessage('error', "Illegal value '$value' for '$varname'");
					$has_errors = 1;
					$form_data[$varname] = $parse_opts[$varname]['default'];
					continue;
				}
			} else {
				if ( !preg_match($parse_opts[$varname]['match'], $value) ) {
				SetMessage('error', "Illegal value '$value' for '$varname'");
				$has_errors = 1;
				$form_data[$varname] = $parse_opts[$varname]['default'];
				continue;
				}
			}
		} 

		// survived match - do we have a validate function?
		if ( !is_null($parse_opts[$varname]['validate']) ) {
			$validatefunc = $parse_opts[$varname]['validate'];
			$err = call_user_func_array($validatefunc, array(&$value, $param_opts));
			switch ($err) {
				case 0:	// no error
					break;
				case 1:
					$has_errors = 1;
					$value = $parse_opts[$varname]['default'];
					break;
				case 2:
					$has_errors = 1;
					break;
			}
		}
		// put it in array
		$form_data[$varname] = $value;
	}

	return array( $form_data, $has_errors);

} // End of ParseForm

/* 
 * Individual form validate functions
 * - input: value to check. Maybe modified as needed -> passed by reference
 *          opts array as defined for this parameter
 * - returns: error code:
 *	0 no error continue
 *	1 check failed -> set default value
 *	2 check failed -> keep value
 */

function profile_exists_validate(&$profile, $opts) {

	if ( preg_match("/^(.+)\/(.+)/", $profile, $matches) ) {
		$_profilegroup = $matches[1];
		$_profilename  = $matches[2];
	} else {
		SetMessage('error', "Error decoding profile switch '$profile'");
		return 1;
	}

	$found = 0;
	foreach ( $_SESSION['ProfileList'] as $p ) {
		if ( $p == $profile ) {
			$found = 1;
		} 	
	}
	if ( $opts['must_exist'] == 1 && $found == 0 ) {
		SetMessage('error', "Profile '$_profilename' does not exist in group '$_profilegroup'");
		return 1;
	}

	if ( $opts['must_exist'] == 0 && $found == 1 ) {
		SetMessage('error', "Profile '$_profilename' already exist in group '$_profilegroup'");
		return 1;
	}

	if ( !preg_match("/^[A-Za-z0-9][A-Za-z0-9\-+_]+$/", $_profilename) ) {
		SetMessage('error', "Illegal characters in profile name '$_profilename'");
		return 1;
	}
	if ( $_profilegroup != '.' && !preg_match("/^[A-Za-z0-9][A-Za-z0-9\-+_]+$/", $_profilegroup) ) {
		SetMessage('error', "Illegal characters in profile name '$_profilegroup'");
		return 1;
	}

	return 0;

} // End of profile_exists_validate

function expire_validate(&$expire, $opts) {

	$str = ParseExpire($expire);
	if ( !is_null($str) ) {
		$expire = $str;
		return 0;
	} else {
		SetMessage('error', "Invalid expire time: '$expire'");
		return 1;
	}

} // End of expire

function maxsize_validate(&$maxsize, $opts) {

	$str = ParseMaxSize($maxsize);
	if ( !is_null($str) ) {
		$maxsize = $str;
		return 0;
	} else {
		SetMessage('error', "Invalid max Size: '$maxsize'");
		return 1;
	}

} // End of expire_validate

function date_time_validate(&$t, $opts) {

	// allow empty values
	if ( $t == NULL )
		return 0;

	$_tmp = DISPLAY2UNIX($t);
	if ( $_tmp > 0 ) {
		$t = $_tmp;
		return 0;
	} else {
		SetMessage('error', "Invalid time '$t'");
		return 1;
	}

} // End of date_time_validate

function filter_validate(&$filter, $opts) {

	if ( is_null($filter) ) {
		$filter = array();
		return 0;
	}

	$filter = preg_replace("/\r/", '', $filter);
	$filter = preg_replace("/^[\s\n]+/", '', $filter);
	$filter = preg_replace("/[\s\n]+$/", '', $filter);

	if ( $filter == '' ) {
		$filter = array();
		return 0;
	}

	$filter = addslashes($filter);
	// $filter = escapeshellarg($filter);
	$filter = explode("\n", $filter);
	$opts = array();
	$opts['args'] = '-Z';
	$opts['filter'] = $filter;
	$out_list = nfsend_query('run-nfdump', $opts, 0);
	if ( $out_list == false ) {
		return 2;
	}
	if ( array_key_exists("nfdump", $out_list) && $out_list["exit"] > 0 ) {
		foreach ( $out_list['nfdump'] as $line ) {
			SetMessage('error', "Filter error: $line");
		}
		return 2;
	}
	return 0;

} // End of filter_validate

function description_validate(&$description, $opts) {

	$_tmp = preg_replace("/\r/", '', $description);
	$description = addslashes($_tmp);
	$description = explode("\n", $description);
	return 0;

} // End of description_validate

// Global input parser

function channel_validate (&$channels, $opts) {

	$_liveprofile = ReadProfile('./live');
	$verified = array();
	$err = 0;
	foreach ( $channels as $channel ) {
		if ( array_key_exists($channel, $_liveprofile['channel'] ) ) {
			$verified[] = $channel;
		} else{
			SetMessage('error', "Channel '$channel' does not exist in profile 'live'");
			$err = 1;
		}
	}
	$channels = $verified;

	if ( $opts['allow_null'] == 0 && count($channels) == 0 ) {
		SetMessage('error', "At least one channel must be selected");
		$err = 1;
	}

	return $err;

} // End of channel_validate

function InitSession ($num_vars) {

	// force loading profil and plugin list
	unset($_SESSION['ProfileList']);
	unset($_SESSION['PluginList']);
	GetProfiles();
	GetPlugins();

	$_SESSION['auto_filter'] = array();
	DefaultFilters();

	// make tab and profileinfo exist in _SESSION
	$_SESSION['tab'] 		   = NULL;
	$_SESSION['profileswitch'] = NULL;

	// simulate a POST of tab and profileswitch
	$_POST['profileswitch'] = './live';
	$_POST['tab'] 			= 0;
	$_POST['sub_tab'] 		= 0;

	// Empty bookmark
	$vars = array();
	for ( $i=0; $i < $num_vars; $i++ ) {
		$vars[] = '-';
	}
	$_SESSION['bookmark'] = urlencode(base64_encode(implode('|', $vars)));


} // End of InitSession

function ParseInput () {

	global $TabList;
	global $BookmarkVars;
	global $GraphTabs;
	global $Refresh;

	// Preset refresh value. Any Input pasring routing may reset refresh to 0, to disable refresh
	$_SESSION['refresh'] = $Refresh;

	/* 
	 * user input may come from forms or links ( POST or GET data ) due to normal
	 * form processing. If a bookmark is specified in the URL, this overwrites other
	 * input data. To simplify data input checks, the bookmark is handled as any other post request
	 */

ReportLog("ParseInput:");
	if ( isset($_GET['bookmark']) ) {
		// process bookmarkstring
		$_bookmark = base64_decode(urldecode($_GET['bookmark']));
ReportLog("Bookmark: '$_bookmark'");
		$_vars = explode( '|', $_bookmark);
		if ( count($BookmarkVars) == count($_vars) ) {
			for ( $i=0; $i<count($BookmarkVars); $i++ ) {
				if ( $_vars[$i] != '-' ) {
					$_varpath = explode('/', $BookmarkVars[$i]);
					$_varname = count($_varpath) == 2 ? $_varpath[1] : $_varpath[0];
ReportLog("Bookmark: Set $_varname");
					$_POST[$_varname] = $_vars[$i];
				}
			}
		} else {
			SetMessage('warning', "Bookmark processing error");
		}
	} 

	
	// process tab 
	if ( !array_key_exists('tab', $_SESSION) ) {
		// first time in this session
		// initialize some more vars in the SESSION var
		InitSession(count($BookmarkVars));
	} else {
		$_tab = $_SESSION['tab'];
	}

	// click on tab list
	if ( array_key_exists('tab', $_GET) ) 
		$_tab = $_GET['tab'];

	// tab from bookmark overwrites other entries
	if ( array_key_exists('tab', $_POST) ) 
		$_tab = $_POST['tab'];

	$tab_changed = 0;
	if ( $_tab != $_SESSION['tab'] || $_SESSION['tab'] == NULL) {
		// _tab changed since last cycle
		if ( array_key_exists('tablock', $_SESSION) ) {
			// must not change tab right now
			SetMessage('error', $_SESSION['tablock']);
		} else {
			// Verify new tab
			if ( !is_numeric($_tab) || ( ($_tab > count($TabList)) || ($_tab < 0) ) ) {
				SetMessage('warning', "Requested Tab not available. Set default tab to " . $TabList[0]);
				$_tab = 0;
			}
			$_tab = (int)$_tab;
			$_SESSION['tab'] = $_tab;
			if ( !isset($_GET['bookmark']) ) {
				$tab_changed = 1;
			}
ReportLog("Tab: Set tab to $_tab: " . $TabList[$_tab]);
		}
	}

	// rebuild profile list
	if ( $tab_changed && $_SESSION['tab'] == 4 ) {
		unset($_SESSION['ProfileList']);
		unset($_SESSION['PluginList']);
		$profiles = GetProfiles();
		GetPlugins();
	}

	// process sub tab
	$_tab = -1;
	if ( array_key_exists('sub_tab', $_GET) ) 
		$_tab = $_GET['sub_tab'];

	if ( array_key_exists('sub_tab', $_POST) )
		$_tab = $_POST['sub_tab'];
	
	if ( $_tab >= 0 ) {
		if ( ! is_numeric($_tab) || ($_tab < 0) ) {
			$_tab = 0;
		}
		$_SESSION['sub_tab'] = $_tab;
ReportLog("Subtab: Set tab to $_tab: " . $GraphTabs[$_tab]);
	}

	// process profileswitch
	if ( !array_key_exists('profileswitch', $_SESSION) ) {
		// this is fishy - InitSession should have set this
		SetMessage('error', "Missing session parameter 'profileswitch'");
		$_SESSION['refresh'] = 0;
		return array(FALSE, 0, 0);
	} else {
		$_profileswitch = $_SESSION['profileswitch'];
	}

	if ( array_key_exists('profileswitch', $_POST) ) {
		$_profileswitch = $_POST['profileswitch'];
	}

	// the alerting module only accepts profile live for now
	if ( $_SESSION['tab'] == 3 )
		$_profileswitch = './live';

	$profile_changed = 0;
	if ( $_profileswitch != $_SESSION['profileswitch'] ) {
		if ( $_profileswitch == "New Profile ..." ) {
			// make sure the profile admin page gets this request;
			$_SESSION['tab']   	 = 4;
			$_SESSION['new_profile'] = TRUE;
			$_SESSION['refresh'] = 0;
		} else {
			// process new profileswitch
			if ( preg_match("/^(.+)\/(.+)/", $_profileswitch, $matches) ) {
				$_profilegroup = $matches[1];
				$_profilename  = $matches[2];

				// Check if profilegroup/profilename exists
				$_found = FALSE;
				foreach ( $_SESSION['ProfileList'] as $p ) {
					if ( $p == $_profileswitch ) {
						$_found = TRUE;
					}
				}
				if ( !$_found ) {
					SetMessage('error', "Profile '$_profilename' does not exists in profile group '$_profilegroup'");
					SetMessage('warning', "Fall back to profile live");
					$_profilegroup = '.';
					$_profilename  = 'live';
				}
			} else {
				SetMessage('error', "Can not parse profileswitch");
				SetMessage('warning', "Fall back to profile live");
				$_profilegroup = '.';
				$_profilename  = 'live';
			}

			$profile_changed = 1;
			$_SESSION['profile'] 	   = $_profilename;
			$_SESSION['profilegroup']  = $_profilegroup;
			$_SESSION['profileswitch'] = $_profileswitch;
		}
	}


	$profileinfo = ReadProfile($_SESSION['profileswitch']);
	if ( $profileinfo == FALSE ) {
		SetMessage('warning', "Fall back to profile live");
		unset($_SESSION['ProfileList']);
		$profiles = GetProfiles();
		$_SESSION['profileswitch'] = './live';
		$_SESSION['profile'] 	   = 'live';
		$_SESSION['profilegroup']  = '.';
		$profileinfo = ReadProfile('./live');
		if ( $profileinfo == FALSE ) {
			// double failure
			SetMessage('error', "Can't read profile 'live'");
			$_SESSION['refresh'] = 0;
			return array(FALSE, 0, 0);
		}
	}
	if ( $profileinfo['status'] == 'new' ) {
		$_SESSION['tab']   	 = 4;
		$_SESSION['refresh'] = 0;
		$_SESSION['tablock'] = "A new profile needs to be completed first.";
	} else {
		unset($_SESSION['tablock']);
	}

	$_SESSION['profileinfo'] = $profileinfo;

	// no refresh for history profiles
	if ( ($profileinfo['type'] & 3) == 1 )
		$_SESSION['refresh'] = 0;

	return array(TRUE, $tab_changed, $profile_changed);

} // End of ParseInput

////////////////////
// Main starts here
////////////////////

// Set debugging var
if ( !isset($DEBUG) ) {
    $DEBUG=0;
}

ClearMessages();

OpenLogFile();

// bootstrap

// Force new nfcapd session
unset($_SESSION['nfsend']);
$out_list = nfsend_query('get-globals', array(), 0);
if ( !is_array($out_list) ) {
	SetMessage('error', "Can not initialize globals");
	SendHeader(0);
	ShowMessages();
	exit;
}
foreach ( $out_list['globals'] as $global ) {
	eval($global);
}
$_SESSION['backend_version'] = $version;

// check disk usage
nfsend_query("get-du", array(), 0);

// Parameter parsing: check for tab and profileswitch
list ($status, $tab_changed, $profile_changed) = ParseInput();
if ( $status == FALSE ) {
	SendHeader(1);
	ShowMessages();
	exit;
}

// tab processing
$label = $TabList[$_SESSION['tab']];
switch ($label) {
	// no further processing needed for tabs 0 - 3
	case "Home":
	case "Graphs":
		if ( $tab_changed || !array_key_exists('sub_tab', $_SESSION ))
			$_SESSION['sub_tab'] = 0;
		break;
	case "Details":
		require ("details.php");
		Process_Details_tab($tab_changed, $profile_changed);
		break;
	case "Alerts":
		require ("alerting.php");
		Process_alert_tab($tab_changed, $profile_changed);
		break;
	case "Stats":
		require("profileadmin.php");
		Process_stat_tab($tab_changed, $profile_changed);
		break;
	case "Plugins":
		if ( $tab_changed || !array_key_exists('sub_tab', $_SESSION )) {
			if(isset($_GET['sub_tab']))
				$_SESSION['sub_tab'] = intval($_GET['sub_tab']);
			else
				$_SESSION['sub_tab'] = 0;
		}
 		$plugins  = GetPlugins ();
		if ($_SESSION['sub_tab'] > count($plugins)) {
			SetMessage('error', "Plugin number out of range!");
			$_SESSION['sub_tab'] = 0;
		}
		if ( count($plugins) > 0 ) {
			$plugin_name = $plugins[ $_SESSION['sub_tab'] ];
			$include_file = "$FRONTEND_PLUGINDIR/$plugin_name" . ".php";
			include($include_file);
			$plugin_parse_input = $plugin_name . "_ParseInput";
			$plugin_run 	    = $plugin_name . "_Run";
			$errstr = '';
			if ( !function_exists($plugin_parse_input) ) {
				$errstr = $plugin_parse_input;
			}
			if ( !function_exists($plugin_run) ) {
				$errstr = "$errstr $plugin_run";
			}
			if ( $errstr != '' ) {
				SetMessage('error', "Missing plugin function $errstr");
				$plugin_run 	    = "Plugin_Error";
			} else {
				// Give the plugin a chance to Parse the plugin specific vars
				// The plugin may call SetMessage('error', ... SetMessage('warning', 
				call_user_func($plugin_parse_input, $_SESSION['sub_tab'], $_SESSION['profile'] );
			}
		}
		break;
	default:
}

// Generate bookmark
$vars = array();
foreach ( $BookmarkVars as $var ) {
	$_varpath = explode('/', $var);
	if ( count($_varpath) == 2 ) {
		$vars[] = array_key_exists($_varpath[0], $_SESSION) && array_key_exists($_varpath[1], $_SESSION[$_varpath[0]]) ? 
					$_SESSION[$_varpath[0]][$_varpath[1]] : '-';
	} else {
		$vars[] = array_key_exists($var, $_SESSION) ? $_SESSION[$var] : '-';
	}

}

ReportLog("New Bookmark: " . implode('|', $vars) );
$_SESSION['bookmark'] = urlencode(base64_encode(implode('|', $vars)));


SendHeader(1);
navigator();
ShowMessages();

// Debugging
ob_start();
print "_SESSION 1:\n";
print_r($_SESSION);
print "_POST:\n";
print_r($_POST);
print "_GET:\n";
print_r($_GET);
print "_COOKIE:\n";
print_r($_COOKIE);
ReportLog(ob_get_contents());
ob_clean();

$TabList 	  = array ( 'Home', 'Graphs', 'Details', 'Alerts', 'Stats', 'Plugins');
// tab display
switch ($label) {
	case "Home":
		require ("overview.php");
		DisplayOverview();
		break;
	case "Graphs":
		require ("overview.php");
		switch ($_SESSION['sub_tab']) {
			case "0":
				DisplayGraphs("flows");
				break;
			case "1":
				DisplayGraphs("packets");
				break;
			case "2":
				DisplayGraphs("traffic");
				break;
		}
		break;
	case "Details":
		DisplayDetails();
		DisplayProcessing();
		break;
	case "Alerts":
		DisplayAlerts();
		break;
	case "Stats":
		DisplayAdminPage();
		break;
	case "Plugins":
		//	Run the plugin
		if ( count($plugins) > 0 )
			call_user_func($plugin_run, $_SESSION['sub_tab'], $_SESSION['profile'] );
		// otherwise do nothing
		break;
	default:
}

nfsend_disconnect();
unset($_SESSION['nfsend']);
CloseLogFile();

?>

<div id="footer" class="footer" align="right">nfsen <?php echo $version ?></div>
<div id="hintbox"></div>
</body>
</html>
