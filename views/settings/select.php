<select class="<?php echo $key; ?>" name="<?php echo $key; ?>">
	<option value=''><?php printf( __( 'Select %s' ), isset( $option_text ) ? $option_text : $key ); ?></option>
	<?php foreach ( $options as $option_key => $option_value ) : ?>
		<option value="<?php echo $option_key; ?>" <?php selected( $selected, $option_key ); ?>><?php echo $option_value; ?></option>
	<?php endforeach; ?>
</select>