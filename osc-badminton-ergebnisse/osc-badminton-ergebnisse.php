<?php
/**
 * Plugin Name: OSC Badminton Ergebnisse
 * Description: Zeigt Badminton-Spielergebnisse und Tabellenstaende von dbv.turnier.de auf der WordPress-Seite an.
 * Version: 1.1.0
 * Author: OSC BG Essen-Werden
 * Text Domain: osc-badminton
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OSC_BADMINTON_VERSION', '1.1.0' );
define( 'OSC_BADMINTON_PATH', plugin_dir_path( __FILE__ ) );
define( 'OSC_BADMINTON_URL', plugin_dir_url( __FILE__ ) );

require_once OSC_BADMINTON_PATH . 'includes/class-scraper.php';
require_once OSC_BADMINTON_PATH . 'includes/class-admin.php';

class OSC_Badminton_Ergebnisse {

    private static $instance = null;
    private $scraper;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->scraper = new OSC_Badminton_Scraper();

        add_shortcode( 'badminton_ergebnisse', array( $this, 'render_shortcode' ) );
        add_shortcode( 'badminton_tabelle', array( $this, 'render_standings_shortcode' ) );
        add_shortcode( 'badminton_alle', array( $this, 'render_all_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        if ( is_admin() ) {
            new OSC_Badminton_Admin();
        }
    }

    public function enqueue_frontend_assets() {
        global $post;
        if ( ! $post ) {
            return;
        }
        $shortcodes = array( 'badminton_ergebnisse', 'badminton_tabelle', 'badminton_alle' );
        foreach ( $shortcodes as $sc ) {
            if ( has_shortcode( $post->post_content, $sc ) ) {
                wp_enqueue_style(
                    'osc-badminton-frontend',
                    OSC_BADMINTON_URL . 'assets/css/frontend.css',
                    array(),
                    OSC_BADMINTON_VERSION
                );
                break;
            }
        }
    }

    private function resolve_team_url( $atts ) {
        $url  = $atts['url'] ?? '';
        $name = '';

        if ( empty( $url ) ) {
            $teams = get_option( 'osc_badminton_teams', array() );
            $index = max( 0, intval( $atts['id'] ?? 1 ) - 1 );
            if ( isset( $teams[ $index ] ) ) {
                $url  = $teams[ $index ]['url'];
                $name = $teams[ $index ]['name'];
            }
        }

        return array( 'url' => $url, 'name' => $name );
    }

    /**
     * [badminton_ergebnisse] - Shows only the match results.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 1, 'url' => '' ), $atts, 'badminton_ergebnisse' );
        $resolved = $this->resolve_team_url( $atts );

        if ( empty( $resolved['url'] ) ) {
            return '<p class="osc-badminton-error">Keine Mannschafts-URL konfiguriert. Bitte unter Einstellungen &gt; Badminton Ergebnisse eine URL eintragen.</p>';
        }

        $url_type = OSC_Badminton_Scraper::detect_url_type( $resolved['url'] );

        if ( $url_type === 'team' ) {
            $data = $this->scraper->get_team_data( $resolved['url'] );
            if ( is_wp_error( $data ) ) {
                return '<p class="osc-badminton-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
            }
            $matches   = $data['matches'];
            $team_name = ! empty( $resolved['name'] ) ? $resolved['name'] : $data['team_name'];
        } else {
            $matches   = $this->scraper->get_matches( $resolved['url'] );
            $team_name = $resolved['name'];
        }

        if ( is_wp_error( $matches ) ) {
            return '<p class="osc-badminton-error">Fehler: ' . esc_html( $matches->get_error_message() ) . '</p>';
        }
        if ( empty( $matches ) ) {
            return '<p class="osc-badminton-info">Keine Spiele gefunden.</p>';
        }

        return $this->render_matches_table( $matches );
    }

    /**
     * [badminton_tabelle] - Shows only the league standings.
     */
    public function render_standings_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 1, 'url' => '' ), $atts, 'badminton_tabelle' );
        $resolved = $this->resolve_team_url( $atts );

        if ( empty( $resolved['url'] ) ) {
            return '<p class="osc-badminton-error">Keine Mannschafts-URL konfiguriert.</p>';
        }

        $url_type = OSC_Badminton_Scraper::detect_url_type( $resolved['url'] );
        if ( $url_type !== 'team' ) {
            return '<p class="osc-badminton-error">Fuer die Tabelle wird eine Team-URL benoetigt (Format: /sport/league/team?...).</p>';
        }

        $data = $this->scraper->get_team_data( $resolved['url'] );
        if ( is_wp_error( $data ) ) {
            return '<p class="osc-badminton-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
        }

        if ( empty( $data['standings'] ) ) {
            return '<p class="osc-badminton-info">Keine Tabellendaten gefunden.</p>';
        }

        return $this->render_standings( $data['standings'] );
    }

    /**
     * [badminton_alle] - Shows standings + matches together.
     */
    public function render_all_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 1, 'url' => '' ), $atts, 'badminton_alle' );
        $resolved = $this->resolve_team_url( $atts );

        if ( empty( $resolved['url'] ) ) {
            return '<p class="osc-badminton-error">Keine Mannschafts-URL konfiguriert.</p>';
        }

        $url_type = OSC_Badminton_Scraper::detect_url_type( $resolved['url'] );

        if ( $url_type === 'team' ) {
            $data = $this->scraper->get_team_data( $resolved['url'] );
            if ( is_wp_error( $data ) ) {
                return '<p class="osc-badminton-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
            }
            $team_name = ! empty( $resolved['name'] ) ? $resolved['name'] : $data['team_name'];

            $html = '';
            if ( ! empty( $data['standings'] ) ) {
                $html .= $this->render_standings( $data['standings'] );
            }
            if ( ! empty( $data['matches'] ) ) {
                $html .= $this->render_matches_table( $data['matches'], $team_name );
            }
            return $html;
        }

        // Fallback: matches-only URL
        $matches = $this->scraper->get_matches( $resolved['url'] );
        if ( is_wp_error( $matches ) ) {
            return '<p class="osc-badminton-error">Fehler: ' . esc_html( $matches->get_error_message() ) . '</p>';
        }
        return $this->render_matches_table( $matches );
    }

    private function render_standings( $standings ) {
        $html = '<div class="osc-badminton-wrapper">';

        $html .= '<div class="osc-badminton-table-container">';
        $html .= '<table class="osc-badminton-table osc-standings-table">';
        $html .= '<thead><tr>';
        $html .= '<th class="osc-rank">#</th>';
        $html .= '<th>Mannschaft</th>';
        $html .= '<th>Sp.</th>';
        $html .= '<th>Pkt.</th>';
        $html .= '<th class="osc-hide-mobile">S</th>';
        $html .= '<th class="osc-hide-mobile">U</th>';
        $html .= '<th class="osc-hide-mobile">N</th>';
        $html .= '<th>Spiele</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ( $standings as $row ) {
            $classes = array();
            if ( $row['selected'] ) {
                $classes[] = 'osc-selected';
            }
            if ( $row['promote'] ) {
                $classes[] = 'osc-promote';
            }
            if ( $row['demote'] ) {
                $classes[] = 'osc-demote';
            }

            $html .= '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '">';
            $html .= '<td class="osc-rank">' . esc_html( $row['rank'] ) . '</td>';
            $html .= '<td class="osc-team-name">' . esc_html( $row['name'] ) . '</td>';
            $html .= '<td>' . esc_html( $row['played'] ) . '</td>';
            $html .= '<td class="osc-points">' . esc_html( $row['points_w'] . ':' . $row['points_l'] ) . '</td>';
            $html .= '<td class="osc-hide-mobile">' . esc_html( $row['won'] ) . '</td>';
            $html .= '<td class="osc-hide-mobile">' . esc_html( $row['draw'] ) . '</td>';
            $html .= '<td class="osc-hide-mobile">' . esc_html( $row['lost'] ) . '</td>';
            $html .= '<td>' . esc_html( $row['games_w'] . ':' . $row['games_l'] ) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '<p class="osc-badminton-source">Quelle: <a href="https://dbv.turnier.de" target="_blank" rel="noopener">dbv.turnier.de</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function render_matches_table( $matches ) {
        $html = '<div class="osc-badminton-wrapper">';

        $html .= '<div class="osc-badminton-table-container">';
        $html .= '<table class="osc-badminton-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Datum</th>';
        $html .= '<th>Heim</th>';
        $html .= '<th></th>';
        $html .= '<th>Gast</th>';
        $html .= '<th>Ergebnis</th>';
        $html .= '<th class="osc-hide-mobile">Spielort</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ( $matches as $match ) {
            $row_class = $this->get_row_class( $match );
            $result_class = $this->get_result_class( $match );

            $html .= '<tr class="' . esc_attr( $row_class ) . '">';
            $html .= '<td class="osc-date">' . esc_html( $match['date'] ) . '</td>';
            $html .= '<td class="osc-team osc-home">' . esc_html( $match['home'] ) . '</td>';
            $html .= '<td class="osc-vs">:</td>';
            $html .= '<td class="osc-team osc-guest">' . esc_html( $match['guest'] ) . '</td>';
            $display_result = str_replace( array( '{', '}' ), '', $match['result'] );
            $html .= '<td class="osc-result ' . esc_attr( $result_class ) . '">' . esc_html( $display_result ) . '</td>';
            $html .= '<td class="osc-venue osc-hide-mobile">' . esc_html( $match['venue'] ) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '<p class="osc-badminton-source">Quelle: <a href="https://dbv.turnier.de" target="_blank" rel="noopener">dbv.turnier.de</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function get_row_class( $match ) {
        $is_home = stripos( $match['home'], 'OSC' ) !== false || stripos( $match['home'], 'Essen-Werden' ) !== false;
        return $is_home ? 'osc-home-game' : 'osc-away-game';
    }

    private function get_result_class( $match ) {
        if ( empty( $match['result'] ) || strpos( $match['result'], '-' ) === false ) {
            return 'osc-pending';
        }

        $result = str_replace( array( '{', '}' ), '', $match['result'] );
        $parts = explode( '-', $result );
        if ( count( $parts ) !== 2 ) {
            return '';
        }

        $home_score  = intval( trim( $parts[0] ) );
        $guest_score = intval( trim( $parts[1] ) );

        $is_home = stripos( $match['home'], 'OSC' ) !== false || stripos( $match['home'], 'Essen-Werden' ) !== false;

        if ( $home_score === $guest_score ) {
            return 'osc-draw';
        }

        $osc_won = ( $is_home && $home_score > $guest_score ) || ( ! $is_home && $guest_score > $home_score );
        return $osc_won ? 'osc-win' : 'osc-loss';
    }
}

add_action( 'plugins_loaded', array( 'OSC_Badminton_Ergebnisse', 'instance' ) );
