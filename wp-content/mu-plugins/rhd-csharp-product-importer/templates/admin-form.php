<?php
/**
 * Admin Form Template for C. Sharp Product Importer
 *
 * @package RHD_CSharp_Product_Importer
 *
 * @since 1.0.0
 */

// Prevent direct access
if ( !defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1><?php _e( 'C. Sharp Product Importer', 'rhd' ); ?></h1>

	<div id="rhd-import-progress" style="display: none;">
		<div class="notice notice-info">
			<p>
				<span class="spinner"></span>
				<span id="progress-text"><?php _e( 'Preparing import...', 'rhd' ); ?></span>
				<span id="progress-counter" style="margin-left: 10px; font-weight: bold;"></span>
			</p>
			<div id="progress-bar-container" style="margin-top: 10px; background: #f0f0f0; border-radius: 3px; height: 20px; display: none;">
				<div id="progress-bar" style="background: #0073aa; height: 100%; border-radius: 3px; width: 0%; transition: width 0.3s ease;"></div>
			</div>
		</div>
	</div>

	<div id="rhd-import-results" style="display: none;"></div>

	<form id="rhd-import-form" method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'rhd_csv_import', 'nonce' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="csv_file"><?php _e( 'CSV File', 'rhd' ); ?></label>
				</th>
				<td>
					<input type="file" id="csv_file" name="csv_file" accept=".csv" required />
					<p class="description"><?php _e( 'Upload your C. Sharp product database CSV file.', 'rhd' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="create_bundles"><?php _e( 'Create Product Bundles', 'rhd' ); ?></label>
				</th>
				<td>
					<input type="checkbox" id="create_bundles" name="create_bundles" value="1" checked />
					<label for="create_bundles"><?php _e( 'Automatically create product bundles for each product family', 'rhd' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="update_existing"><?php _e( 'Update Existing Products', 'rhd' ); ?></label>
				</th>
				<td>
					<input type="checkbox" id="update_existing" name="update_existing" value="1" />
					<label for="update_existing"><?php _e( 'Update existing products if SKU matches', 'rhd' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Import Products', 'rhd' ), 'primary', 'submit', false ); ?>
	</form>
</div>
