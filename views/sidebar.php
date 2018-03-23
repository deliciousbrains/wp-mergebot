<div class="sidebar">
	<div class="header">
		<?php $bot->render_view( 'logo' ); ?>
	</div>
	<div class="block support">
		<h4>Support</h4>
		<ul>
			<li>
				<a href="<?php echo $support_url; ?>"><?php _e( 'Request support', 'mergebot' ); ?></a>
			</li>
			<li>
				<a href="<?php echo $diagnostic_info_url; ?>"><?php _e( 'Download Diagnostic Data', 'mergebot' ); ?></a>
			</li>
			<li class="subtle">
				<?php echo wptexturize( __( 'Please attach the diagnostic log with a support request.', 'mergebot' ) ); // xss ok ?>
			</li>
		</ul>
	</div>
	<div class="block credits">
		<h4>Created &amp; Maintained By</h4>
		<ul>
			<li>
				<a href="https://deliciousbrains.com/?utm_source=insideplugin&amp;utm_medium=web&amp;utm_content=sidebar&amp;utm_campaign=mergebot">
					<img src="//www.gravatar.com/avatar/e62fc2e9c8d9fc6edd4fea5339036a91?size=64" alt="" width="32" height="32">
					<span>Delicious Brains Inc.</span>
				</a>
			</li>
		</ul>
	</div>
</div>
