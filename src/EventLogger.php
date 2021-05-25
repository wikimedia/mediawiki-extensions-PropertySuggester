<?php

namespace PropertySuggester;

use EventLogging;
use ExtensionRegistry;

class EventLogger {

	/**
	 * Constants mapping to the keys in the AB-testing schema.
	 */
	public const EVENT_SUGGESTER_NAME = 'propertysuggester_name';
	public const EVENT_EXISTING_PROPERTIES = 'existing_properties';
	public const EVENT_EXISTING_TYPES = 'existing_types';
	public const EVENT_REQ_DURATION_MS = 'request_duration_ms';
	public const EVENT_ADD_SUGGESTIONS = 'add_suggestions_made';
	public const EVENT_LANGUAGE_CODE = 'language_code';
	public const EVENT_ID = 'event_id';

	/**
	 * @var string
	 */
	private $propertySuggesterName;

	/**
	 * @var string[]
	 */
	private $existingProperties;

	/**
	 * @var string[]
	 */
	private $existingTypes;

	/**
	 * @var int
	 */
	private $requestDuration;

	/**
	 * @var string[]
	 */
	private $addSuggestions;

	/**
	 * @var string
	 */
	private $languageCode;

	/**
	 * @var string
	 */
	private $eventID;

	/**
	 * @var string
	 */
	private $eventSchema;

	public function __construct(
		string $eventID,
		string $languageCode
	) {
		$this->eventID = $eventID;
		$this->languageCode = $languageCode;

		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventLogging' ) ) {
			$schemas = ExtensionRegistry::getInstance()->getAttribute( 'EventLoggingSchemas' );
			$this->eventSchema = $schemas['PropertySuggesterServerSidePropertyRequest'];
		} else {
			$this->eventSchema = '';
		}
	}

	/**
	 * @param string $suggesterName
	 */
	public function setPropertySuggesterName( string $suggesterName ) {
		$this->propertySuggesterName = $suggesterName;
	}

	/**
	 * @param string[] $existingProperties
	 */
	public function setExistingProperties( array $existingProperties ) {
		$this->existingProperties = $existingProperties;
	}

	/**
	 * @param string[] $existingTypes
	 */
	public function setExistingTypes( array $existingTypes ) {
		$this->existingTypes = $existingTypes;
	}

	/**
	 * @param int $requestDuration
	 * The duration of the request in milliseconds
	 * If the SchemaTreeSuggester request fails, the
	 * value logged will be -1
	 */
	public function setRequestDuration( int $requestDuration ) {
		$this->requestDuration = $requestDuration;
	}

	/**
	 * @param string[] $additionalSuggestions
	 */
	public function setAddSuggestions( array $additionalSuggestions ) {
		$this->addSuggestions = $additionalSuggestions;
	}

	public function getEvent(): array {
		return [
			'$schema' => $this->eventSchema,
			self::EVENT_SUGGESTER_NAME => $this->propertySuggesterName ?: 'Failed setting name',
			self::EVENT_EXISTING_PROPERTIES => $this->existingProperties ?: [],
			self::EVENT_EXISTING_TYPES => $this->existingTypes ?: [],
			self::EVENT_REQ_DURATION_MS => $this->requestDuration ?: -1,
			self::EVENT_ADD_SUGGESTIONS => $this->addSuggestions ?: [],
			self::EVENT_LANGUAGE_CODE => $this->languageCode,
			self::EVENT_ID => $this->eventID
		];
	}

	public function logEvent(): bool {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'EventLogging' ) || $this->eventID === '' ) {
			return false;
		}

		$event = $this->getEvent();

		EventLogging::submit( 'wd_propertysuggester.server_side_property_request', $event );
		return true;
	}
}
