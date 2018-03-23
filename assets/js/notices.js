(function( $, wp ) {

	var $body = $( 'body' );

	$body.on( 'click', '.mergebot-notice .notice-dismiss', function( e ) {
		var id = $( this ).parents( '.mergebot-notice' ).attr( 'id' );
		if ( ! id ) {
			return;
		}

		var data = {
			notice_id: id,
			_nonce: mergebot_notice.nonces.dismiss_notice
		};

		var request = wp.ajax.post( 'mergebot_dismiss_notice', data );

		request.fail( function( response ) {
			alert( response.statusText );
		} );
	} );

	$body.on( 'click', '.mergebot-notice-toggle', function( e ) {
		e.preventDefault();
		var $link = $( this );
		var label = $link.data( 'hide' );

		$link.data( 'hide', $link.html() );
		$link.html( label );

		$link.closest( '.mergebot-notice' ).find( '.mergebot-notice-toggle-content' ).toggle();
	} );

})( jQuery, window.wp );
