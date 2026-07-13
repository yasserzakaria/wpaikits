<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPAK_PATH . 'includes/Core/Settings/class-wpak-upgrade-renderer.php';

class WPAK_Settings_Renderer {

	public static function render( array $data ): void {
		$is_pro = ! empty( $data['is_pro'] );
		?>
		<div class="wrap wpak-admin-app">
			<?php self::sidebar( (array) $data['active_modules'], $is_pro ); ?>
			<main class="wpak-admin-main">
				<div id="wpak-onboarding-root"></div>
				<header class="wpak-admin-topbar">
					<div>
						<p class="wpak-admin-eyebrow">One problem at a time</p>
						<h1>WP AI Kits</h1>
						<p>Small, focused AI kits that fix real WordPress problems.</p>
					</div>
				</header>

				<div class="wpak-admin-routes">
					<section class="wpak-admin-route is-active" data-route="hub">
						<?php self::hub( $data ); ?>
					</section>
					<section class="wpak-admin-route" data-route="profiles">
						<div id="wpak-core-settings-root"></div>
					</section>
					<?php if ( $is_pro ) : ?>
						<section class="wpak-admin-route" data-route="context">
							<?php self::pro_route( $is_pro, 'AI Context', 'Global brand context and block controls are available in Pro.', 'wpak-ai-context-root' ); ?>
						</section>
						<section class="wpak-admin-route" data-route="architect">
							<?php self::module_route( 'architect', (array) $data['active_modules'], $is_pro, 'Editor AI Kit', 'AI Block Builder and reusable Skills are paused until this kit is active.', 'wpak-architect-root' ); ?>
						</section>
						<section class="wpak-admin-route" data-route="magic-wand">
							<?php self::magic_wand_route( (array) $data['active_modules'], $is_pro ); ?>
						</section>
					<?php endif; ?>
					<section class="wpak-admin-route" data-route="archivist">
						<?php self::module_route( 'archivist', (array) $data['active_modules'], true, 'Media AI Kit', 'AI metadata generation and background processing are paused until this kit is active.', 'wpak-archivist-root' ); ?>
					</section>
				</div>
			</main>
		</div>
		<?php
	}

	private static function sidebar( array $active_modules, bool $is_pro ): void {
		$items = array(
			array( 'The Hub', 'house', 'hub', true, true ),
			array( 'AI Settings', 'sliders-horizontal', 'profiles', false, true ),
		);

		if ( $is_pro ) {
			$items[] = array( 'AI Context', 'brain', 'context', false, true );
			$items[] = array( 'Editor AI Kit', 'squares-four', 'architect', false, in_array( 'architect', $active_modules, true ), 'Off' );
			$items[] = array( 'Magic Wand Kit', 'magic-wand', 'magic-wand', false, in_array( 'magic-wand', $active_modules, true ), 'Off' );
		}

		$items[] = array( 'Media AI Kit', 'image', 'archivist', false, in_array( 'archivist', $active_modules, true ) );
		?>
		<aside class="wpak-admin-sidebar">
			<div class="wpak-admin-brand">
				<img src="<?php echo esc_url( WPAK_URL . 'logo.svg' ); ?>" alt="WP AI Kits" />
			</div>
			<nav class="wpak-admin-nav" aria-label="WP AI Kits">
				<?php foreach ( $items as $item ) : ?>
					<a class="<?php echo esc_attr( self::nav_class( $item ) ); ?>" href="#<?php echo esc_attr( $item[2] ); ?>" data-route="<?php echo esc_attr( $item[2] ); ?>">
						<?php self::icon( $item[1] ); ?> <span><?php echo esc_html( $item[0] ); ?></span>
						<?php if ( empty( $item[4] ) ) : ?><em><?php echo esc_html( $item[5] ?? 'Off' ); ?></em><?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</aside>
		<?php
	}

	private static function hub( array $data ): void {
		$active = (array) $data['active_modules'];
		$stats  = (array) ( $data['hub_stats'] ?? array() );
		?>
		<div class="wpak-hub-grid">
			<?php self::kit_card( $active, $stats ); ?>
			<?php self::suggest_kit_card(); ?>
		</div>
		<?php self::quick_actions(); ?>
		<?php
	}

	private static function kit_card( array $active, array $stats ): void {
		$enabled   = in_array( 'archivist', $active, true );
		$processed = (int) ( $stats['processed'] ?? 0 );
		$in_queue  = (int) ( $stats['in_queue'] ?? 0 );
		$last_run  = (string) ( $stats['last_run'] ?? '' );
		?>
		<article class="wpak-kit-card">
			<div class="wpak-kit-card-top">
				<span class="wpak-kit-icon"><i class="ph ph-image" aria-hidden="true"></i></span>
				<?php if ( $enabled ) : ?>
					<span class="wpak-kit-badge is-active"><span class="wpak-kit-dot"></span> Active</span>
				<?php else : ?>
					<span class="wpak-kit-badge">Inactive</span>
				<?php endif; ?>
			</div>
			<h2 class="wpak-kit-title">Media AI Kit</h2>
			<p class="wpak-kit-desc">Fill missing alt text across your library, add searchable titles and descriptions, and cover new uploads automatically.</p>
			<div class="wpak-kit-tags">
				<span>Useful alt text</span><span>Searchable titles</span><span>New uploads</span>
			</div>
			<div class="wpak-kit-stats">
				<div><strong><?php echo esc_html( number_format_i18n( $processed ) ); ?></strong><span>Images processed</span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $in_queue ) ); ?></strong><span>In queue</span></div>
				<div><strong>Last run</strong><span><?php echo esc_html( '' !== $last_run ? $last_run : 'Not yet' ); ?></span></div>
			</div>
			<a class="wpak-btn wpak-btn-primary wpak-kit-cta" href="#archivist" data-route="archivist">
				Open Kit <?php self::icon( 'arrow-right' ); ?>
			</a>
		</article>
		<?php
	}

	private static function suggest_kit_card(): void {
		?>
		<article class="wpak-kit-card wpak-kit-suggest">
			<span class="wpak-kit-icon is-yellow"><i class="ph ph-lightbulb" aria-hidden="true"></i></span>
			<h2 class="wpak-kit-title">Suggest a new kit</h2>
			<p class="wpak-kit-desc">What WordPress chore wastes your time? Tell us the problem and we will consider it for the next kit.</p>
			<a class="wpak-btn wpak-btn-secondary wpak-kit-cta" href="mailto:hello@wpaikits.site?subject=Kit%20idea">
				Share your idea <?php self::icon( 'arrow-right' ); ?>
			</a>
		</article>
		<?php
	}

	private static function quick_actions(): void {
		?>
		<div class="wpak-quick-actions">
			<div class="wpak-quick-actions-head">
				<strong>Quick actions</strong>
				<span>Shortcuts to the most common tasks.</span>
			</div>
			<a class="wpak-quick-action" href="#profiles" data-route="profiles">
				<span><strong>AI Settings</strong><small>Manage your providers</small></span>
				<?php self::icon( 'caret-right' ); ?>
			</a>
			<a class="wpak-quick-action" href="#archivist" data-route="archivist">
				<span><strong>Media AI Kit</strong><small>Configure automation</small></span>
				<?php self::icon( 'caret-right' ); ?>
			</a>
			<a class="wpak-quick-action" href="#archivist" data-route="archivist">
				<span><strong>View activity</strong><small>See recent runs</small></span>
				<?php self::icon( 'caret-right' ); ?>
			</a>
		</div>
		<?php
	}

	private static function card_header( string $icon, string $title, string $description ): void {
		?>
		<div class="wpak-card-header">
			<?php self::icon( $icon ); ?>
			<div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div>
		</div>
		<?php
	}

	private static function module_route( string $slug, array $active, bool $available, string $title, string $message, string $root_id ): void {
		if ( ! $available ) {
			WPAK_Upgrade_Renderer::panel(
				$title . ' is a Pro feature',
				'Upgrade to unlock the AI Block Builder, reusable Skills, and AI media generation.',
				array( 'AI Block Builder', 'Skills', '@ Mentions', 'Generative Media' )
			);
			return;
		}

		if ( in_array( $slug, $active, true ) ) {
			echo '<div id="' . esc_attr( $root_id ) . '"></div>';
			return;
		}
		?>
		<div class="wpak-card wpak-module-locked">
			<?php self::card_header( 'lock-key', $title . ' is inactive', $message ); ?>
			<div class="wpak-card-body">
				<a class="wpak-btn wpak-btn-primary" href="#hub"><?php self::icon( 'cube' ); ?> Activate in The Hub</a>
			</div>
		</div>
		<?php
	}

	private static function pro_route( bool $is_pro, string $title, string $message, string $root_id ): void {
		if ( $is_pro ) {
			echo '<div id="' . esc_attr( $root_id ) . '"></div>';
			return;
		}

		WPAK_Upgrade_Renderer::panel(
			$title . ' is a Pro feature',
			$message,
			array( 'Brand context', 'Block controls', 'Reusable instructions' )
		);
	}

	private static function magic_wand_route( array $active, bool $is_pro ): void {
		if ( ! $is_pro ) {
			WPAK_Upgrade_Renderer::panel(
				'Magic Wand Kit is a Pro feature',
				'Edit selected Gutenberg blocks with AI without loading the full block builder.',
				array( 'Inline editing', 'Scope selection', 'Review and undo' )
			);
			return;
		}

		if ( ! in_array( 'magic-wand', $active, true ) ) {
			self::inactive_module( 'Magic Wand Kit', 'Inline AI editing is paused until this kit is active.' );
			return;
		}

		?>
		<div class="wpak-card">
			<?php self::card_header( 'magic-wand', 'Magic Wand Kit is active', 'Select a Gutenberg block and use Magic Wand from the block toolbar.' ); ?>
			<div class="wpak-card-body"><p>Magic Wand uses your Editor AI Kit routing profile and works independently from the AI Block Builder.</p></div>
		</div>
		<?php
	}

	private static function inactive_module( string $title, string $message ): void {
		?>
		<div class="wpak-card wpak-module-locked">
			<?php self::card_header( 'lock-key', $title . ' is inactive', $message ); ?>
			<div class="wpak-card-body">
				<a class="wpak-btn wpak-btn-primary" href="#hub"><?php self::icon( 'cube' ); ?> Activate in The Hub</a>
			</div>
		</div>
		<?php
	}

	private static function nav_class( array $item ): string {
		$classes = array();
		if ( ! empty( $item[3] ) ) {
			$classes[] = 'is-active is-default';
		}
		if ( empty( $item[4] ) ) {
			$classes[] = 'is-inactive';
		}
		return implode( ' ', $classes );
	}

	private static function icon( string $name ): void {
		echo '<i class="ph ph-' . esc_attr( $name ) . '" aria-hidden="true"></i>';
	}
}
