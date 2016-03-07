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

		if (!file_exists(dirname(self::$extra['wordpress-install-dir']) . '/wp-config.php')) {
			self::$io->write('---------------------------------');
			self::$io->write('--   WordPress configuration   --');
			self::$io->write('---------------------------------');

			$params = array();
			$params['DB_HOST'] = self::$io->askAndValidate('Set DB host (default: localhost): ', [self::class, 'returnValue'], null, 'localhost');
			$params['DB_NAME'] = self::$io->askAndValidate('Set DB name: ', [self::class, 'returnValue'], null);
			$params['DB_USER'] = self::$io->askAndValidate('Set DB username (default: root): ', [self::class, 'returnValue'], null, 'root');
			$params['DB_PASSWORD'] = self::$io->askAndValidate('Set DB password: ', [self::class, 'returnValue'], null);
			$params['table_prefix'] = self::$io->askAndValidate('Set Wordpress table prefix (default: wp_): ', [self::class, 'returnValue'], null, 'wp_');
			$params['WP_DEBUG'] = self::$io->askAndValidate('Debug (default: false): ', [self::class, 'returnValue'], null, 'false');

			self::prepareDatabase($params);

			$config_file = self::processConfig($config_file, $params);
			self::writeConfigFile($config_dir, $config_file);

			self::$io->write('Done. wp-config.php was successfully generated.');
		}
		self::configureFilestructure();
		
		return true;
	}

	public static function returnValue($value) {
		return $value;
	}

	protected static function processConfig($config_file, $params) {
		foreach ($config_file as $line_num => $line) {
			if (!preg_match('/^define\(\'([A-Z_]+)\',([ ]+)/', $line, $match)) {
				if (preg_match('/^\$table_prefix.*$/', $line, $match)) {
					$config_file[$line_num] = "\$table_prefix = '".$params['table_prefix']."';\r\n";
				}
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
				case 'AUTH_KEY':
				case 'SECURE_AUTH_KEY':
				case 'LOGGED_IN_KEY':
				case 'NONCE_KEY':
				case 'AUTH_SALT':
				case 'SECURE_AUTH_SALT':
				case 'LOGGED_IN_SALT':
				case 'NONCE_SALT':
					$config_file[$line_num] = "define('" . $constant . "'," . $padding . "'" . self::randomString(40) . "');\r\n";
					break;
				case 'WP_DEBUG':
					$config_file[$line_num] = "define('" . $constant . "'," . $padding . "" . $params[$constant] . ");\r\n";
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

	public static function randomString($length = 20) {
	    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	    $randstring = '';
	    for ($i = 0; $i < $length; $i++) {
	        $randstring .= $characters[rand(0, strlen($characters)-1)];
	    }
	    return $randstring;
	}

	public static function configureFilestructure() {
		self::$io->write('Configuring filestructure.');
		$wpContentDir = self::$extra['wordpress-install-dir'].'/wp-content';
		self::deleteWPContent();

		$themePath = "";
		$muPluginPath = "";
		$pluginPath = "";
		foreach (self::$extra['installer-paths'] as $key => $value) {
			foreach ($value as $param) {
				switch ($param) {
				 	case 'type:wordpress-plugin':
				 		$pluginPath = preg_replace('/\{\$name\}\//', '', $key);
				 		break;
			 		case 'type:wordpress-theme':
				 		$themePath = preg_replace('/\{\$name\}\//', '', $key);
				 		break;
					case 'type:wordpress-muplugin':
				 		$muPluginPath = preg_replace('/\{\$name\}\//', '', $key);
				 		break;
				}
			}
		}

		$uploadsFolder = dirname($muPluginPath)."/uploads";

		self::createTheme($themePath);
		self::createPluginFolder($pluginPath);
		self::createMuPluginFolder($muPluginPath);
		self::createUploadsFolder($uploadsFolder);
		self::copyRunFiles();
	}

	public static function deleteWPContent() {
		$wpContentDir = self::$extra['wordpress-install-dir'].'/wp-content';
		if (file_exists($wpContentDir)) {
			self::$io->write('Deleting wp-content from wordpress folder.');
			self::deleteDir($wpContentDir);
		}
	}

	private static function deleteDir($dir) {
		$it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
		$files = new \RecursiveIteratorIterator($it,
		             \RecursiveIteratorIterator::CHILD_FIRST);
		foreach($files as $file) {
		    if ($file->isDir()){
		        rmdir($file->getRealPath());
		    } else {
		        unlink($file->getRealPath());
		    }
		}
		rmdir($dir);
	}

	private static function recurse_copy($src, $dst) { 
	    $dir = opendir($src); 
	    self::createDirectory($dst);

	    while(false !== ( $file = readdir($dir)) ) { 
	        if (( $file != '.' ) && ( $file != '..' )) { 
	            if ( is_dir($src . '/' . $file) ) { 
	                self::recurse_copy($src . '/' . $file,$dst . '/' . $file); 
	            } 
	            else {
	                copy($src . '/' . $file,$dst . '/' . $file); 
	            } 
	        } 
	    } 
	    closedir($dir); 
	} 

	private static function createDirectory($dst) {
		$dstParts = explode('/', $dst);
	    $dstSubFolder = '';
	    foreach ($dstParts as $dstFolderPart) {
	    	$dstSubFolder .= $dstFolderPart;
	    	if (!file_exists($dstSubFolder)) {
				@mkdir($dstSubFolder);
	    	}
	    	$dstSubFolder .= "/";
	    }
	}

	private static function createTheme($themeFolder) {
		if (!file_exists($themeFolder)) {
			$themeName = self::$io->askAndValidate('Name of your theme (Default: Default Theme): ', [self::class, 'returnValue'], null, 'Default Theme');
			$author = self::$io->askAndValidate('Author of your theme: ', [self::class, 'returnValue'], null, '');
			$themeUri = self::$io->askAndValidate('Theme URI: ', [self::class, 'returnValue'], null, '');
			$authorUri = self::$io->askAndValidate('Author URI: ', [self::class, 'returnValue'], null, '');
			$textDomain = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $themeName));
			self::$io->write('Making copy of the base template.');

			$themeFolder .= ''.$textDomain;

			self::recurse_copy(self::$extra['blank-theme-dir'], $themeFolder);
			
			$themeStringStyle = "/*\r\nTheme Name: ".$themeName."\r\nTheme URI: ".$themeUri."\r\nAuthor: ".$author."\r\nAuthor URI: ".$authorUri."\r\nDescription: Custom theme created from koprivajakub/blank-wp-theme\r\nVersion: 1.0\r\nText Domain: ".$textDomain."\r\n*/\r\n";

			file_put_contents($themeFolder.'/style.css', $themeStringStyle);
		}
	}

	private static function createPluginFolder($pluginPath) {
		if (!file_exists($pluginPath)) {
			self::$io->write('Creating plugin folder.');
			self::createDirectory($pluginPath);
		}
	}

	private static function createMuPluginFolder($muPluginPath) {
		if (!file_exists($muPluginPath)) {
			self::$io->write('Creating mu-plugin folder.');
			self::createDirectory($muPluginPath);
		}
	}

	private static function createUploadsFolder($uploadsFolder) {
		if (!file_exists($uploadsFolder)) {
			self::$io->write('Creating uploads folder.');
			self::createDirectory($uploadsFolder);
		}
	}

	private static function copyRunFiles() {
		$index = dirname(self::$extra['wordpress-install-dir']).'/index.php';
		$htaccess = dirname(self::$extra['wordpress-install-dir']).'/.htaccess';
		if (!file_exists($index)) {
			copy(self::$extra['wordpress-install-dir']."/index.php", $index);
		}
		self::processIndexFile(file($index));

		if (!file_exists($htaccess)) {
			copy(__DIR__."/.htaccess", $htaccess);
		}
	}

	private static function processIndexFile($index_file) {
		foreach ($index_file as $line_num => $line) {
			if (preg_match('#.*\'/wp\-blog\-header\.php\'.*\);#', $line, $match)) {
				$index_file[$line_num] = 'require( dirname( __FILE__ ) . '."'/wordpress'".' . \'/wp-blog-header.php\' );';
			}
		}

		$handle = fopen(dirname(self::$extra['wordpress-install-dir']).'/index.php', 'w');
		foreach ($index_file as $line) {
			fwrite($handle, $line);
		}
		fclose($handle);

		return $index_file;
	}

	private static function prepareDatabase($params) {
		$sqlPath = __DIR__."/01-first-import.sql";
		$sqlFile = file($sqlPath);
		$params['site_title'] = self::$io->askAndValidate('Title of your Website (Default: Title of new Wordpress Site): ', [self::class, 'returnValue'], null, 'Title of new Wordpress Site');
		$params['admin_email'] = self::$io->askAndValidate('Admin email (Default: username@example.local): ', [self::class, 'returnValue'], null, 'username@example.local');
		$params['admin_username'] = self::$io->askAndValidate('Wordpress admin username (Default: admin): ', [self::class, 'returnValue'], null, 'admin');
		$params['admin_password'] = self::$io->askAndValidate('Wordpress admin password (Default: admin): ', [self::class, 'returnValue'], null, 'admin');
		$params['website_url'] = self::$io->askAndValidate('Url of the website with http:// (Default: http://wordpress.loc): ', [self::class, 'returnValue'], null, 'http://wordpress.loc');

		$conn = new \mysqli($params['DB_HOST'], $params['DB_USER'], $params['DB_PASSWORD']);
		if ($conn->connect_error) {
		    self::$io->write("Connection failed: " . $conn->connect_error);
		    die;
		}
		$sql = "CREATE DATABASE IF NOT EXISTS ".$params['DB_NAME'];
		if ($conn->query($sql) === TRUE) {
			self::$io->write("Database `".$params['DB_NAME']." created successfully!");
		} else {
			self::$io->write("Error creating database `".$params['DB_NAME'].": ".$conn->error);
		}
		$conn->close();

		$plainSQL = "";
		foreach ($sqlFile as $line_num => $line) {
			$line = preg_replace('#{{table_prefix}}#', $params['table_prefix'], $line);
			$line = preg_replace('#{{website_url}}#', $params['website_url'], $line);
			$line = preg_replace('#{{title-of-the-site}}#', $params['site_title'], $line);
			$line = preg_replace('#{{admin_email}}#', $params['admin_email'], $line);
			$line = preg_replace('#{{admin_password}}#', "MD5('".$params['admin_password']."')", $line);
			$line = preg_replace('#{{admin_username}}#', $params['admin_username'], $line);
			$plainSQL .= $line;
		}

		$mysql_host = $params['DB_HOST'];
		$mysql_database = $params['DB_NAME'];
		$mysql_user = $params['DB_USER'];
		$mysql_password = $params['DB_PASSWORD'];
		$db = new PDO("mysql:host=$mysql_host;dbname=$mysql_database", $mysql_user, $mysql_password);

		$stmt = $db->prepare($plainSQL);

		if ($stmt->execute()) {
		    self::$io->write('Database query successfully executed!');
		} else {
			self::$io->write('Database query failed!');
			self::$io->write(mysqli_stmt_error($stmt));
	    }
	}
}
