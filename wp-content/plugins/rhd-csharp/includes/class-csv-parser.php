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
}
