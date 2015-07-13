<?php

namespace WP\WPAutoinstall;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use \PDO;

class WPAutoinstall {

	/** @var string */
	protected static $package;

	/** @var array */
	protected static $extra;

	/** @var IOInterface */
	protected static $io;

	protected static function setUp(Event $event = null) {
		self::$package = $event->getComposer()->getPackage();
		self::$extra = self::$package->getExtra();
		self::$io = $event->getIO();
	}

	/**
	 * @param Event $event
	 * @return bool
	 */
	public static function configBuild(Event $event = null) {
		self::setUp($event);

		$config_dir = dirname(self::$extra['wordpress-install-dir']);
		$config_file = file(self::$extra['wordpress-install-dir'] . '/wp-config-sample.php');

		if (file_exists(dirname(self::$extra['wordpress-install-dir']) . '/wp-config.php')) {
			return;
		}

		self::$io->write('---------------------------------');
		self::$io->write('--   WordPress configuration   --');
		self::$io->write('---------------------------------');

		$params = array();
		$params['DB_HOST'] = self::$io->askAndValidate('Set DB host (default: localhost): ', [self::class, 'returnValue'], null, 'localhost');
		$params['DB_NAME'] = self::$io->askAndValidate('Set DB name: ', [self::class, 'returnValue'], null);
		$params['DB_USER'] = self::$io->askAndValidate('Set DB username (default: root): ', [self::class, 'returnValue'], null, 'root');
		$params['DB_PASSWORD'] = self::$io->askAndValidate('Set DB password: ', [self::class, 'returnValue'], null);

		$config_file = self::processConfig($config_file, $params);
		self::writeConfigFile($config_dir, $config_file);

		self::$io->write('Done. wp-config.php was successfully generated.');

		return true;
	}

	/**
	 * @param Event $event
	 * @return bool
	 */
	public static function databaseSeed(Event $event = null) {
		// self::setUp($event);

		// // create DB

		// if (!file_exists(dirname(self::$extra['wordpress-install-dir']) . '/wp-config.php')) {
		// 	throw new \Exception('Generate wp-config.php first!');
		// }

		// include_once(dirname(self::$extra['wordpress-install-dir']) . '/wp-config.php');
		// // $result = wp_install( $weblog_title, $user_name, $admin_email, $public, '', wp_slash( $admin_password ), $loaded_language );

		// $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8', DB_USER, DB_PASSWORD);
		// $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

		// $stmt = $db->query('SELECT * FROM `'.$table_prefix.'users`');
		// $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
		// var_dump($results);

		// self::$io->write('Done. Database was successfully seeded.');
		// return true;
	}

	public static function returnValue($value) {
		return $value;
	}

	protected static function processConfig($config_file, $params) {
		foreach ($config_file as $line_num => $line) {
			if (!preg_match('/^define\(\'([A-Z_]+)\',([ ]+)/', $line, $match)) {
				continue;
			}

			$constant = $match[1];
			$padding = $match[2];

			switch ($constant) {
				case 'DB_NAME':
				case 'DB_USER':
				case 'DB_PASSWORD':
				case 'DB_HOST':
					$config_file[$line_num] = "define('" . $constant . "'," . $padding . "'" . addcslashes($params[$constant], "\\'") . "');\r\n";
					break;
				case 'DB_COLLATE':
					$config_file[$line_num] .= "\r\n// custom directory
define('WP_CONTENT_DIR', dirname(__FILE__) . '/wp-content');
define('WP_CONTENT_URL', 'http://' . \$_SERVER['HTTP_HOST'] . '/wp-content');\r\n";
					break;
			}
		}
		return $config_file;
	}

	protected static function writeConfigFile($dir, $config_file) {
		if (!is_writable($dir)) {
			// print cfg to console
			echo implode('', $config_file);
		} else {
			$path_to_wp_config = $dir . '/wp-config.php';
			$handle = fopen($path_to_wp_config, 'w');
			foreach ($config_file as $line) {
				fwrite($handle, $line);
			}
			fclose($handle);
			chmod($path_to_wp_config, 0666);
		}
	}
}
