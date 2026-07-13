<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAK_Upgrade_Renderer {

	public static function panel( string $title, string $description, array $features = array() ): void {
		?>
		<div class="wpak-card wpak-module-locked wpak-pro-locked">
			<div class="wpak-card-header">
				<i class="ph ph-lock-key" aria-hidden="true"></i>
				<div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div>
			</div>
			<div class="wpak-card-body">
				<?php if ( $features ) : ?>
					<div class="wpak-module-features">
						<?php foreach ( $features as $feature ) : ?>
							<em><?php echo esc_html( $feature ); ?></em>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<a class="wpak-btn wpak-btn-primary" href="https://wpaikits.site/pricing" target="_blank" rel="noreferrer">
					Upgrade to Pro <i class="ph ph-arrow-right" aria-hidden="true"></i>
				</a>
			</div>
		</div>
		<?php
	}

	public static function module_card(
		string $title,
		string $eyebrow,
		string $description,
		array $features
	): void {
		?>
		<article class="wpak-module-card wpak-pro-locked">
			<span class="wpak-module-check"><i class="ph ph-lock-key" aria-hidden="true"></i></span>
			<span class="wpak-module-copy">
				<small><?php echo esc_html( $eyebrow ); ?></small>
				<strong><?php echo esc_html( $title ); ?></strong>
				<span><?php echo esc_html( $description ); ?></span>
			</span>
			<b class="wpak-module-status">Pro</b>
			<span class="wpak-module-features">
				<?php foreach ( $features as $feature ) : ?><em><?php echo esc_html( $feature ); ?></em><?php endforeach; ?>
			</span>
		</article>
		<?php
	}
}
