<?php

declare( strict_types = 1 );

namespace PropertySuggester\Tests\PropertySuggester\UpdateTable\Importer;

use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use PropertySuggester\UpdateTable\ImportContext;
use PropertySuggester\UpdateTable\Importer\BasicImporter;

/**
 * @covers \PropertySuggester\UpdateTable\Importer\BasicImporter
 * @covers \PropertySuggester\UpdateTable\ImportContext
 *
 * @group Database
 */
class ImportContextTest extends MediaWikiIntegrationTestCase {

	private ?string $testCsvDataPath = null;

	public function setUp(): void {
		parent::setUp();
		$this->testCsvDataPath = $this->createTestCsv();
		stream_wrapper_register( 'testimport', FileWrappingImportStreamHandler::class );
	}

	private array $rows = [
		[ 1, 2, 3, 0, 0, 0 ],
		[ 4, 5, 6, 0, 0, 0 ],
		[ 7, 8, 9, 0, 0, 0 ],
	];

	private function createTestCsv(): string {
		$testfilename = $this->getNewTempFile();
		$fhandle = fopen( $testfilename, 'w' );
		$rowHeader = [ "pid1", "qid1", "pid2", "count", "probability", "context" ];
		fputcsv( $fhandle, $rowHeader, ',' );
		foreach ( $this->rows as $row ) {
			fputcsv( $fhandle, $row, ',' );
		}
		fclose( $fhandle );
		return $testfilename;
	}

	private function getImportContext(): ImportContext {
		$context = new ImportContext();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lbFactory->waitForReplication();
		$context->setLbFactory( $lbFactory );
		$tableName = 'wbs_propertypairs';
		$context->setTargetTableName( $tableName );
		$context->setCsvFilePath( $this->testCsvDataPath );
		$context->setBatchSize( 100 );
		$context->setQuiet( true );
		return $context;
	}

	public function testCanImportFromFile() {
		$context = $this->getImportContext();
		$context->setCsvFilePath( $this->testCsvDataPath );

		$importStrategy = new BasicImporter();

		$success = $importStrategy->importFromCsvFileToDb( $context );
		$this->assertTrue( $success, 'Expecting import to succeed' );
		$this->newSelectQueryBuilder()
			->select( [ 'pid1', 'qid1', 'pid2', 'count', 'probability', 'context' ] )
			->from( 'wbs_propertypairs' )
			->assertResultSet( $this->rows );
	}

	public function testCanImportFromStream() {
		$context = $this->getImportContext();
		$context->setCsvFilePath( 'testimport://' . $this->testCsvDataPath );

		$importStrategy = new BasicImporter();

		$success = $importStrategy->importFromCsvFileToDb( $context );
		$this->assertTrue( $success, 'Expecting import to succeed' );
		$this->newSelectQueryBuilder()
			->select( [ 'pid1', 'qid1', 'pid2', 'count', 'probability', 'context' ] )
			->from( 'wbs_propertypairs' )
			->assertResultSet( $this->rows );
	}

	public function tearDown(): void {
		parent::tearDown();
		stream_wrapper_unregister( 'testimport' );
	}
}
