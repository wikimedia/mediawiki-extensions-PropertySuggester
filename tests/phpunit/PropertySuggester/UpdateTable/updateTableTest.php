<?php

namespace PropertySuggester\UpdateTable;

use MediaWikiTestCase;
use PropertySuggester\Maintenance\UpdateTable;

/**
 * @covers PropertySuggester\maintenance\UpdateTable
 * @covers PropertySuggester\UpdateTable\Importer\BasicImporter
 * @covers PropertySuggester\UpdateTable\Importer\MySQLImporter
 * @covers PropertySuggester\UpdateTable\ImportContext
 * @group PropertySuggester
 * @group Database
 * @group medium
 */
class UpdateTableTest extends MediaWikiTestCase {

	/**
	 * @var string
	 */
	protected $testfilename;

	public function setUp() {
		parent::setUp();

		$this->tablesUsed[] = 'wbs_propertypairs';

		$this->testfilename = sys_get_temp_dir() . "/_temp_test_csv_file.csv";

	}

	public function getRows() {
		$rows1 = array();
		$rows1[] = array( 1, null, 2, 100, 0.1, 'item' );
		$rows1[] = array( 1, null, 3, 50, 0.05, 'item' );
		$rows1[] = array( 2, null, 3, 100, 0.1, 'item' );
		$rows1[] = array( 2, null, 4, 200, 0.2, 'item' );
		$rows1[] = array( 3, null, 1, 123, 0.5, 'item' );

		$rows2 = array();
		for ($i=0; $i<1100; $i++) {
			$rows2[] = array( $i, null, 2, 100, 0.1, 'item' );
		}

		return array(
			array( $rows1 ),
			array( $rows2 )
		);
	}

	/**
	 * @dataProvider getRows
	 */
	public function testRewriteNativeStrategy( array $rows ) {
		$this->setupData( $rows );
		$maintenanceScript = new UpdateTable();
		$maintenanceScript->loadParamsAndArgs( null, array( "file" => $this->testfilename, "silent" => true ), null );
		$this->runScriptAndAssert( $maintenanceScript, $rows );
	}

	/**
	 * @dataProvider getRows
	 */
	public function testRewriteWithSQLInserts( array $rows ) {
		$this->setupData( $rows );
		$maintenanceScript = new UpdateTable();
		$maintenanceScript->loadParamsAndArgs( null, array( "file" => $this->testfilename, "silent" => true, "use-insert" => true ), null );
		$this->runScriptAndAssert( $maintenanceScript, $rows );
	}

	private function runScriptAndAssert( UpdateTable $maintenanceScript, array $rows ) {
		$maintenanceScript->execute();
		$this->assertSelect(
			'wbs_propertypairs',
			array( 'pid1', 'qid1', 'pid2', 'count', 'probability', 'context' ),
			array(),
			$rows
		);
	}

	private function setupData( array $rows ) {
		$fhandle = fopen( $this->testfilename, "w" );
		foreach ( $rows as $row ) {
			fputcsv( $fhandle, $row, "," );
		}
		fclose( $fhandle );
	}

	public function tearDown() {
		if ( file_exists( $this->testfilename ) ) {
			unlink( $this->testfilename );
		}
		parent::tearDown();
	}

}
