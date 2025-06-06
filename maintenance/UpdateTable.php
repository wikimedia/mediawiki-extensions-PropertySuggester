<?php

namespace PropertySuggester\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use PropertySuggester\UpdateTable\ImportContext;
use PropertySuggester\UpdateTable\Importer\BasicImporter;
use UnexpectedValueException;
use Wikimedia\Rdbms\IDatabase;
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
	 * If passed a filename, find the absolute path and convert to unix format.
	 *
	 * @param string $uriOrFilePath
	 * @return string
	 * @throws \MediaWiki\Maintenance\MaintenanceFatalError
	 */
	private function normaliseStreamPath( string $uriOrFilePath ): string {
		if ( str_contains( $uriOrFilePath, '://' ) ) {
			// Path is a stream - not attempt to normalise / verify
			return $uriOrFilePath;
		}
		$fullPath = realpath( $uriOrFilePath );
		$fullPath = str_replace( '\\', '/', $fullPath );

		if ( !file_exists( $fullPath ) ) {
			$this->fatalError( "Cant find $uriOrFilePath \n" );
		}
		return $fullPath;
	}

	/**
	 * loads property pair occurrence probability table from given csv file
	 */
	public function execute() {
		if ( substr( $this->getOption( 'file' ), 0, 2 ) === "--" ) {
			$this->fatalError( "The --file option requires a file as an argument.\n" );
		}
		$path = $this->getOption( 'file' );
		$fullPath = $this->normaliseStreamPath( $path );

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
			$db->newDeleteQueryBuilder()
				->deleteFrom( $tableName )
				->where( IDatabase::ALL_ROWS )
				->caller( __METHOD__ )
				->execute();
		} else {
			do {
				$db->commit( __METHOD__, 'flush' );
				$lbFactory->waitForReplication();
				$this->output( "Deleting a batch\n" );
				$table = $db->tableName( $tableName );
				$db->query( "DELETE FROM $table LIMIT $this->mBatchSize", __METHOD__ );
			} while ( $db->affectedRows() > 0 );
		}
	}

}

$maintClass = UpdateTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
