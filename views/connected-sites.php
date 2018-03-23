<label><?php echo $title ?>:</label>
<?php if ( empty( $connected_sites ) ) : ?>
	<span class="error"><?php echo $empty_msg ?></span>
<?php else: ?>
	<?php
	$count = count( $connected_sites );
	foreach ( $connected_sites as $i => $connected_site ) : ?>
		<span>
		<a target="_blank" href="<?php echo $connected_site['admin_url']; ?>">
				<?php echo $connected_site['url']; ?>
			</a>
		</span><?php echo ( $count > 0 && $i < ( $count - 1 ) ) ? '/ ' : ''; ?>
	<?php endforeach; ?>
<?php endif; ?>