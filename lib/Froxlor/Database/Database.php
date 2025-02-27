<?php
namespace Froxlor\Database;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Michael Kaufmann <mkaufmann@nutime.de>
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Classes
 *         
 * @since 0.9.31
 *       
 */

/**
 * Class Database
 *
 * Wrapper-class for PHP-PDO
 *
 * @copyright (c) the authors
 * @author Michael Kaufmann <mkaufmann@nutime.de>
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Classes
 *         
 * @method static \PDOStatement prepare($statement, array $driver_options = null) Prepares a statement for execution and returns a statement object
 * @method static \PDOStatement query ($statement) Executes an SQL statement, returning a result set as a PDOStatement object
 * @method static string lastInsertId ($name = null) Returns the ID of the last inserted row or sequence value
 * @method static string quote ($string, $parameter_type = null) Quotes a string for use in a query.
 */
class Database
{

	/**
	 * current database link
	 *
	 * @var object
	 */
	private static $link = null;

	/**
	 * indicator whether to use root-connection or not
	 */
	private static $needroot = false;

	/**
	 * indicator which database-server we're on (not really used)
	 */
	private static $dbserver = 0;

	/**
	 * used database-name
	 */
	private static $dbname = null;

	/**
	 * sql-access data
	 */
	private static $needsqldata = false;

	private static $sqldata = null;

	/**
	 * Wrapper for PDOStatement::execute so we can catch the PDOException
	 * and display the error nicely on the panel
	 *
	 * @param \PDOStatement $stmt
	 * @param array $params
	 *        	(optional)
	 * @param bool $showerror
	 *        	suppress errordisplay (default true)
	 */
	public static function pexecute(&$stmt, $params = null, $showerror = true, $json_response = false)
	{
		try {
			$stmt->execute($params);
		} catch (\PDOException $e) {
			self::showerror($e, $showerror, $json_response, $stmt);
		}
	}

	/**
	 * Wrapper for PDOStatement::execute so we can catch the PDOException
	 * and display the error nicely on the panel - also fetches the
	 * result from the statement and returns the resulting array
	 *
	 * @param \PDOStatement $stmt
	 * @param array $params
	 *        	(optional)
	 * @param bool $showerror
	 *        	suppress errordisplay (default true)
	 *        	
	 * @return array
	 */
	public static function pexecute_first(&$stmt, $params = null, $showerror = true, $json_response = false)
	{
		self::pexecute($stmt, $params, $showerror, $json_response);
		return $stmt->fetch(\PDO::FETCH_ASSOC);
	}

	/**
	 * returns the number of found rows of the last select query
	 *
	 * @return int
	 */
	public static function num_rows()
	{
		return Database::query("SELECT FOUND_ROWS()")->fetchColumn();
	}

	/**
	 * returns the database-name which is used
	 *
	 * @return string
	 */
	public static function getDbName()
	{
		return self::$dbname;
	}

	/**
	 * enabled the usage of a root-connection to the database
	 * Note: must be called *before* any prepare/query/etc.
	 * and should be called again with 'false'-parameter to resume
	 * the 'normal' database-connection
	 *
	 * @param bool $needroot
	 * @param int $dbserver
	 *        	optional
	 */
	public static function needRoot($needroot = false, $dbserver = 0)
	{
		// force re-connecting to the db with corresponding user
		// and set the $dbserver (mostly to 0 = default)
		self::setServer($dbserver);
		self::$needroot = $needroot;
	}

	/**
	 * enable the temporary access to sql-access data
	 * note: if you want root-sqldata you need to
	 * call needRoot(true) first.
	 * Also, this will
	 * only give you the data ONCE as it disable itself
	 * after the first access to the data
	 */
	public static function needSqlData()
	{
		self::$needsqldata = true;
		self::$sqldata = array();
		self::$link = null;
		// we need a connection here because
		// if getSqlData() is called RIGHT after
		// this function and no "real" PDO
		// function was called, getDB() wasn't
		// involved and no data collected
		self::getDB();
	}

	/**
	 * returns the sql-access data as array using indices
	 * 'user', 'passwd' and 'host'.
	 * Returns false if not enabled
	 *
	 * @return array|bool
	 */
	public static function getSqlData()
	{
		$return = false;
		if (self::$sqldata !== null && is_array(self::$sqldata) && isset(self::$sqldata['user'])) {
			$return = self::$sqldata;
			// automatically disable sql-data
			self::$sqldata = null;
			self::$needsqldata = false;
		}
		return $return;
	}

	/**
	 * return number of characters that are allowed to use as username
	 *
	 * @return int
	 */
	public static function getSqlUsernameLength()
	{
		// MySQL user names can be up to 32 characters long (16 characters before MySQL 5.7.8).
		$mysql_max = 32;
		if (version_compare(Database::getAttribute(\PDO::ATTR_SERVER_VERSION), '5.7.8', '<')) {
			$mysql_max = 16;
		}
		return $mysql_max;
	}

	/**
	 * let's us interact with the PDO-Object by using static
	 * call like "Database::function()"
	 *
	 * @param string $name
	 * @param mixed $args
	 *
	 * @return mixed
	 */
	public static function __callStatic($name, $args)
	{
		$callback = array(
			self::getDB(),
			$name
		);
		$result = null;
		try {
			$result = call_user_func_array($callback, $args);
		} catch (\PDOException $e) {
			self::showerror($e);
		}
		return $result;
	}

	/**
	 * set the database-server (relevant for root-connection)
	 *
	 * @param int $dbserver
	 */
	private static function setServer($dbserver = 0)
	{
		self::$dbserver = $dbserver;
		self::$link = null;
	}

	/**
	 * function that will be called on every static call
	 * which connects to the database if necessary
	 *
	 * @param bool $root
	 *
	 * @return object
	 */
	private static function getDB()
	{
		if (! extension_loaded('pdo') || in_array("mysql", \PDO::getAvailableDrivers()) == false) {
			self::showerror(new \Exception("The php PDO extension or PDO-MySQL driver is not available"));
		}

		// do we got a connection already?
		if (self::$link) {
			// return it
			return self::$link;
		}

		// include userdata.inc.php
		require \Froxlor\Froxlor::getInstallDir() . "/lib/userdata.inc.php";

		// le format
		if (self::$needroot == true && isset($sql['root_user']) && isset($sql['root_password']) && (! isset($sql_root) || ! is_array($sql_root))) {
			$sql_root = array(
				0 => array(
					'caption' => 'Default',
					'host' => $sql['host'],
					'socket' => (isset($sql['socket']) ? $sql['socket'] : null),
					'user' => $sql['root_user'],
					'password' => $sql['root_password']
				)
			);
			unset($sql['root_user']);
			unset($sql['root_password']);
		}

		// either root or unprivileged user
		if (self::$needroot) {
			$caption = $sql_root[self::$dbserver]['caption'];
			$user = $sql_root[self::$dbserver]['user'];
			$password = $sql_root[self::$dbserver]['password'];
			$host = $sql_root[self::$dbserver]['host'];
			$socket = isset($sql_root[self::$dbserver]['socket']) ? $sql_root[self::$dbserver]['socket'] : null;
			$port = isset($sql_root[self::$dbserver]['port']) ? $sql_root[self::$dbserver]['port'] : '3306';
		} else {
			$caption = 'localhost';
			$user = $sql["user"];
			$password = $sql["password"];
			$host = $sql["host"];
			$socket = isset($sql['socket']) ? $sql['socket'] : null;
			$port = isset($sql['port']) ? $sql['port'] : '3306';
		}

		// save sql-access-data if needed
		if (self::$needsqldata) {
			self::$sqldata = array(
				'user' => $user,
				'passwd' => $password,
				'host' => $host,
				'port' => $port,
				'socket' => $socket,
				'db' => $sql["db"],
				'caption' => $caption
			);
		}

		// build up connection string
		$driver = 'mysql';
		$dsn = $driver . ":";
		$options = array(
			'PDO::MYSQL_ATTR_INIT_COMMAND' => 'SET names utf8'
		);
		$attributes = array(
			'ATTR_ERRMODE' => 'ERRMODE_EXCEPTION'
		);

		$dbconf["dsn"] = array(
			'dbname' => $sql["db"],
			'charset' => 'utf8'
		);

		if ($socket != null) {
			$dbconf["dsn"]['unix_socket'] = \Froxlor\FileDir::makeCorrectFile($socket);
		} else {
			$dbconf["dsn"]['host'] = $host;
			$dbconf["dsn"]['port'] = $port;
		}

		self::$dbname = $sql["db"];

		// add options to dsn-string
		foreach ($dbconf["dsn"] as $k => $v) {
			$dsn .= $k . "=" . $v . ";";
		}

		// clean up
		unset($dbconf);

		// try to connect
		try {
			self::$link = new \PDO($dsn, $user, $password, $options);
		} catch (\PDOException $e) {
			self::showerror($e);
		}

		// set attributes
		foreach ($attributes as $k => $v) {
			self::$link->setAttribute(constant("PDO::" . $k), constant("PDO::" . $v));
		}

		$version_server = self::$link->getAttribute(\PDO::ATTR_SERVER_VERSION);
		$sql_mode = 'NO_ENGINE_SUBSTITUTION';
		if (version_compare($version_server, '8.0.11', '<')) {
			$sql_mode .= ',NO_AUTO_CREATE_USER';
		}
		self::$link->exec('SET sql_mode = "' . $sql_mode . '"');

		// return PDO instance
		return self::$link;
	}

	/**
	 * display a nice error if it occurs and log everything
	 *
	 * @param \PDOException $error
	 * @param bool $showerror
	 *        	if set to false, the error will be logged but we go on
	 */
	private static function showerror($error, $showerror = true, $json_response = false, \PDOStatement $stmt = null)
	{
		global $userinfo, $theme, $linker;

		// include userdata.inc.php
		require \Froxlor\Froxlor::getInstallDir() . "/lib/userdata.inc.php";

		// le format
		if (isset($sql['root_user']) && isset($sql['root_password']) && (! isset($sql_root) || ! is_array($sql_root))) {
			$sql_root = array(
				0 => array(
					'caption' => 'Default',
					'host' => $sql['host'],
					'socket' => (isset($sql['socket']) ? $sql['socket'] : null),
					'user' => $sql['root_user'],
					'password' => $sql['root_password']
				)
			);
		}

		$substitutions = array(
			$sql['password'] => 'DB_UNPRIV_PWD',
			$sql_root[0]['password'] => 'DB_ROOT_PWD'
		);

		// hide username/password in messages
		$error_message = $error->getMessage();
		$error_trace = $error->getTraceAsString();
		// error-message
		$error_message = self::substitute($error_message, $substitutions);
		// error-trace
		$error_trace = self::substitute($error_trace, $substitutions);

		if ($error->getCode() == 2003) {
			$error_message = "Unable to connect to database. Either the mysql-server is not running or your user/password is wrong.";
			$error_trace = "";
		}

		/**
		 * log to a file, so we can actually ask people for the error
		 * (no one seems to find the stuff in the syslog)
		 */
		$sl_dir = \Froxlor\FileDir::makeCorrectDir(\Froxlor\Froxlor::getInstallDir() . "/logs/");
		if (! file_exists($sl_dir)) {
			@mkdir($sl_dir, 0755);
		}
		openlog("froxlor", LOG_PID | LOG_PERROR, LOG_LOCAL0);
		syslog(LOG_WARNING, str_replace("\n", " ", $error_message));
		syslog(LOG_WARNING, str_replace("\n", " ", "--- DEBUG: " . $error_trace));
		closelog();

		/**
		 * log error for reporting
		 */
		$errid = substr(md5(microtime()), 5, 5);
		$err_file = \Froxlor\FileDir::makeCorrectFile($sl_dir . "/" . $errid . "_sql-error.log");
		$errlog = @fopen($err_file, 'w');
		@fwrite($errlog, "|CODE " . $error->getCode() . "\n");
		@fwrite($errlog, "|MSG " . $error_message . "\n");
		@fwrite($errlog, "|FILE " . $error->getFile() . "\n");
		@fwrite($errlog, "|LINE " . $error->getLine() . "\n");
		@fwrite($errlog, "|TRACE\n" . $error_trace . "\n");
		@fclose($errlog);

		if (empty($sql['debug'])) {
			$error_trace = '';
		} elseif (! is_null($stmt)) {
			$error_trace .= "<br><br>" . $stmt->queryString;
		}

		if ($showerror && $json_response) {
			$exception_message = $error_message;
			if (isset($sql['debug']) && $sql['debug'] == true) {
				$exception_message .= "\n\n" . $error_trace;
			}
			throw new \Exception($exception_message, 500);
		}

		if ($showerror) {
			// fallback
			$theme = 'Sparkle';

			// clean up sensitive data
			unset($sql);
			unset($sql_root);

			if ((isset($theme) && $theme != '') && ! isset($_SERVER['SHELL']) || (isset($_SERVER['SHELL']) && $_SERVER['SHELL'] == '')) {
				// if we're not on the shell, output a nice error
				$_errtpl = dirname($sl_dir) . '/templates/' . $theme . '/misc/dberrornice.tpl';
				if (file_exists($_errtpl)) {
					$err_hint = file_get_contents($_errtpl);
					// replace values
					$err_hint = str_replace("<TEXT>", $error_message, $err_hint);
					$err_hint = str_replace("<DEBUG>", $error_trace, $err_hint);
					$err_hint = str_replace("<CURRENT_YEAR>", date('Y', time()), $err_hint);

					$err_report_html = '';
					if (is_array($userinfo) && (($userinfo['adminsession'] == '1' && \Froxlor\Settings::Get('system.allow_error_report_admin') == '1') || ($userinfo['adminsession'] == '0' && \Froxlor\Settings::Get('system.allow_error_report_customer') == '1'))) {
						$err_report_html = '<a href="<LINK>" title="Click here to report error">Report error</a>';
						$err_report_html = str_replace("<LINK>", $linker->getLink(array(
							'section' => 'index',
							'page' => 'send_error_report',
							'errorid' => $errid
						)), $err_report_html);
					}
					$err_hint = str_replace("<REPORT>", $err_report_html, $err_hint);

					// show
					die($err_hint);
				}
			}
			die("We are sorry, but a MySQL - error occurred. The administrator may find more information in the syslog");
		}
	}

	/**
	 * Substitutes patterns in content.
	 *
	 * @param string $content
	 * @param array $substitutions
	 * @param int $minLength
	 * @return string
	 */
	private static function substitute($content, array $substitutions, $minLength = 6)
	{
		$replacements = array();

		foreach ($substitutions as $search => $replace) {
			$replacements = $replacements + self::createShiftedSubstitutions($search, $replace, $minLength);
		}

		$content = str_replace(array_keys($replacements), array_values($replacements), $content);

		return $content;
	}

	/**
	 * Creates substitutions, shifted by length, e.g.
	 *
	 * _createShiftedSubstitutions('abcdefgh', 'value', 4):
	 * array(
	 * 'abcdefgh' => 'value',
	 * 'abcdefg' => 'value',
	 * 'abcdef' => 'value',
	 * 'abcde' => 'value',
	 * 'abcd' => 'value',
	 * )
	 *
	 * @param string $search
	 * @param string $replace
	 * @param int $minLength
	 * @return array
	 */
	private static function createShiftedSubstitutions($search, $replace, $minLength)
	{
		$substitutions = array();
		$length = strlen($search);

		if ($length > $minLength) {
			for ($shiftedLength = $length; $shiftedLength >= $minLength; $shiftedLength --) {
				$substitutions[substr($search, 0, $shiftedLength)] = $replace;
			}
		}

		return $substitutions;
	}
}
