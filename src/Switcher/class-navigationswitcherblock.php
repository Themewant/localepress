<?php
/**
 * Language switcher for the Navigation block.
 *
 * @package LocalePress
 */

namespace LocalePress\Switcher;

use WP_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language links as native Navigation block items.
 *
 * Block themes build their header menu from `core/navigation`, which only styles
 * its own child blocks. Rather than emitting standalone markup that a theme would
 * not recognize, each language is rendered through `core/navigation-link` (or one
 * `core/navigation-submenu` in dropdown mode) so the switcher inherits the menu's
 * spacing, colors, typography, and responsive overlay for free.
 */
final class NavigationSwitcherBlock {

	/**
	 * Block name.
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'localepress/navigation-language-switcher';

	/**
	 * Token swapped for a language label after WordPress renders the link.
	 *
	 * WordPress escapes the label it receives, so a flag image cannot be passed
	 * through as markup. A plain token survives escaping unchanged and is replaced
	 * once the surrounding link markup exists.
	 *
	 * @var string
	 */
	const LABEL_TOKEN = 'localepress-switcher-label-token';

	/**
	 * Language switcher service.
	 *
	 * @var LanguageSwitcher
	 */
	private $switcher;

	/**
	 * Constructor.
	 *
	 * @param LanguageSwitcher $switcher Language switcher service.
	 */
	public function __construct( LanguageSwitcher $switcher ) {
		$this->switcher = $switcher;
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved content, unused for a dynamic block.
	 * @param WP_Block|null        $block      Parsed block instance.
	 * @return string
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		unset( $content );
		$attributes = is_array( $attributes ) ? $attributes : array();

		if ( ! $this->switcher->is_available() ) {
			return '';
		}

		$args  = $this->build_args( $attributes );
		$items = $this->switcher->get_items( $args );

		if ( empty( $items ) ) {
			return '';
		}

		$context = $block instanceof WP_Block ? $block->context : array();

		return ! empty( $attributes['dropdown'] )
			? $this->render_submenu( $items, $attributes, $context )
			: $this->render_links( $items, $attributes, $context );
	}

	/**
	 * Renders one navigation item per language.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @param array<string, mixed>             $context    Parent block context.
	 * @return string
	 */
	private function render_links( array $items, array $attributes, array $context ) {
		$output = '';

		foreach ( $items as $item ) {
			$output .= $this->render_navigation_block( 'core/navigation-link', $item, $attributes, $context );
		}

		return $output;
	}

	/**
	 * Renders the current language as a submenu holding the other languages.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @param array<string, mixed>             $context    Parent block context.
	 * @return string
	 */
	private function render_submenu( array $items, array $attributes, array $context ) {
		$parent = null;

		foreach ( $items as $item ) {
			if ( ! empty( $item['current'] ) ) {
				$parent = $item;
				break;
			}
		}

		if ( null === $parent ) {
			$parent = reset( $items );
		}

		$children = array();

		foreach ( $items as $item ) {
			if ( $item['id'] === $parent['id'] && ! empty( $attributes['hideCurrent'] ) ) {
				continue;
			}

			$children[] = $this->build_navigation_block( 'core/navigation-link', $item, $attributes, $context );
		}

		if ( empty( $children ) ) {
			return '';
		}

		$submenu = new WP_Block(
			array(
				'blockName'   => 'core/navigation-submenu',
				'attrs'       => $this->build_item_attributes( $parent, $attributes ),
				'innerBlocks' => $children,
			),
			$context
		);

		return $this->replace_label_tokens( $submenu->render(), $this->collect_labels( $items, $parent, $attributes ), $attributes );
	}

	/**
	 * Builds and renders one navigation block for a language.
	 *
	 * @param string               $block_name Core block name.
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $context    Parent block context.
	 * @return string
	 */
	private function render_navigation_block( $block_name, array $item, array $attributes, array $context ) {
		$block = $this->build_navigation_block( $block_name, $item, $attributes, $context );

		return $this->replace_label_tokens( $block->render(), array( $item ), $attributes );
	}

	/**
	 * Builds one core navigation block instance for a language.
	 *
	 * @param string               $block_name Core block name.
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $context    Parent block context.
	 * @return WP_Block
	 */
	private function build_navigation_block( $block_name, array $item, array $attributes, array $context ) {
		return new WP_Block(
			array(
				'blockName' => $block_name,
				'attrs'     => $this->build_item_attributes( $item, $attributes ),
			),
			$context
		);
	}

	/**
	 * Builds the core block attributes representing one language.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function build_item_attributes( array $item, array $attributes ) {
		$classes = array( 'localepress-switcher__item', 'localepress-switcher__item--' . sanitize_html_class( $item['id'] ) );

		if ( ! empty( $item['current'] ) ) {
			$classes[] = 'is-current-language';
		}

		if ( empty( $item['available'] ) ) {
			$classes[] = 'localepress-switcher__item--unavailable';
		}

		$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';

		return array(
			'label'       => $this->build_label( $item, $attributes ),
			'url'         => $url,
			'kind'        => 'custom',
			'type'        => 'custom',
			'className'   => implode( ' ', $classes ),
			'description' => '',
		);
	}

	/**
	 * Returns the label WordPress should render for one language.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	private function build_label( array $item, array $attributes ) {
		$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';

		if ( ! $this->uses_flag( $item, $attributes ) ) {
			return $label;
		}

		return self::LABEL_TOKEN . '-' . sanitize_html_class( $item['id'] );
	}

	/**
	 * Collects every item whose label may still contain a token.
	 *
	 * @param array<int, array<string, mixed>> $items      Switcher items.
	 * @param array<string, mixed>             $parent     Item rendered as the submenu parent.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_labels( array $items, array $parent, array $attributes ) {
		unset( $attributes );
		$collected = $items;
		$collected[] = $parent;

		return $collected;
	}

	/**
	 * Swaps rendered tokens for the label, optionally preceded by a flag.
	 *
	 * @param string                           $output     Rendered navigation markup.
	 * @param array<int, array<string, mixed>> $items      Items that may own a token.
	 * @param array<string, mixed>             $attributes Block attributes.
	 * @return string
	 */
	private function replace_label_tokens( $output, array $items, array $attributes ) {
		foreach ( $items as $item ) {
			if ( ! $this->uses_flag( $item, $attributes ) ) {
				continue;
			}

			$token = self::LABEL_TOKEN . '-' . sanitize_html_class( $item['id'] );
			$label = isset( $item['label'] ) && is_scalar( $item['label'] ) ? (string) $item['label'] : '';
			$flag  = sprintf(
				'<img class="localepress-switcher__flag" src="%1$s" alt="" width="16" height="11" loading="lazy" decoding="async" /> ',
				esc_url( $item['flag_url'] )
			);

			$output = str_replace( $token, $flag . esc_html( $label ), $output );
		}

		return $output;
	}

	/**
	 * Reports whether one item should render a flag image.
	 *
	 * @param array<string, mixed> $item       Switcher item.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return bool
	 */
	private function uses_flag( array $item, array $attributes ) {
		return ! empty( $attributes['showFlags'] )
			&& isset( $item['flag_url'] )
			&& is_string( $item['flag_url'] )
			&& '' !== $item['flag_url'];
	}

	/**
	 * Translates block attributes into switcher arguments.
	 *
	 * Layout is fixed: the Navigation block owns the presentation, so only the
	 * dropdown choice — a submenu or a flat list of items — belongs to this block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function build_args( array $attributes ) {
		return array(
			'display'              => isset( $attributes['display'] ) && is_string( $attributes['display'] )
				? $attributes['display']
				: 'native_name',
			'hide_current'         => ! empty( $attributes['hideCurrent'] ) && empty( $attributes['dropdown'] ),
			'hide_missing'         => ! empty( $attributes['hideMissing'] ),
			'unavailable_behavior' => isset( $attributes['unavailableBehavior'] ) && is_string( $attributes['unavailableBehavior'] )
				? $attributes['unavailableBehavior']
				: 'home',
			'show_flags'           => ! empty( $attributes['showFlags'] ),
			'show_disabled'        => ! empty( $attributes['showDisabled'] ),
			'context'              => 'navigation_block',
		);
	}
}
