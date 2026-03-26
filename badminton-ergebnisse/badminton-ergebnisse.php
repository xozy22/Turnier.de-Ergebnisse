<?php
/**
 * Plugin Name: Badminton Ergebnisse
 * Description: Zeigt Badminton-Spielergebnisse und Tabellenstaende von dbv.turnier.de auf der WordPress-Seite an.
 * Version: 1.2.0
 * Author: Dennis Kobiolka
 * Text Domain: badminton-ergebnisse
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BADMINTON_ERGEBNISSE_VERSION', '1.2.0' );
define( 'BADMINTON_ERGEBNISSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BADMINTON_ERGEBNISSE_URL', plugin_dir_url( __FILE__ ) );

require_once BADMINTON_ERGEBNISSE_PATH . 'includes/class-scraper.php';
require_once BADMINTON_ERGEBNISSE_PATH . 'includes/class-admin.php';

class Badminton_Ergebnisse {

    private static $instance = null;
    private $scraper;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->scraper = new Badminton_Ergebnisse_Scraper();

        add_shortcode( 'badminton_ergebnisse', array( $this, 'render_shortcode' ) );
        add_shortcode( 'badminton_tabelle', array( $this, 'render_standings_shortcode' ) );
        add_shortcode( 'badminton_alle', array( $this, 'render_all_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        if ( is_admin() ) {
            new Badminton_Ergebnisse_Admin();
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
                    'badminton-ergebnisse-frontend',
                    BADMINTON_ERGEBNISSE_URL . 'assets/css/frontend.css',
                    array(),
                    BADMINTON_ERGEBNISSE_VERSION
                );
                break;
            }
        }
    }

    private function resolve_team_url( $atts ) {
        $url  = $atts['url'] ?? '';
        $name = '';

        if ( empty( $url ) ) {
            $teams = get_option( 'badminton_ergebnisse_teams', array() );
            $index = max( 0, intval( $atts['id'] ?? 1 ) - 1 );
            if ( isset( $teams[ $index ] ) ) {
                $url  = $teams[ $index ]['url'];
                $name = $teams[ $index ]['name'];
            }
        }

        return array( 'url' => $url, 'name' => $name );
    }

    /**
     * Returns configured team names for home/away detection.
     */
    private function get_team_keywords() {
        $teams = get_option( 'badminton_ergebnisse_teams', array() );
        $keywords = array();
        foreach ( $teams as $team ) {
            if ( ! empty( $team['name'] ) ) {
                $keywords[] = $team['name'];
            }
        }
        return $keywords;
    }

    /**
     * [badminton_ergebnisse] - Shows only the match results.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 1, 'url' => '' ), $atts, 'badminton_ergebnisse' );
        $resolved = $this->resolve_team_url( $atts );

        if ( empty( $resolved['url'] ) ) {
            return '<p class="bdm-error">Keine Mannschafts-URL konfiguriert. Bitte unter Einstellungen &gt; Badminton Ergebnisse eine URL eintragen.</p>';
        }

        $url_type = Badminton_Ergebnisse_Scraper::detect_url_type( $resolved['url'] );

        if ( $url_type === 'team' ) {
            $data = $this->scraper->get_team_data( $resolved['url'] );
            if ( is_wp_error( $data ) ) {
                return '<p class="bdm-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
            }
            $matches   = $data['matches'];
            $team_name = ! empty( $resolved['name'] ) ? $resolved['name'] : $data['team_name'];
        } else {
            $matches   = $this->scraper->get_matches( $resolved['url'] );
            $team_name = $resolved['name'];
        }

        if ( is_wp_error( $matches ) ) {
            return '<p class="bdm-error">Fehler: ' . esc_html( $matches->get_error_message() ) . '</p>';
        }
        if ( empty( $matches ) ) {
            return '<p class="bdm-info">Keine Spiele gefunden.</p>';
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
            return '<p class="bdm-error">Keine Mannschafts-URL konfiguriert.</p>';
        }

        $url_type = Badminton_Ergebnisse_Scraper::detect_url_type( $resolved['url'] );
        if ( $url_type !== 'team' ) {
            return '<p class="bdm-error">Fuer die Tabelle wird eine Team-URL benoetigt (Format: /sport/league/team?...).</p>';
        }

        $data = $this->scraper->get_team_data( $resolved['url'] );
        if ( is_wp_error( $data ) ) {
            return '<p class="bdm-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
        }

        if ( empty( $data['standings'] ) ) {
            return '<p class="bdm-info">Keine Tabellendaten gefunden.</p>';
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
            return '<p class="bdm-error">Keine Mannschafts-URL konfiguriert.</p>';
        }

        $url_type = Badminton_Ergebnisse_Scraper::detect_url_type( $resolved['url'] );

        if ( $url_type === 'team' ) {
            $data = $this->scraper->get_team_data( $resolved['url'] );
            if ( is_wp_error( $data ) ) {
                return '<p class="bdm-error">Fehler: ' . esc_html( $data->get_error_message() ) . '</p>';
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
            return '<p class="bdm-error">Fehler: ' . esc_html( $matches->get_error_message() ) . '</p>';
        }
        return $this->render_matches_table( $matches );
    }

    private function render_standings( $standings ) {
        $html = '<div class="bdm-wrapper">';

        $html .= '<div class="bdm-table-container">';
        $html .= '<table class="bdm-table bdm-standings-table">';
        $html .= '<thead><tr>';
        $html .= '<th class="bdm-rank">#</th>';
        $html .= '<th>Mannschaft</th>';
        $html .= '<th>Sp.</th>';
        $html .= '<th>Pkt.</th>';
        $html .= '<th class="bdm-hide-mobile">S</th>';
        $html .= '<th class="bdm-hide-mobile">U</th>';
        $html .= '<th class="bdm-hide-mobile">N</th>';
        $html .= '<th>Spiele</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ( $standings as $row ) {
            $classes = array();
            if ( $row['selected'] ) {
                $classes[] = 'bdm-selected';
            }
            if ( $row['promote'] ) {
                $classes[] = 'bdm-promote';
            }
            if ( $row['demote'] ) {
                $classes[] = 'bdm-demote';
            }

            $html .= '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '">';
            $html .= '<td class="bdm-rank">' . esc_html( $row['rank'] ) . '</td>';
            $html .= '<td class="bdm-team-name">' . esc_html( $row['name'] ) . '</td>';
            $html .= '<td>' . esc_html( $row['played'] ) . '</td>';
            $html .= '<td class="bdm-points">' . esc_html( $row['points_w'] . ':' . $row['points_l'] ) . '</td>';
            $html .= '<td class="bdm-hide-mobile">' . esc_html( $row['won'] ) . '</td>';
            $html .= '<td class="bdm-hide-mobile">' . esc_html( $row['draw'] ) . '</td>';
            $html .= '<td class="bdm-hide-mobile">' . esc_html( $row['lost'] ) . '</td>';
            $html .= '<td>' . esc_html( $row['games_w'] . ':' . $row['games_l'] ) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '<p class="bdm-source">Quelle: <a href="https://dbv.turnier.de" target="_blank" rel="noopener">dbv.turnier.de</a></p>';
        $html .= '</div>';

        return $html;
    }

    private function render_matches_table( $matches ) {
        $team_keywords = $this->get_team_keywords();

        $html = '<div class="bdm-wrapper">';

        $html .= '<div class="bdm-table-container">';
        $html .= '<table class="bdm-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Datum</th>';
        $html .= '<th>Heim</th>';
        $html .= '<th></th>';
        $html .= '<th>Gast</th>';
        $html .= '<th>Ergebnis</th>';
        $html .= '<th class="bdm-hide-mobile">Spielort</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ( $matches as $match ) {
            $is_home     = $this->is_own_team( $match['home'], $team_keywords );
            $is_guest    = $this->is_own_team( $match['guest'], $team_keywords );
            $row_class   = $is_home ? 'bdm-home-game' : 'bdm-away-game';
            $result_class = $this->get_result_class( $match, $is_home );

            $home_name  = esc_html( $match['home'] );
            $guest_name = esc_html( $match['guest'] );
            if ( $is_home ) {
                $home_name = '<strong>' . $home_name . '</strong>';
            }
            if ( $is_guest ) {
                $guest_name = '<strong>' . $guest_name . '</strong>';
            }

            $html .= '<tr class="' . esc_attr( $row_class ) . '">';
            $html .= '<td class="bdm-date">' . esc_html( $match['date'] ) . '</td>';
            $html .= '<td class="bdm-team bdm-home">' . $home_name . '</td>';
            $html .= '<td class="bdm-vs">:</td>';
            $html .= '<td class="bdm-team bdm-guest">' . $guest_name . '</td>';
            $display_result = str_replace( array( '{', '}' ), '', $match['result'] );
            $html .= '<td class="bdm-result ' . esc_attr( $result_class ) . '">' . esc_html( $display_result ) . '</td>';
            $venue = esc_html( $match['venue'] );
            // Insert line break after the 2nd comma
            $parts = explode( ',', $venue );
            if ( count( $parts ) > 2 ) {
                $venue = implode( ',', array_slice( $parts, 0, 2 ) ) . ',<br>' . ltrim( implode( ',', array_slice( $parts, 2 ) ) );
            }
            $html .= '<td class="bdm-venue bdm-hide-mobile">' . $venue . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';
        $html .= '<p class="bdm-source">Quelle: <a href="https://dbv.turnier.de" target="_blank" rel="noopener">dbv.turnier.de</a></p>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if a team name matches one of the configured teams.
     */
    private function is_own_team( $team_name, $keywords ) {
        foreach ( $keywords as $keyword ) {
            if ( ! empty( $keyword ) && stripos( $team_name, $keyword ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private function get_result_class( $match, $is_home ) {
        if ( empty( $match['result'] ) || strpos( $match['result'], '-' ) === false ) {
            return 'bdm-pending';
        }

        $result = str_replace( array( '{', '}' ), '', $match['result'] );
        $parts = explode( '-', $result );
        if ( count( $parts ) !== 2 ) {
            return '';
        }

        $home_score  = intval( trim( $parts[0] ) );
        $guest_score = intval( trim( $parts[1] ) );

        if ( $home_score === $guest_score ) {
            return 'bdm-draw';
        }

        $own_won = ( $is_home && $home_score > $guest_score ) || ( ! $is_home && $guest_score > $home_score );
        return $own_won ? 'bdm-win' : 'bdm-loss';
    }
}

add_action( 'plugins_loaded', array( 'Badminton_Ergebnisse', 'instance' ) );
