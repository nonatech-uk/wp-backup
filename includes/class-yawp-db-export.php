<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_DB_Export {

    /**
     * Export the entire database to a SQL file using $wpdb.
     */
    public function export( $output_path ) {
        global $wpdb;

        // Use raw mysqli to avoid $wpdb->_real_escape() corrupting %
        // characters via WordPress placeholder escaping.
        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return new WP_Error( 'yawp_db', 'Cannot open database connection for export.' );
        }

        $fh = fopen( $output_path, 'w' );
        if ( ! $fh ) {
            mysqli_close( $dbh );
            return new WP_Error( 'yawp_db', 'Cannot open output file: ' . $output_path );
        }

        fwrite( $fh, "-- YAWP Database Export\n" );
        fwrite( $fh, "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
        fwrite( $fh, "-- WordPress DB: " . DB_NAME . "\n\n" );
        fwrite( $fh, "SET NAMES utf8mb4;\n" );
        fwrite( $fh, "SET foreign_key_checks = 0;\n\n" );

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! $tables ) {
            fclose( $fh );
            mysqli_close( $dbh );
            return new WP_Error( 'yawp_db', 'No tables found in database.' );
        }

        foreach ( $tables as $table ) {
            $result = $this->export_table( $fh, $dbh, $table );
            if ( is_wp_error( $result ) ) {
                fclose( $fh );
                mysqli_close( $dbh );
                return $result;
            }
        }

        fwrite( $fh, "SET foreign_key_checks = 1;\n" );
        fclose( $fh );
        mysqli_close( $dbh );

        return true;
    }

    private function export_table( $fh, $dbh, $table ) {
        $create_result = mysqli_query( $dbh, "SHOW CREATE TABLE `{$table}`" );
        $create = $create_result ? mysqli_fetch_row( $create_result ) : null;
        if ( ! $create || ! isset( $create[1] ) ) {
            return new WP_Error( 'yawp_db', "Cannot get CREATE TABLE for {$table}." );
        }

        fwrite( $fh, "-- --------------------------------------------------------\n" );
        fwrite( $fh, "-- Table: {$table}\n" );
        fwrite( $fh, "-- --------------------------------------------------------\n\n" );

        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create[1] . ";\n\n" );

        // Find a single-column primary key for keyset pagination.
        // Without it LIMIT/OFFSET pagination is O(n²) on large tables —
        // exporting a million-row wp_postmeta would take quadratic time.
        $pk_col = $this->find_keyset_column( $dbh, $table );

        $batch         = $pk_col ? 1000 : 100;
        $columns       = null;
        $pk_index      = -1;
        $last_pk       = null;
        $offset        = 0;
        $max_stmt_size = 1048576; // 1 MB per INSERT statement.

        while ( true ) {
            if ( $pk_col ) {
                $where = ( null === $last_pk )
                    ? ''
                    : "WHERE `{$pk_col}` > '" . mysqli_real_escape_string( $dbh, $last_pk ) . "'";
                $sql = "SELECT * FROM `{$table}` {$where} ORDER BY `{$pk_col}` LIMIT {$batch}";
            } else {
                $sql = "SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}";
            }

            $result = mysqli_query( $dbh, $sql );
            if ( ! $result || 0 === mysqli_num_rows( $result ) ) {
                if ( $result ) {
                    mysqli_free_result( $result );
                }
                break;
            }

            // Get column names + PK column index once per table.
            if ( null === $columns ) {
                $fields  = mysqli_fetch_fields( $result );
                $columns = [];
                foreach ( $fields as $i => $f ) {
                    $columns[] = '`' . $f->name . '`';
                    if ( $pk_col && $f->name === $pk_col ) {
                        $pk_index = $i;
                    }
                }
            }

            $insert_prefix = 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES ';
            $values        = [];
            $stmt_size     = strlen( $insert_prefix );

            while ( $row = mysqli_fetch_row( $result ) ) {
                $escaped = array_map( function ( $val ) use ( $dbh ) {
                    if ( null === $val ) {
                        return 'NULL';
                    }
                    return "'" . mysqli_real_escape_string( $dbh, $val ) . "'";
                }, $row );
                $row_str  = '(' . implode( ',', $escaped ) . ')';
                $row_size = strlen( $row_str ) + 2; // comma + newline.

                // Flush if adding this row would exceed the limit.
                if ( ! empty( $values ) && ( $stmt_size + $row_size ) > $max_stmt_size ) {
                    fwrite( $fh, $insert_prefix . "\n" . implode( ",\n", $values ) . ";\n" );
                    $values    = [];
                    $stmt_size = strlen( $insert_prefix );
                }

                $values[]   = $row_str;
                $stmt_size += $row_size;

                if ( $pk_index >= 0 ) {
                    $last_pk = $row[ $pk_index ];
                }
            }

            if ( ! empty( $values ) ) {
                fwrite( $fh, $insert_prefix . "\n" . implode( ",\n", $values ) . ";\n" );
            }

            $rows_read = mysqli_num_rows( $result );
            mysqli_free_result( $result );

            // Stop when we got fewer rows than the batch size — no more pages.
            if ( $rows_read < $batch ) {
                break;
            }
            if ( ! $pk_col ) {
                $offset += $batch;
            }
        }

        fwrite( $fh, "\n" );
        return true;
    }

    /**
     * Return the name of a single-column primary key suitable for keyset
     * pagination, or null if the table has no PK (or has a composite PK).
     */
    private function find_keyset_column( $dbh, $table ) {
        $result = mysqli_query( $dbh, "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'" );
        if ( ! $result ) {
            return null;
        }
        $cols = [];
        while ( $row = mysqli_fetch_assoc( $result ) ) {
            $cols[] = $row['Column_name'];
        }
        mysqli_free_result( $result );
        return ( 1 === count( $cols ) ) ? $cols[0] : null;
    }

    private function get_raw_dbh() {
        $host = DB_HOST;
        $port = 3306;

        if ( strpos( $host, ':' ) !== false ) {
            list( $host, $port ) = explode( ':', $host, 2 );
            $port = (int) $port;
        }

        $dbh = mysqli_init();

        if ( defined( 'MYSQL_CLIENT_FLAGS' ) && ( MYSQL_CLIENT_FLAGS & MYSQLI_CLIENT_SSL ) ) {
            mysqli_ssl_set( $dbh, null, null, null, null, null );
        }

        if ( ! mysqli_real_connect( $dbh, $host, DB_USER, DB_PASSWORD, DB_NAME, $port, null, defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ) ) {
            return false;
        }

        mysqli_set_charset( $dbh, DB_CHARSET );
        return $dbh;
    }
}
