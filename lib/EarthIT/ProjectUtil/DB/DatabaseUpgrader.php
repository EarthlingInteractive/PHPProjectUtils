<?php

class EarthIT_ProjectUtil_DB_DatabaseUpgrader
{
	const VERBOSITY_LIST_SCRIPTS = 1;
	const VERBOSITY_DUMP_SCRIPTS = 2;
	
	protected $sqlRunner;
	protected $upgradeScriptDirs;
	protected $upgradeTableSchemaName;
	protected $upgradeTableName;
	protected $upgradeTableExpression;
	protected $shouldDoQueries; // Set to false while dry-running upgrades
	protected $shouldDumpQueries; // Set to true when dumping upgrade SQL
	
	/*
	 * The upgrade process consists of 2 steps:
	 * 1. Determine what upgrades to run
	 * 2. Run them.
	 *
	 * $this->shouldDoQueries and $this->shouldDumpQueries should be
	 * set to true and false, respectively, for step 1, but may be
	 * modified according to $actuallyDoUpgrades and
	 * $dumpUpgradesToStdout for step 2.
	 *
	 * Maybe it would be cleaner to have separate 'query' and 'exectute'
	 * SQLRunners, but that would be a major change.
	 */
	
	/*
	 * When an upgrade script is represented as an array, it will have some subset of the following information:
	 *
	 * {
	 *   "scriptFilename": basename of script; used for determining order (e.g. '0101-create-tables.sql')
	 *   "scriptFileHash": hex-encoded SHA-1 of script (e.g. '23d9979512bc9211d744915f22d3c09233016b0e')
	 *   "scriptFilePath": full path to script file (e.g. 'build/db/upgrades/0101-create-tables.sql')
	 *   "scriptFileContent": content of script file, as a string
	 * }
	 */
	
	public $allowOutOfOrderScripts;
	public $verbosity = 0;
	public $actuallyDoUpgrades = true;
	/**
	 * If true, will dump the same SQL to standard output
	 * that would be run to actually do the upgrades.
	 * 
	 * This is completely separate from verbosity settings.
	 */
	public $dumpUpgradesToStdout = false;
	/**
	 * Path of a directory to dump upgrade script content into;
	 * e.g. "datastore/data/upgrade-scripts"
	 * Upgrades will be written to "{$upgradeScriptBlobstoreDir}/{$base32First2}/{$base32}"
	 * Where $base32 = base32(sha1(script text)), and $base32First2 is the first 2 characters
	 * of $base32 (base 32 is used for compatibility with PHPN2R and ContentCouch datastores).
	 */
	public $upgradeScriptBlobstoreDir = null;
	
	protected static function first( array $arr ) {
		foreach( $arr as $v ) return $v;
		return null;
	}
	
	protected static function firstKey( array $arr ) {
		foreach( $arr as $k=>$v ) return $k;
		return null;
	}
	protected static function lastKey( array $arr ) {
		$k = null;
		foreach( $arr as $k=>$v );
		return $k;
	}
	
	public function __construct( $sqlRunner, $upgradeScriptDirs ) {
		if( !is_array($upgradeScriptDirs) ) $upgradeScriptDirs = array($upgradeScriptDirs);
		$this->sqlRunner = $sqlRunner;
		$this->upgradeScriptDirs = $upgradeScriptDirs;
		$this->setUpgradeTable('schemaupgrade');
	}
	
	/* This thing is designed to log upgrades
	 * to a 'schemaupgrade' table in a postgres database.
	 * These functions are defined the way they are with
	 * the expectation that you can override them if you're
	 * using a different database or your upgrade table is
	 * structured somewhat differently. */

	public function setUpgradeTable( $t ) {
		$p = explode('.',$t,2);
		if( count($p) == 2 ) {
			$this->upgradeTableSchemaName = $p[0];
			$this->upgradeTableName = $p[1];
		} else {
			$this->upgradeTableSchemaName = null;
			$this->upgradeTableName = $t;
		}
		$this->upgradeTableExpression = $t;
	}
	
	/** Make sure the last non-comment line ends with a semicolon. */
	protected static function semicolonTerminate( $sql ) {
		$lines = explode("\n", $sql);
		$lastLineK = null;
		foreach( $lines as $k=>$line ) {
			$line = trim($line);
			if( !preg_match('/^$|^--$|^--\s+/', $line) ) $lastLineK = $k;
		}
		if( $lastLineK !== null and !preg_match('/;$/', $lines[$lastLineK]) ) {
			$lines[$lastLineK] .= ";";
			return implode("\n", $lines);
		} else {
			return $sql;
		}
	}
	
	const COMMENT_LINE_REGEX = '/^\s*(?:--|--\s+.*|)$/'; // Commented or empty
	
	protected function isEntirelyCommented( $sql ) {
		$lines = explode("\n", $sql);
		foreach( $lines as $line ) {
			if( preg_match(self::COMMENT_LINE_REGEX,$line) ) continue;
			
			// Otherwise this line's not a comment!
			return false;
		}
		// If we get here, there were no non-comment, non-blank lines, so yes.
		return true;
	}
	
	protected function doRawQuery( $sql ) {
		if( $this->verbosity >= self::VERBOSITY_DUMP_SCRIPTS ) {
			echo "-- doRawQuery\n", $sql, "\n";
		}
		if( $this->shouldDumpQueries ) {
			echo self::semicolonTerminate($sql), "\n";
		}
		if( $this->shouldDoQueries && !$this->isEntirelyCommented($sql) ) $this->sqlRunner->doRawQuery($sql);
	}
	
	protected function doQuery( $sql, array $params=array() ) {
		if( $this->verbosity >= self::VERBOSITY_DUMP_SCRIPTS ) {
			echo "-- doQuery with params: ", json_encode($params), "\n", $sql, "\n";
		}
		if( $this->shouldDumpQueries ) {
			if( !method_exists($this->sqlRunner, 'quoteParams') ) {
				throw new Exception("Can't dump parameterized queries because the database adapter doesn't implement #quoteParams.");
			}
			echo $this->sqlRunner->quoteParams(self::semicolonTerminate($sql), $params), "\n";
		}
		if( $this->shouldDoQueries ) $this->sqlRunner->doQuery($sql,$params);
	}
	
	protected function doQueries( array $queries ) {
		foreach( $queries as $q ) {
			$this->doQuery($q[0], $q[1]);
		}
	}
	
	protected function fetchRows( $sql, array $params=array() ) {
		if( $this->verbosity >= self::VERBOSITY_DUMP_SCRIPTS ) {
			echo "-- fetchRows with params: ", json_encode($params), "\n", $sql, "\n";
		}
		if( $this->shouldDumpQueries ) {
			if( !method_exists($this->sqlRunner, 'quoteParams') ) {
				throw new Exception("Can't dump parameterized queries because the database adapter doesn't implement #quoteParams.");
			}
			echo "-- Warning: Dumping query whose result is used.  This is maybe not useful.\n";
			echo $this->sqlRunner->quoteParams($sql, $params), "\n";
		}
		if( !$this->shouldDoQueries ) {
			throw new Exception("Can't fetch data from database because \$shouldDoQueries = false.");
		}
		return $this->shouldDoQueries ? $this->sqlRunner->fetchRows($sql,$params) : array();
	}
	
	protected function fetchValue( $sql, $params=array() ) {
		foreach( $this->fetchRows($sql,$params) as $row ) foreach( $row as $v ) return $v;
		return null;
	}
	
	protected function getUpgradeTableExistenceQuery() {
		return array(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = {tableschema} AND table_name = {tablename}",
			array('tableschema'=>$this->upgradeTableSchemaName ?: 'public', 'tablename'=>$this->upgradeTableName)
		);
	}
	
	protected function upgradeTableExists() {
		list($sql,$params) = $this->getUpgradeTableExistenceQuery();
		return $this->fetchValue($sql, $params) > 0;
	}
	
	protected function hasUpgradeBeenRun($scriptFilename) {
		if( !$this->upgradeTableExists() ) return false;
		
		$colnames = $this->getUpgradeLogColumnNames();
		return (bool)$this->fetchValue(
			"SELECT COUNT(*)\n".
			"FROM {$this->upgradeTableExpression}\n".
			"WHERE \"{$colnames['scriptFilename']}\" = {scriptFilename}",
			array('scriptFilename'=>$scriptFilename));
	}
	
	protected function readUpgradesTable() {
		$colnames = $this->getUpgradeLogColumnNames();
		$upgradesAlreadyRun = array();
		foreach($this->fetchRows(
			"SELECT \"{$colnames['scriptFilename']}\" AS \"scriptFilename\"\n".
			"FROM {$this->upgradeTableExpression}") as $sar
		) {
			$upgradesAlreadyRun[$sar['scriptFilename']] = $sar;
		}
		return $upgradesAlreadyRun;
	}
	
	protected function getUpgradesAlreadyRun() {
		return $this->upgradeTableExists() ?
			$this->readUpgradesTable() :
			array();
	}
	
	protected function getUpgradeLogColumnNames() {
		return array(
			'time' => 'time',
			'scriptFilename' => 'scriptfilename',
			'scriptFileHash' => 'scriptfilehash'
		);
	}
	
	protected function generateUpgradeLogQueries( $scriptName, $scriptHash ) {
		$colnames = $this->getUpgradeLogColumnNames();
		return array(array(
			"INSERT INTO {$this->upgradeTableExpression}\n".
			"({$colnames['time']}, {$colnames['scriptFilename']}, {$colnames['scriptFileHash']}) VALUES\n".
			"(CURRENT_TIMESTAMP, {scriptFilename}, {scriptFileHash})",
			array('scriptFilename'=>$scriptName, 'scriptFileHash'=>$scriptHash)
		));
	}
	
	protected $lastNonFatalError = null;
	protected function logNonFatalError($text) {
		if( $this->lastNonFatalError == $text ) return; // Already printed; let's not be too annoying.
		$this->lastNonFatalError = $text;
		fwrite(STDERR, "Warning: ".str_replace("\n","\n         ",$text)."\n");
	}
	
	public function logUpgrade( $scriptName, $scriptHash, $usText ) {
		$this->doQueries( $this->generateUpgradeLogQueries($scriptName, $scriptHash) );
		
		if( $this->upgradeScriptBlobstoreDir !== null ) {
			if( !class_exists('TOGoS_Base32') ) {
				$this->logNonFatalError("Can't copy upgrade script to blobstore because TOGoS_Base32 isn't defined.\nTry composer installing togos/base32.");
				return;
			}
			$b32 = TOGoS_Base32::encode(hex2bin($scriptHash));
			$dir = $this->upgradeScriptBlobstoreDir.'/'.substr($b32,0,2);
			if( !is_dir($dir) ) {
				if( !@mkdir($dir, 0777, true) ) {
					$this->logNonFatalError("Failed to mkdir(".var_export($dir,true)."); can't copy upgrade script to there");
					return;
				}
			}
			$file = "{$dir}/{$b32}";
			if( file_exists($file) ) return;
			
			file_put_contents($file, $usText);
		}
	}
	
	protected function beginTransaction() {
		$this->doRawQuery('BEGIN');
	}
	protected function commitTransaction() {
		$this->doRawQuery('COMMIT');
	}
	protected function cancelTransaction() {
		$this->doRawQuery('ROLLBACK');
	}
	
	/* Here endeth the list of functions that are expected to be
	 * overridden. */
	
	protected function runPhpScript($text, $phpFile) {
		require $phpFile;
	}
	
	/**
	 * @param string $sql upgrade script SQL (no placeholders or parameters) to be run
	 * @param string $us upgrade script filename
	 * @param string $hash hex-encoded SHA-1 of SQL
	 * @param boolean $useTransaction whether or not the upgrade should be wrapped in a transaction
	 */
	protected function doUpgrade(array $upgradeScript, $useTransaction) {
		// Need to use exec specifically (rather than query or fetchAll)
		// to avoid attempting to 'prepare' the statement, as PDO does not allow
		// multiple commands in one prepared statement.
		// TODO: Ensure that two upgrade scripts running in parallel don't accidentally
		// run scripts twice.
		// TODO: Actually do into the above TODO sometimes; it's known to be a real problem
		// on sites with multiple deployments trying to upgrade the same database.

		$usText = $upgradeScript['scriptFileContent'];
		$usName = $upgradeScript['scriptFilename'];
		$usPath = $upgradeScript['scriptFilePath'];
		$usHash = $upgradeScript['scriptFileHash'];
		
		if( $useTransaction ) $this->beginTransaction();
		try {
			if( $this->shouldDoQueries && $this->hasUpgradeBeenRun($usName) ) {
				// Maybe another script is attempting to run in parallel?
				// Anyway, this means something's wrong.
				// So abort.
				// And if not explicitly aborted, hopefully having checked that table
				// causes the transaction to fail.
				throw new Exception("Script {$usName} has already been run.  Is there another upgrading running in parallel?");
			}
			
			if( preg_match('/\.(php|sql)$/',$usName,$bif) ) {
				switch( $ext = $bif[1] ) {
				case 'sql': $this->doRawQuery($usText); break;
				case 'php': $this->runPhpScript($usText, $usPath); break;
				default: throw new Exception("'$ext'?", "{$this->upgradeScriptDir}/{$usName}");
				}
			} else {
				throw new Exception("Can't glean upgrade script type from name: '$usName'");
			}
			$this->logUpgrade($usName, $usHash, $usText);
			if( $useTransaction ) $this->commitTransaction();
		} catch( Exception $e ) {
			if( $useTransaction ) $this->cancelTransaction();
			fputs(STDERR, "Error while running '$usName' : ".$e->getMessage()."\n");
			throw new Exception("Error while running '$usName'", 0, $e);
		}
	}
	
	protected function _findUpgradeScripts($dir, array &$upgradeScripts, array &$errors) {
		$dh = opendir($dir);
		if( $dh === false ) {
			throw new Exception("Failed to open upgrade script directory '$dir'");
		}
		
		$junkFiles = array();
		while( ($fn = readdir($dh)) !== false ) {
			if( preg_match('/^\./',$fn) ) continue;
			$fullpath = "{$dir}/{$fn}";
			if( is_dir($fullpath) ) {
				$this->_findUpgradeScripts($fullpath, $upgradeScripts, $junkFiles);
			} else if( preg_match('/\.(?:sql|php)$/',$fn) ) {
				if( isset($upgradeScripts[$fn]) ) {
					$upgradeScript = $upgradeScripts[$fn];
					$oldFullPath = $upgradeScript['scriptFilePath'];
					$errors['duplicateScripts'][$fn][$oldFullPath] = $upgradeScript;
					$errors['duplicateScripts'][$fn][$fullpath] = $upgradeScript;
				}
				
				$upgradeScripts[$fn] = array(
					'scriptFilename' => $fn,
					'scriptFilePath' => $fullpath,
				);
			} else {
				$errors['junkFiles'][$fullpath] = $fullpath;
				continue;
			}
		}
		closedir($dh);
		
		return count($errors) == 0; // Not quite the right logic but will work for now
	}
	
	public function findUpgradeScripts() {
		$upgradeScripts = array();
		$errors         = array();
		
		$success = true;
		foreach( $this->upgradeScriptDirs as $dir ) {
			$success &= $this->_findUpgradeScripts($dir, $upgradeScripts, $errors);
		}
		
		$anyErrors = false;
		if( !empty($errors['junkFiles']) ) {
			fwrite(STDERR, "Error: There is extra junk in your upgrade scripts directory:\n");
			foreach( $errors['junkFiles'] as $f ) {
				fwrite(STDERR, "  $f\n");
			}
			$anyErrors = true;
		}
		if( !empty($errors['duplicateScripts']) ) {
			fwrite(STDERR, "Error: Some scripts are duplicated (same name in different directories):\n");
			foreach( $errors['duplicateScripts'] as $us=>$versions ) {
				fwrite(STDERR, "  $us: ".implode(', ',array_keys($versions))."\n");
			}
			$anyErrors = true;
		}
		if( !$success ) {
			fwrite(STDERR, "Aborting.  Clean up the upgrade scripts directory and try again.\n");
			exit(1);
		}
		
		ksort($upgradeScripts);
		return $upgradeScripts;
	}
	
	/**
	 * Determines what upgrade scripts to run and runs them in one go.
	 * If any script is marked as modifying the upgrade table, this
	 * function will return true after running that script to indicate
	 * that there may be more upgrades to run.  Otherwise it returns
	 * false, indicating that the upgrade process is complete.
	 */
	public function _run() {
		$this->shouldDoQueries = true;
		$this->shouldDumpQueries = false;
		
		$upgradeScripts = $this->findUpgradeScripts(); // scriptFilename => array(...)
		$upgradesAlreadyRun = $this->getUpgradesAlreadyRun(); // scriptFilename => array(...)
		$alreadyRunOutOfOrderScripts = array();
		$upgradeScriptsToRun = array();
		
		foreach( $upgradeScripts as $us=>$upgradeScript ) {
			if( !isset($upgradesAlreadyRun[$us]) ) {
				$upgradeScriptsToRun[$us] = $upgradeScript;
			} else if( count($upgradeScriptsToRun) > 0 ) {
				$alreadyRunOutOfOrderScripts[$us] = $upgradeScript;
			}
		}
		
		if( !$this->allowOutOfOrderScripts and count($alreadyRunOutOfOrderScripts) > 0 ) {
			$firstToRun = self::first($upgradeScriptsToRun);
			fwrite(STDERR, "Error: Some scripts after the first unrun one ({$firstToRun['scriptFilePath']}) have already been run:\n");
			foreach( $alreadyRunOutOfOrderScripts as $s=>$_ ) {
				fwrite(STDERR, "  $s\n");
			}
			fwrite(STDERR, "Aborting.  Pass -f to force upgrades to run anyway.\n");
			exit(1);
		}
		
		if( $this->verbosity >=self::VERBOSITY_LIST_SCRIPTS ) {
			fwrite(STDOUT, "-- ".count($upgradeScriptsToRun)." scripts to be run:\n");
			if( $upgradeScriptsToRun ) {
				fwrite(STDOUT,
					"-- From ".self::firstKey($upgradeScriptsToRun)." to ".
					self::lastKey($upgradeScriptsToRun)."\n"
				);
			}
		}
		
		$this->shouldDoQueries = $this->actuallyDoUpgrades;
		$this->shouldDumpQueries = $this->dumpUpgradesToStdout;
		
		foreach( $upgradeScriptsToRun as $us=>$upgradeScript ) {
			$usFile = $upgradeScript['scriptFilePath'];
			$usText = file_get_contents($usFile);
			
			$useTransaction = true;
			$updatesUpgradeLog = false;
			
			$firstFewLines = explode("\n", $usText, 100);
			foreach( $firstFewLines as $l ) {
				if( preg_match('<^(?:--|#|//)\s*Dear database upgrader:\s*(.*)$>', $l, $bif) ) {
					$directive = trim($bif[1]);
					switch( $directive ) {
					case "Please do not wrap this script in a transaction.":
						$useTransaction = false;
						break;
					case "This script updates the upgrade log.":
						$updatesUpgradeLog = true;
						break;
					default:
						throw new Exception("Unrecognized upgrader directive in '$usFile': \"$directive\"");
					}
				}
			}

			$hash = sha1($usText);
			if( $this->verbosity >=self::VERBOSITY_LIST_SCRIPTS ) {
				fwrite(STDOUT, "-- Running $usFile (SHA1 = $hash)...\n");
			}
			if( $this->dumpUpgradesToStdout ) {
				echo "\n-- $us\n";
			}
			
			$upgradeScript['scriptFileHash'] = $hash;
			$upgradeScript['scriptFileContent'] = $usText;
			
			$this->doUpgrade($upgradeScript, $useTransaction);
			
			if( $updatesUpgradeLog ) {
				if( $this->verbosity >= self::VERBOSITY_LIST_SCRIPTS ) {
					fwrite(STDOUT, "Upgrade '$us' made modifications to the upgrade log.  Need to reload.\n");
				}
				return true;
			}
		}
		
		if( $this->verbosity >= self::VERBOSITY_LIST_SCRIPTS ) {
			fwrite(STDOUT, "Upgrade completed successfully!\n");
		}
		
		return false;
	}
	
	public function run() {
		while( $this->_run() );
	}
}
