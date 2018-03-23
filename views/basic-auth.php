<form method="post" id="basic-auth-form">
	<input type="hidden" name="plugin" value="<?php echo $slug; ?>" />
	<input type="hidden" name="action" value="save" />
	<input type="hidden" name="form" value="basic-auth" />
	<?php wp_nonce_field( $slug . '-options' ) ?>
	<table class="form-table">
		<tr>
			<td>
				<input type="text" name="auth_username" class="auth-username auth-credentials" placeholder="Username" value="<?php echo esc_html( $username ); ?>" autocomplete="off" />
				<input type="password" name="auth_password" class="auth-password auth-credentials" placeholder="Password" value="<?php echo esc_html( $password ); ?>" autocomplete="off" />
				<?php submit_button( __( 'Save', 'mergebot' ), 'primary', 'submit', false ); ?>
			</td>
		</tr>
	</table>
</form>