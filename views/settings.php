<form method="post">
	<input type="hidden" name="plugin" value="<?php echo $slug; ?>" />
	<input type="hidden" name="action" value="save" />
	<?php wp_nonce_field( $slug . '-options' ) ?>
	<table class="form-table">
		<?php if ( $is_dev_mode ) : ?>
			<tr>
				<th class="row">
					<?php _e( 'Production Site', 'mergebot' ); ?>
				</th>
				<td>
					<?php $page->render_parent_site_id(); ?>
				</td>
			</tr>
		<?php endif; ?>
		<?php if ( ! $is_dev_mode ) : ?>
			<tr>
				<th class="row">
					<?php _e( 'Team', 'mergebot' ); ?>
				</th>
				<td>
					<?php $page->render_team_id(); ?>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
	$attributes = null;
	if ( false === $page->can_connect_site() ) {
		// Disable the button if API down or no connected prod sites when in dev mode
		$attributes = array( 'disabled' => 'disabled' );
	}
	submit_button( __( 'Connect Site', 'mergebot' ), 'primary', 'submit', true, $attributes ); ?>
</form>