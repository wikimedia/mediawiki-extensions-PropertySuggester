<?php

namespace PropertySuggester\Tests\PropertySuggester\UpdateTable;

use MediaWikiIntegrationTestCase;
use PropertySuggester\Maintenance\UpdateTable;
use PropertySuggester\Tests\PropertySuggester\UpdateTable\Importer\FileWrappingImportStreamHandler;

/**
 * @covers \PropertySuggester\maintenance\UpdateTable
 * @covers \PropertySuggester\UpdateTable\Importer\BasicImporter
 * @covers \PropertySuggester\UpdateTable\ImportContext
 *
 * @group PropertySuggester
 * @group Database
 * @group medium
 */
class UpdateTableTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var string[]
	 */
	private $rowHeader = [ 'pid1', 'qid1', 'pid2', 'count', 'probability', 'context' ];

	public static function provideRows() {
		$rows1 = [
			[ 1, 0, 2, 100, 0.1, 'item' ],
			[ 1, 0, 3, 50, 0.05, 'item' ],
			[ 2, 0, 3, 100, 0.1, 'item' ],
			[ 2, 0, 4, 200, 0.2, 'item' ],
			[ 3, 0, 1, 123, 0.5, 'item' ],
		];

		$rows2 = [];
		for ( $i = 0; $i < 1100; $i++ ) {
			$rows2[] = [ $i, 0, 2, 100, 0.1, 'item' ];
		}

		return [
			[ $rows1 ],
			[ $rows2 ],
		];
	}

	public function setUp(): void {
		parent::setUp();
		stream_wrapper_register( 'testimport', FileWrappingImportStreamHandler::class );
	}

	private function getTempFileWithData( array $rows ): string {
		$filename = $this->getNewTempFile();
		$this->setupData( $filename, $rows );
		return $filename;
	}

	/**
	 * @dataProvider provideRows
	 */
	public function testRewriteNativeStrategy( array $rows ) {
		$args = [ 'file' => $this->getTempFileWithData( $rows ), 'quiet' => true, 'use-loaddata' => true ];
		$this->runScriptAndAssert( $args, $rows );
	}

	/**
	 * @dataProvider provideRows
	 */
	public function testRewriteWithSQLInserts( array $rows ) {
		$args = [ 'file' => $this->getTempFileWithData( $rows ), 'quiet' => true ];
		$this->runScriptAndAssert( $args, $rows );
	}

	/**
	 * @dataProvider provideRows
	 */
	public function testReadFromStream( array $rows ) {
		$tempfile = $this->getTempFileWithData( $rows );
		$args = [ 'file' => "testimport://$tempfile", 'quiet' => true ];
		$this->runScriptAndAssert( $args, $rows );
	}

	private function runScriptAndAssert( array $args, array $rows ) {
		$maintenanceScript = new UpdateTable();
		$maintenanceScript->loadParamsAndArgs( null, $args, null );
		$maintenanceScript->execute();
		if ( count( $rows ) < 100 ) {
			$this->newSelectQueryBuilder()
				->select( [ 'pid1', 'qid1', 'pid2', 'count', 'probability', 'context' ] )
				->from( 'wbs_propertypairs' )
				->assertResultSet( $rows );
		} else {
			// assertResultSet is too slow to compare 1100 rows... just check the size
			$this->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'wbs_propertypairs' )
				->assertFieldValue( count( $rows ) );
		}
	}

	private function setupData( string $testfilename, array $rows ) {
		$fhandle = fopen( $testfilename, 'w' );
		fputcsv( $fhandle, $this->rowHeader, ',' );
		foreach ( $rows as $row ) {
			fputcsv( $fhandle, $row, ',' );
		}
		fclose( $fhandle );
	}

	public function tearDown(): void {
		parent::tearDown();
		stream_wrapper_unregister( 'testimport' );
	}
}
