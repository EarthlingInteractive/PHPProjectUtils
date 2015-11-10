<?php

class EarthIT_ProjectUtil_DB_DatabaseUpgrader
{
	const VERBOSITY_LIST_SCRIPTS = 1;
	const VERBOSITY_DUMP_SCRIPTS = 2;
	
	protected $sqlRunner;
	protected $upgradeScriptDir;
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
	
	public function __construct( $sqlRunner, $upgradeScriptDir ) {
		$this->sqlRunner = $sqlRunner;
		$this->upgradeScriptDir = $upgradeScriptDir;
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
	
	protected function doRawQuery( $sql ) {
		if( $this->verbosity >= self::VERBOSITY_DUMP_SCRIPTS ) {
			echo "-- doRawQuery\n", $sql, "\n";
		}
		if( $this->shouldDumpQueries ) {
			echo self::semicolonTerminate($sql), "\n";
		}
		if( $this->shouldDoQueries ) $this->sqlRunner->doRawQuery($sql);
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
	
	protected function readUpgradesTable() {
		$upgradesAlreadyRun = array();
		foreach($this->fetchRows("SELECT scriptfilename AS scriptfilename FROM {$this->upgradeTableExpression}") as $sar) {
			$upgradesAlreadyRun[$sar['scriptfilename']] = $sar;
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
			'script file name' => 'scriptfilename',
			'script file hash' => 'scriptfilehash'
		);
	}
	
	protected function generateUpgradeLogQueries( $scriptName, $scriptHash ) {
		$colnames = $this->getUpgradeLogColumnNames();
		return array(array(
			"INSERT INTO {$this->upgradeTableExpression}\n".
			"({$colnames['time']}, {$colnames['script file name']}, {$colnames['script file hash']}) VALUES\n".
			"(CURRENT_TIMESTAMP, {scriptfilename}, {scriptfilehash})",
			array('scriptfilename'=>$scriptName, 'scriptfilehash'=>$scriptHash)
		));
	}
	
	public function logUpgrade( $scriptName, $scriptHash ) {
		$this->doQueries( $this->generateUpgradeLogQueries($scriptName, $scriptHash) );
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
	
	protected function runPhpScript($text, $name) {
		require "{$this->upgradeScriptDir}/{$name}";
		// eval("? >".$text."< ? php");
	}
	
	/**
	 * @param string $sql upgrade script SQL (no placeholders or parameters) to be run
	 * @param string $us upgrade script filename
	 * @param string $hash hex-encoded SHA-1 of SQL
	 * @param boolean $useTransaction whether or not the upgrade should be wrapped in a transaction
	 */
	protected function doUpgrade($usText, $usName, $hash, $useTransaction) {
		// Need to use exec specifically (rather than query or fetchAll)
		// to avoid attempting to 'prepare' the statement, as PDO does not allow
		// multiple commands in one prepared statement.
		// TODO: Ensure that two upgrade scripts running in parallel don't accidentally
		// run scripts twice.
		// TODO: Actually do into the above TODO sometimes; it's known to be a real problem
		// on sites with multiple deployments trying to upgrade the same database.
		if( $useTransaction ) $this->beginTransaction();
		try {
			if( preg_match('/\.(php|sql)$/',$usName,$bif) ) {
				switch( $ext = $bif[1] ) {
				case 'sql': $this->doRawQuery($usText); break;
				case 'php': $this->runPhpScript($usText, $usName); break;
				default: throw new Exception("'$ext'?", "{$this->upgradeScriptDir}/{$usName}");
				}
			} else {
				throw new Exception("Can't glean upgrade script type from name: '$usName'");
			}
			$this->logUpgrade($usName, $hash);
			if( $useTransaction ) $this->commitTransaction();
		} catch( Exception $e ) {
			if( $useTransaction ) $this->cancelTransaction();
			fputs(STDERR, "Error while running '$usName' : ".$e->getMessage()."\n");
			throw new Exception("Error while running '$usName'", 0, $e);
		}
	}
	
	public function run() {
		$this->shouldDoQueries = true;
		$this->shouldDumpQueries = false;
		
		$upgradesAlreadyRun = $this->getUpgradesAlreadyRun();
		
		$upgradeScripts = array();
		$dh = opendir($this->upgradeScriptDir);
		if( $dh === false ) {
			throw new Exception("Failed to open upgrade script directory");
		}
		$junkFiles = array();
		while( ($fn = readdir($dh)) !== false ) {
			if( preg_match('/^\./',$fn) ) continue;
			if( !preg_match('/\.(?:sql|php)$/',$fn) ) {
				$junkFiles[] = $fn;
				continue;
			}
	
			$upgradeScripts[] = $fn;
		}
		closedir($dh);
		
		if( count($junkFiles) > 0 ) {
			fwrite(STDERR, "Error: There is extra junk in your upgrade scripts directory:\n");
			foreach( $junkFiles as $f ) {
				fwrite(STDERR, "  $f\n");
			}
			fwrite(STDERR, "Aborting.  Clean up the upgrade scripts directory and try again.\n");
			exit(1);
		}
		
		sort($upgradeScripts);
		
		$alreadyRunOutOfOrderScripts = array();
		$upgradeScriptsToRun = array();
		foreach( $upgradeScripts as $us ) {
			if( !isset($upgradesAlreadyRun[$us]) ) {
				$upgradeScriptsToRun[] = $us;
			} else if( count($upgradeScriptsToRun) > 0 ) {
				$alreadyRunOutOfOrderScripts[] = $us;
			}
		}
		
		if( !$this->allowOutOfOrderScripts and count($alreadyRunOutOfOrderScripts) > 0 ) {
			fwrite(STDERR, "Error: Some scripts after the first unrun one have already been run:\n");
			foreach( $alreadyRunOutOfOrderScripts as $s ) {
				fwrite(STDERR, "  $s\n");
			}
			fwrite(STDERR, "Aborting.  Pass -f to force upgrades to run anyway.\n");
			exit(1);
		}
		
		if( $this->verbosity >=self::VERBOSITY_LIST_SCRIPTS ) {
			fwrite(STDOUT, "-- ".count($upgradeScriptsToRun)." scripts to be run:\n");
			if( $upgradeScriptsToRun ) {
				fwrite(STDOUT,
					"-- From ".$upgradeScriptsToRun[0]." to ".
					$upgradeScriptsToRun[count($upgradeScriptsToRun)-1]."\n"
				);
			}
		}
		
		$this->shouldDoQueries = $this->actuallyDoUpgrades;
		$this->shouldDumpQueries = $this->dumpUpgradesToStdout;
		
		foreach( $upgradeScriptsToRun as $us ) {
			$usFile = "{$this->upgradeScriptDir}/$us";
			$usText = file_get_contents($usFile);
			
			$useTransaction = true;
			
			$firstFewLines = explode("\n", $usText, 100);
			foreach( $firstFewLines as $l ) {
				if( preg_match('/^(?:--|#)\s*Dear database upgrader:\s*(.*)$/', $l, $bif) ) {
					$directive = trim($bif[1]);
					switch( $directive ) {
					case "Please do not wrap this script in a transaction.":
						$useTransaction = false;
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
			
			$this->doUpgrade($usText, $us, $hash, $useTransaction);
		}
		
		if( $this->verbosity >= self::VERBOSITY_LIST_SCRIPTS ) {
			fwrite(STDOUT, "Upgrade completed successfully!\n");
		}
	}
}
