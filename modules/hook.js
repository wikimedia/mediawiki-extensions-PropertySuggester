( function () {
	'use strict';

	let PropertySuggester = require( './PropertySuggester.js' ),
		config = require( './config.json' ),
		schemas = require( './schemas.json' ),
		element = null;

	const logPropertySelected = function ( value ) {
		if ( mw.loader.getState( 'ext.eventLogging' ) !== 'ready' ) {
			return;
		}
		const propertiesLists = document.querySelectorAll( 'ul.ui-entityselector-list' );
		$( propertiesLists ).each( ( _, ul ) => {
			$( ul ).children().each( ( index, li ) => {
				const elements = $( li ).find( '.ui-entityselector-label' );
				if ( elements.length > 0 && elements[ 0 ].innerText === value ) {
					element = null;
					/* eslint-disable camelcase */
					mw.eventLog.submit(
						'wd_propertysuggester.client_side_property_request',
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

	const observer = new MutationObserver( ( mutations ) => {
		mutations.forEach( ( mutation ) => {
			if ( mutation.attributeName === 'class' ) {
				if ( $( mutation.target ).is( '.ui-entityselector-input-recognized' ) ) {
					logPropertySelected( $( mutation.target ).val() );
				}
			}
		} );
	} );

	mw.hook( 'wikibase.entityselector.search' ).add( ( data, addPromise ) => {

		const suggester = new PropertySuggester( data.element );
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
