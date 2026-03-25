<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OSC_Badminton_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_osc_badminton_clear_cache', array( $this, 'ajax_clear_cache' ) );
    }

    public function add_menu() {
        add_options_page(
            'Badminton Ergebnisse',
            'Badminton Ergebnisse',
            'manage_options',
            'osc-badminton',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting( 'osc_badminton_settings', 'osc_badminton_teams', array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_teams' ),
            'default'           => array(),
        ) );

        register_setting( 'osc_badminton_settings', 'osc_badminton_cache_hours', array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 6,
        ) );
    }

    public function sanitize_teams( $input ) {
        if ( ! is_array( $input ) ) {
            return array();
        }

        $clean = array();
        foreach ( $input as $team ) {
            if ( empty( $team['url'] ) ) {
                continue;
            }
            $clean[] = array(
                'name' => sanitize_text_field( $team['name'] ?? '' ),
                'url'  => esc_url_raw( $team['url'] ),
            );
        }
        return $clean;
    }

    public function enqueue_admin_assets( $hook ) {
        if ( $hook !== 'settings_page_osc-badminton' ) {
            return;
        }
        wp_enqueue_style(
            'osc-badminton-admin',
            OSC_BADMINTON_URL . 'assets/css/admin.css',
            array(),
            OSC_BADMINTON_VERSION
        );
    }

    public function ajax_clear_cache() {
        check_ajax_referer( 'osc_badminton_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Keine Berechtigung.' );
        }

        $scraper = new OSC_Badminton_Scraper();
        $scraper->clear_cache();
        delete_transient( 'osc_badminton_cookies' );

        wp_send_json_success( 'Cache geleert.' );
    }

    public function render_page() {
        $teams       = get_option( 'osc_badminton_teams', array() );
        $cache_hours = get_option( 'osc_badminton_cache_hours', 6 );

        if ( empty( $teams ) ) {
            $teams = array( array( 'name' => '', 'url' => '' ) );
        }
        ?>
        <div class="wrap osc-badminton-admin">
            <h1>Badminton Ergebnisse</h1>
            <p>Konfiguriere die Mannschaften, deren Ergebnisse von <strong>dbv.turnier.de</strong> geladen werden sollen.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'osc_badminton_settings' ); ?>

                <h2>Mannschaften</h2>
                <p class="description">
                Trage die <strong>Team-URL</strong> ein (Format: <code>https://dbv.turnier.de/sport/league/team?id=...&amp;team=...</code>).<br />
                Damit werden automatisch <strong>Tabellenstand und Spielergebnisse</strong> geladen.<br />
                Alternativ funktioniert auch die Matches-URL (<code>.../sport/teammatches.aspx?...</code>) - dann aber nur Ergebnisse.
            </p>

                <div id="osc-teams-list">
                    <?php foreach ( $teams as $i => $team ) : ?>
                        <div class="osc-team-row" data-index="<?php echo $i; ?>">
                            <span class="osc-team-number"><?php echo $i + 1; ?>.</span>
                            <label>
                                Name:
                                <input type="text"
                                       name="osc_badminton_teams[<?php echo $i; ?>][name]"
                                       value="<?php echo esc_attr( $team['name'] ); ?>"
                                       placeholder="z.B. OSC BG Essen-Werden 3"
                                       class="regular-text" />
                            </label>
                            <label>
                                URL:
                                <input type="url"
                                       name="osc_badminton_teams[<?php echo $i; ?>][url]"
                                       value="<?php echo esc_url( $team['url'] ); ?>"
                                       placeholder="https://dbv.turnier.de/sport/league/team?id=...&team=..."
                                       class="large-text" />
                            </label>
                            <button type="button" class="button osc-remove-team" title="Entfernen">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button" id="osc-add-team">+ Mannschaft hinzufuegen</button>
                </p>

                <h2>Cache-Einstellungen</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Cache-Dauer</th>
                        <td>
                            <input type="number" name="osc_badminton_cache_hours"
                                   value="<?php echo esc_attr( $cache_hours ); ?>"
                                   min="1" max="168" class="small-text" />
                            Stunden
                            <p class="description">Wie oft sollen die Ergebnisse von turnier.de neu geladen werden? (Standard: 6 Stunden)</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Einstellungen speichern' ); ?>
            </form>

            <hr />
            <h2>Cache verwalten</h2>
            <p>
                <button type="button" class="button" id="osc-clear-cache">Cache jetzt leeren</button>
                <span id="osc-cache-status"></span>
            </p>

            <hr />
            <h2>Shortcode-Hilfe</h2>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Beschreibung</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[badminton_alle]</code></td>
                        <td>Zeigt <strong>Tabelle + Ergebnisse</strong> der ersten Mannschaft</td>
                    </tr>
                    <tr>
                        <td><code>[badminton_tabelle]</code></td>
                        <td>Zeigt nur den <strong>Tabellenstand</strong> (benoetigt Team-URL)</td>
                    </tr>
                    <tr>
                        <td><code>[badminton_ergebnisse]</code></td>
                        <td>Zeigt nur die <strong>Spielergebnisse</strong></td>
                    </tr>
                    <tr>
                        <td><code>[badminton_alle id="2"]</code></td>
                        <td>Zeigt Tabelle + Ergebnisse der zweiten Mannschaft</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            var list = document.getElementById('osc-teams-list');
            var addBtn = document.getElementById('osc-add-team');

            addBtn.addEventListener('click', function() {
                var rows = list.querySelectorAll('.osc-team-row');
                var idx = rows.length;
                var div = document.createElement('div');
                div.className = 'osc-team-row';
                div.dataset.index = idx;
                div.innerHTML = '<span class="osc-team-number">' + (idx + 1) + '.</span>' +
                    '<label>Name: <input type="text" name="osc_badminton_teams[' + idx + '][name]" value="" placeholder="z.B. Mannschaftsname" class="regular-text" /></label>' +
                    '<label>URL: <input type="url" name="osc_badminton_teams[' + idx + '][url]" value="" placeholder="https://dbv.turnier.de/sport/league/team?id=...&team=..." class="large-text" /></label>' +
                    '<button type="button" class="button osc-remove-team" title="Entfernen">&times;</button>';
                list.appendChild(div);
            });

            list.addEventListener('click', function(e) {
                if (e.target.classList.contains('osc-remove-team')) {
                    var rows = list.querySelectorAll('.osc-team-row');
                    if (rows.length > 1) {
                        e.target.closest('.osc-team-row').remove();
                    }
                }
            });

            document.getElementById('osc-clear-cache').addEventListener('click', function() {
                var status = document.getElementById('osc-cache-status');
                var btn = this;
                btn.disabled = true;
                status.textContent = 'Wird geleert...';

                var data = new FormData();
                data.append('action', 'osc_badminton_clear_cache');
                data.append('nonce', '<?php echo wp_create_nonce( 'osc_badminton_nonce' ); ?>');

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        status.textContent = r.success ? 'Cache geleert!' : 'Fehler: ' + r.data;
                        btn.disabled = false;
                    })
                    .catch(function() {
                        status.textContent = 'Fehler beim Leeren.';
                        btn.disabled = false;
                    });
            });
        })();
        </script>
        <?php
    }
}
