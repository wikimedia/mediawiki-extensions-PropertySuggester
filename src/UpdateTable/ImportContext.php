<?php

namespace PropertySuggester\UpdateTable;

use Wikimedia\Rdbms\LoadBalancer;

/**
 * Context for importing data from a csv file to a db table using a Importer strategy
 *
 * @author BP2013N2
 * @license GNU GPL v2+
 */
class ImportContext {

	/**
	 * file system path to the CSV to load data from
	 * @var string
	 */
	private $csvFilePath = "";

	/**
	 * delimiter used in csv file
	 * @var string
	 */
	private $csvDelimiter = ",";

	/**
	 * table name of the table to import to
	 * @var string
	 */
	private $targetTableName = "";

	/**
	 * @var LoadBalancer|null
	 */
	private $lb = null;

	/**
	 * @var int
	 */
	private $batchSize;

	/**
	 * @var boolean
	 */
	private $quiet;

	/**
	 * @return string
	 */
	public function getCsvDelimiter() {
		return $this->csvDelimiter;
	}

	/**
	 * @param string $csvDelimiter
	 */
	public function setCsvDelimiter( $csvDelimiter ) {
		$this->csvDelimiter = $csvDelimiter;
	}

	/**
	 * @return LoadBalancer|null
	 */
	public function getLb() {
		return $this->lb;
	}

	public function setLb( LoadBalancer $lb ) {
		$this->lb = $lb;
	}

	/**
	 * @return string
	 */
	public function getTargetTableName() {
		return $this->targetTableName;
	}

	/**
	 * @param string $tableName
	 */
	public function setTargetTableName( $tableName ) {
		$this->targetTableName = $tableName;
	}

	/**
	 * @return string
	 */
	public function getCsvFilePath() {
		return $this->csvFilePath;
	}

	/**
	 * @param string $fullPath
	 */
	public function setCsvFilePath( $fullPath ) {
		$this->csvFilePath = $fullPath;
	}

	/**
	 * @return int
	 */
	public function getBatchSize() {
		return $this->batchSize;
	}

	/**
	 * @param int $batchSize
	 */
	public function setBatchSize( $batchSize ) {
		$this->batchSize = $batchSize;
	}

	/**
	 * @return boolean
	 */
	public function isQuiet() {
		return $this->quiet;
	}

	/**
	 * @param boolean $quiet
	 */
	public function setQuiet( $quiet ) {
		$this->quiet = $quiet;
	}

}
