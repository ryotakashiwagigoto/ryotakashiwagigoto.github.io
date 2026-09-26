<?php
/**
 * researchmap integration.
 *
 * Fetches achievements from the public researchmap API, caches them,
 * and renders them with the [researchmap] shortcode.
 *
 * Usage:
 *   [researchmap type="published_papers"]
 *   [researchmap type="publications"]  … papers, misc, books, presentations, patents
 *   [researchmap type="cv"]            … experience, education, awards, grants, etc.
 *   [researchmap type="all"]
 *
 * @package Goto_Research
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** researchmap permalink (https://researchmap.jp/{permalink}). */
const GOTO_RM_PERMALINK = 'gotoryota';

/** Author names treated as "me" (shown emphasised in author lists). */
const GOTO_RM_SELF_PATTERN = '/Ryota\s+Goto|Goto,?\s*R(yota|\.)?\b|R\.\s*Goto|後藤\s*良太/u';

/** How long fetched data is reused before asking researchmap again. */
const GOTO_RM_CACHE_TTL = 12 * HOUR_IN_SECONDS;

/**
 * Section definitions: API type => [Japanese heading, English heading].
 */
function goto_rm_sections() {
	return array(
		'published_papers'           => array( '論文', 'Papers' ),
		'misc'                       => array( 'MISC（総説・解説など）', 'Misc' ),
		'books_etc'                  => array( '書籍等出版物', 'Books' ),
		'presentations'              => array( '講演・口頭発表等', 'Presentations' ),
		'industrial_property_rights' => array( '産業財産権', 'Patents' ),
		'research_experience'        => array( '経歴', 'Research experience' ),
		'education'                  => array( '学歴', 'Education' ),
		'awards'                     => array( '受賞', 'Awards' ),
		'research_projects'          => array( '共同研究・競争的資金等の研究課題', 'Research grants' ),
		'committee_memberships'      => array( '委員歴', 'Committee memberships' ),
		'association_memberships'    => array( '所属学協会', 'Academic societies' ),
		'teaching_experience'        => array( '担当経験のある科目', 'Teaching experience' ),
		'social_contribution'        => array( '社会貢献活動', 'Social contribution' ),
		'media_coverage'             => array( 'メディア報道', 'Media coverage' ),
		'works'                      => array( 'Works（作品等）', 'Works' ),
		'others'                     => array( 'その他', 'Others' ),
	);
}

/**
 * Named groups of sections for the shortcode.
 */
function goto_rm_groups() {
	return array(
		'publications' => array( 'published_papers', 'misc', 'books_etc', 'presentations', 'industrial_property_rights' ),
		'cv'           => array( 'research_experience', 'education', 'awards', 'research_projects', 'committee_memberships', 'association_memberships', 'teaching_experience', 'social_contribution', 'media_coverage', 'works', 'others' ),
		'all'          => array_keys( goto_rm_sections() ),
	);
}

/**
 * Fetch all items of one achievement type, with caching.
 *
 * Returns an array of items, or null when nothing could be fetched at all.
 */
function goto_rm_fetch( $type ) {
	$key    = 'goto_rm_' . $type;
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}

	$items = array();
	$start = 1;
	$ok    = true;
	do {
		$url      = sprintf( 'https://api.researchmap.jp/%s/%s?format=json&limit=1000&start=%d', rawurlencode( GOTO_RM_PERMALINK ), rawurlencode( $type ), $start );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$ok = false;
			break;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$ok = false;
			break;
		}
		$page  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array_merge( $items, $page );
		$total = isset( $data['total_items'] ) ? (int) $data['total_items'] : count( $items );
		$start += 1000;
	} while ( count( $page ) > 0 && count( $items ) < $total && $start < 10000 );

	if ( ! $ok ) {
		// Fall back to the last good copy, and retry again in 30 minutes.
		$backup = get_option( $key . '_backup', null );
		set_transient( $key, is_array( $backup ) ? $backup : array(), 30 * MINUTE_IN_SECONDS );
		return is_array( $backup ) ? $backup : null;
	}

	set_transient( $key, $items, GOTO_RM_CACHE_TTL );
	update_option( $key . '_backup', $items, false );
	update_option( 'goto_rm_last_fetched', time(), false );
	return $items;
}

/**
 * Pick a string from a {ja:…, en:…} field, preferring $lang.
 */
function goto_rm_text( $value, $lang = 'ja' ) {
	if ( is_string( $value ) || is_numeric( $value ) ) {
		return (string) $value;
	}
	if ( ! is_array( $value ) ) {
		return '';
	}
	$other = 'ja' === $lang ? 'en' : 'ja';
	foreach ( array( $lang, $other ) as $l ) {
		if ( isset( $value[ $l ] ) && is_string( $value[ $l ] ) && '' !== trim( $value[ $l ] ) ) {
			return trim( $value[ $l ] );
		}
	}
	return '';
}

/**
 * Escape text but keep simple formatting tags researchmap allows in titles.
 */
function goto_rm_rich( $text ) {
	$html = esc_html( $text );
	return preg_replace( '#&lt;(/?)(sub|sup|i|em|b|strong)&gt;#i', '<$1$2>', $html );
}

/**
 * Author-like list ({ja:[{name}], en:[{name}]}) as escaped HTML, own name in bold.
 */
function goto_rm_people( $value, $lang = 'ja' ) {
	if ( ! is_array( $value ) ) {
		return '';
	}
	$other = 'ja' === $lang ? 'en' : 'ja';
	$list  = array();
	foreach ( array( $lang, $other ) as $l ) {
		if ( ! empty( $value[ $l ] ) && is_array( $value[ $l ] ) ) {
			$list = $value[ $l ];
			break;
		}
	}
	$names = array();
	foreach ( $list as $person ) {
		$name = is_array( $person ) && isset( $person['name'] ) ? trim( (string) $person['name'] ) : '';
		if ( '' === $name ) {
			continue;
		}
		$html    = esc_html( $name );
		$names[] = preg_match( GOTO_RM_SELF_PATTERN, $name ) ? '<strong class="rm-self">' . $html . '</strong>' : $html;
	}
	return implode( ', ', $names );
}

/**
 * "2025-04-13" → "2025.04", "9999" → "現在".
 */
function goto_rm_date( $date ) {
	$date = (string) $date;
	if ( '' === $date ) {
		return '';
	}
	if ( 0 === strpos( $date, '9999' ) ) {
		return '現在';
	}
	$parts = explode( '-', $date );
	return isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : $parts[0];
}

function goto_rm_period( $item ) {
	$from = goto_rm_date( $item['from_date'] ?? '' );
	$to   = goto_rm_date( $item['to_date'] ?? '' );
	if ( '' === $from && '' === $to ) {
		return goto_rm_date( $item['publication_date'] ?? '' );
	}
	return '' === $to ? $from : $from . ' – ' . $to;
}

function goto_rm_year( $item ) {
	foreach ( array( 'publication_date', 'from_event_date', 'award_date', 'application_date', 'from_date' ) as $k ) {
		if ( ! empty( $item[ $k ] ) ) {
			return substr( (string) $item[ $k ], 0, 4 );
		}
	}
	return '';
}

function goto_rm_badge( $label, $variant = '' ) {
	return '<span class="rm-badge' . ( $variant ? ' rm-badge--' . esc_attr( $variant ) : '' ) . '">' . esc_html( $label ) . '</span>';
}

function goto_rm_doi_link( $item ) {
	$doi = $item['identifiers']['doi'][0] ?? '';
	if ( '' === $doi ) {
		return '';
	}
	return ' <a class="rm-doi" href="' . esc_url( 'https://doi.org/' . $doi ) . '" target="_blank" rel="noopener">DOI: ' . esc_html( $doi ) . '</a>';
}

/**
 * Volume(Number), pp–pp.
 */
function goto_rm_biblio( $item ) {
	$out = '';
	if ( ! empty( $item['volume'] ) ) {
		$out .= '<span class="rm-vol">' . esc_html( $item['volume'] ) . '</span>';
	}
	if ( ! empty( $item['number'] ) ) {
		$out .= '(' . esc_html( $item['number'] ) . ')';
	}
	$sp = $item['starting_page'] ?? '';
	$ep = $item['ending_page'] ?? '';
	if ( '' !== $sp ) {
		$out .= ( '' !== $out ? ', ' : '' ) . esc_html( $sp ) . ( '' !== $ep && $ep !== $sp ? '–' . esc_html( $ep ) : '' );
	}
	return $out;
}

/**
 * Render one item as an <li> body.
 */
function goto_rm_render_item( $type, $item, $lang ) {
	$t = static function ( $key ) use ( $item, $lang ) {
		return goto_rm_rich( goto_rm_text( $item[ $key ] ?? '', $lang ) );
	};
	$parts  = array();
	$badges = '';

	switch ( $type ) {
		case 'published_papers':
		case 'misc':
			$parts[] = goto_rm_people( $item['authors'] ?? null, 'en' );
			$title   = goto_rm_rich( goto_rm_text( $item['paper_title'] ?? '', 'en' ) );
			$parts[] = '<span class="rm-title">' . $title . '</span>';
			$venue   = esc_html( goto_rm_text( $item['publication_name'] ?? '', 'en' ) );
			$biblio  = goto_rm_biblio( $item );
			$venue   = '' !== $venue ? '<em>' . $venue . '</em>' : '';
			$vb      = trim( $venue . ' ' . $biblio );
			$parts[] = ( '' !== $vb ? $vb . ' ' : '' ) . '(' . esc_html( goto_rm_year( $item ) ) . ')';
			if ( ! empty( $item['referee'] ) ) {
				$badges .= goto_rm_badge( '査読有り' );
			}
			$roles = isset( $item['published_paper_owner_roles'] ) && is_array( $item['published_paper_owner_roles'] ) ? $item['published_paper_owner_roles'] : array();
			if ( in_array( 'lead', $roles, true ) ) {
				$badges .= goto_rm_badge( '筆頭著者', 'accent' );
			}
			if ( in_array( 'corresponding', $roles, true ) ) {
				$badges .= goto_rm_badge( '責任著者', 'accent' );
			}
			$ptypes = array(
				'international_conference_proceedings' => '国際会議プロシーディングス',
				'research_institution'                 => '研究機関紀要',
				'symposium'                            => 'シンポジウム',
				'in_book'                              => '論文集（書籍）内論文',
				'doctoral_thesis'                      => '博士論文',
				'master_thesis'                        => '修士論文',
			);
			$ptype = $item['published_paper_type'] ?? '';
			if ( isset( $ptypes[ $ptype ] ) ) {
				$badges .= goto_rm_badge( $ptypes[ $ptype ] );
			}
			return '<div class="rm-body">' . implode( ', ', array_filter( $parts ) ) . '.' . goto_rm_doi_link( $item ) . '</div>' . ( $badges ? '<div class="rm-badges">' . $badges . '</div>' : '' );

		case 'books_etc':
			$parts[] = goto_rm_people( $item['authors'] ?? null, $lang );
			$parts[] = '<span class="rm-title">' . $t( 'book_title' ) . '</span>';
			$parts[] = $t( 'publisher' );
			$parts[] = esc_html( goto_rm_year( $item ) );
			return '<div class="rm-body">' . implode( ', ', array_filter( $parts ) ) . '.' . goto_rm_doi_link( $item ) . '</div>';

		case 'presentations':
			$parts[] = goto_rm_people( $item['presenters'] ?? null, $lang );
			$parts[] = '<span class="rm-title">' . $t( 'presentation_title' ) . '</span>';
			$parts[] = '<em>' . $t( 'event' ) . '</em>';
			$parts[] = $t( 'location' );
			$parts[] = esc_html( goto_rm_date( $item['publication_date'] ?? ( $item['from_event_date'] ?? '' ) ) );
			if ( ! empty( $item['invited'] ) ) {
				$badges .= goto_rm_badge( '招待講演', 'accent' );
			}
			$types = array(
				'oral_presentation'          => '口頭発表',
				'poster_presentation'        => 'ポスター発表',
				'invited_oral_presentation'  => '招待講演',
				'keynote_oral_presentation'  => '基調講演',
				'public_discourse'           => '公開講演',
				'nominated_symposium'        => 'シンポジウム',
			);
			$ptype = $item['presentation_type'] ?? '';
			if ( isset( $types[ $ptype ] ) && empty( $item['invited'] ) ) {
				$badges .= goto_rm_badge( $types[ $ptype ] );
			}
			if ( ! empty( $item['is_international_presentation'] ) ) {
				$badges .= goto_rm_badge( '国際会議' );
			}
			return '<div class="rm-body">' . implode( ', ', array_filter( $parts ) ) . '.</div>' . ( $badges ? '<div class="rm-badges">' . $badges . '</div>' : '' );

		case 'industrial_property_rights':
			$parts[] = goto_rm_people( $item['inventors'] ?? null, $lang );
			$parts[] = '<span class="rm-title">' . $t( 'industrial_property_right_name' ) . '</span>';
			$parts[] = esc_html( (string) ( $item['application_number'] ?? '' ) );
			$parts[] = esc_html( goto_rm_date( $item['application_date'] ?? '' ) );
			return '<div class="rm-body">' . implode( ', ', array_filter( $parts ) ) . '.</div>';

		case 'awards':
			$body = '<span class="rm-title">' . $t( 'award_name' ) . '</span>';
			if ( $t( 'association' ) ) {
				$body .= '（' . $t( 'association' ) . '）';
			}
			if ( $t( 'award_title' ) ) {
				$body .= '<br><span class="rm-sub">' . $t( 'award_title' ) . '</span>';
			}
			return '<div class="rm-date">' . esc_html( goto_rm_date( $item['award_date'] ?? '' ) ) . '</div><div class="rm-body">' . $body . '</div>';

		case 'research_projects':
			$body = '<span class="rm-title">' . $t( 'research_project_title' ) . '</span><br><span class="rm-sub">';
			$meta = array_filter( array( $t( 'offer_organization' ), $t( 'system_name' ), $t( 'category' ) ) );
			$body .= implode( ' / ', $meta );
			$num   = $item['identifiers']['grant_number'][0] ?? '';
			if ( '' !== $num ) {
				$body .= '（' . esc_html( $num ) . '）';
			}
			$body .= '</span>';
			foreach ( ( $item['see_also'] ?? array() ) as $link ) {
				if ( isset( $link['label'], $link['@id'] ) && 'kaken' === $link['label'] ) {
					$body .= ' <a class="rm-doi" href="' . esc_url( $link['@id'] ) . '" target="_blank" rel="noopener">KAKEN</a>';
				}
			}
			return '<div class="rm-date">' . esc_html( goto_rm_period( $item ) ) . '</div><div class="rm-body">' . $body . '</div>';

		case 'research_experience':
			$body = implode( ' ', array_filter( array( $t( 'affiliation' ), $t( 'section' ), $t( 'job' ) ) ) );
			return '<div class="rm-date">' . esc_html( goto_rm_period( $item ) ) . '</div><div class="rm-body">' . $body . '</div>';

		case 'education':
			$body = implode( ' ', array_filter( array( $t( 'affiliation' ), $t( 'department' ), $t( 'course' ) ) ) );
			return '<div class="rm-date">' . esc_html( goto_rm_period( $item ) ) . '</div><div class="rm-body">' . $body . '</div>';

		default:
			// Generic: the first *_title / *_name field, plus organisation-like fields.
			$title = '';
			foreach ( $item as $k => $v ) {
				if ( preg_match( '/(_title|_name|^association|^subject)$/', (string) $k ) && is_array( $v ) ) {
					$title = esc_html( goto_rm_text( $v, $lang ) );
					if ( '' !== $title ) {
						break;
					}
				}
			}
			$org = '';
			foreach ( array( 'association', 'organizer', 'publisher', 'affiliation', 'committee_name' ) as $k ) {
				if ( isset( $item[ $k ] ) && $t( $k ) && $t( $k ) !== $title ) {
					$org = $t( $k );
					break;
				}
			}
			$body = '<span class="rm-title">' . $title . '</span>' . ( $org ? '（' . $org . '）' : '' );
			return '<div class="rm-date">' . esc_html( goto_rm_period( $item ) ) . '</div><div class="rm-body">' . $body . '</div>';
	}
}

/**
 * Sort key: newest first.
 */
function goto_rm_sort_key( $item ) {
	foreach ( array( 'publication_date', 'from_event_date', 'award_date', 'application_date', 'from_date' ) as $k ) {
		if ( ! empty( $item[ $k ] ) ) {
			return (string) $item[ $k ];
		}
	}
	return '';
}

/**
 * Render one section. Returns '' when there is nothing to show.
 */
function goto_rm_render_section( $type, $lang, $with_heading = true ) {
	$sections = goto_rm_sections();
	if ( ! isset( $sections[ $type ] ) ) {
		return '';
	}
	$items = goto_rm_fetch( $type );
	if ( empty( $items ) ) {
		return '';
	}
	$items = array_values(
		array_filter(
			$items,
			static function ( $i ) {
				return is_array( $i ) && ( ! isset( $i['display'] ) || 'disclosed' === $i['display'] );
			}
		)
	);
	usort(
		$items,
		static function ( $a, $b ) {
			return strcmp( goto_rm_sort_key( $b ), goto_rm_sort_key( $a ) );
		}
	);

	$by_year  = in_array( $type, array( 'published_papers', 'misc', 'books_etc', 'presentations', 'industrial_property_rights' ), true );
	$numbered = in_array( $type, array( 'published_papers', 'misc', 'books_etc', 'presentations' ), true );
	$heading  = 'en' === $lang ? $sections[ $type ][1] : $sections[ $type ][0];

	$html = '<section class="rm-section rm-' . esc_attr( $type ) . '">';
	if ( $with_heading ) {
		$html .= '<h2 class="wp-block-heading rm-heading">' . esc_html( $heading ) . ' <span class="rm-count">' . count( $items ) . '</span></h2>';
	}

	$counter = count( $items );
	$year    = null;
	$tag     = $numbered ? 'ol' : 'ul';
	$open    = false;
	foreach ( $items as $item ) {
		$y = goto_rm_year( $item );
		if ( $by_year && $y !== $year ) {
			if ( $open ) {
				$html .= '</' . $tag . '>';
			}
			$html .= '<h3 class="rm-year">' . esc_html( '' !== $y ? $y : '—' ) . '</h3>';
			$html .= '<' . $tag . ' class="rm-list' . ( $by_year ? '' : ' rm-list--dated' ) . '" reversed start="' . (int) $counter . '">';
			$open  = true;
			$year  = $y;
		} elseif ( ! $open ) {
			$html .= '<' . $tag . ' class="rm-list rm-list--dated">';
			$open  = true;
		}
		$html .= '<li class="rm-item">' . goto_rm_render_item( $type, $item, $lang ) . '</li>';
		$counter--;
	}
	if ( $open ) {
		$html .= '</' . $tag . '>';
	}
	return $html . '</section>';
}

/**
 * [researchmap type="published_papers|publications|cv|all" lang="ja|en" heading="yes|no"]
 */
function goto_rm_shortcode( $atts ) {
	$atts  = shortcode_atts(
		array(
			'type'    => 'all',
			'lang'    => 'ja',
			'heading' => 'yes',
		),
		$atts,
		'researchmap'
	);
	$lang   = 'en' === $atts['lang'] ? 'en' : 'ja';
	$groups = goto_rm_groups();
	$types  = isset( $groups[ $atts['type'] ] ) ? $groups[ $atts['type'] ] : array_map( 'trim', explode( ',', $atts['type'] ) );

	$html = '';
	foreach ( $types as $type ) {
		$html .= goto_rm_render_section( $type, $lang, 'no' !== $atts['heading'] );
	}
	// The markers let the static-site build replace this block with fresh data.
	$marker = sprintf( 'rm:type=%s;lang=%s;heading=%s', $atts['type'], $lang, 'no' !== $atts['heading'] ? 'yes' : 'no' );
	$open   = '<!--' . esc_html( $marker ) . '-->';
	if ( '' === $html ) {
		return $open . '<p class="rm-empty">' . ( 'en' === $lang ? 'No data available from researchmap right now.' : 'researchmap からデータを取得できませんでした。' ) . '</p><!--/rm-->';
	}

	$source = sprintf(
		'<p class="rm-source">%s <a href="%s" target="_blank" rel="noopener">researchmap</a></p>',
		'en' === $lang ? 'Source:' : 'データ出典：',
		esc_url( 'https://researchmap.jp/' . GOTO_RM_PERMALINK )
	);
	return $open . '<div class="rm-achievements">' . $html . $source . '</div><!--/rm-->';
}
add_shortcode( 'researchmap', 'goto_rm_shortcode' );

/**
 * Clear the cache when the theme is (re)activated, or via ?rm_refresh=1 for admins.
 */
function goto_rm_clear_cache() {
	foreach ( array_keys( goto_rm_sections() ) as $type ) {
		delete_transient( 'goto_rm_' . $type );
	}
}
add_action( 'after_switch_theme', 'goto_rm_clear_cache' );

function goto_rm_maybe_refresh() {
	if ( isset( $_GET['rm_refresh'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		goto_rm_clear_cache();
	}
}
add_action( 'init', 'goto_rm_maybe_refresh' );
