<?php

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright  (c) the authors
 * @author     Florian Lippert <flo@syscp.org> (2003-2009)
 * @author     Froxlor team <team@froxlor.org> (2010-)
 * @license    GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package    Panel
 *
 */

define('AREA', 'customer');

/**
 * Include our init.php, which manages Sessions, Language etc.
 */
require('./lib/init.php');

if ($action == 'logout') {
	$log->logAction(USR_ACTION, LOG_NOTICE, 'logged out');

        $query = "DELETE FROM `" . TABLE_PANEL_SESSIONS . "` WHERE `userid` = '" . (int)$userinfo['customerid'] . "' AND `adminsession` = '0'";
	if ($settings['session']['allow_multiple_login'] == '1') {
		$query .= " AND `hash` = '" . $s . "'";
	}
	$db->query($query);
	redirectTo('index.php');
	exit;
}

if ($page == 'overview') {
	$log->logAction(USR_ACTION, LOG_NOTICE, "viewed customer_index");
	$domains = '';
	$result = $db->query("SELECT `domain` FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `customerid`='" . (int)$userinfo['customerid'] . "' AND `parentdomainid`='0' AND `id` <> '" . (int)$userinfo['standardsubdomain'] . "' ");
	$domainArray = array();

	while ($row = $db->fetch_array($result)) {
		$domainArray[] = $idna_convert->decode($row['domain']);
	}

	natsort($domainArray);
	$domains = implode(',<br />', $domainArray);
	$userinfo['email'] = $idna_convert->decode($userinfo['email']);
	$yesterday = time() - (60 * 60 * 24);
	$month = date('M Y', $yesterday);

	/*		$traffic=$db->query_first("SELECT SUM(http) AS http_sum, SUM(ftp_up) AS ftp_up_sum, SUM(ftp_down) AS ftp_down_sum, SUM(mail) AS mail_sum FROM ".TABLE_PANEL_TRAFFIC." WHERE year='".date('Y')."' AND month='".date('m')."' AND day<='".date('d')."' AND customerid='".$userinfo['customerid']."'");
		$userinfo['traffic_used']=$traffic['http_sum']+$traffic['ftp_up_sum']+$traffic['ftp_down_sum']+$traffic['mail_sum'];*/

	$userinfo['diskspace'] = round($userinfo['diskspace'] / 1024, $settings['panel']['decimal_places']);
	$userinfo['diskspace_used'] = round($userinfo['diskspace_used'] / 1024, $settings['panel']['decimal_places']);
	$userinfo['traffic'] = round($userinfo['traffic'] / (1024 * 1024), $settings['panel']['decimal_places']);
	$userinfo['traffic_used'] = round($userinfo['traffic_used'] / (1024 * 1024), $settings['panel']['decimal_places']);
	$userinfo = str_replace_array('-1', $lng['customer']['unlimited'], $userinfo, 'diskspace traffic mysqls emails email_accounts email_forwarders email_quota email_autoresponder ftps tickets subdomains aps_packages');

	eval("echo \"" . getTemplate('index/index') . "\";");
} elseif ($page == 'change_password') {
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		$old_password = validate($_POST['old_password'], 'old password');
		if (md5($old_password) != $userinfo['password']) {
			standard_error('oldpasswordnotcorrect');
			exit;
		}

		$new_password = validatePassword($_POST['new_password'], 'new password');
		$new_password_confirm = validatePassword($_POST['new_password_confirm'], 'new password confirm');

		if ($old_password == '') {
			standard_error(array('stringisempty', 'oldpassword'));
		} elseif($new_password == '') {
			standard_error(array('stringisempty', 'newpassword'));
		} elseif($new_password_confirm == '') {
			standard_error(array('stringisempty', 'newpasswordconfirm'));
		} elseif($new_password != $new_password_confirm) {
			standard_error('newpasswordconfirmerror');
		} else {
			$db->query("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `password`='" . md5($new_password) . "' WHERE `customerid`='" . (int)$userinfo['customerid'] . "' AND `password`='" . md5($old_password) . "'");
			$log->logAction(USR_ACTION, LOG_NOTICE, 'changed password');

			if (isset($_POST['change_main_ftp'])
			   && $_POST['change_main_ftp'] == 'true'
			) {
				$cryptPassword = makeCryptPassword($new_password);
				$db->query("UPDATE `" . TABLE_FTP_USERS . "` SET `password`='" . $db->escape($cryptPassword) . "' WHERE `customerid`='" . (int)$userinfo['customerid'] . "' AND `username`='" . $db->escape($userinfo['loginname']) . "'");
				$log->logAction(USR_ACTION, LOG_NOTICE, 'changed main ftp password');
			}

			if (isset($_POST['change_webalizer'])
			   && $_POST['change_webalizer'] == 'true'
			) {
				if (CRYPT_STD_DES == 1) {
					$saltfordescrypt = substr(md5(uniqid(microtime(), 1)), 4, 2);
					$new_webalizer_password = crypt($new_password, $saltfordescrypt);
				} else {
					$new_webalizer_password = crypt($new_password);
				}

				$db->query("UPDATE `" . TABLE_PANEL_HTPASSWDS . "` SET `password`='" . $db->escape($new_webalizer_password) . "' WHERE `customerid`='" . (int)$userinfo['customerid'] . "' AND `username`='" . $db->escape($userinfo['loginname']) . "'");
			}

			redirectTo($filename, Array('s' => $s));
		}
	} else {
		eval("echo \"" . getTemplate('index/change_password') . "\";");
	}
} elseif ($page == 'change_language') {
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		$def_language = validate($_POST['def_language'], 'default language');
		if (isset($languages[$def_language])) {
			$db->query("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `def_language`='" . $db->escape($def_language) . "' WHERE `customerid`='" . (int)$userinfo['customerid'] . "'");
			$db->query("UPDATE `" . TABLE_PANEL_SESSIONS . "` SET `language`='" . $db->escape($def_language) . "' WHERE `hash`='" . $db->escape($s) . "'");
			$log->logAction(USR_ACTION, LOG_NOTICE, "changed default language to '" . $def_language . "'");
		}

		redirectTo($filename, Array('s' => $s));
	} else {
		$default_lang = $settings['panel']['standardlanguage'];
		if ($userinfo['def_language'] != '') { 
			$default_lang = $userinfo['def_language'];
		}

		$language_options = '';
		while (list($language_file, $language_name) = each($languages)) {
			$language_options .= makeoption($language_name, $language_file, $default_lang, true);
		}

		eval("echo \"" . getTemplate('index/change_language') . "\";");
	}
} elseif ($page == 'change_theme') {
	if (isset($_POST['send']) && $_POST['send'] == 'send') {
		$theme = validate($_POST['theme'], 'theme');
 
		$db->query("UPDATE `" . TABLE_PANEL_CUSTOMERS . "` SET `theme`='" . $db->escape($theme) . "' WHERE `customerid`='" . (int)$userinfo['customerid'] . "'");
		$db->query("UPDATE `" . TABLE_PANEL_SESSIONS . "` SET `theme`='" . $db->escape($theme) . "' WHERE `hash`='" . $db->escape($s) . "'");
		$log->logAction(USR_ACTION, LOG_NOTICE, "changed default theme to '" . $theme . "'");
		redirectTo($filename, Array('s' => $s));
	} else {
		$default_theme = $settings['panel']['default_theme'];
		if ($userinfo['theme'] != '') {
			$default_theme = $userinfo['theme'];
		}

		$theme_options = '';
		$themes_avail = getThemes();
		foreach ($themes_avail as $t) {
			$theme_options .= makeoption($t, $t, $default_theme, true);
		}

		eval("echo \"" . getTemplate('index/change_theme') . "\";");
	}
}
