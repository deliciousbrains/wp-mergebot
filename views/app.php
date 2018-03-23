<div class="postbox app">
	<h2 class="hndle"><span><?php echo $app_url; ?></span></h2>
	<div class="inside">
		<div class="submitbox">
			<div class="data">
				<ul>
					<?php if ( isset( $changeset ) ) : ?>
						<li>
							<label><?php _e( 'Current Changeset ID', 'mergebot' ); ?>:</label>
							<span><?php echo esc_html( $changeset->changeset_id ); ?></span>
						</li>
						<li>
							<label><?php _e( 'Total Queries', 'mergebot' ); ?>:</label>
							<span><?php echo number_format( esc_html( $changeset->total_queries ) ); ?></span>
						</li>
					<?php endif; ?>
					<li>
						<?php do_action( $bot->slug() . '_view_app_data'); ?>
					</li>
				</ul>
				<div class="clear"></div>
			</div>
			<?php if ( isset( $changeset ) ) : ?>
				<div id="major-publishing-actions">
					<div id="delete-action">
						<a class="discard-changeset submitdelete" title="<?php echo __( 'Discard all the queries in the changeset', 'mergebot' ); ?>" href="<?php echo $reject_url; ?>"><?php _e( 'Discard All Changes', 'mergebot' ); ?></a>
					</div>

					<div id="publishing-action">
						<a target="_blank" href="<?php echo esc_url( $changeset->queries_link ); ?>" title="<?php _e( 'View queries in the changeset', 'mergebot' ); ?>" class="button button-large"><?php _e( 'View Queries', 'mergebot' ); ?></a>
					</div>
					<div class="clear"></div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>