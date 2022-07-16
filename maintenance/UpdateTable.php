<?php

namespace PropertySuggester\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use PropertySuggester\UpdateTable\ImportContext;
use PropertySuggester\UpdateTable\Importer\BasicImporter;
use UnexpectedValueException;
use Wikimedia\Rdbms\ILBFactory;

$basePath = getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' )
	: __DIR__ . '/../../..';
require_once $basePath . '/maintenance/Maintenance.php';

/**
 * Maintenance script to load property pair occurrence probability table from given csv file
 *
 * @author BP2013N2
 * @license GPL-2.0-or-later
 */
class UpdateTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( "Read CSV Dump and refill probability table" );
		$this->addOption( 'file', 'CSV table to be loaded (relative path)', true, true );
		$this->setBatchSize( 10000 );
		$this->requireExtension( 'PropertySuggester' );
	}

	/**
	 * loads property pair occurrence probability table from given csv file
	 */
	public function execute() {
		if ( substr( $this->getOption( 'file' ), 0, 2 ) === "--" ) {
			$this->fatalError( "The --file option requires a file as an argument.\n" );
		}
		$path = $this->getOption( 'file' );
		$fullPath = realpath( $path );
		$fullPath = str_replace( '\\', '/', $fullPath );

		if ( !file_exists( $fullPath ) ) {
			$this->fatalError( "Cant find $path \n" );
		}

		$tableName = 'wbs_propertypairs';

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lbFactory->waitForReplication();

		$this->clearTable( $lbFactory, $tableName );

		$this->output( "loading new entries from file\n" );

		$importContext = $this->createImportContext(
			$lbFactory,
			$tableName,
			$fullPath,
			$this->isQuiet()
		);
		$importStrategy = new BasicImporter();

		try {
			$success = $importStrategy->importFromCsvFileToDb( $importContext );
		} catch ( UnexpectedValueException $e ) {
			$this->fatalError( "Import failed: " . $e->getMessage() );
		}

		if ( !$success ) {
			$this->fatalError( "Failed to run import to db" );
		}
		$this->output( "... Done loading\n" );
	}

	/**
	 * @param ILBFactory $lbFactory
	 * @param string $tableName
	 * @param string $wholePath
	 * @param bool $quiet
	 * @return ImportContext
	 */
	private function createImportContext( ILBFactory $lbFactory, $tableName, $wholePath, $quiet ) {
		$importContext = new ImportContext();
		$importContext->setLbFactory( $lbFactory );
		$importContext->setTargetTableName( $tableName );
		$importContext->setCsvFilePath( $wholePath );
		$importContext->setCsvDelimiter( ',' );
		$importContext->setBatchSize( $this->mBatchSize );
		$importContext->setQuiet( $quiet );

		return $importContext;
	}

	/**
	 * @param ILBFactory $lbFactory
	 * @param string $tableName
	 */
	private function clearTable( ILBFactory $lbFactory, $tableName ) {
		global $wgDBtype;

		$lb = $lbFactory->getMainLB();
		$db = $lb->getMaintenanceConnectionRef( DB_PRIMARY );
		if ( !$db->tableExists( $tableName, __METHOD__ ) ) {
			$this->fatalError( "$tableName table does not exist.\n" .
				"Executing core/maintenance/update.php may help.\n" );
		}
		$this->output( "Removing old entries\n" );
		if ( $wgDBtype === 'sqlite' || $wgDBtype === 'postgres' ) {
			$db->delete( $tableName, "*", __METHOD__ );
		} else {
			do {
				$db->commit( __METHOD__, 'flush' );
				$lbFactory->waitForReplication();
				$this->output( "Deleting a batch\n" );
				$table = $db->tableName( $tableName );
				$db->query( "DELETE FROM $table LIMIT $this->mBatchSize", __METHOD__ );
			} while ( $db->affectedRows() > 0 );
		}
		$lb->reuseConnection( $db );
	}

}

$maintClass = UpdateTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
