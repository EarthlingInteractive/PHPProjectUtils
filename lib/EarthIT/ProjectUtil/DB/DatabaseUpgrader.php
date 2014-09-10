<?php

class EarthIT_ProjectUtil_DB_DatabaseUpgrader
{
	const VERBOSITY_LIST_SCRIPTS = 1;
	const VERBOSITY_DUMP_SCRIPTS = 2;
	
	protected $DNC;
	protected $upgradeScriptDir;
	public $allowOutOfOrderScripts;
	protected $upgradeTableSchemaName;
	protected $upgradeTableName;
	protected $upgradeTableExpression;
	public $verbosity = 0;
	
	public function __construct( $DBA, $upgradeScriptDir ) {
		$this->DBA = $DBA;
		$this->upgradeScriptDir = $upgradeScriptDir;
		$this->setUpgradeTable('schemaupgrade');
	}
	
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
	
	public function run() {
		$upgradesAlreadyRun = array();
		if( count($this->DBA->fetchAll(
			"SELECT * FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
			array($this->upgradeTableSchemaName ?: 'public', $this->upgradeTableName)
		)) > 0 ) {
			foreach($this->DBA->fetchAll("SELECT * FROM {$this->upgradeTableExpression}") as $sar) {
				$upgradesAlreadyRun[$sar['scriptfilename']] = $sar;
			}
		}
		
		$upgradeScripts = array();
		$dh = opendir($this->upgradeScriptDir);
		if( $dh === false ) {
			throw new Exception("Failed to open upgrade script directory");
		}
		$junkFiles = array();
		while( ($fn = readdir($dh)) !== false ) {
			if( preg_match('/^\./',$fn) ) continue;
			if( !preg_match('/\.sql$/',$fn) ) {
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
			fwrite(STDERR, count($upgradeScriptsToRun)." scripts to be run:\n");
			if( $upgradeScriptsToRun ) {
				fwrite(STDERR,
					"From ".$upgradeScriptsToRun[0]." to ".
					$upgradeScriptsToRun[count($upgradeScriptsToRun)-1]."\n"
				);
			}
		}
		
		foreach( $upgradeScriptsToRun as $us ) {
			$usFile = "{$this->upgradeScriptDir}/$us";
			$sql = file_get_contents($usFile);
			$hash = sha1($sql);
			if( $this->verbosity >=self::VERBOSITY_LIST_SCRIPTS ) {
				fwrite(STDERR, "Running $usFile (SHA1 = $hash)...\n");
			}
			if( $this->verbosity >=self::VERBOSITY_DUMP_SCRIPTS ) {
				fwrite(STDERR, "\n  ".str_replace("\n","\n  ",rtrim($sql))."\n\n");
			}
			// Need to use exec specifically (rather than query or fetchAll)
			// to avoid attempting to 'prepare' the statement, as PDO does not allow
			// multiple commands in one prepared statement.
			$this->DBA->exec('BEGIN');
			try {
				$this->DBA->exec($sql);
				$this->DBA->fetchAll(
					"INSERT INTO {$this->upgradeTableExpression} (time, scriptfilename, scriptfilehash) VALUES (NOW(), :scriptfilename, :scriptfilehash)",
					array('scriptfilename'=>$us, 'scriptfilehash'=>$hash)
				);
				$this->DBA->exec('COMMIT');
			} catch( Exception $e ) {
				$this->DBA->exec('ROLLBACK');
				fputs(STDERR, "Error while running $usFile : ".$e->getMessage()."\n");
				throw new Exception("Error while running $usFile", 0, $e);
			}
		}
		
		if( $this->verbosity >= self::VERBOSITY_LIST_SCRIPTS ) {
			fwrite(STDERR, "Upgrade completed successfully!\n");
		}
	}
}
