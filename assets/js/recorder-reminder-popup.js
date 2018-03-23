( function( $, wp ) {

		/**
		 * Popup to remind people to turn on recording
		 */
		var mergebotRecorderReminderPopup = {

			$container: $( '.mergebot-reminder-popup' ),

			close: function() {
				this.$container.hide();
			},

			open: function() {
				this.$container.show();
			},

			toggleProcessing: function( processing ) {
				this.$container.toggleClass( 'doing-ajax', processing );
			},

			isProcessing: function() {
				return this.$container.hasClass( 'doing-ajax' );
			},

			anchorToRecorder: function( recorderElement ) {
				var $button = $( recorderElement );
				if ( $button.outerHeight() >= 46 ) {
					return;
				}

				var button_half = $button.outerWidth() / 2;
				var right = $( window ).width() - $button.offset().left - button_half;
				var container_half = this.$container.outerWidth() / 2;
				right = right - container_half;

				this.$container.css(
					{
						'float': 'none',
						'margin-right': '0',
						'right': right + 'px'
					}
				);
			},

			actionClick: function( $button ) {
				if ( this.isProcessing() ) {
					return;
				}

				this.toggleProcessing( true );

				this.close();

				if ( 'dismiss' === $button.data( 'action' ) ) {
					var minutes = (
						$button.data( 'mins' )
					) ? $button.data( 'mins' ) : 0;
					this.dismiss( minutes );

					return;
				}

				var that = this;

				var request = wp.mergebotAdminBar.toggleButton( { 'background_record': 1 } );

				request.done( function() {
					that.toggleProcessing( false );
				} );

				request.fail( function() {
					that.toggleProcessing( false );
					that.open();
				} );
			},

			dismiss: function( minutes ) {
				var that = this;

				var request = wp.ajax.post( 'mergebot_dismiss_recorder_reminder_popup', { minutes: minutes } );

				request.done( function() {
					that.toggleProcessing( false );
				} );

				request.fail( function( response ) {
					that.toggleProcessing( false );
					that.open();
					alert( response.statusText );
				} );
			}
		};

		$( document ).ready( function() {
			// Tie it to the admin button bottom
			if ( mergebotRecorderReminderPopup.$container.length ) {
				mergebotRecorderReminderPopup.anchorToRecorder( '#wp-admin-bar-mergebot' );
			}

			// Listen for button clicks
			mergebotRecorderReminderPopup.$container.on( 'click', 'a', function( e ) {
				e.preventDefault();

				mergebotRecorderReminderPopup.actionClick( $( this ) );
			} );

			// Listen for turning on recording
			$( '#wp-admin-bar-mergebot' ).click( function() {
				if ( 0 === wp.mergebotAdminBar.activeStatus ) {
					mergebotRecorderReminderPopup.close();
				}
			} );
		} );

	}
)( jQuery, window.wp );
