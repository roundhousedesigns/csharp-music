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
					// Debug: Log the header names
					error_log( 'RHD CSV Debug: Headers found: ' . print_r( $headers, true ) );
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
	 * Parse a slice of the CSV file without loading the entire file
	 *
	 * @param string $file_path
	 * @param int    $offset     Number of data rows to skip (excludes header)
	 * @param int    $limit      Max number of rows to return
	 * @return array
	 */
	public function parse_slice( $file_path, $offset, $limit ) {
		$data    = [];
		$headers = [];
		$offset  = max( 0, intval( $offset ) );
		$limit   = max( 0, intval( $limit ) );

		if ( 0 === $limit ) {
			return $data;
		}

		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			$row_count = 0;
			// Read header
			while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				if ( 0 === $row_count ) {
					$headers = $row;
					$row_count++;
					break;
				}
			}

			// Skip rows up to offset
			$skipped = 0;
			while ( $skipped < $offset && ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				$skipped++;
			}

			// Read up to limit rows
			$read = 0;
			while ( $read < $limit && ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				if ( count( $headers ) === count( $row ) ) {
					$data[] = array_combine( $headers, $row );
				}
				$read++;
			}

			fclose( $handle );
		}

		return $data;
	}
}
