<?php
namespace Froxlor\Cron\Http\LetsEncrypt;

use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\Database\Database;
use Froxlor\PhpHelper;
use Froxlor\Domain\Domain;
use Froxlor\FileDir;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2016 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Florian Aders <kontakt-froxlor@neteraser.de>
 * @author Froxlor team <team@froxlor.org> (2016-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Cron
 *         
 * @since 0.9.35
 *       
 */
class AcmeSh extends \Froxlor\Cron\FroxlorCron
{

	const ACME_PROVIDER = [
		'letsencrypt' => "https://acme-v02.api.letsencrypt.org/directory",
		'letsencrypt_test' => "https://acme-staging-v02.api.letsencrypt.org/directory",
		'buypass' => "https://api.buypass.com/acme/directory",
		'buypass_test' => "https://api.test4.buypass.no/acme/directory",
		'zerossl' => "https://acme.zerossl.com/v2/DV90"
	];

	private static $apiserver = "";

	private static $acmesh = "/root/.acme.sh/acme.sh";

	/**
	 *
	 * @var \PDOStatement
	 */
	private static $updcert_stmt = null;

	/**
	 *
	 * @var \PDOStatement
	 */
	private static $upddom_stmt = null;

	public static $no_inserttask = false;

	/**
	 * run the task
	 *
	 * @param boolean $internal
	 * @return number
	 */
	public static function run($internal = false)
	{
		// usually, this is action is called from within the tasks-jobs
		if (! defined('CRON_IS_FORCED') && ! defined('CRON_DEBUG_FLAG') && $internal == false) {
			// Let's Encrypt cronjob is combined with regeneration of webserver configuration files.
			// For debugging purposes you can use the --debug switch and the --force switch to run the cron manually.
			// check whether we MIGHT need to run although there is no task to regenerate config-files
			$issue_froxlor = self::issueFroxlorVhost();
			$issue_domains = self::issueDomains();
			$renew_froxlor = self::renewFroxlorVhost();
			$renew_domains = self::renewDomains(true);
			if ($issue_froxlor || ! empty($issue_domains) || ! empty($renew_froxlor) || $renew_domains) {
				// insert task to generate certificates and vhost-configs
				\Froxlor\System\Cronjob::inserttask(1);
			}
			return 0;
		}

		// set server according to settings
		self::$apiserver = self::ACME_PROVIDER[Settings::Get('system.letsencryptca')];

		// validate acme.sh installation
		if (! self::checkInstall()) {
			return - 1;
		}

		self::checkUpgrade();

		// flag for re-generation of vhost files
		$changedetected = 0;

		// prepare update sql
		self::$updcert_stmt = Database::prepare("
			REPLACE INTO
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
			SET
				`id` = :id,
				`domainid` = :domainid,
				`ssl_cert_file` = :crt,
				`ssl_key_file` = :key,
				`ssl_ca_file` = :ca,
				`ssl_cert_chainfile` = :chain,
				`ssl_csr_file` = :csr,
				`ssl_fullchain_file` = :fullchain,
				`expirationdate` = :expirationdate
		");

		// prepare domain update sql
		self::$upddom_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `ssl_redirect` = '1' WHERE `id` = :domainid");

		// check whether there are certificates to issue
		$issue_froxlor = self::issueFroxlorVhost();
		$issue_domains = self::issueDomains();

		// first - generate LE for system-vhost if enabled
		if ($issue_froxlor) {
			// build row
			$certrow = array(
				'loginname' => 'froxlor.panel',
				'domain' => Settings::Get('system.hostname'),
				'domainid' => 0,
				'documentroot' => \Froxlor\Froxlor::getInstallDir(),
				'leprivatekey' => Settings::Get('system.leprivatekey'),
				'lepublickey' => Settings::Get('system.lepublickey'),
				'leregistered' => Settings::Get('system.leregistered'),
				'ssl_redirect' => Settings::Get('system.le_froxlor_redirect'),
				'expirationdate' => null,
				'ssl_cert_file' => null,
				'ssl_key_file' => null,
				'ssl_ca_file' => null,
				'ssl_csr_file' => null,
				'id' => null
			);

			// add to queue
			$issue_domains[] = $certrow;
		}

		if (count($issue_domains)) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Requesting " . count($issue_domains) . " new Let's Encrypt certificates");
			self::runIssueFor($issue_domains);
			$changedetected = 1;
		}

		// compare file-system certificates with the ones in our database
		// and update if needed
		$renew_froxlor = self::renewFroxlorVhost();
		$renew_domains = self::renewDomains();

		if ($renew_froxlor) {
			// build row
			$certrow = array(
				'loginname' => 'froxlor.panel',
				'domain' => Settings::Get('system.hostname'),
				'domainid' => 0,
				'documentroot' => \Froxlor\Froxlor::getInstallDir(),
				'leprivatekey' => Settings::Get('system.leprivatekey'),
				'lepublickey' => Settings::Get('system.lepublickey'),
				'leregistered' => Settings::Get('system.leregistered'),
				'ssl_redirect' => Settings::Get('system.le_froxlor_redirect'),
				'expirationdate' => is_array($renew_froxlor) ? $renew_froxlor['expirationdate'] : date('Y-m-d H:i:s', 0),
				'ssl_cert_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_cert_file'] : null,
				'ssl_key_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_key_file'] : null,
				'ssl_ca_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_ca_file'] : null,
				'ssl_csr_file' => is_array($renew_froxlor) ? $renew_froxlor['ssl_csr_file'] : null,
				'id' => is_array($renew_froxlor) ? $renew_froxlor['id'] : null
			);
			$renew_domains[] = $certrow;
		}

		foreach ($renew_domains as $domain) {
			$cronlog = FroxlorLogger::getInstanceOf(array(
				'loginname' => $domain['loginname'],
				'adminsession' => 0
			));
			if (defined('CRON_IS_FORCED') || self::checkFsFilesAreNewer($domain['domain'], $domain['expirationdate'])) {
				self::certToDb($domain, $cronlog, array());
				$changedetected = 1;
			}
		}

		// If we have a change in a certificate, we need to update the webserver - configs
		// This is easiest done by just creating a new task ;)
		if ($changedetected) {
			if (self::$no_inserttask == false) {
				\Froxlor\System\Cronjob::inserttask(1);
			}
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Let's Encrypt certificates have been updated");
		} else {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "No new certificates or certificate updates found");
		}
	}

	/**
	 * issue certificates for a list of domains
	 */
	private static function runIssueFor($certrows = array())
	{
		// prepare aliasdomain-check
		$aliasdomains_stmt = Database::prepare("
			SELECT
				dom.`id` as domainid,
				dom.`domain`,
				dom.`wwwserveralias`
			FROM `" . TABLE_PANEL_DOMAINS . "` AS dom
			WHERE
				dom.`aliasdomain` = :id
				AND dom.`letsencrypt` = 1
				AND dom.`iswildcarddomain` = 0
		");
		// iterate through all domains
		foreach ($certrows as $certrow) {
			// set logger to corresponding loginname for the log to appear in the users system-log
			$cronlog = FroxlorLogger::getInstanceOf(array(
				'loginname' => $certrow['loginname'],
				'adminsession' => 0
			));
			// Only issue let's encrypt certificate if no broken ssl_redirect is enabled
			if ($certrow['ssl_redirect'] != 2) {
				$do_force = false;
				if (! empty($certrow['ssl_cert_file']) && empty($certrow['expirationdate'])) {
					// domain changed (SAN or similar)
					$do_force = true;
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Re-creating certificate for " . $certrow['domain']);
				} else {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Creating certificate for " . $certrow['domain']);
				}
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding common-name: " . $certrow['domain']);
				$domains = array(
					strtolower($certrow['domain'])
				);
				// add www.<domain> to SAN list
				if ($certrow['wwwserveralias'] == 1) {
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $certrow['domain']);
					$domains[] = strtolower('www.' . $certrow['domain']);
				}
				if ($certrow['domainid'] == 0) {
					$froxlor_aliases = Settings::Get('system.froxloraliases');
					if (! empty($froxlor_aliases)) {
						$froxlor_aliases = explode(",", $froxlor_aliases);
						foreach ($froxlor_aliases as $falias) {
							if (\Froxlor\Validate\Validate::validateDomain(trim($falias))) {
								$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . strtolower(trim($falias)));
								$domains[] = strtolower(trim($falias));
							}
						}
					}
				} else {
					// add alias domains (and possibly www.<aliasdomain>) to SAN list
					Database::pexecute($aliasdomains_stmt, array(
						'id' => $certrow['domainid']
					));
					$aliasdomains = $aliasdomains_stmt->fetchAll(\PDO::FETCH_ASSOC);
					foreach ($aliasdomains as $aliasdomain) {
						$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: " . $aliasdomain['domain']);
						$domains[] = strtolower($aliasdomain['domain']);
						if ($aliasdomain['wwwserveralias'] == 1) {
							$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Adding SAN entry: www." . $aliasdomain['domain']);
							$domains[] = strtolower('www.' . $aliasdomain['domain']);
						}
					}
				}

				self::validateDns($domains, $certrow['domainid'], $cronlog);

				self::runAcmeSh($certrow, $domains, $cronlog, $do_force);
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Skipping Let's Encrypt generation for " . $certrow['domain'] . " due to an enabled ssl_redirect");
			}
		}
	}

	/**
	 * validate dns (A / AAAA record) of domain against known system ips
	 *
	 * @param array $domains
	 * @param int $domain_id
	 * @param FroxlorLogger $cronlog
	 */
	private static function validateDns(array &$domains, $domain_id, &$cronlog)
	{
		if (Settings::Get('system.le_domain_dnscheck') == '1' && ! empty($domains)) {
			$loop_domains = $domains;
			// ips according to our system
			$our_ips = Domain::getIpsOfDomain($domain_id);
			foreach ($loop_domains as $idx => $domain) {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Validating DNS of " . $domain);
				// ips according to NS
				$domain_ips = PhpHelper::gethostbynamel6($domain);
				if ($domain_ips == false || count(array_intersect($our_ips, $domain_ips)) <= 0) {
					// no common ips...
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Skipping Let's Encrypt generation for " . $domain . " due to no system known IP address via DNS check");
					unset($domains[$idx]);
					// in order to avoid a cron-loop that tries to get a certificate every 5 minutes, we disable let's encrypt for this domain
					$upd_stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `letsencrypt` = '0' WHERE `id` = :did");
					Database::pexecute($upd_stmt, [
						'did' => $domain_id
					]);
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "Let's Encrypt deactivated for domain " . $domain);
				}
			}
		}
	}

	private static function runAcmeSh(array $certrow, array $domains, &$cronlog = null, $force = false)
	{
		if (! empty($domains)) {

			$acmesh_cmd = self::$acmesh . " --server " . self::$apiserver . " --issue -d " . implode(" -d ", $domains);
			// challenge path
			$acmesh_cmd .= " -w " . Settings::Get('system.letsencryptchallengepath');
			if (Settings::Get('system.leecc') > 0) {
				// ecc certificate
				$acmesh_cmd .= " --keylength ec-" . Settings::Get('system.leecc');
			} else {
				$acmesh_cmd .= " --keylength " . Settings::Get('system.letsencryptkeysize');
			}
			if (Settings::Get('system.letsencryptreuseold') != '1') {
				$acmesh_cmd .= " --always-force-new-domain-key";
			}
			if (Settings::Get('system.letsencryptca') == 'letsencrypt_test') {
				$acmesh_cmd .= " --staging";
			}
			if ($force) {
				$acmesh_cmd .= " --force";
			}
			if (defined('CRON_DEBUG_FLAG')) {
				$acmesh_cmd .= " --debug";
			}

			$acme_result = \Froxlor\FileDir::safe_exec($acmesh_cmd);
			// debug output of acme.sh run
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_DEBUG, implode("\n", $acme_result));

			self::certToDb($certrow, $cronlog, $acme_result);
		}
	}

	private static function certToDb($certrow, &$cronlog, $acme_result)
	{
		$return = array();
		self::readCertificateToVar(strtolower($certrow['domain']), $return, $cronlog);

		if (! empty($return['crt'])) {

			$newcert = openssl_x509_parse($return['crt']);

			if ($newcert) {
				// Store the new data
				Database::pexecute(self::$updcert_stmt, array(
					'id' => $certrow['id'],
					'domainid' => $certrow['domainid'],
					'crt' => $return['crt'],
					'key' => $return['key'],
					'ca' => $return['chain'],
					'chain' => $return['chain'],
					'csr' => $return['csr'],
					'fullchain' => $return['fullchain'],
					'expirationdate' => date('Y-m-d H:i:s', $newcert['validTo_time_t'])
				));

				if ($certrow['ssl_redirect'] == 3) {
					Database::pexecute(self::$upddom_stmt, array(
						'domainid' => $certrow['domainid']
					));
				}

				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Updated Let's Encrypt certificate for " . $certrow['domain']);
			} else {
				$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Got non-successful Let's Encrypt response for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
			}
		} else {
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not get Let's Encrypt certificate for " . $certrow['domain'] . ":\n" . implode("\n", $acme_result));
		}
	}

	/**
	 * check whether we need to issue a new certificate for froxlor itself
	 *
	 * @return boolean
	 */
	private static function issueFroxlorVhost()
	{
		if (Settings::Get('system.le_froxlor_enabled') == '1') {
			// let's encrypt is enabled, now check whether we have a certificate
			$froxlor_ssl_settings_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
				WHERE `domainid` = '0'
			");
			$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
			// also check for possible existing certificate
			if (! $froxlor_ssl && ! self::checkFsFilesAreNewer(Settings::Get('system.hostname'), date('Y-m-d H:i:s'))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * check whether we need to renew-check the certificate for froxlor itself
	 *
	 * @return boolean
	 */
	private static function renewFroxlorVhost()
	{
		if (Settings::Get('system.le_froxlor_enabled') == '1') {
			// let's encrypt is enabled, now check whether we have a certificate
			$froxlor_ssl_settings_stmt = Database::prepare("
				SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
				WHERE `domainid` = '0'
			");
			$froxlor_ssl = Database::pexecute_first($froxlor_ssl_settings_stmt);
			// also check for possible existing certificate
			if ($froxlor_ssl && self::checkFsFilesAreNewer(Settings::Get('system.hostname'), $froxlor_ssl['expirationdate'])) {
				return $froxlor_ssl;
			}
		}
		return false;
	}

	/**
	 * get a list of domains that have a lets encrypt certificate (possible renew)
	 */
	private static function renewDomains($check = false)
	{
		$certificates_stmt = Database::query("
			SELECT
				domssl.`id`,
				domssl.`domainid`,
				domssl.`expirationdate`,
				domssl.`ssl_cert_file`,
				domssl.`ssl_key_file`,
				dom.`domain`,
				dom.`id` AS 'domainid',
				dom.`ssl_redirect`,
				cust.`loginname`
			FROM
				`" . TABLE_PANEL_CUSTOMERS . "` AS cust,
				`" . TABLE_PANEL_DOMAINS . "` AS dom
			LEFT JOIN
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` AS domssl ON
					dom.`id` = domssl.`domainid`
			WHERE
				dom.`customerid` = cust.`customerid`
				AND cust.deactivated = 0
				AND dom.`letsencrypt` = 1
				AND dom.`aliasdomain` IS NULL
				AND dom.`iswildcarddomain` = 0
		");
		$renew_certs = $certificates_stmt->fetchAll(\PDO::FETCH_ASSOC);
		if ($renew_certs) {
			if ($check) {
				foreach ($renew_certs as $cert) {
					if (self::checkFsFilesAreNewer($cert['domain'], $cert['expirationdate'])) {
						return true;
					}
				}
				return false;
			}
			return $renew_certs;
		}
		return array();
	}

	/**
	 * get a list of domains that require a new certificate (issue)
	 */
	private static function issueDomains()
	{
		$certificates_stmt = Database::query("
			SELECT
				domssl.`id`,
				domssl.`domainid`,
				domssl.`expirationdate`,
				domssl.`ssl_cert_file`,
				domssl.`ssl_key_file`,
				domssl.`ssl_ca_file`,
				domssl.`ssl_csr_file`,
				dom.`domain`,
				dom.`wwwserveralias`,
				dom.`documentroot`,
				dom.`id` AS 'domainid',
				dom.`ssl_redirect`,
				cust.`leprivatekey`,
				cust.`lepublickey`,
				cust.`leregistered`,
				cust.`customerid`,
				cust.`loginname`
			FROM
				`" . TABLE_PANEL_CUSTOMERS . "` AS cust,
				`" . TABLE_PANEL_DOMAINS . "` AS dom
			LEFT JOIN
				`" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` AS domssl ON
					dom.`id` = domssl.`domainid`
			WHERE
				dom.`customerid` = cust.`customerid`
				AND cust.deactivated = 0
				AND dom.`letsencrypt` = 1
				AND dom.`aliasdomain` IS NULL
				AND dom.`iswildcarddomain` = 0
				AND domssl.`expirationdate` IS NULL
		");
		$customer_ssl = $certificates_stmt->fetchAll(\PDO::FETCH_ASSOC);
		if ($customer_ssl) {
			return $customer_ssl;
		}
		return array();
	}

	private static function checkFsFilesAreNewer($domain, $cert_date = 0)
	{
		$certificate_folder = self::getWorkingDirFromEnv(strtolower($domain));
		$ssl_file = \Froxlor\FileDir::makeCorrectFile($certificate_folder . '/' . strtolower($domain) . '.cer');

		if (is_dir($certificate_folder) && file_exists($ssl_file) && is_readable($ssl_file)) {
			$cert_data = openssl_x509_parse(file_get_contents($ssl_file));
			if ($cert_data && $cert_data['validTo_time_t'] > strtotime($cert_date)) {
				return true;
			}
		}
		return false;
	}

	public static function getWorkingDirFromEnv($domain = "", $forced_noecc = false)
	{
		if (Settings::Get('system.leecc') > 0 && ! $forced_noecc) {
			$domain .= "_ecc";
		}
		$env_file = FileDir::makeCorrectFile(dirname(self::$acmesh) . '/acme.sh.env');
		if (file_exists($env_file)) {
			$output = [];
$cut = <<<EOC
cut -d'"' -f2
EOC;
			exec('grep "LE_WORKING_DIR" ' . escapeshellarg($env_file) . ' | ' . $cut, $output);
			if (is_array($output) && ! empty($output) && isset($output[0]) && ! empty($output[0])) {
				return FileDir::makeCorrectDir($output[0] . "/" . $domain);
			}
		}
		return FileDir::makeCorrectDir(dirname(self::$acmesh) . "/" . $domain);
	}

	public static function getAcmeSh()
	{
		return self::$acmesh;
	}

	/**
	 * get certificate files from filesystem and store in $return array
	 *
	 * @param string $domain
	 * @param array $return
	 * @param object $cronlog
	 */
	private static function readCertificateToVar($domain, &$return, &$cronlog)
	{
		$certificate_folder = self::getWorkingDirFromEnv($domain);
		$certificate_folder_noecc = null;
		if (Settings::Get('system.leecc') > 0) {
			$certificate_folder_noecc = self::getWorkingDirFromEnv($domain, true);
		}
		$certificate_folder = \Froxlor\FileDir::makeCorrectDir($certificate_folder);

		if (is_dir($certificate_folder) || is_dir($certificate_folder_noecc)) {
			foreach ([
				'crt' => $domain . '.cer',
				'key' => $domain . '.key',
				'chain' => 'ca.cer',
				'fullchain' => 'fullchain.cer',
				'csr' => $domain . '.csr'
			] as $index => $sslfile) {
				$ssl_file = \Froxlor\FileDir::makeCorrectFile($certificate_folder . '/' . $sslfile);
				if (file_exists($ssl_file)) {
					$return[$index] = file_get_contents($ssl_file);
				} else {
					if (! empty($certificate_folder_noecc)) {
						$ssl_file_fb = \Froxlor\FileDir::makeCorrectFile($certificate_folder_noecc . '/' . $sslfile);
						if (file_exists($ssl_file_fb)) {
							$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, "ECC certificates activated but found only non-ecc file");
							$return[$index] = file_get_contents($ssl_file_fb);
							continue;
						}
					}
					$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not find file '" . $sslfile . "' in '" . $certificate_folder . "'");
					$return[$index] = null;
				}
			}
		} else {
			$cronlog->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Could not find certificate-folder '" . $certificate_folder . "'");
		}
	}

	/**
	 * install acme.sh if not found yet
	 */
	private static function checkInstall($tries = 0)
	{
		if (! file_exists(self::$acmesh) && $tries > 0) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_ERR, "Download/installation of acme.sh seems to have failed. Re-run cronjob to try again or install manually to '" . self::$acmesh . "'");
			echo PHP_EOL . "Download/installation of acme.sh seems to have failed. Re-run cronjob to try again or install manually to '" . self::$acmesh . "'" . PHP_EOL;
			return false;
		} else if (! file_exists(self::$acmesh)) {
			FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Could not find acme.sh - installing it to /root/.acme.sh/");
			$return = false;
			\Froxlor\FileDir::safe_exec("wget -O - https://get.acme.sh | sh", $return, array(
				'|'
			));
			// check whether the installation worked
			return self::checkInstall(++ $tries);
		}
		return true;
	}

	/**
	 * run upgrade
	 */
	private static function checkUpgrade()
	{
		$acmesh_result = \Froxlor\FileDir::safe_exec(self::$acmesh . " --upgrade --auto-upgrade 0");
		// check for activated cron
		$acmesh_result2 = \Froxlor\FileDir::safe_exec(self::$acmesh . " --install-cronjob");
		FroxlorLogger::getInstanceOf()->logAction(FroxlorLogger::CRON_ACTION, LOG_INFO, "Checking for LetsEncrypt client upgrades before renewing certificates:\n" . implode("\n", $acmesh_result) . "\n" . implode("\n", $acmesh_result2));
	}
}
