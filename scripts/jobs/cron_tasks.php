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
 * @package    Cron
 *
 */

/*
 * necessary includes
 */
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.dns.10.bind.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.10.apache.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.15.apache_fcgid.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.20.lighttpd.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.25.lighttpd_fcgid.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.30.nginx.php'));
require_once(makeCorrectFile(dirname(__FILE__) . '/cron_tasks.inc.http.35.nginx_phpfpm.php'));

/**
 * LOOK INTO TASKS TABLE TO SEE IF THERE ARE ANY UNDONE JOBS
 */

fwrite($debugHandler, '  cron_tasks: Searching for tasks to do' . "\n");
$cronlog->logAction(CRON_ACTION, LOG_INFO, "Searching for tasks to do");
$result_tasks = $db->query("SELECT `id`, `type`, `data` FROM `" . TABLE_PANEL_TASKS . "` ORDER BY `id` ASC");
$resultIDs = array();

while ($row = $db->fetch_array($result_tasks)) {

	$resultIDs[] = $row['id'];

	if ($row['data'] != '') {
		$row['data'] = unserialize($row['data']);
	}

	/**
	 * TYPE=1 MEANS TO REBUILD APACHE VHOSTS.CONF
	 */

	if ($row['type'] == '1') {

		// get configuration-I/O object
		$configio = new ConfigIO($settings);
		// clean up old configs
		$configio->cleanUp();

		if (!isset($webserver)) {
			if ($settings['system']['webserver'] == "apache2") {
				$websrv = 'apache';
				if ($settings['system']['mod_fcgid'] == 1 || $settings['phpfpm']['enabled'] == 1) {
					$websrv .= '_fcgid';
				}
			} elseif ($settings['system']['webserver'] == "lighttpd") {
				$websrv = 'lighttpd';
				if ($settings['system']['mod_fcgid'] == 1 || $settings['phpfpm']['enabled'] == 1) {
					$websrv .= '_fcgid';
				}
			} elseif($settings['system']['webserver'] == "nginx") {
				$websrv = 'nginx';
				if ($settings['phpfpm']['enabled'] == 1) {
					$websrv .= '_phpfpm';
				}
			}

			$webserver = new $websrv($db, $cronlog, $debugHandler, $idna_convert, $settings);
		}

		if (isset($webserver)) {
			$webserver->createIpPort();
			$webserver->createVirtualHosts();
			$webserver->createFileDirOptions();
			$webserver->writeConfigs();
			$webserver->createOwnVhostStarter();
			$webserver->reload();
		} else {
			echo "Please check you Webserver settings\n";
		}

		/**
		 * as we might have a change from mod_php to fcgid/fpm or the other way around
		 * we need to check customer directory permissions
		 * -> 0.9.31
		 */

	}

	/**
	 * TYPE=2 MEANS TO CREATE A NEW HOME AND CHOWN
	 */
	elseif ($row['type'] == '2')
	{
		fwrite($debugHandler, '  cron_tasks: Task2 started - create new home' . "\n");
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Task2 started - create new home');

		if(is_array($row['data']))
		{
			// define paths
			$userhomedir = makeCorrectDir($settings['system']['documentroot_prefix'] . '/' . $row['data']['loginname'] . '/');
			$usermaildir = makeCorrectDir($settings['system']['vmail_homedir'] . '/' . $row['data']['loginname'] . '/');

			// stats directory
			if($settings['system']['awstats_enabled'] == '1')
			{
				$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: mkdir -p ' . escapeshellarg($userhomedir . 'awstats'));
				safe_exec('mkdir -p ' . escapeshellarg($userhomedir . 'awstats'));
			} else {
				$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: mkdir -p ' . escapeshellarg($userhomedir . 'webalizer'));
				safe_exec('mkdir -p ' . escapeshellarg($userhomedir . 'webalizer'));
			}

			// maildir
			$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: mkdir -p ' . escapeshellarg($usermaildir));
			safe_exec('mkdir -p ' . escapeshellarg($usermaildir));

			//check if admin of customer has added template for new customer directories
			if((int)$row['data']['store_defaultindex'] == 1)
			{
				storeDefaultIndex($row['data']['loginname'], $userhomedir, $cronlog, true);
			}

			// strip of last slash of paths to have correct chown results
			$userhomedir = (substr($userhomedir, 0, -1) == '/') ? substr($userhomedir, 0, -1) : $userhomedir;
			$usermaildir = (substr($usermaildir, 0, -1) == '/') ? substr($usermaildir, 0, -1) : $usermaildir;

			$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: chown -R ' . (int)$row['data']['uid'] . ':' . (int)$row['data']['gid'] . ' ' . escapeshellarg($userhomedir));
			safe_exec('chown -R ' . (int)$row['data']['uid'] . ':' . (int)$row['data']['gid'] . ' ' . escapeshellarg($userhomedir));
			$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: chown -R ' . (int)$settings['system']['vmail_uid'] . ':' . (int)$settings['system']['vmail_gid'] . ' ' . escapeshellarg($usermaildir));
			safe_exec('chown -R ' . (int)$settings['system']['vmail_uid'] . ':' . (int)$settings['system']['vmail_gid'] . ' ' . escapeshellarg($usermaildir));
		}
	}

	/**
	 * TYPE=3 MEANS TO DO NOTHING
	 */
	elseif ($row['type'] == '3')
	{
	}

	/**
	 * TYPE=4 MEANS THAT SOMETHING IN THE BIND CONFIG HAS CHANGED. REBUILD froxlor_bind.conf IF BIND IS ENABLED
	 */
	elseif ($row['type'] == '4' && (int)$settings['system']['bind_enable'] != 0)
	{
		if(!isset($nameserver))
		{
			$nameserver = new bind($db, $cronlog, $debugHandler, $settings);
		}

		if($settings['dkim']['use_dkim'] == '1')
		{
			$nameserver->writeDKIMconfigs();
		}

		$nameserver->writeConfigs();
	}

	/**
	 * TYPE=5 MEANS THAT A NEW FTP-ACCOUNT HAS BEEN CREATED, CREATE THE DIRECTORY
	 */
	elseif ($row['type'] == '5')
	{
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Creating new FTP-home');
		$result_directories = $db->query('SELECT `f`.`homedir`, `f`.`uid`, `f`.`gid`, `c`.`documentroot` AS `customerroot` FROM `' . TABLE_FTP_USERS . '` `f` LEFT JOIN `' . TABLE_PANEL_CUSTOMERS . '` `c` USING (`customerid`) WHERE `f`.`username` NOT LIKE \'%_backup\'');

		while($directory = $db->fetch_array($result_directories))
		{
			mkDirWithCorrectOwnership($directory['customerroot'], $directory['homedir'], $directory['uid'], $directory['gid']);
		}
	}

	/**
	 * TYPE=6 MEANS THAT A CUSTOMER HAS BEEN DELETED AND THAT WE HAVE TO REMOVE ITS FILES
	 */
	elseif ($row['type'] == '6')
	{
		fwrite($debugHandler, '  cron_tasks: Task6 started - deleting customer data' . "\n");
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Task6 started - deleting customer data');

		if(is_array($row['data']))
		{
			if(isset($row['data']['loginname']))
			{
				/*
				 * remove homedir
				 */
				$homedir = makeCorrectDir($settings['system']['documentroot_prefix'] . '/' . $row['data']['loginname']);

				if($homedir != '/'
				&& $homedir != $settings['system']['documentroot_prefix']
				&& substr($homedir, 0, strlen($settings['system']['documentroot_prefix'])) == $settings['system']['documentroot_prefix'])
				{
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($homedir));
					safe_exec('rm -rf '.escapeshellarg($homedir));
				}
				
				/*
				 * remove backup dir
				 */
				$backupdir = makeCorrectDir($settings['system']['backup_dir'] . $row['data']['loginname']);

				if($backupdir != '/'
				&& $backupdir != $settings['system']['backup_dir']
				&& substr($backupdir, 0, strlen($settings['system']['backup_dir'])) == $settings['system']['backup_dir'])
				{
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($backupdir));
					safe_exec('rm -rf '.escapeshellarg($backupdir));
				}
				
				/*
				 * remove maildir
				 */
				$maildir = makeCorrectDir($settings['system']['vmail_homedir'] . '/' . $row['data']['loginname']);

				if($maildir != '/'
				&& $maildir != $settings['system']['vmail_homedir']
				&& substr($maildir, 0, strlen($settings['system']['vmail_homedir'])) == $settings['system']['vmail_homedir']
				&& is_dir($maildir)
				&& fileowner($maildir) == $settings['system']['vmail_uid']
				&& filegroup($maildir) == $settings['system']['vmail_gid'])
				{
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir));
					safe_exec('rm -rf '.escapeshellarg($maildir));
				}

				/*
				 * remove tmpdir if it exists
				 */
				$tmpdir = makeCorrectDir($settings['system']['mod_fcgid_tmpdir'] . '/' . $row['data']['loginname'] . '/');

				if (is_dir($tmpdir)
				&& $tmpdir != "/"
				&& $tmpdir != $settings['system']['mod_fcgid_tmpdir']
				&& substr($tmpdir, 0, strlen($settings['system']['mod_fcgid_tmpdir'])) == $settings['system']['mod_fcgid_tmpdir'])
				{
					 $cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($tmpdir));
					 safe_exec('rm -rf '.escapeshellarg($tmpdir));
				}

				/*
				 * see if we have some php-fcgid leftovers if used
				 * and remove them, #200
				 * UPDATE: this is being done in ConfigIO::cleanUp()
				 */

				/**
				 * webserver logs
				 */
				$logsdir = makeCorrectFile($settings['system']['logfiles_directory'].'/'.$row['data']['loginname']);
				if ($logsdir != '/'
					&& $logsdir != makeCorrectDir($settings['system']['logfiles_directory'])
					&& substr($logsdir, 0, strlen($settings['system']['logfiles_directory'])) == $settings['system']['logfiles_directory']
				) {
					// build up wildcard for webX-{access,error}.log{*}
					$logfiles = $logsdir.'-*';
					safe_exec('rm -f '.escapeshellarg($logfiles));
				}

			}
		}
	}

	/**
	 * TYPE=7 Customer deleted an email account and wants the data to be deleted on the filesystem
	 */
	elseif ($row['type'] == '7')
	{
		fwrite($debugHandler, '  cron_tasks: Task7 started - deleting customer e-mail data' . "\n");
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Task7 started - deleting customer e-mail data');

		if(is_array($row['data']))
		{
			if(isset($row['data']['loginname'])
				&& isset($row['data']['email'])
			) {
				/*
				 * remove specific maildir
				 */
				$email_full = $row['data']['email'];
				if (empty($email_full)) {
					$cronlog->logAction(CRON_ACTION, LOG_ERROR, 'FATAL: Task7 asks to delete a email account but email field is empty!');
				}
				$email_user=substr($email_full,0,strrpos($email_full,"@"));
				$email_domain=substr($email_full,strrpos($email_full,"@")+1);
				$maildirname=trim($settings['system']['vmail_maildirname']);
				// Add trailing slash to Maildir if needed
				$maildirpath=$maildirname;
				if (!empty($maildirname) and substr($maildirname,-1) != "/") $maildirpath.="/";
				$maildir = makeCorrectDir($settings['system']['vmail_homedir'] .'/'. $row['data']['loginname'] .'/'. $email_domain .'/'. $email_user);

				if($maildir != '/' && !empty($maildir) && !empty($email_full)
				&& $maildir != $settings['system']['vmail_homedir']
				&& substr($maildir, 0, strlen($settings['system']['vmail_homedir'])) == $settings['system']['vmail_homedir']
				&& is_dir($maildir) 
				&& is_dir(makeCorrectDir($maildir.'/'.$maildirpath))
				&& fileowner($maildir) == $settings['system']['vmail_uid']
				&& filegroup($maildir) == $settings['system']['vmail_gid'])
				{
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir));
					safe_exec('rm -rf '.escapeshellarg($maildir));
				}
				else {
					// backward-compatibility for old folder-structure
					$maildir_old = makeCorrectDir($settings['system']['vmail_homedir'] .'/'. $row['data']['loginname'] .'/'. $row['data']['email']);

					if ($maildir_old != '/' && !empty($maildir_old)
						&& $maildir_old != $settings['system']['vmail_homedir']
						&& substr($maildir_old, 0, strlen($settings['system']['vmail_homedir'])) == $settings['system']['vmail_homedir']
						&& is_dir($maildir_old)
						&& fileowner($maildir_old) == $settings['system']['vmail_uid']
						&& filegroup($maildir_old) == $settings['system']['vmail_gid'])
					{
						$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($maildir_old));
						safe_exec('rm -rf '.escapeshellarg($maildir_old));
					}
				}
			}
		}
	}

	/**
	 * TYPE=8 Customer deleted a ftp account and wants the homedir to be deleted on the filesystem
	 * refs #293
	 */
	elseif ($row['type'] == '8')
	{
		fwrite($debugHandler, '  cron_tasks: Task8 started - deleting customer ftp homedir' . "\n");
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Task8 started - deleting customer ftp homedir');

		if(is_array($row['data']))
		{
			if(isset($row['data']['loginname'])
				&& isset($row['data']['homedir'])
			) {
				/*
				 * remove specific homedir
				 */
				$ftphomedir = makeCorrectDir($row['data']['homedir']);
				$customerdocroot = makeCorrectDir($settings['system']['documentroot_prefix'].'/'.$row['data']['loginname'].'/');

				if($ftphomedir != '/'
				&& $ftphomedir != $settings['system']['documentroot_prefix']
				&& $ftphomedir != $customerdocroot
				) {
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, 'Running: rm -rf ' . escapeshellarg($ftphomedir));
					safe_exec('rm -rf '.escapeshellarg($ftphomedir));
				}
			}
		}
	}

	/**
	 * TYPE=10 Set the filesystem - quota
	 */
	elseif ($row['type'] == '10' && (int)$settings['system']['diskquota_enabled'] != 0) {

		fwrite($debugHandler, '  cron_tasks: Task10 started - setting filesystem quota' . "\n");
		$cronlog->logAction(CRON_ACTION, LOG_INFO, 'Task10 started - setting filesystem quota');

		$usedquota = getFilesystemQuota();

		// Select all customers Froxlor knows about
		$result = $db->query("SELECT `guid`, `loginname`, `diskspace` FROM `" . TABLE_PANEL_CUSTOMERS . "`;");
		while ($row = $db->fetch_array($result)) {
			// We do not want to set a quota for root by accident
			if ($row['guid'] != 0) {
				// The user has no quota in Froxlor, but on the filesystem
				if (($row['diskspace'] == 0 || $row['diskspace'] == -1024) 
						&& $usedquota[$row['guid']]['block']['hard'] != 0
				) {
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, "Disabling quota for " . $row['loginname']);
					safe_exec($settings['system']['diskquota_quotatool_path'] . " -u " . $row['guid'] . " -bl 0 -q 0 " . escapeshellarg($settings['system']['diskquota_customer_partition']));
				}
				// The user quota in Froxlor is different than on the filesystem
				elseif ($row['diskspace'] != $usedquota[$row['guid']]['block']['hard'] 
						&& $row['diskspace'] != -1024
				) {
					$cronlog->logAction(CRON_ACTION, LOG_NOTICE, "Setting quota for " . $row['loginname'] . " from " . $usedquota[$row['guid']]['block']['hard'] . " to " . $row['diskspace']);
					safe_exec($settings['system']['diskquota_quotatool_path'] . " -u " . $row['guid'] . " -bl " . $row['diskspace'] . " -q " . $row['diskspace'] . " " . escapeshellarg($settings['system']['diskquota_customer_partition']));
				}
			}
		}
	}
}

if ($db->num_rows($result_tasks) != 0) {
	$where = array();
	foreach ($resultIDs as $id) {
		$where[] = '`id`=\'' . (int)$id . '\'';
	}
	$where = implode($where, ' OR ');
	$db->query('DELETE FROM `' . TABLE_PANEL_TASKS . '` WHERE ' . $where);
	unset($resultIDs);
	unset($where);
}

$db->query('UPDATE `' . TABLE_PANEL_SETTINGS . '` SET `value` = UNIX_TIMESTAMP() WHERE `settinggroup` = \'system\'   AND `varname` = \'last_tasks_run\' ');
