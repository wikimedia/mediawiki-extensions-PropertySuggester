( function () {
	'use strict';

	var PropertySuggester = require( './PropertySuggester.js' ),
		config = require( './config.json' ),
		schemas = require( './schemas.json' ),
		element = null;

	var logPropertySelected = function ( value ) {
		if ( mw.loader.getState( 'ext.eventLogging' ) !== 'ready' ) {
			return;
		}
		var propertiesLists = document.querySelectorAll( 'ul.ui-entityselector-list' );
		$( propertiesLists ).each( function ( _, ul ) {
			$( ul ).children().each( function ( index, li ) {
				var elements = $( li ).find( '.ui-entityselector-label' );
				if ( elements.length > 0 && elements[ 0 ].innerText === value ) {
					element = null;
					/* eslint-disable camelcase */
					mw.eventLog.submit(
						'wd_propertysuggester.client_ab_testing',
						{
							$schema: schemas.PropertySuggesterClientSidePropertyRequest,
							rank_selected: index,
							recommendation_selected: $( li ).find( 'a' )[ 0 ].href,
							session_id: mw.config.get( 'wgRequestId' )
						}
					);
					/* eslint-enable camelcase */
				}
			} );
		} );
	};

	var observer = new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			if ( mutation.attributeName === 'class' ) {
				if ( $( mutation.target ).is( '.ui-entityselector-input-recognized' ) ) {
					logPropertySelected( $( mutation.target ).val() );
				}
			}
		} );
	} );

	mw.hook( 'wikibase.entityselector.search' ).add( function ( data, addPromise ) {

		var suggester = new PropertySuggester( data.element );
		if ( !suggester.useSuggester( data.options.type ) ) {
			return;
		}

		addPromise(
			suggester.getSuggestions( data.options.url, data.options.language, data.term )
		);

		if ( config.PropertySuggesterABTestingState ) {
			if ( element === null || element !== data.element[ 0 ] ) {
				observer.disconnect();
				element = data.element[ 0 ];
				observer.observe( element, {
					attributes: true
				} );
			}
		}

	} );

}() );
