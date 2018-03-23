<div class="mergebot-reminder-popup">
	<p>
		<strong><?php echo $name; ?> &mdash;</strong> <?php _e( 'Whoa, we just detected some queries that could have been recorded.', 'mergebot' ); ?>
	</p>
	<a data-action="record" href="#"><?php _e( 'Record them and turn on recording', 'mergebot' ); ?></a>
	<a data-action="dismiss" href="#"><?php _e( 'Ignore them and dismiss', 'mergebot' ); ?></a>
	<a data-action="dismiss" data-mins="<?php echo $minutes; ?>" href="#"><?php _e( sprintf( 'Ignore them and dismiss for %d mins', $minutes ), 'mergebot' ); ?></a>
</div>