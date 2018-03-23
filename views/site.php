<div class="postbox site">
	<h2 class="hndle"><span><?php echo $site_url ?></span></h2>
	<div class="inside">
		<div class="submitbox">
			<div class="data">
				<ul>
					<li>
						<label>Mode:</label>
						<span><?php echo ucfirst( $bot->mode() ); ?></span>
					</li>
					<?php if ( is_wp_error( $can_deploy ) ) : ?>
						<li>
							<p class="deploy-warning"><?php
								$error_data = $can_deploy->get_error_data();

								echo $can_deploy->get_error_message() . ( $bot->is_dev_mode() && empty( $error_data ) ? $db_support_link : '' ); ?>
							</p>
						</li>
					<?php endif; ?>
				</ul>
				<div class="clear"></div>
			</div>
			<?php if ( false !== $can_deploy ) : ?>
				<div id="major-publishing-actions">
					<div id="publishing-action">
						<?php if ( ! is_wp_error( $can_deploy ) ) : ?>
							<a href="<?php echo $deploy_url; ?>" title="<?php _e( 'Apply Changeset', 'mergebot' ); ?>" class="button button-primary button-large"><?php _e( 'Apply Changeset', 'mergebot' ); ?></a>
						<?php else : ?>
							<input type="button" disabled="disabled" class="button button-primary button-large" value="<?php _e( 'Apply Changeset', 'mergebot' ); ?>">
						<?php endif; ?>
					</div>
					<div class="clear"></div>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>