module.exports = ( function () {
	'use strict';

	var config = require( './config.json' ),
		schemas = require( './schemas.json' );

	/**
	 * Used for hashing user IDs to log the information
	 * and link events to specific users
	 * The function uses a constant seed to keep the
	 * hashed IDs constant for the same user
	 *
	 * @param {string} id user ID (equal to wgUserId)
	 * @return {string}
	 */
	var hashCode = function ( id ) {
		var i, l;
		var hval = 0x811c9dc5;

		/* eslint-disable no-bitwise */
		for ( i = 0, l = id.length; i < l; i++ ) {
			hval = hval ^ id.charCodeAt( i );
			hval = hval + ( hval << 1 ) + ( hval << 4 ) +
				( hval << 7 ) + ( hval << 8 ) + ( hval << 24 );
		}
		// return a 16 character string
		return ( hval >>> 0 ).toString( 16 );
		/* eslint-enable no-bitwise */
	};

	function SELF( $element ) {
		this._$element = $element;
	}

	/**
	 * @property {string}
	 * @private
	 */
	SELF.prototype._$element = null;

	/**
	 * @public
	 * @param {string} type entity type
	 * @return {boolean}
	 */
	SELF.prototype.useSuggester = function ( type ) {
		var entity = this._getEntity();

		return type === 'property' &&
			entity && entity.getType() === 'item' &&
			this._getPropertyContext() !== null;
	};

	/**
	 * Get the entity from the surrounding entityview or return null
	 *
	 * @private
	 * @return {wikibase.Entity|null}
	 */
	SELF.prototype._getEntity = function () {
		var $entityView;

		try {
			$entityView = this._$element.closest( ':wikibase-entityview' );
		} catch ( ex ) {
			return null;
		}

		if ( $entityView.length > 0 ) {
			return $entityView.data( 'entityview' ).option( 'value' );
		}

		return null;
	};

	/**
	 * Returns the property id for the enclosing statementview or null if no property is
	 * selected yet.
	 *
	 * @private
	 * @return {string|null}
	 */
	SELF.prototype._getPropertyId = function () {
		var $statementview,
			statement;

		try {
			$statementview = this._$element.closest( ':wikibase-statementview' );
		} catch ( ex ) {
			return null;
		}

		if ( $statementview.length > 0 ) {
			statement = $statementview.data( 'statementview' ).option( 'value' );
			if ( statement ) {
				return statement.getClaim().getMainSnak().getPropertyId();
			}
		}

		return null;
	};

	/**
	 * Returns either 'item', 'qualifier', 'reference' or null depending on the context of the
	 * entityselector. 'item' is returned in case that the selector is for a new property in an
	 * item.
	 *
	 * @private
	 * @return {string|null}
	 */
	SELF.prototype._getPropertyContext = function () {
		if ( this._isInNewStatementView() ) {
			if ( !this._isQualifier() && !this._isReference() ) {
				return 'item';
			}
		} else if ( this._isQualifier() ) {
			return 'qualifier';
		} else if ( this._isReference() ) {
			return 'reference';
		}

		return null;
	};

	/**
	 * @private
	 * @return {boolean}
	 */
	SELF.prototype._isQualifier = function () {
		var $statementview = this._$element.closest( ':wikibase-statementview' ),
			statementview = $statementview.data( 'statementview' );

		if ( !statementview ) {
			return false;
		}

		return this._$element.closest( statementview.$qualifiers ).length > 0;
	};

	/**
	 * @private
	 * @return {boolean}
	 */
	SELF.prototype._isReference = function () {
		var $referenceview = this._$element.closest( ':wikibase-referenceview' );

		return $referenceview.length > 0;
	};

	/**
	 * detect if this is a new statement view.
	 *
	 * @private
	 * @return {boolean}
	 */
	SELF.prototype._isInNewStatementView = function () {
		var $statementview = this._$element.closest( ':wikibase-statementview' );

		if ( $statementview.length > 0 ) {
			return !$statementview.data( 'statementview' ).option( 'value' );
		}

		return true;
	};

	/**
	 * Get suggestions
	 *
	 * @public
	 * @param {string} url of api endpoint
	 * @param {string} language
	 * @param {term} term search term
	 * @return {jQuery.Promise}
	 */
	SELF.prototype.getSuggestions = function ( url, language, term ) {
		var $deferred = $.Deferred(),
			data = {
				action: 'wbsgetsuggestions',
				search: term,
				context: this._getPropertyContext(),
				format: 'json',
				language: language
			};

		if ( data.context === 'item' ) {
			data.entity = this._getEntity().getId();

			var userID = mw.config.get( 'wgUserId' ) === null ?
				'not logged in' : hashCode( mw.config.get( 'wgUserId' ).toString() );

			var uniqueKey = ( userID === 'not logged in' ) ?
				'not' + Date.now().toString() + hashCode( data.entity ) : Date.now().toString() + userID;
			data.event = uniqueKey;

			if ( mw.loader.getState( 'ext.eventLogging' ) === 'ready' &&
				config.PropertySuggesterABTestingState ) {
				/* eslint-disable */
				mw.eventLog.submit(
					'wd_propertysuggester.client_side_property_request',
					{
						$schema: schemas.PropertySuggesterClientSidePropertyRequest,
						num_characters: term.length,
						user_id: userID,
						session_id: mw.config.get( 'wgRequestId' ),
						entity_id: data.entity,
						event_id: uniqueKey
					}
				);
				/* eslint-enable */
			}

		} else {
			data.properties = this._getPropertyId();
		}

		return $.getJSON( url, data ).then( function ( d ) {
			if ( !d.search ) {
				return $deferred.resolve().promise();
			}

			return $deferred.resolve( d.search ).promise();
		} );

	};

	return SELF;
}() );
