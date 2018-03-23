(function( $, wp ) {

	/**
	 * Controls the button in the admin bar for recording
	 */
	wp.mergebotAdminBar = {

		/**
		 * Button object
		 */
		button: $( '#wp-admin-bar-mergebot' ),

		/**
		 * Recording state
		 */
		activeStatus: 0,

		/**
		 * Spinner object
		 */
		spinner: false,

		/**
		 * Spinner options
		 */
		spinOptions: {
			lines: 9, // The number of lines to draw
			length: 3, // The length of each line
			width: 2, // The line thickness
			radius: 2, // The radius of the inner circle
			corners: 1, // Corner roundness (0..1)
			rotate: 0, // The rotation offset
			direction: 1, // 1: clockwise, -1: counterclockwise
			color: '#fff', // #rgb or #rrggbb or array of colors
			speed: 1, // Rounds per second
			trail: 60, // Afterglow percentage
			shadow: false, // Whether to render a shadow
			hwaccel: false, // Whether to use hardware acceleration
			className: 'dh-spinner', // The CSS class to assign to the spinner
			zIndex: 2e9, // The z-index (defaults to 2000000000)
			top: '50%', // Top position relative to parent
			left: '50%' // Left position relative to parent
		},

		/**
		 * Set the active status and update the title
		 *
		 * @param status
		 */
		setActiveStatus: function( status ) {
			this.activeStatus = parseInt( status );
			this.updateTitle();
		},

		/**
		 * Toggle the active status
		 */
		toggleActiveStatus: function() {
			var newStatus = ( 1 === this.activeStatus ) ? 0 : 1;
			this.setActiveStatus( newStatus );
		},

		/**
		 * Initialize the spinner
		 */
		initSpinner: function() {
			if ( ! this.spinner ) {
				this.spinner = new Spinner( this.spinOptions );
			}
		},

		/**
		 * Toggle the spinner
		 *
		 * @param toggle
		 */
		toggleSpinner: function( toggle ) {
			this.initSpinner();

			if ( toggle ) {
				this.spinner.spin( this.button[ 0 ] );
			} else {
				this.spinner.stop();
			}
		},

		/**
		 * Toggle the button recording display
		 */
		toggleButton: function( data ) {
			data = 'undefined' !== typeof data ? data : {};

			if ( this.button.hasClass( 'doing-ajax' ) ) {
				return;
			}

			this.button.addClass( 'doing-ajax' );

			this.toggleSpinner( true );

			var that = this;

			var default_data = {
				action: 'mergebot_toggle_recorder',
				active_status: this.activeStatus
			};

			data = $.extend( default_data, data );

			var request = wp.ajax.post( 'mergebot_toggle_recorder', data );

			request.done( function() {
				that.button.removeClass( 'doing-ajax' );
				that.toggleSpinner( false );
				that.toggleActiveStatus();
			} );

			request.fail( function( response ) {
				that.button.removeClass( 'doing-ajax' );
				that.toggleSpinner( false );
				that.throwError( response.statusText );
			} );

			return request;
		},

		/**
		 * Update the button title
		 */
		updateTitle: function() {
			if ( 1 === this.activeStatus ) {
				this.button.attr( 'title', mergebot.strings.stop_recording );
				this.button.addClass( 'active' );
			} else {
				this.button.attr( 'title', mergebot.strings.start_recording );
				this.button.removeClass( 'active' );
			}
		},

		/**
		 * Throw an error whilst changing the button state
		 */
		throwError: function( message ) {
			message = ': ' + message || '';

			if ( '1' === this.activeStatus ) {
				alert( mergebot.strings.ajax_problem_off + message );
			} else {
				alert( mergebot.strings.ajax_problem_on + message );
			}
		}
	};

	$( document ).ready( function() {
		// Set the state of the button
		wp.mergebotAdminBar.setActiveStatus( mergebot.active );

		// Listen for button clicks
		$( '#wp-admin-bar-mergebot' ).click( function() {
			wp.mergebotAdminBar.toggleButton();
		} );
	} );

})( jQuery, window.wp );
