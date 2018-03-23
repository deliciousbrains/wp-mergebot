<div class="error mergebot-requirements-notice">
	<p>
		<?php
		$deactivate_link = sprintf( '<a style="text-decoration:none;" href="%s">%s</a>', $deactivate_url, __( 'deactivate', 'mergebot' ) );
		printf( __( 'Mergebot plugin disabled as it requires %s. You can %s the plugin to remove this notice.', 'mergebot' ), $requirements, $deactivate_link ); ?>
	</p>
</div>
