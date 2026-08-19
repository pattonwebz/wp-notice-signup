<?php
/**
 * Main plugin runtime.
 *
 * THE BASELINE IS CLEAN, AND THAT IS THE POINT
 * --------------------------------------------
 * This plugin is the thing under test in the WCAG-in-CI/CD demo. It used to
 * ship deliberate accessibility failures permanently. It no longer does:
 * everything here scans at zero violations, front end and admin screen alike.
 *
 * Failures are introduced *deliberately, one demo at a time*, by flipping a
 * flag in get_demo_issue_flags() — normally in a pull request or a release, so
 * a gate has a real regression to catch. A build that was already red proves
 * nothing when it goes red again.
 *
 * The marketing site lives in the separate `corveto-site` plugin. This one is
 * only the product feature: a notice bar, a modal, an inline signup form and a
 * settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Notice_Signup_Plugin {
	const OPTION_KEY = 'wp_notice_signup_settings';

	/**
	 * Singleton instance.
	 *
	 * @var WP_Notice_Signup_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Incremented per rendered form so a page containing more than one copy
	 * (the modal and the inline shortcode, say) cannot emit duplicate IDs.
	 *
	 * Duplicate IDs are not cosmetic: `<label for>` resolves to whichever
	 * element comes first, so clicking a label focuses the wrong field.
	 *
	 * @var int
	 */
	protected $form_instance = 0;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_Notice_Signup_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Seed defaults on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::get_default_settings() );
		}
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_frontend_experience' ) );
		add_shortcode( 'wp_notice_signup_form', array( $this, 'render_inline_form_shortcode' ) );
	}

	/**
	 * Defaults used for the demo plugin.
	 *
	 * @return array<string,string>
	 */
	protected static function get_default_settings() {
		return array(
			'notice_text'         => 'Accessibility updates now land in your inbox first.',
			'button_text'         => 'Get notice updates',
			'form_heading'        => 'Stay in the loop',
			'form_description'    => 'Signup for release alerts, launch news, and short accessibility notes.',
			'email_label'         => 'Email address',
			'name_label'          => 'First name',
			'success_message'     => 'Thanks for signing up. We will email you the next notice.',
			'image_url'           => WP_NOTICE_SIGNUP_URL . 'assets/images/notice-illustration.svg',
			'enable_banner'       => 'yes',
			'enable_inline_form'  => 'yes',
			'demo_variant'        => 'clean-baseline',
			'manual_issue_bucket' => 'defer',
		);
	}

	/**
	 * Register persisted settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'wp_notice_signup',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string,mixed> $input Raw submitted settings.
	 * @return array<string,string>
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::get_default_settings();
		$output   = $defaults;

		foreach ( $defaults as $key => $default_value ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
			}
		}

		$output['enable_banner']      = isset( $input['enable_banner'] ) ? 'yes' : 'no';
		$output['enable_inline_form'] = isset( $input['enable_inline_form'] ) ? 'yes' : 'no';

		return $output;
	}

	/**
	 * Return saved settings with defaults merged in.
	 *
	 * @return array<string,string>
	 */
	protected function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::get_default_settings() );
	}

	/**
	 * Register plugin admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_menu_page(
			__( 'WP Notice Signup', 'wp-notice-signup' ),
			__( 'WP Notice Signup', 'wp-notice-signup' ),
			'manage_options',
			'wp-notice-signup',
			array( $this, 'render_admin_page' ),
			'dashicons-megaphone',
			60
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook_suffix Current page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_wp-notice-signup' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-notice-signup-admin', WP_NOTICE_SIGNUP_URL . 'assets/css/admin.css', array(), '0.2.0' );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_public_assets() {
		wp_enqueue_style( 'wp-notice-signup-public', WP_NOTICE_SIGNUP_URL . 'assets/css/public.css', array(), '0.2.0' );

		wp_enqueue_script(
			'wp-notice-signup-public',
			WP_NOTICE_SIGNUP_URL . 'assets/js/public.js',
			array(),
			'0.2.0',
			true
		);
	}

	/**
	 * Is a named demo failure switched on?
	 *
	 * @param string $flag Flag name, as in get_demo_issue_flags().
	 * @return bool
	 */
	protected function demo( $flag ) {
		$flags = $this->get_demo_issue_flags();

		return ! empty( $flags[ $flag ] );
	}

	/**
	 * Render settings page.
	 *
	 * Heading levels run h1 -> h2 -> h3 with nothing skipped. WordPress renders
	 * the screen's own <h1>, so everything here starts at h2.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		$settings = $this->get_settings();
		?>
		<div class="wrap wpns-admin-page">
			<h1><?php esc_html_e( 'WP Notice Signup', 'wp-notice-signup' ); ?></h1>
			<p class="wpns-admin-intro"><?php esc_html_e( 'Settings for the announcement bar and signup form.', 'wp-notice-signup' ); ?></p>

			<div class="wpns-admin-grid">
				<form method="post" action="options.php" class="wpns-settings-card<?php echo $this->demo( 'color_contrast' ) ? ' wpns-demo-low-contrast' : ''; ?>">
					<?php settings_fields( 'wp_notice_signup' ); ?>
					<?php
					/*
					 * h1 -> h4 rather than h1 -> h3. wp-admin renders several
					 * visually hidden <h2>s of its own (screen-reader navigation
					 * headings), so an h3 here is preceded by an h2 in document
					 * order and axe reports no skip at all. Jumping to h4
					 * produces the violation the demo is meant to show, and it
					 * is still a realistic mistake — picking a heading level by
					 * how big it looks is exactly how this happens.
					 */
					?>
					<?php if ( $this->demo( 'heading_order' ) ) : ?>
						<h4><?php esc_html_e( 'Banner settings', 'wp-notice-signup' ); ?></h4>
					<?php else : ?>
						<h2><?php esc_html_e( 'Banner settings', 'wp-notice-signup' ); ?></h2>
					<?php endif; ?>

					<p>
						<label for="wpns_notice_text"><?php esc_html_e( 'Notice text', 'wp-notice-signup' ); ?></label>
						<textarea id="wpns_notice_text" name="wp_notice_signup_settings[notice_text]" rows="3"><?php echo esc_textarea( $settings['notice_text'] ); ?></textarea>
					</p>

					<p>
						<?php if ( $this->demo( 'missing_labels' ) ) : ?>
							<span class="wpns-field-caption"><?php esc_html_e( 'Button text', 'wp-notice-signup' ); ?></span>
						<?php else : ?>
							<label for="wpns_button_text"><?php esc_html_e( 'Button text', 'wp-notice-signup' ); ?></label>
						<?php endif; ?>
						<input id="wpns_button_text" type="text" name="wp_notice_signup_settings[button_text]" value="<?php echo esc_attr( $settings['button_text'] ); ?>">
					</p>

					<p>
						<label for="wpns_form_heading"><?php esc_html_e( 'Form heading', 'wp-notice-signup' ); ?></label>
						<input id="wpns_form_heading" type="text" name="wp_notice_signup_settings[form_heading]" value="<?php echo esc_attr( $settings['form_heading'] ); ?>">
					</p>

					<p>
						<label for="wpns_email_label"><?php esc_html_e( 'Email field label', 'wp-notice-signup' ); ?></label>
						<input id="wpns_email_label" type="text" name="wp_notice_signup_settings[email_label]" value="<?php echo esc_attr( $settings['email_label'] ); ?>">
					</p>

					<p>
						<label>
							<input type="checkbox" name="wp_notice_signup_settings[enable_banner]" value="yes" <?php checked( 'yes', $settings['enable_banner'] ); ?>>
							<?php esc_html_e( 'Enable announcement bar', 'wp-notice-signup' ); ?>
						</label>
					</p>

					<p>
						<label>
							<input type="checkbox" name="wp_notice_signup_settings[enable_inline_form]" value="yes" <?php checked( 'yes', $settings['enable_inline_form'] ); ?>>
							<?php esc_html_e( 'Enable inline form shortcode output', 'wp-notice-signup' ); ?>
						</label>
					</p>

					<?php submit_button( __( 'Save settings', 'wp-notice-signup' ) ); ?>
				</form>

				<section class="wpns-preview-card<?php echo $this->demo( 'color_contrast' ) ? ' wpns-demo-low-contrast' : ''; ?>">
					<h2><?php esc_html_e( 'Preview', 'wp-notice-signup' ); ?></h2>
					<p class="wpns-microcopy"><?php esc_html_e( 'How the announcement bar will appear on the front end.', 'wp-notice-signup' ); ?></p>

					<div class="wpns-preview-banner">
						<?php
						/*
						 * The illustration repeats the wording beside it, so it is
						 * decorative and takes an empty alt. An empty alt is a
						 * decision, not an omission: it tells assistive tech to skip
						 * the image, where a missing alt attribute would make it
						 * announce the file name instead.
						 */
						?>
						<?php if ( $this->demo( 'missing_alt_text' ) ) : ?>
							<img class="wpns-preview-banner__image" src="<?php echo esc_url( $settings['image_url'] ); ?>" width="48" height="48">
						<?php else : ?>
							<img class="wpns-preview-banner__image" src="<?php echo esc_url( $settings['image_url'] ); ?>" alt="" width="48" height="48">
						<?php endif; ?>
						<div>
							<?php if ( $this->demo( 'heading_order' ) ) : ?>
								<h5><?php echo esc_html( $settings['form_heading'] ); ?></h5>
							<?php else : ?>
								<h3><?php echo esc_html( $settings['form_heading'] ); ?></h3>
							<?php endif; ?>
							<p><?php echo esc_html( $settings['notice_text'] ); ?></p>
						</div>
						<?php
						/*
						 * Icon-only control. The glyph is hidden from assistive tech
						 * because it conveys nothing, so the button would otherwise
						 * have no accessible name at all and announce as just
						 * "button". The visually hidden text supplies one.
						 */
						?>
						<button type="button" class="button button-secondary wpns-icon-button">
							<span aria-hidden="true">&#9881;</span>
							<?php if ( ! $this->demo( 'button_name' ) ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( 'Banner display options', 'wp-notice-signup' ); ?></span>
							<?php endif; ?>
						</button>
					</div>

				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sitewide banner plus modal.
	 *
	 * NOTE FOR THE TALK: this is hooked to `wp_footer` with no page check, so
	 * it renders on every front-end page. That is realistic — it is how plugins
	 * actually inject sitewide UI — and it is why a single mistake in here
	 * shows up on every scanned page at once. The markup below is clean, so the
	 * multiplier currently works in our favour.
	 *
	 * @return void
	 */
	public function render_frontend_experience() {
		if ( is_admin() ) {
			return;
		}

		$settings = $this->get_settings();
		$flags    = $this->get_demo_issue_flags();

		if ( 'yes' !== $settings['enable_banner'] ) {
			return;
		}

		do_action( 'wp_notice_signup_before_banner', $settings, $flags );
		?>
		<?php
		/*
		 * Labelled by aria-label rather than a heading. A component injected
		 * into the footer of every page cannot know the page's heading outline,
		 * so adding an <h4> here (as this markup used to) risks skipping a level
		 * on some pages and not others. A landmark with an accessible name gives
		 * screen reader users a way to find and skip the region without touching
		 * the document outline at all.
		 */
		?>
		<section class="wpns-banner<?php echo $this->demo( 'color_contrast' ) ? ' wpns-demo-low-contrast' : ''; ?>" aria-label="<?php echo esc_attr( $settings['form_heading'] ); ?>" data-demo-variant="<?php echo esc_attr( $settings['demo_variant'] ); ?>">
			<div class="wpns-banner__content">
				<?php if ( $this->demo( 'missing_alt_text' ) ) : ?>
					<img class="wpns-banner__image" src="<?php echo esc_url( $settings['image_url'] ); ?>" width="40" height="40">
				<?php else : ?>
					<img class="wpns-banner__image" src="<?php echo esc_url( $settings['image_url'] ); ?>" alt="" width="40" height="40">
				<?php endif; ?>
				<div class="wpns-banner__copy">
					<?php if ( $this->demo( 'heading_order' ) ) : ?>
						<h4 class="wpns-banner__title"><?php echo esc_html( $settings['form_heading'] ); ?></h4>
					<?php else : ?>
						<p class="wpns-banner__title"><?php echo esc_html( $settings['form_heading'] ); ?></p>
					<?php endif; ?>
					<p><?php echo esc_html( $settings['notice_text'] ); ?></p>
				</div>
				<?php if ( $this->demo( 'icon_banner_cta' ) ) : ?>
					<button type="button" class="wpns-banner__button wpns-banner__button--icon" data-wpns-open-modal="true">
						<span aria-hidden="true">&#8594;</span>
					</button>
				<?php else : ?>
					<button type="button" class="wpns-banner__button" data-wpns-open-modal="true"><?php echo esc_html( $settings['button_text'] ); ?></button>
				<?php endif; ?>
			</div>
		</section>

		<?php
		/*
		 * The dialog uses the `hidden` attribute rather than aria-hidden.
		 *
		 * aria-hidden="true" hides an element from assistive tech but leaves its
		 * children focusable, so a keyboard user tabs into a dialog they cannot
		 * see while a screen reader insists nothing is there. That combination
		 * is what axe reports as `aria-hidden-focus`, and it is one of the most
		 * common home-grown modal bugs. `hidden` removes it from both the
		 * accessibility tree and the tab order, which is what was actually
		 * meant.
		 *
		 * Focus handling (trap, restore, Escape) lives in assets/js/public.js.
		 */
		?>
		<?php
		/*
		 * With the aria_hidden_focus demo on, the dialog reverts to the broken
		 * pattern: aria-hidden="true" and no `hidden`, so it is removed from the
		 * accessibility tree while its children stay in the tab order. The
		 * data attribute tells public.js to toggle aria-hidden instead of
		 * `hidden`, so the JS matches the markup rather than fighting it.
		 */
		$broken_modal = $this->demo( 'aria_hidden_focus' );
		?>
		<div class="wpns-modal-shell<?php echo $broken_modal ? ' wpns-demo-offscreen' : ''; ?>"
			data-wpns-modal
			<?php echo $broken_modal ? 'data-wpns-hide-mode="aria" aria-hidden="true"' : 'hidden'; ?>>
			<div class="wpns-modal-shell__dialog" role="dialog" aria-modal="true" aria-labelledby="wpns-modal-title">
				<button type="button" class="wpns-modal-shell__close" data-wpns-close-modal="true">
					<span aria-hidden="true">&times;</span>
					<?php if ( ! $this->demo( 'button_name' ) ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'wp-notice-signup' ); ?></span>
					<?php endif; ?>
				</button>
				<p class="wpns-modal-shell__title" id="wpns-modal-title"><?php echo esc_html( $settings['form_heading'] ); ?></p>
				<p><?php echo esc_html( $settings['form_description'] ); ?></p>
				<?php $this->render_form_markup( $settings, 'modal' ); ?>
			</div>
		</div>
		<?php
		do_action( 'wp_notice_signup_after_banner', $settings, $flags );
	}

	/**
	 * Render shortcode output.
	 *
	 * @return string
	 */
	public function render_inline_form_shortcode() {
		$settings = $this->get_settings();

		if ( 'yes' !== $settings['enable_inline_form'] ) {
			return '';
		}

		ob_start();
		?>
		<?php
		/*
		 * A distinct landmark name from the footer banner's.
		 *
		 * Both regions used to take their name from the same `form_heading`
		 * setting. On the contact page, which carries this inline form *and*
		 * the sitewide banner, that produced two landmarks with the same role
		 * and the same accessible name — indistinguishable in a screen reader's
		 * landmark list, and reported by axe as `landmark-unique`.
		 *
		 * That rule is tagged best-practice rather than WCAG, so the default
		 * gate does not catch it. Fixed anyway: "two regions called the same
		 * thing" is a real navigation problem regardless of which tag it
		 * carries.
		 */
		?>
		<section class="wpns-inline-signup" aria-label="<?php esc_attr_e( 'Email signup', 'wp-notice-signup' ); ?>">
			<p class="wpns-inline-signup__title"><?php echo esc_html( $settings['form_heading'] ); ?></p>
			<p><?php echo esc_html( $settings['form_description'] ); ?></p>
			<?php $this->render_form_markup( $settings, 'inline' ); ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the signup form.
	 *
	 * Every control has a real <label for>. The previous version used a
	 * <span class="wpns-field-caption"> that looked like a label and was not
	 * one: no accessible name, and clicking it did not focus the field.
	 *
	 * Placeholders are deliberately absent. A placeholder does satisfy the
	 * accessible-name computation — which means axe reports nothing — but it
	 * disappears the moment somebody types, leaving no persistent label. That
	 * is the "the scan passes and the page is still wrong" case, and it belongs
	 * in the talk, not in the baseline.
	 *
	 * @param array<string,string> $settings Plugin settings.
	 * @param string               $context  Rendering context: modal or inline.
	 * @return void
	 */
	protected function render_form_markup( $settings, $context ) {
		do_action( 'wp_notice_signup_before_form', $settings, $context );

		// A page can contain both the modal and the inline shortcode, so IDs are
		// suffixed per instance. Without this the two copies share IDs and every
		// <label for> resolves to the first one on the page.
		++$this->form_instance;

		/*
		 * With duplicate_active_ids on, every rendered copy of the form reuses
		 * the same IDs, so the contact page — which carries both the inline form
		 * and the footer modal — ends up with two elements per ID and every
		 * <label for> resolves to whichever comes first. Clicking the inline
		 * form's "Email address" label focuses the modal's field instead.
		 *
		 * This is a real bug that a WCAG-tagged scan will NOT catch. See
		 * docs/plugin-failure-modes.md: `duplicate-id-active` is deprecated and
		 * tagged wcag2a-obsolete because WCAG 2.2 removed SC 4.1.1 (Parsing),
		 * and `duplicate-id-aria` returns "needs review" rather than a violation
		 * when one of the two elements is hidden. It is kept as narration
		 * material, not as a gate demo.
		 */
		$uid = $this->demo( 'duplicate_active_ids' ) ? 'shared' : $context . '-' . $this->form_instance;

		$name_id  = 'wpns-name-' . $uid;
		$email_id = 'wpns-email-' . $uid;
		?>
		<form class="wpns-signup-form wpns-signup-form--<?php echo esc_attr( $context ); ?><?php echo $this->demo( 'color_contrast' ) ? ' wpns-demo-low-contrast' : ''; ?>" action="#" method="post">
			<?php if ( $this->demo( 'missing_labels' ) ) : ?>
				<?php
				/*
				 * A <span> that looks like a label and is not one: the input has
				 * no accessible name, and clicking the caption does not focus the
				 * field. Renders identically, which is why it survives review.
				 */
				?>
				<p>
					<span class="wpns-field-caption"><?php echo esc_html( $settings['name_label'] ); ?></span>
					<input id="<?php echo esc_attr( $name_id ); ?>" type="text" name="wpns_name">
				</p>
				<p>
					<span class="wpns-field-caption"><?php echo esc_html( $settings['email_label'] ); ?></span>
					<input id="<?php echo esc_attr( $email_id ); ?>" type="email" name="wpns_email">
				</p>
			<?php else : ?>
				<p>
					<label for="<?php echo esc_attr( $name_id ); ?>"><?php echo esc_html( $settings['name_label'] ); ?></label>
					<input id="<?php echo esc_attr( $name_id ); ?>" type="text" name="wpns_name" autocomplete="given-name">
				</p>
				<p>
					<label for="<?php echo esc_attr( $email_id ); ?>"><?php echo esc_html( $settings['email_label'] ); ?></label>
					<input id="<?php echo esc_attr( $email_id ); ?>" type="email" name="wpns_email" autocomplete="email">
				</p>
			<?php endif; ?>
			<?php if ( $this->demo( 'select_name' ) ) : ?>
				<p>
					<span class="wpns-field-caption"><?php esc_html_e( 'How often', 'wp-notice-signup' ); ?></span>
					<select name="wpns_frequency" id="wpns-frequency-<?php echo esc_attr( $uid ); ?>">
						<option value="weekly"><?php esc_html_e( 'Weekly digest', 'wp-notice-signup' ); ?></option>
						<option value="monthly"><?php esc_html_e( 'Monthly roundup', 'wp-notice-signup' ); ?></option>
					</select>
				</p>
			<?php endif; ?>
			<p>
				<?php if ( $this->demo( 'empty_submit' ) ) : ?>
					<button type="submit"><span aria-hidden="true">&#10148;</span></button>
				<?php else : ?>
					<button type="submit"><?php esc_html_e( 'Join list', 'wp-notice-signup' ); ?></button>
				<?php endif; ?>
			</p>
			<p class="wpns-success-message"><?php echo esc_html( $settings['success_message'] ); ?></p>
		</form>
		<?php
		do_action( 'wp_notice_signup_after_form', $settings, $context );
	}

	/**
	 * Deliberate-failure switches for the demos.
	 *
	 * ALL DEFAULT TO FALSE, AND MUST STAY THAT WAY.
	 *
	 * The baseline has to be clean or the gate cannot be shown blocking
	 * anything: a red check on a build that was already red proves nothing. A
	 * demo turns exactly one of these on, in a pull request or a release, and
	 * turns it off again afterwards.
	 *
	 * See docs/plugin-failure-modes.md for what each one does, which axe rule
	 * it triggers, and which of the two gate patterns it belongs to.
	 *
	 * @return array<string,bool>
	 */
	protected function get_demo_issue_flags() {
		$flags = array(
			'missing_labels'       => true,
			'color_contrast'       => true,
			'missing_alt_text'     => true,
			'heading_order'        => true,
			'button_name'          => true,
			'aria_hidden_focus'    => true,
			'duplicate_active_ids' => true,
			'icon_banner_cta'      => true,
			'empty_submit'         => true,
			'select_name'          => true,
			'manual_issue_hook'    => false,
		);

		return apply_filters( 'wp_notice_signup_demo_issue_flags', $flags );
	}
}
