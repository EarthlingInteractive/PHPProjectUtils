<?php

/**
 * This is a simplified SQLRunner meant to
 * handle only the cases needed by DatabaseUpgrader.
 */
class EarthIT_ProjectUtil_DB_DoctrineSQLRunner
{
	public function __construct( $conn ) {
		$this->conn = $conn;
	}
	
	public function doRawQuery( $sql ) {
		$this->conn->exec($sql);
	}
	
	protected function rewriteForDoctrine( &$sql, array &$params ) {
		$rewrittenParams = array();
		
		$rewrittenSql = preg_replace('/\{([^\}]+)\}/', '?', $sql);
		
		preg_match_all('/\{([^\}]+)\}/', $sql, $bifs, PREG_SET_ORDER);
		foreach( $bifs as $bif ) {
			$rewrittenParams[] = $params[$bif[1]];
		}
		
		$sql = $rewrittenSql;
		$params = $rewrittenParams;
	}
	
	public function doQuery( $sql, array $params=array() ) {
		$this->rewriteForDoctrine($sql, $params);
		return $this->conn->fetchAll($sql, $params);
	}
	
	public function fetchRows( $sql, array $params=array() ) {
		$this->rewriteForDoctrine($sql, $params);
		return $this->conn->fetchAll($sql, $params);
	}
}
