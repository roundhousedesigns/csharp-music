<?php
/**
 * CSV file parser class
 */
class RHD_CSharp_CSV_Parser {

	/**
	 * Parse CSV file
	 */
	public function parse( $file_path ) {
		$data    = [];
		$headers = [];

		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			$row_count = 0;
			while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				if ( 0 === $row_count ) {
					$headers = $row;
				} else {
					if ( count( $headers ) === count( $row ) ) {
						$data[] = array_combine( $headers, $row );
					}
				}
				$row_count++;
			}
			fclose( $handle );
		}

		return $data;
	}

	/**
	 * Validate import files exist
	 */
	public function validate_import_files( $csv_data ) {
		$missing_files = [];
		$base_path     = WP_CONTENT_DIR . '/csharp-import/';
		$file_handler  = new RHD_CSharp_File_Handler();

		foreach ( $csv_data as $row ) {
			// Check protected file
			if ( !empty( $row['Product File Name'] ) ) {
				$file_path = $file_handler->find_file_in_directory( $base_path . 'protected-files', $row['Product File Name'] );
				if ( !$file_path ) {
					$missing_files[] = 'Protected: ' . $row['Product File Name'];
				}
			}

			// Check image file
			if ( !empty( $row['Image File Name'] ) ) {
				$file_path = $file_handler->find_file_in_directory( $base_path . 'images', $row['Image File Name'] );
				if ( !$file_path ) {
					$missing_files[] = 'Image: ' . $row['Image File Name'];
				}
			}

			// Check sound files
			$sound_files_field = 'Sound Filenames (comma-separated)\nExample: f-warmup.mp3, g-warmup.wav';
			if ( !empty( $row[$sound_files_field] ) ) {
				$filenames = array_map( 'trim', explode( ',', $row[$sound_files_field] ) );
				foreach ( $filenames as $filename ) {
					if ( !empty( $filename ) ) {
						$file_path = $file_handler->find_file_in_directory( $base_path . 'sounds', $filename );
						if ( !$file_path ) {
							$missing_files[] = 'Sound: ' . $filename;
						}
					}
				}
			}
		}

		return $missing_files;
	}
}
