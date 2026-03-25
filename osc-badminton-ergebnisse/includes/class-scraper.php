<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OSC_Badminton_Scraper {

    private $cache_hours;
    private $cookie_file;

    public function __construct() {
        $this->cache_hours = intval( get_option( 'osc_badminton_cache_hours', 6 ) );
        $upload_dir = wp_upload_dir();
        $this->cookie_file = $upload_dir['basedir'] . '/osc-badminton-cookies.txt';
    }

    /**
     * Get all data for a team: standings + matches.
     * Accepts either a team overview URL or a matches URL.
     */
    public function get_team_data( $url ) {
        $cache_key = 'osc_badminton_data_' . md5( $url );

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $data = $this->fetch_team_data( $url );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        set_transient( $cache_key, $data, $this->cache_hours * HOUR_IN_SECONDS );
        return $data;
    }

    public function get_matches( $url ) {
        $cache_key = 'osc_badminton_' . md5( $url );

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $matches = $this->fetch_matches( $url );
        if ( is_wp_error( $matches ) ) {
            return $matches;
        }

        set_transient( $cache_key, $matches, $this->cache_hours * HOUR_IN_SECONDS );
        return $matches;
    }

    public function clear_cache( $url = '' ) {
        if ( ! empty( $url ) ) {
            delete_transient( 'osc_badminton_' . md5( $url ) );
            delete_transient( 'osc_badminton_data_' . md5( $url ) );
            return;
        }
        $teams = get_option( 'osc_badminton_teams', array() );
        foreach ( $teams as $team ) {
            if ( ! empty( $team['url'] ) ) {
                delete_transient( 'osc_badminton_' . md5( $team['url'] ) );
                delete_transient( 'osc_badminton_data_' . md5( $team['url'] ) );
            }
        }
        if ( file_exists( $this->cookie_file ) ) {
            @unlink( $this->cookie_file );
        }
    }

    /**
     * Detect URL type: 'team' for team overview, 'matches' for matches page.
     */
    public static function detect_url_type( $url ) {
        if ( strpos( $url, '/sport/league/team' ) !== false ) {
            return 'team';
        }
        if ( strpos( $url, 'teammatches' ) !== false ) {
            return 'matches';
        }
        return 'unknown';
    }

    private function fetch_team_data( $url ) {
        $url_type = self::detect_url_type( $url );

        // If it's a matches URL, we need the team URL - derive it
        if ( $url_type === 'matches' ) {
            $matches_url = $url;
            $team_page_body = null;
        } else {
            // It's a team URL - fetch the team overview page
            $team_page_body = $this->fetch_page( $url );
            if ( is_wp_error( $team_page_body ) ) {
                return $team_page_body;
            }

            // Extract matches URL from the team page
            $matches_url = $this->extract_matches_url( $team_page_body, $url );
        }

        // Parse standings from team page
        $standings = null;
        $league_name = '';
        $team_name = '';

        if ( $team_page_body !== null ) {
            $parsed = $this->parse_team_page( $team_page_body );
            $standings   = $parsed['standings'];
            $league_name = $parsed['league_name'];
            $team_name   = $parsed['team_name'];
        }

        // Fetch matches
        $matches = array();
        if ( ! empty( $matches_url ) ) {
            $matches_body = $this->fetch_page( $matches_url );
            if ( ! is_wp_error( $matches_body ) ) {
                $matches = $this->parse_matches( $matches_body );
                if ( is_wp_error( $matches ) ) {
                    $matches = array();
                }
            }
        }

        return array(
            'team_name'   => $team_name,
            'league_name' => $league_name,
            'standings'   => $standings,
            'matches'     => $matches,
        );
    }

    private function fetch_matches( $url ) {
        $body = $this->fetch_page( $url );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        return $this->parse_matches( $body );
    }

    private function fetch_page( $url ) {
        $this->accept_cookiewall( $url );

        $body = $this->curl_get( $url );

        if ( $body === false ) {
            return new WP_Error( 'fetch_error', 'Fehler beim Abrufen der Seite von turnier.de.' );
        }

        if ( strpos( $body, 'cookiewall' ) !== false && strpos( $body, 'ruler matches' ) === false && strpos( $body, 'teamstandings' ) === false ) {
            @unlink( $this->cookie_file );
            $this->accept_cookiewall( $url );
            $body = $this->curl_get( $url );

            if ( $body === false ) {
                return new WP_Error( 'fetch_error', 'Fehler beim Abrufen der Seite von turnier.de.' );
            }
        }

        return $body;
    }

    private function accept_cookiewall( $url ) {
        if ( file_exists( $this->cookie_file ) && ( time() - filemtime( $this->cookie_file ) ) < 86400 ) {
            return;
        }

        $parsed = wp_parse_url( $url );
        $base   = $parsed['scheme'] . '://' . $parsed['host'];
        $return_path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        if ( isset( $parsed['query'] ) ) {
            $return_path .= '?' . $parsed['query'];
        }

        $cookiewall_url = $base . '/cookiewall/?returnurl=' . rawurlencode( $return_path );
        $save_url       = $base . '/cookiewall/Save';

        $this->curl_get( $cookiewall_url );

        $post_data = 'returnurl=' . rawurlencode( $return_path ) . '&purposes=0';
        $this->curl_post( $save_url, $post_data, $cookiewall_url );
    }

    private function curl_get( $url ) {
        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_COOKIEJAR      => $this->cookie_file,
            CURLOPT_COOKIEFILE     => $this->cookie_file,
            CURLOPT_HTTPHEADER     => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            ),
        ) );

        $body = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        return ( $code >= 200 && $code < 400 ) ? $body : false;
    }

    private function curl_post( $url, $post_data, $referer = '' ) {
        $ch = curl_init( $url );
        $headers = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        );
        if ( ! empty( $referer ) ) {
            $headers[] = 'Referer: ' . $referer;
        }

        curl_setopt_array( $ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_COOKIEJAR      => $this->cookie_file,
            CURLOPT_COOKIEFILE     => $this->cookie_file,
            CURLOPT_HTTPHEADER     => $headers,
        ) );

        $body = curl_exec( $ch );
        curl_close( $ch );

        return $body;
    }

    private function extract_matches_url( $html, $base_url ) {
        $prev = libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        $parsed_base = wp_parse_url( $base_url );
        $base = $parsed_base['scheme'] . '://' . $parsed_base['host'];

        // Find any link containing "teammatches"
        $links = $xpath->query( "//a[contains(@href, 'teammatches')]" );
        if ( $links->length > 0 ) {
            $href = $links->item( 0 )->getAttribute( 'href' );
            $href = html_entity_decode( $href );
            return $this->resolve_url( $href, $base, $parsed_base['path'] ?? '/' );
        }

        // Fallback: find "mehr Spiele..." link
        $links = $xpath->query( "//a[contains(text(), 'mehr Spiele')]" );
        if ( $links->length > 0 ) {
            $href = $links->item( 0 )->getAttribute( 'href' );
            $href = html_entity_decode( $href );
            return $this->resolve_url( $href, $base, $parsed_base['path'] ?? '/' );
        }

        return '';
    }

    private function parse_team_page( $html ) {
        $prev = libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        // Extract team name - look for "Mannschaft:" text in various elements
        $team_name = '';
        // Try multiple selectors
        $selectors = array(
            "//h3[contains(text(), 'Mannschaft:')]",
            "//h2[contains(text(), 'Mannschaft:')]",
            "//h1[contains(text(), 'Mannschaft:')]",
            "//*[contains(text(), 'Mannschaft:')]",
        );
        foreach ( $selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            foreach ( $nodes as $node ) {
                $text = trim( $node->textContent );
                if ( strpos( $text, 'Mannschaft:' ) !== false || strpos( $text, 'Mannschaft' ) !== false ) {
                    // Extract name after "Mannschaft:"
                    if ( preg_match( '/Mannschaft:\s*(.+?)(?:\s*\([^)]+\))?(?:\s*[-–]\s*\w+)?$/u', $text, $m ) ) {
                        $team_name = trim( $m[1] );
                    }
                    if ( ! empty( $team_name ) ) {
                        break 2;
                    }
                }
            }
        }
        // Fallback: extract from page title
        if ( empty( $team_name ) ) {
            $titles = $xpath->query( '//title' );
            if ( $titles->length > 0 ) {
                $title = $titles->item( 0 )->textContent;
                if ( preg_match( '/Mannschaft:\s*(.+?)(?:\s*\([^)]+\))/u', $title, $m ) ) {
                    $team_name = trim( $m[1] );
                }
            }
        }
        // Remove star/favorite characters
        $team_name = trim( preg_replace( '/[\x{2605}\x{2606}\x{2764}]/u', '', $team_name ) );

        // Extract league name from h2
        $league_name = '';
        $h2_nodes = $xpath->query( "//h2[contains(@class, '') and (contains(text(), 'Liga') or contains(text(), 'liga') or contains(text(), 'O19') or contains(text(), 'Klasse'))]" );
        if ( $h2_nodes->length > 0 ) {
            $league_name = trim( $h2_nodes->item( 0 )->textContent );
        }
        // Fallback: find any h2 before the standings table
        if ( empty( $league_name ) ) {
            $h2_all = $xpath->query( "//h2" );
            foreach ( $h2_all as $h2 ) {
                $text = trim( $h2->textContent );
                if ( strpos( $text, 'O19' ) !== false || strpos( $text, 'Liga' ) !== false || strpos( $text, 'liga' ) !== false || strpos( $text, 'Klasse' ) !== false ) {
                    $league_name = $text;
                    break;
                }
            }
        }

        // Parse standings table
        $standings = $this->parse_standings( $xpath );

        return array(
            'team_name'   => $team_name,
            'league_name' => $league_name,
            'standings'   => $standings,
        );
    }

    private function parse_standings( $xpath ) {
        $tables = $xpath->query( "//table[contains(@class, 'teamstandings')]" );
        if ( $tables->length === 0 ) {
            return null;
        }

        $table = $tables->item( 0 );
        $rows  = $xpath->query( './/tr', $table );
        $standings = array();

        for ( $i = 1; $i < $rows->length; $i++ ) {
            $row   = $rows->item( $i );
            $cells = $xpath->query( './/td', $row );

            if ( $cells->length < 9 ) {
                continue;
            }

            $class = $row->getAttribute( 'class' );
            $is_selected = strpos( $class, 'selected' ) !== false;
            $is_promote  = strpos( $class, 'promote' ) !== false;
            $is_demote   = strpos( $class, 'demote' ) !== false;

            // Columns: 0=rank, 1=name, 2=played, 3=points_w, 4=":", 5=points_l, 6=won, 7=draw, 8=lost, 9=games_w, 10=":", 11=games_l
            $standings[] = array(
                'rank'       => trim( $cells->item( 0 )->textContent ),
                'name'       => trim( $cells->item( 1 )->textContent ),
                'played'     => trim( $cells->item( 2 )->textContent ),
                'points_w'   => trim( $cells->item( 3 )->textContent ),
                'points_l'   => trim( $cells->item( 5 )->textContent ),
                'won'        => trim( $cells->item( 6 )->textContent ),
                'draw'       => trim( $cells->item( 7 )->textContent ),
                'lost'       => trim( $cells->item( 8 )->textContent ),
                'games_w'    => $cells->length > 9 ? trim( $cells->item( 9 )->textContent ) : '',
                'games_l'    => $cells->length > 11 ? trim( $cells->item( 11 )->textContent ) : '',
                'selected'   => $is_selected,
                'promote'    => $is_promote,
                'demote'     => $is_demote,
            );
        }

        return $standings;
    }

    private function parse_matches( $html ) {
        if ( empty( $html ) ) {
            return array();
        }

        $prev = libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        $tables = $xpath->query( "//table[contains(@class, 'ruler') and contains(@class, 'matches')]" );

        if ( $tables->length === 0 ) {
            $tables = $xpath->query( "//table[contains(@class, 'ruler')]" );
        }

        if ( $tables->length === 0 ) {
            return new WP_Error( 'no_table', 'Keine Ergebnis-Tabelle auf der Seite gefunden.' );
        }

        $table = $tables->item( 0 );
        $rows  = $xpath->query( './/tr', $table );
        $matches = array();

        for ( $i = 1; $i < $rows->length; $i++ ) {
            $cells = $xpath->query( './/td', $rows->item( $i ) );

            if ( $cells->length < 10 ) {
                continue;
            }

            $date   = trim( $cells->item( 1 )->textContent );
            $home   = trim( $cells->item( 6 )->textContent );
            $guest  = trim( $cells->item( 8 )->textContent );
            $result = trim( $cells->item( 9 )->textContent );

            $venue = '';
            if ( $cells->length > 11 ) {
                $venue = trim( $cells->item( 11 )->textContent );
            }

            $date = $this->clean_date( $date );

            $matches[] = array(
                'date'   => $date,
                'home'   => $home,
                'guest'  => $guest,
                'result' => $result,
                'venue'  => $venue,
            );
        }

        return $matches;
    }

    private function resolve_url( $href, $base, $current_path ) {
        // Absolute URL
        if ( strpos( $href, 'http' ) === 0 ) {
            return $href;
        }
        // Absolute path
        if ( strpos( $href, '/' ) === 0 ) {
            return $base . $href;
        }
        // Relative path (e.g. ../teammatches.aspx?...)
        $dir = rtrim( dirname( $current_path ), '/' );
        // Handle ../ by going up one level
        while ( strpos( $href, '../' ) === 0 ) {
            $href = substr( $href, 3 );
            $dir  = dirname( $dir );
        }
        return $base . '/' . ltrim( $dir . '/' . $href, '/' );
    }

    private function clean_date( $date_str ) {
        $date_str = preg_replace( '/^(Mo|Di|Mi|Do|Fr|Sa|So)\s+/', '$1, ', $date_str );
        return $date_str;
    }
}
