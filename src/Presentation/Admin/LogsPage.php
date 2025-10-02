<?php
// phpcs:ignoreFile

namespace {
        if ( ! class_exists( 'WP_List_Table', false ) ) {
                if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
                }
                if ( ! class_exists( 'WP_List_Table', false ) ) {
                        // Lightweight stub to avoid fatal errors during tests when WP_List_Table is absent.
                        #[\AllowDynamicProperties]
                        class WP_List_Table {
                                protected array $items        = array();
                                public array $_column_headers = array();
                                public function prepare_items() {}
                                public function display() {
                                        echo '<table class="wp-list-table">';
                                        if ( ! empty( $this->_column_headers[0] ) ) {
                                                echo '<thead><tr>';
                                                foreach ( $this->_column_headers[0] as $label ) {
                                                        echo '<th>' . esc_html( (string) $label ) . '</th>';
                                                }
                                                echo '</tr></thead>';
                                        }
                                        echo '<tbody>';
                                        foreach ( $this->items as $row ) {
                                                echo '<tr>';
                                                foreach ( $row as $col ) {
                                                        echo '<td>' . esc_html( (string) $col ) . '</td>';
                                                }
                                                echo '</tr>';
                                        }
                                        echo '</tbody></table>';
                                }
                                protected function column_default( $item, $column_name ) {
                                        return $item[ $column_name ] ?? '';
                                }
                                protected function get_columns() {
                                        return array(); }
                                protected function set_pagination_args( $args ) {}
                        }
                }
        }
}

namespace Sii\BoletaDte\Presentation\Admin {

use Sii\BoletaDte\Infrastructure\Persistence\LogDb;
use WP_List_Table;

class LogsTable extends WP_List_Table {
	private array $items_data = array();

	public function get_columns() {
		return array(
			'track_id'   => __( 'Track ID', 'sii-boleta-dte' ),
			'status'     => __( 'Estado', 'sii-boleta-dte' ),
			'created_at' => __( 'Fecha', 'sii-boleta-dte' ),
		);
	}

	public function prepare_items(): void {
		$status   = isset( $_GET['status'] ) ? sanitize_text_field( (string) $_GET['status'] ) : '';
		$track_id = isset( $_GET['track_id'] ) ? sanitize_text_field( (string) $_GET['track_id'] ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = 20;
		$args     = array( 'limit' => $per_page );
		if ( $status ) {
			$args['status'] = $status;
		}
		$logs = LogDb::get_logs( $args );
		if ( $track_id ) {
			$logs = array_values( array_filter( $logs, static fn( $row ) => $row['track_id'] === $track_id ) );
		}
		$this->items = $logs;
		$total       = count(
			LogDb::get_logs(
				array(
					'status' => $status,
					'limit'  => PHP_INT_MAX,
				)
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}

class LogsPage {
	public function register(): void {
		if ( function_exists( 'add_submenu_page' ) ) {
			add_submenu_page(
				'sii-boleta-dte',
				__( 'Logs', 'sii-boleta-dte' ),
				__( 'Logs', 'sii-boleta-dte' ),
				'manage_options',
				'sii-boleta-dte-logs',
				array( $this, 'render_page' )
			);
		}
	}

        public function render_page(): void {
                $table = new LogsTable();
                $table->prepare_items();
                AdminStyles::open_container( 'sii-logs-page' );
				echo '<h1>' . esc_html__( 'Registros', 'sii-boleta-dte' ) . '</h1>';
                echo '<div class="sii-admin-card sii-admin-card--table">';
                $table->display();
                echo '</div>';
                AdminStyles::close_container();
        }
}

class_alias( LogsPage::class, 'SII_Boleta_Logs_Page' );
}
