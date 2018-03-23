<div class="wrap mergebot-wrap" data-mode="<?php echo ( false === $mode ) ? '' : $mode; ?>">
	<div class="main">
		<h1>
			<?php echo $bot->name(); ?>
			<?php if ( $bot->is_beta() ) : ?>
				<span class="beta">Beta</span>
			<?php endif; ?>
			<?php
			if ( $is_setup ) {
				$class = $site_connected ? 'connected' : 'disconnected';
				?>
				<span class="site-connected <?php echo $class; ?> dashicons dashicons-admin-links"><a href="<?php echo $app_url; ?>" target="_blank" title="<?php printf( __( 'Connected to %s', 'mergebot' ), $app_link ); ?>"><?php echo $app_link; ?></a></span>
			<?php } ?>
		</h1>
		<?php do_action( $bot->slug() . '_pre_settings' ); ?>
		<?php
		if ( $is_setup ) {
			do_action( $bot->slug() . '_view_admin' ); ?>
			<div class="metabox-holder">
				<?php do_action( $bot->slug() . '_view_admin_metaboxes' ); ?>
			</div>
		<?php } ?>
	</div>
	<?php do_action( $bot->slug() . '_view_post_admin' ) ?>
</div>