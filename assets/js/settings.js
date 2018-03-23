(function( $ ) {

	/**
	 * Controls the settings page UI for the plugin
	 */
	var mergebotSettings = {

		/**
		 * Hide the rows for hidden input settings
		 */
		hideParentRows: function() {
			$( 'input[type=hidden].hidden' ).each( function() {
				$( this ).parents( 'tr' ).hide();
			} );
		}
	};

	$( document ).ready( function() {
		mergebotSettings.hideParentRows();

		// Ask for confirmation when rejecting a changeset
		$( 'body' ).on( 'click', 'a.discard-changeset', function( e ) {
			return confirm( mergebot_admin.strings.reject_changeset_confirm );
		} );
	} );

})( jQuery );
