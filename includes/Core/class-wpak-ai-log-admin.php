<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_AI_Log_Admin {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			'wpaikits',
			'WP AI Kits Logs',
			'Logs',
			'manage_options',
			'wpaikits-logs',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$rounds = WPAK_AI_Log_Rounds::from_logs( WPAK_AI_Logger::latest( 200 ) );
		?>
		<div class="wrap wpak-log-page">
			<h1>WP AI Kits Audit Log</h1>
			<p>AI usage grouped by user prompt round for the last 7 days.</p>
			<?php $this->styles(); ?>
			<div class="wpak-log-table-wrap">
				<table class="widefat striped wpak-log-table">
					<thead>
						<tr>
							<th scope="col">Time</th>
							<th scope="col">Tokens consumed</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rounds ) ) : ?>
							<tr><td colspan="2">No AI activity has been recorded yet.</td></tr>
						<?php endif; ?>
						<?php foreach ( $rounds as $round ) : ?>
							<tr>
								<td><?php echo esc_html( $round['time'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $round['tokens'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function styles(): void {
		?>
		<style>
			.wpak-log-page{max-width:960px}
			.wpak-log-page>p{color:#646970;margin-bottom:18px}
			.wpak-log-table-wrap{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}
			.wpak-log-table{border:0;box-shadow:none}
			.wpak-log-table th{font-weight:700}
			.wpak-log-table td,.wpak-log-table th{padding:14px 16px}
			.wpak-log-table td:last-child,.wpak-log-table th:last-child{text-align:right}
		</style>
		<?php
	}
}
