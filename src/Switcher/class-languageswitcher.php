<?php
/**
 * Language switcher service and renderer.
 *
 * @package LocalePress
 */

namespace LocalePress\Switcher;

use LocalePress\Language\FlagRegistry;
use LocalePress\Language\LanguageManager;
use LocalePress\Language\LanguageTag;
use LocalePress\Routing\LanguageUrlManager;
use LocalePress\Settings\PluginSettings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Builds language switcher items and accessible frontend markup.
 */
final class LanguageSwitcher {

	/**
	 * Default switcher arguments.
	 *
	 * @var array<string, mixed>
	 */
	const DEFAULT_ARGS = array(
		'display'              => 'native_name',
		'layout'               => 'horizontal',
		'hide_current'         => false,
		'hide_missing'         => false,
		/*
		 * A language is offered where the visitor can read this content, and
		 * elsewhere it points at its own home. Only a language the site has
		 * published nothing in leaves the switcher: that one has nowhere to go.
		 */
		'unavailable_behavior' => 'hide',
		'show_flags'           => false,
		'show_disabled'        => false,
		'aria_label'           => '',
		'class_name'           => '',
		'context'              => 'template',
	);

	/**
	 * Language manager.
	 *
	 * @var LanguageManager
	 */
	private $language_manager;

	/**
	 * Language URL service.
	 *
	 * @var LanguageUrlManager
	 */
	private $url_manager;

	/**
	 * Language tag formatter.
	 *
	 * @var LanguageTag
	 */
	private $language_tag;

	/**
	 * Central plugin settings.
	 *
	 * @var PluginSettings
	 */
	private $settings;

	/**
	 * Language flag registry, built on first flag lookup.
	 *
	 * @var FlagRegistry|null
	 */
	private $flags = null;

	/**
	 * Display labels for the switcher being built, keyed by language ID.
	 *
	 * Rebuilt by every item pass because labels depend on the display mode and
	 * on which languages that pass renders.
	 *
	 * @var array<string, string>
	 */
	private $labels = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageManager     $language_manager Language manager.
	 * @param LanguageUrlManager  $url_manager      Language URL service.
	 * @param LanguageTag|null    $language_tag     Optional language tag formatter.
	 * @param PluginSettings|null $settings        Optional central settings service.
	 */
	public function __construct(
		LanguageManager $language_manager,
		LanguageUrlManager $url_manager,
		?LanguageTag $language_tag = null,
		?PluginSettings $settings = null
	) {
		$this->language_manager = $language_manager;
		$this->url_manager      = $url_manager;
		$this->language_tag     = null === $language_tag ? new LanguageTag() : $language_tag;
		$this->settings         = null === $settings ? new PluginSettings() : $settings;
	}

	/**
	 * Returns normalized switcher arguments.
	 *
	 * @param array<string, mixed> $args Switcher arguments.
	 * @return array<string, mixed>
	 */
	public function normalize_args( array $args = array() ) {
		/**
		 * Filters the default language switcher arguments.
		 *
		 * @param array<string, mixed> $defaults Default arguments.
		 */
		$stored   = $this->settings->get_switcher_defaults();
		$defaults = wp_parse_args( $stored, self::DEFAULT_ARGS );
		$defaults = apply_filters( 'localepress_switcher_default_args', $defaults );
		$defaults = is_array( $defaults ) ? wp_parse_args( $defaults, self::DEFAULT_ARGS ) : self::DEFAULT_ARGS;
		$args     = wp_parse_args( $args, $defaults );

		/**
		 * Filters switcher arguments before normalization.
		 *
		 * @param array<string, mixed> $args Raw merged arguments.
		 */
		$filtered = apply_filters( 'localepress_switcher_args', $args );
		$args     = is_array( $filtered ) ? wp_parse_args( $filtered, $defaults ) : $args;
		$display  = is_scalar( $args['display'] ) ? sanitize_key( (string) $args['display'] ) : '';
		$layout   = is_scalar( $args['layout'] ) ? sanitize_key( (string) $args['layout'] ) : '';
		$behavior = is_scalar( $args['unavailable_behavior'] )
			? sanitize_key( (string) $args['unavailable_behavior'] )
			: '';

		$args['display']              = in_array( $display, array( 'name', 'native_name', 'language_code' ), true )
			? $display
			: self::DEFAULT_ARGS['display'];
		$args['layout']               = in_array( $layout, array( 'dropdown', 'horizontal', 'vertical' ), true )
			? $layout
			: self::DEFAULT_ARGS['layout'];
		$args['unavailable_behavior'] = in_array( $behavior, array( 'disabled', 'hide', 'home', 'current' ), true )
			? $behavior
			: self::DEFAULT_ARGS['unavailable_behavior'];
		$args['hide_current']         = $this->normalize_boolean( $args['hide_current'] );
		$args['hide_missing']         = $this->normalize_boolean( $args['hide_missing'] );
		$args['show_flags']           = $this->normalize_boolean( $args['show_flags'] );
		$args['show_disabled']        = $this->normalize_boolean( $args['show_disabled'] );
		$args['aria_label']           = is_scalar( $args['aria_label'] )
			? sanitize_text_field( (string) $args['aria_label'] )
			: '';
		$args['aria_label']           = '' === $args['aria_label']
			? __( 'Language selector', 'localepress' )
			: $args['aria_label'];
		$args['class_name']           = $this->normalize_classes( $args['class_name'] );
		$args['context']              = is_scalar( $args['context'] )
			? sanitize_key( (string) $args['context'] )
			: self::DEFAULT_ARGS['context'];

		if ( $args['hide_missing'] ) {
			$args['unavailable_behavior'] = 'hide';
		}

		return $args;
	}

	/**
	 * Returns normalized switcher items in configured language order.
	 *
	 * @param array<string, mixed> $args Switcher arguments.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_items( array $args = array() ) {
		$args = $this->normalize_args( $args );

		return $this->build_items( $args );
	}

	/**
	 * Returns the flag and label markup for one prepared switcher item.
	 *
	 * Navigation menus supply their own list item and anchor through the theme's
	 * walker, so they need this inner markup on its own, without the surrounding
	 * switcher structure that render() adds.
	 *
	 * @param array<string, mixed> $item Prepared switcher item.
	 * @param array<string, mixed> $args Switcher arguments.
	 * @return string
	 */
	public function get_item_content( array $item, array $args = array() ) {
		if ( empty( $item['language'] ) || ! is_array( $item['language'] ) ) {
			return '';
		}

		return $this->render_language_content( $item['language'], $this->normalize_args( $args ), $item );
	}

	/**
	 * Reports whether a switcher can generate LocalePress language URLs.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->url_manager->supports_language_prefixes();
	}

	/**
	 * Renders one complete switcher.
	 *
	 * @param array<string, mixed> $args Switcher arguments.
	 * @return string
	 */
	public function render( array $args = array() ) {
		$args = $this->normalize_args( $args );

		if ( ! $this->is_available() ) {
			return '';
		}

		$items = $this->build_items( $args );

		if ( empty( $items ) ) {
			return '';
		}

		wp_enqueue_style( 'localepress-switcher' );

		if ( 'dropdown' === $args['layout'] ) {
			// A panel that opens downward off the bottom of the page is clipped,
			// and how much room it has is a question only the browser can answer.
			wp_enqueue_script( 'localepress-switcher' );
		}

		$classes = array(
			'localepress-switcher',
			'localepress-switcher--' . $args['layout'],
		);

		if ( '' !== $args['class_name'] ) {
			$classes = array_merge( $classes, explode( ' ', $args['class_name'] ) );
		}

		$classes = array_values( array_unique( array_filter( $classes ) ) );
		$list    = $this->render_list( $items, $args );
		$tag     = 'menu' === $args['context'] ? 'div' : 'nav';
		$label   = 'nav' === $tag
			? ' aria-label="' . esc_attr( $args['aria_label'] ) . '"'
			: '';

		if ( 'dropdown' === $args['layout'] ) {
			$current      = $this->url_manager->get_current_language();
			$summary_item = null === $current
				? null
				: array(
					'label'    => $this->get_item_label( $current, $args['display'] ),
					'flag_url' => $this->get_flag_url( $current, $args ),
				);
			$summary      = null === $current
				? esc_html( $args['aria_label'] )
				: $this->render_language_content( $current, $args, $summary_item );
			$list         = '<details class="localepress-switcher__dropdown"><summary>' . $summary . '</summary>' . $list . '</details>';
		}

		$html = sprintf(
			'<%1$s class="%2$s"%3$s>%4$s</%1$s>',
			tag_escape( $tag ),
			esc_attr( implode( ' ', $classes ) ),
			$label,
			$list
		);

		/**
		 * Filters final language switcher HTML.
		 *
		 * @param string                           $html  Rendered HTML.
		 * @param array<int, array<string, mixed>> $items Switcher items.
		 * @param array<string, mixed>              $args  Normalized arguments.
		 */
		$filtered = apply_filters( 'localepress_switcher_html', $html, $items, $args );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * Builds switcher items from registered languages and current query context.
	 *
	 * @param array<string, mixed> $args Normalized arguments.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_items( $args ) {
		$languages = $this->language_manager->get_languages( false );

		/**
		 * Filters language records before switcher items are built.
		 *
		 * @param array<int, array<string, mixed>> $languages Registered languages.
		 * @param array<string, mixed>             $args      Normalized arguments.
		 */
		$filtered_languages = apply_filters( 'localepress_switcher_languages', $languages, $args );
		$languages          = is_array( $filtered_languages ) ? $filtered_languages : $languages;
		$this->labels       = $this->build_labels( $languages, $args );
		$preview_post_id    = $this->get_preview_post_id();
		$current            = $this->url_manager->get_current_language();
		$current_id         = null === $current ? '' : (string) $current['id'];
		$items              = array();

		if ( 0 < $preview_post_id ) {
			$preview_language_id = $this->url_manager->get_post_language_id( $preview_post_id );
			$current_id          = '' === $preview_language_id ? $current_id : $preview_language_id;
		}

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['id'] ) ) {
				continue;
			}

			$language_id = (string) $language['id'];
			$is_current  = $current_id === $language_id;
			$is_disabled = empty( $language['enabled'] );

			if ( ( $is_current && $args['hide_current'] ) || ( $is_disabled && ! $args['show_disabled'] ) ) {
				continue;
			}

			$url       = $is_disabled ? '' : $this->resolve_item_url( $language_id, $preview_post_id );
			$available = ! $is_disabled && '' !== $url;
			$fallback  = false;

			if ( ! $available && ! $is_disabled ) {
				if ( 'hide' === $args['unavailable_behavior'] ) {
					/*
					 * Hiding says "this site has nothing in your language", which
					 * a page-by-page check is not entitled to conclude: a cart or
					 * a checkout has no translation in any language, and a site
					 * with translated posts behind it is plainly multilingual. So
					 * only a language nothing has been written in yet leaves the
					 * switcher. The rest keep their place and lead to their own
					 * home, which admits this page is untranslated instead of
					 * hiding that the language exists.
					 */
					if ( ! $this->url_manager->language_has_content( $language_id ) ) {
						continue;
					}

					$url = $this->url_manager->get_language_home_url( $language_id );
				} elseif ( 'home' === $args['unavailable_behavior'] ) {
					$url = $this->url_manager->get_language_home_url( $language_id );
				} elseif ( 'current' === $args['unavailable_behavior'] ) {
					$url = 0 < $preview_post_id
						? $this->url_manager->get_post_url( $preview_post_id )
						: $this->url_manager->get_current_request_url_in_language( $language_id );

					/*
					 * Archives and searches answer in every language, so the reader
					 * stays put. A cart, an account page, or any other single page
					 * without a translation has no address in the language asked
					 * for, and its home page is the nearest one that exists —
					 * better than a link back to the page already on screen.
					 */
					if ( '' === $url ) {
						$url = $this->url_manager->get_language_home_url( $language_id );
					}
				}

				/**
				 * Filters an unavailable language's configured fallback URL.
				 *
				 * @param string               $url      Proposed fallback URL.
				 * @param string               $behavior Fallback behavior.
				 * @param array<string, mixed> $language Language record.
				 * @param array<string, mixed> $args     Normalized arguments.
				 */
				$url      = apply_filters(
					'localepress_switcher_unavailable_url',
					$url,
					$args['unavailable_behavior'],
					$language,
					$args
				);
				$url      = is_string( $url ) ? esc_url_raw( $url ) : '';
				// Every behavior but "show as unavailable" is allowed to offer a
				// substitute, and an entry that leads somewhere is a link rather
				// than a dead label.
				$fallback = 'disabled' !== $args['unavailable_behavior'] && '' !== $url;

				/*
				 * A reader who asked to hide these languages must not be shown a
				 * dead one, so a substitute that came back empty — a filter that
				 * cleared it, a language with no reachable home — takes the entry
				 * out rather than leaving a label that goes nowhere.
				 */
				if ( 'hide' === $args['unavailable_behavior'] && '' === $url ) {
					continue;
				}
			}

			$flag_url = $this->get_flag_url( $language, $args );

			$item = array(
				'id'        => $language_id,
				'language'  => $language,
				'label'     => $this->get_item_label( $language, $args['display'] ),
				'url'       => is_string( $url ) ? esc_url_raw( $url ) : '',
				'current'   => $is_current,
				'available' => $available,
				'disabled'  => $is_disabled,
				'fallback'  => $fallback,
				'flag_url'  => $flag_url,
			);

			/**
			 * Filters one normalized language switcher item.
			 *
			 * Return null or false to omit the item.
			 *
			 * @param array<string, mixed> $item     Switcher item.
			 * @param array<string, mixed> $language Language record.
			 * @param array<string, mixed> $args     Normalized arguments.
			 */
			$item = apply_filters( 'localepress_switcher_item', $item, $language, $args );

			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}

		/**
		 * Filters all switcher items before rendering.
		 *
		 * @param array<int, array<string, mixed>> $items Switcher items.
		 * @param array<string, mixed>             $args  Normalized arguments.
		 */
		$filtered_items = apply_filters( 'localepress_switcher_items', $items, $args );

		return is_array( $filtered_items ) ? array_values( $filtered_items ) : $items;
	}

	/**
	 * Returns the post a REST render should resolve switcher URLs against.
	 *
	 * The block editor renders this block through the REST block-renderer
	 * endpoint, which sets up post data but never a main query, so the frontend
	 * request conditionals cannot resolve a route and every language would fall
	 * back to the reserved `/wp-json/` request path. The previewed post is the
	 * only reliable context there.
	 *
	 * @return int Zero on a normal frontend request.
	 */
	private function get_preview_post_id() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return 0;
		}

		$post = get_post();

		return $post instanceof WP_Post ? (int) $post->ID : 0;
	}

	/**
	 * Returns the target URL for one language in the active render context.
	 *
	 * @param string $language_id     Target stable language ID.
	 * @param int    $preview_post_id Previewed post identifier, or zero.
	 * @return string Empty when no equivalent target exists.
	 */
	private function resolve_item_url( $language_id, $preview_post_id ) {
		if ( 0 < $preview_post_id ) {
			return $this->url_manager->get_translation_url( $preview_post_id, $language_id );
		}

		return $this->url_manager->switch_language_url( $language_id );
	}

	/**
	 * Renders the switcher item list.
	 *
	 * @param array<int, array<string, mixed>> $items Switcher items.
	 * @param array<string, mixed>             $args  Normalized arguments.
	 * @return string
	 */
	private function render_list( $items, $args ) {
		$output = '<ul class="localepress-switcher__list" role="list">';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['language'] ) || ! is_array( $item['language'] ) ) {
				continue;
			}

			$output .= $this->render_item( $item, $args );
		}

		return $output . '</ul>';
	}

	/**
	 * Renders one language item.
	 *
	 * @param array<string, mixed> $item Switcher item.
	 * @param array<string, mixed> $args Normalized arguments.
	 * @return string
	 */
	private function render_item( $item, $args ) {
		$language   = $item['language'];
		$is_current = ! empty( $item['current'] );
		$available  = ! empty( $item['available'] );
		$fallback   = ! empty( $item['fallback'] );
		$url        = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
		$classes    = array( 'localepress-switcher__item' );

		if ( $is_current ) {
			$classes[] = 'is-current';
		}

		if ( ! $available ) {
			$classes[] = 'is-unavailable';
		}

		if ( $fallback ) {
			$classes[] = 'has-fallback';
		}

		$language_code = isset( $language['language_code'] )
			? sanitize_key( $language['language_code'] )
			: '';
		$direction     = LanguageTag::direction( $language );
		$item['label'] = isset( $item['label'] ) && is_scalar( $item['label'] )
			? sanitize_text_field( (string) $item['label'] )
			: $this->get_item_label( $language, $args['display'] );
		$content       = $this->render_language_content( $language, $args, $item );
		$status        = '';

		if ( ! $available && ! $fallback ) {
			/*
			 * A language that still links somewhere carries its explanation in the
			 * link's aria-label, so only a genuinely dead entry needs this. The
			 * wording is announced rather than printed: an English word beside a
			 * translated label reads as a defect on a multilingual site.
			 */
			$status = '<span class="localepress-switcher__status">' . esc_html__( 'Translation unavailable', 'localepress' ) . '</span>';
		}

		$attributes = ' lang="' . esc_attr( $language_code ) . '" dir="' . esc_attr( $direction ) . '"';

		if ( '' !== $url && ( $available || $fallback ) ) {
			$link_attributes  = ' href="' . esc_url( $url ) . '"';
			$link_attributes .= ' hreflang="' . esc_attr( $this->language_tag->format( $language ) ) . '" rel="alternate"';

			if ( $is_current ) {
				$link_attributes .= ' aria-current="page"';
			}

			if ( $fallback ) {
				$link_attributes .= ' aria-label="' . esc_attr(
					sprintf(
						/* translators: %s: language label. */
						__( '%s (translation unavailable)', 'localepress' ),
						$item['label']
					)
				) . '"';
			}

			$content = '<a class="localepress-switcher__link"' . $link_attributes . '>' . $content . $status . '</a>';
		} else {
			$content = '<span class="localepress-switcher__unavailable" aria-disabled="true">' . $content . $status . '</span>';
		}

		return '<li class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $attributes . '>' . $content . '</li>';
	}

	/**
	 * Renders a language label and optional flag.
	 *
	 * @param array<string, mixed>      $language Language record.
	 * @param array<string, mixed>      $args     Normalized arguments.
	 * @param array<string, mixed>|null $item     Optional prepared item.
	 * @return string
	 */
	private function render_language_content( $language, $args, $item = null ) {
		$label    = null !== $item && isset( $item['label'] ) && is_scalar( $item['label'] )
			? (string) $item['label']
			: $this->get_item_label( $language, $args['display'] );
		$flag_url = null !== $item && isset( $item['flag_url'] ) && is_scalar( $item['flag_url'] )
			? (string) $item['flag_url']
			: '';
		$flag     = '';

		if ( $args['show_flags'] && '' !== $flag_url ) {
			$flag = sprintf(
				'<img class="localepress-switcher__flag" src="%1$s" alt="" width="%2$d" height="%3$d" loading="lazy" decoding="async" />',
				esc_url( $flag_url ),
				FlagRegistry::FLAG_WIDTH,
				FlagRegistry::FLAG_HEIGHT
			);
		}

		/**
		 * Filters optional flag HTML after the URL has been validated.
		 *
		 * @param string               $flag     Generated flag HTML.
		 * @param array<string, mixed> $language Language record.
		 * @param array<string, mixed> $args     Normalized arguments.
		 */
		$flag = apply_filters( 'localepress_switcher_flag_html', $flag, $language, $args );
		$flag = is_string( $flag )
			? wp_kses(
				$flag,
				array(
					'img'  => array(
						'alt'      => true,
						'class'    => true,
						'decoding' => true,
						'height'   => true,
						'loading'  => true,
						'src'      => true,
						'width'    => true,
					),
					'span' => array(
						'aria-hidden' => true,
						'class'       => true,
					),
				)
			)
			: '';

		return $flag . '<span class="localepress-switcher__label">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Returns display labels for one switcher pass, keyed by language ID.
	 *
	 * A locale name usually carries its region in trailing parentheses, as in
	 * "English (United States)". The region only earns its place when it is what
	 * separates two otherwise identical entries, so it is dropped wherever the
	 * shortened name stays unique across the languages being rendered. A site
	 * offering both American and British English keeps both regions.
	 *
	 * @param array<int, array<string, mixed>> $languages Language records.
	 * @param array<string, mixed>             $args      Normalized arguments.
	 * @return array<string, string>
	 */
	private function build_labels( $languages, $args ) {
		$labels    = array();
		$shortened = array();
		$counts    = array();

		foreach ( $languages as $language ) {
			if ( ! is_array( $language ) || empty( $language['id'] ) ) {
				continue;
			}

			$language_id               = (string) $language['id'];
			$labels[ $language_id ]    = $this->get_label( $language, $args['display'] );
			$shortened[ $language_id ] = $this->strip_region( $labels[ $language_id ] );
			$key                       = strtolower( $shortened[ $language_id ] );
			$counts[ $key ]            = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}

		foreach ( $shortened as $language_id => $label ) {
			if ( 1 === $counts[ strtolower( $label ) ] ) {
				$labels[ $language_id ] = $label;
			}
		}

		/**
		 * Filters the display labels used by one switcher pass.
		 *
		 * @param array<string, string>            $labels    Labels keyed by language ID.
		 * @param array<int, array<string, mixed>> $languages Language records.
		 * @param array<string, mixed>             $args      Normalized arguments.
		 */
		$filtered = apply_filters( 'localepress_switcher_labels', $labels, $languages, $args );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $labels;
	}

	/**
	 * Removes a trailing parenthetical region from a display label.
	 *
	 * @param string $label Display label.
	 * @return string The original label when nothing would remain.
	 */
	private function strip_region( $label ) {
		$stripped = preg_replace( '/\s*\([^()]*\)\s*$/u', '', $label );
		$stripped = is_string( $stripped ) ? trim( $stripped ) : '';

		return '' === $stripped ? $label : $stripped;
	}

	/**
	 * Returns the prepared label for one language in the current pass.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string               $display  Display mode.
	 * @return string
	 */
	private function get_item_label( $language, $display ) {
		$language_id = isset( $language['id'] ) ? (string) $language['id'] : '';

		return isset( $this->labels[ $language_id ] )
			? $this->labels[ $language_id ]
			: $this->get_label( $language, $display );
	}

	/**
	 * Returns one display label from a language record.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param string               $display  Display mode.
	 * @return string
	 */
	private function get_label( $language, $display ) {
		if ( 'language_code' === $display ) {
			$label = isset( $language['language_code'] ) ? strtoupper( (string) $language['language_code'] ) : '';
		} elseif ( 'name' === $display ) {
			$label = isset( $language['name'] ) ? (string) $language['name'] : '';
		} else {
			$label = isset( $language['native_name'] ) ? (string) $language['native_name'] : '';
		}

		if ( '' === $label && isset( $language['name'] ) ) {
			$label = (string) $language['name'];
		}

		return sanitize_text_field( $label );
	}

	/**
	 * Returns the flag URL for one language.
	 *
	 * Flags come from the bundled flag set, from a site override, or from an
	 * integration that filters the URL.
	 *
	 * @param array<string, mixed> $language Language record.
	 * @param array<string, mixed> $args     Normalized arguments.
	 * @return string
	 */
	private function get_flag_url( $language, $args ) {
		if ( empty( $args['show_flags'] ) ) {
			return '';
		}

		if ( null === $this->flags ) {
			$this->flags = new FlagRegistry();
		}

		/**
		 * Filters the flag image URL for one language.
		 *
		 * @param string               $flag_url Resolved flag URL.
		 * @param array<string, mixed> $language Language record.
		 * @param array<string, mixed> $args     Normalized arguments.
		 */
		$flag_url = apply_filters( 'localepress_switcher_flag_url', $this->flags->get_flag_url( $language ), $language, $args );

		return is_string( $flag_url ) ? esc_url_raw( $flag_url ) : '';
	}

	/**
	 * Normalizes shortcode and API boolean values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Sanitizes a space-separated class list.
	 *
	 * @param mixed $classes Raw classes.
	 * @return string
	 */
	private function normalize_classes( $classes ) {
		if ( ! is_scalar( $classes ) ) {
			return '';
		}

		$normalized = array();
		$class_list = preg_split( '/\s+/', (string) $classes );

		foreach ( is_array( $class_list ) ? $class_list : array() as $class_name ) {
			$class_name = sanitize_html_class( $class_name );

			if ( '' !== $class_name ) {
				$normalized[] = $class_name;
			}
		}

		return implode( ' ', array_unique( $normalized ) );
	}
}
