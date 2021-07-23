<?php

namespace PropertySuggester;

use ExtensionRegistry;
use MediaWikiTestCase;

/**
 * @covers \PropertySuggester\EventLogger
 *
 * @group PropertySuggester
 * @group API
 * @group medium
 */
class EventLoggerTest extends MediaWikiTestCase {

	/**
	 * @var string
	 */
	private $language;

	/**
	 * @var string
	 */
	private $eventID;

	/**
	 * @var string
	 */
	private $schema;

	public function setUp(): void {
		parent::setUp();

		$this->language = 'en-gb';
		$this->eventID = '1234';

		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventLogging' ) ) {
			$schemas = ExtensionRegistry::getInstance()->getAttribute( 'EventLoggingSchemas' );
			$this->schema = $schemas['PropertySuggesterServerSidePropertyRequest'];
		} else {
			$this->schema = '';
		}
	}

	private function createEvent(
		string $propertySuggester,
		array $existingProperties,
		array $existingTypes,
		int $requestDuration,
		array $addSuggestionsMade,
		string $language,
		string $eventID
	): array {
		return [
			'$schema' => $this->schema,
			EventLogger::EVENT_SUGGESTER_NAME => $propertySuggester,
			EventLogger::EVENT_EXISTING_PROPERTIES => $existingProperties,
			EventLogger::EVENT_EXISTING_TYPES => $existingTypes,
			EventLogger::EVENT_REQ_DURATION_MS => $requestDuration,
			EventLogger::EVENT_ADD_SUGGESTIONS => $addSuggestionsMade,
			EventLogger::EVENT_LANGUAGE_CODE => $language,
			EventLogger::EVENT_ID => $eventID
		];
	}

	public function testCorrectEventInfo() {
		$eventLogger = new EventLogger(
			$this->eventID,
			$this->language
		);

		$propertySuggester = 'PropertySuggester';
		$requestDuration = 10;
		$addSuggestionsMade = [ 'P1', 'P2', 'P3' ];
		$existingProperties = [ 'P31' ];
		$existingTypes = [];

		$eventLogger->setRequestDuration( $requestDuration );
		$eventLogger->setPropertySuggesterName( $propertySuggester );
		$eventLogger->setAddSuggestions( $addSuggestionsMade );
		$eventLogger->setExistingProperties( $existingProperties );
		$eventLogger->setExistingTypes( $existingTypes );

		$expectedEvent = $this->createEvent( $propertySuggester, $existingProperties, $existingTypes,
			$requestDuration, $addSuggestionsMade, $this->language, $this->eventID );

		$actualEvent = $eventLogger->getEvent();

		$this->assertArrayEquals( $expectedEvent, $actualEvent, false, true );
	}

	public function testOverwriteInformationSet() {
		$eventLogger = new EventLogger(
			$this->eventID,
			$this->language
		);

		$propertySuggester = 'PropertySuggester';
		$requestDuration = 10;
		$addSuggestionsMade = [ 'P1', 'P2', 'P3' ];
		$existingProperties = [ 'P31' ];
		$existingTypes = [];

		$eventLogger->setRequestDuration( $requestDuration );
		$eventLogger->setPropertySuggesterName( $propertySuggester );
		$eventLogger->setAddSuggestions( $addSuggestionsMade );
		$eventLogger->setExistingProperties( $existingProperties );
		$eventLogger->setExistingTypes( $existingTypes );

		$expectedEvent = $this->createEvent( $propertySuggester, $existingProperties, $existingTypes,
			$requestDuration, $addSuggestionsMade, $this->language, $this->eventID );

		$actualEvent = $eventLogger->getEvent();

		$this->assertArrayEquals( $expectedEvent, $actualEvent, false, true );

		$propertySuggesterNew = 'SchemaTreeSuggester';
		$requestDurationNew = 11;

		$eventLogger->setRequestDuration( $requestDurationNew );
		$eventLogger->setPropertySuggesterName( $propertySuggesterNew );

		$expectedEvent = $this->createEvent( $propertySuggesterNew, $existingProperties, $existingTypes,
			$requestDurationNew, $addSuggestionsMade, $this->language, $this->eventID );

		$actualEvent = $eventLogger->getEvent();

		$this->assertArrayEquals( $expectedEvent, $actualEvent, false, true );
	}

	public function testNoLogWhenMissingID() {
		$eventLogger = new EventLogger(
			'',
			$this->language
		);

		$propertySuggester = 'PropertySuggester';
		$requestDuration = 10;
		$addSuggestionsMade = [ 'P1', 'P2', 'P3' ];
		$existingProperties = [];
		$existingTypes = [];

		$eventLogger->setRequestDuration( $requestDuration );
		$eventLogger->setPropertySuggesterName( $propertySuggester );
		$eventLogger->setAddSuggestions( $addSuggestionsMade );
		$eventLogger->setExistingProperties( $existingProperties );
		$eventLogger->setExistingTypes( $existingTypes );

		$response = $eventLogger->logEvent();

		$this->assertFalse( $response );
	}

}
