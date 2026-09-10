<?php
/**
 * Elementor compatibility integration tests.
 *
 * @package LocalePress
 */

use LocalePress\Integrations\Elementor\ElementorCompatibility;
use LocalePress\Integrations\Elementor\ElementorModule;

/**
 * Verifies builder data copying without requiring Elementor in the test suite.
 */
class Test_LocalePress_Elementor_Compatibility extends WP_UnitTestCase {

	/**
	 * Compatibility service under test.
	 *
	 * @var ElementorCompatibility
	 */
	private $compatibility;

	/**
	 * Posts created by a test.
	 *
	 * @var array<int, int>
	 */
	private $post_ids = array();

	/**
	 * Enables the optional integration for metadata-only unit tests.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->compatibility = new ElementorCompatibility();
		$this->post_ids      = array();

		add_filter( 'localepress_elementor_available', array( $this, 'enable_elementor' ) );
	}

	/**
	 * Removes test posts and filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'localepress_elementor_available', array( $this, 'enable_elementor' ) );
		remove_filter( 'localepress_elementor_available', array( $this, 'disable_elementor' ) );
		remove_filter( 'localepress_elementor_copy_meta_keys', array( $this, 'extend_copy_meta_keys' ) );

		foreach ( array_unique( $this->post_ids ) as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		parent::tear_down();
	}

	/**
	 * Forces Elementor availability for metadata tests.
	 *
	 * @return bool
	 */
	public function enable_elementor() {
		return true;
	}

	/**
	 * Forces Elementor unavailability.
	 *
	 * @return bool
	 */
	public function disable_elementor() {
		return false;
	}

	/**
	 * Extends copied keys while attempting to add a protected generated key.
	 *
	 * @param array<int, string> $meta_keys Metadata keys.
	 * @return array<int, string>
	 */
	public function extend_copy_meta_keys( $meta_keys ) {
		$meta_keys[] = '_elementor_custom_setting';
		$meta_keys[] = '_elementor_css';

		return $meta_keys;
	}

	/**
	 * Elementor absence leaves ordinary LocalePress translation behavior alone.
	 *
	 * @return void
	 */
	public function test_inactive_elementor_is_a_no_op() {
		remove_filter( 'localepress_elementor_available', array( $this, 'enable_elementor' ) );
		add_filter( 'localepress_elementor_available', array( $this, 'disable_elementor' ) );

		$source = $this->create_elementor_page();
		$target = $this->create_page();

		$this->assertFalse( $this->compatibility->copy_document( $target, $source ) );
		$this->assertFalse( metadata_exists( 'post', $target, ElementorCompatibility::DATA_META_KEY ) );
	}

	/**
	 * Containers, legacy sections, columns, and common widget data are preserved.
	 *
	 * @return void
	 */
	public function test_common_elementor_structure_and_stable_meta_are_copied() {
		$source = $this->create_elementor_page();
		$target = $this->create_page();

		update_post_meta( $source, '_elementor_custom_setting', 'extension-value' );
		update_post_meta( $source, '_elementor_pro_version', 'unsupported' );
		update_post_meta( $source, '_elementor_css', array( 'time' => 100 ) );
		update_post_meta( $target, '_elementor_css', array( 'time' => 200 ) );
		update_post_meta( $target, '_elementor_page_assets', array( 'styles' => array( 'stale' ) ) );
		update_post_meta( $target, '_elementor_controls_usage', array( 'stale' => 1 ) );
		update_post_meta( $target, '_elementor_element_cache', 'stale-render' );

		add_filter( 'localepress_elementor_copy_meta_keys', array( $this, 'extend_copy_meta_keys' ) );
		$result = $this->compatibility->copy_document( $target, $source );

		$this->assertTrue( $result );
		$this->assertSame(
			get_post_meta( $source, ElementorCompatibility::DATA_META_KEY, true ),
			get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true )
		);
		$this->assertSame( 'builder', get_post_meta( $target, '_elementor_edit_mode', true ) );
		$this->assertSame( 'wp-page', get_post_meta( $target, '_elementor_template_type', true ) );
		$this->assertSame( '3.35.0', get_post_meta( $target, '_elementor_version', true ) );
		$this->assertSame( 'elementor_canvas', get_post_meta( $target, '_wp_page_template', true ) );
		$this->assertSame(
			array( 'background_color' => '#ffffff' ),
			get_post_meta( $target, '_elementor_page_settings', true )
		);
		$this->assertSame( 'extension-value', get_post_meta( $target, '_elementor_custom_setting', true ) );
		$this->assertSame( '', get_post_meta( $target, '_elementor_pro_version', true ) );

		foreach (
			array(
				'_elementor_css',
				'_elementor_page_assets',
				'_elementor_controls_usage',
				'_elementor_element_cache',
			) as $generated_key
		) {
			$this->assertFalse( metadata_exists( 'post', $target, $generated_key ) );
		}

		$element_data = json_decode( get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true ), true );
		$widget_types = $this->collect_widget_types( $element_data );

		$this->assertContains( 'heading', $widget_types );
		$this->assertContains( 'text-editor', $widget_types );
		$this->assertContains( 'image', $widget_types );
		$this->assertContains( 'button', $widget_types );
		$this->assertContains( 'icon', $widget_types );
		$this->assertSame( 'container', $element_data[0]['elType'] );
		$this->assertSame( 'section', $element_data[1]['elType'] );
		$this->assertSame( 'column', $element_data[1]['elements'][0]['elType'] );
	}

	/**
	 * Target edits do not mutate the source document or its page settings.
	 *
	 * @return void
	 */
	public function test_translated_document_is_independent() {
		$source        = $this->create_elementor_page();
		$target        = $this->create_page();
		$source_data   = get_post_meta( $source, ElementorCompatibility::DATA_META_KEY, true );
		$source_config = get_post_meta( $source, '_elementor_page_settings', true );

		$this->assertTrue( $this->compatibility->copy_document( $target, $source ) );

		$target_data = json_decode( $source_data, true );

		$target_data[0]['elements'][0]['settings']['title'] = 'German heading';
		update_post_meta( $target, ElementorCompatibility::DATA_META_KEY, wp_slash( wp_json_encode( $target_data ) ) );
		update_post_meta( $target, '_elementor_page_settings', array( 'background_color' => '#000000' ) );

		$this->assertSame( $source_data, get_post_meta( $source, ElementorCompatibility::DATA_META_KEY, true ) );
		$this->assertSame( $source_config, get_post_meta( $source, '_elementor_page_settings', true ) );
		$this->assertNotSame(
			get_post_meta( $source, ElementorCompatibility::DATA_META_KEY, true ),
			get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true )
		);
	}

	/**
	 * Empty-content mode keeps a valid, editable Elementor document shell.
	 *
	 * @return void
	 */
	public function test_empty_content_mode_keeps_elementor_document_settings() {
		$source = $this->create_elementor_page();
		$target = $this->create_page();
		$module = new ElementorModule( $this->compatibility );

		$module->copy_translated_document(
			$target,
			$source,
			array(),
			'test-group',
			array( 'copy_content' => false )
		);

		$this->assertSame( '[]', get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true ) );
		$this->assertSame( 'builder', get_post_meta( $target, '_elementor_edit_mode', true ) );
		$this->assertSame( 'wp-page', get_post_meta( $target, '_elementor_template_type', true ) );
		$this->assertSame(
			get_post_meta( $source, '_elementor_page_settings', true ),
			get_post_meta( $target, '_elementor_page_settings', true )
		);
	}

	/**
	 * Existing translated Elementor content is never overwritten.
	 *
	 * @return void
	 */
	public function test_existing_target_document_is_not_overwritten() {
		$source = $this->create_elementor_page();
		$target = $this->create_elementor_page( 'Target heading' );
		$before = get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true );
		$result = $this->compatibility->copy_document( $target, $source );

		$this->assertWPError( $result );
		$this->assertSame( 'elementor_translation_target_not_empty', $result->get_error_code() );
		$this->assertSame( $before, get_post_meta( $target, ElementorCompatibility::DATA_META_KEY, true ) );
	}

	/**
	 * Invalid source JSON is rejected before target metadata is changed.
	 *
	 * @return void
	 */
	public function test_invalid_elementor_data_is_rejected() {
		$source = $this->create_elementor_page();
		$target = $this->create_page();

		update_post_meta( $source, ElementorCompatibility::DATA_META_KEY, '{invalid-json' );
		$result = $this->compatibility->copy_document( $target, $source );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_elementor_document_data', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'post', $target, ElementorCompatibility::DATA_META_KEY ) );
	}

	/**
	 * Creates a plain page.
	 *
	 * @return int
	 */
	private function create_page() {
		$post_id          = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Creates an Elementor page fixture containing common free widgets.
	 *
	 * @param string $heading Heading widget text.
	 * @return int
	 */
	private function create_elementor_page( $heading = 'English heading' ) {
		$post_id = $this->create_page();
		$data    = array(
			array(
				'id'       => 'a1000001',
				'elType'   => 'container',
				'settings' => array( 'content_width' => 'boxed' ),
				'elements' => array(
					array(
						'id'         => 'a1000002',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => $heading ),
						'elements'   => array(),
					),
					array(
						'id'         => 'a1000003',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>Source text</p>' ),
						'elements'   => array(),
					),
					array(
						'id'         => 'a1000004',
						'elType'     => 'widget',
						'widgetType' => 'image',
						'settings'   => array( 'image' => array( 'id' => 101 ) ),
						'elements'   => array(),
					),
					array(
						'id'         => 'a1000005',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array( 'text' => 'Read more' ),
						'elements'   => array(),
					),
					array(
						'id'         => 'a1000006',
						'elType'     => 'widget',
						'widgetType' => 'icon',
						'settings'   => array( 'selected_icon' => array( 'value' => 'fas fa-star' ) ),
						'elements'   => array(),
					),
				),
			),
			array(
				'id'       => 'b1000001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'b1000002',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(),
					),
				),
			),
		);

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', '3.35.0' );
		update_post_meta( $post_id, '_elementor_page_settings', array( 'background_color' => '#ffffff' ) );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		update_post_meta( $post_id, ElementorCompatibility::DATA_META_KEY, wp_slash( wp_json_encode( $data ) ) );

		return $post_id;
	}

	/**
	 * Collects widget types from nested Elementor element data.
	 *
	 * @param array<int, array<string, mixed>> $elements Element collection.
	 * @return array<int, string>
	 */
	private function collect_widget_types( array $elements ) {
		$widget_types = array();

		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ) {
				$widget_types[] = $element['widgetType'];
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$widget_types = array_merge( $widget_types, $this->collect_widget_types( $element['elements'] ) );
			}
		}

		return $widget_types;
	}
}
