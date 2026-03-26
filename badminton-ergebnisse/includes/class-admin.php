<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Badminton_Ergebnisse_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_badminton_ergebnisse_clear_cache', array( $this, 'ajax_clear_cache' ) );
    }

    public function add_menu() {
        add_options_page(
            'Badminton Ergebnisse',
            'Badminton Ergebnisse',
            'manage_options',
            'badminton-ergebnisse',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting( 'badminton_ergebnisse_settings', 'badminton_ergebnisse_teams', array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitize_teams' ),
            'default'           => array(),
        ) );

        register_setting( 'badminton_ergebnisse_settings', 'badminton_ergebnisse_cache_hours', array(
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
        if ( $hook !== 'settings_page_badminton-ergebnisse' ) {
            return;
        }
        wp_enqueue_style(
            'badminton-ergebnisse-admin',
            BADMINTON_ERGEBNISSE_URL . 'assets/css/admin.css',
            array(),
            BADMINTON_ERGEBNISSE_VERSION
        );
    }

    public function ajax_clear_cache() {
        check_ajax_referer( 'badminton_ergebnisse_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Keine Berechtigung.' );
        }

        $scraper = new Badminton_Ergebnisse_Scraper();
        $scraper->clear_cache();
        delete_transient( 'badminton_ergebnisse_cookies' );

        wp_send_json_success( 'Cache geleert.' );
    }

    public function render_page() {
        $teams       = get_option( 'badminton_ergebnisse_teams', array() );
        $cache_hours = get_option( 'badminton_ergebnisse_cache_hours', 6 );

        if ( empty( $teams ) ) {
            $teams = array( array( 'name' => '', 'url' => '' ) );
        }
        ?>
        <div class="wrap bdm-admin">
            <h1>Badminton Ergebnisse</h1>
            <p>Konfiguriere die Mannschaften, deren Ergebnisse von <strong>dbv.turnier.de</strong> geladen werden sollen.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'badminton_ergebnisse_settings' ); ?>

                <h2>Mannschaften</h2>
                <p class="description">
                Trage die <strong>Team-URL</strong> ein (Format: <code>https://dbv.turnier.de/sport/league/team?id=...&amp;team=...</code>).<br />
                Damit werden automatisch <strong>Tabellenstand und Spielergebnisse</strong> geladen.<br />
                Alternativ funktioniert auch die Matches-URL (<code>.../sport/teammatches.aspx?...</code>) - dann aber nur Ergebnisse.<br />
                <strong>Hinweis:</strong> Der Name wird auch zur Erkennung von Heim-/Auswaertsspielen verwendet.
            </p>

                <div id="bdm-teams-list">
                    <?php foreach ( $teams as $i => $team ) : ?>
                        <div class="bdm-team-row" data-index="<?php echo $i; ?>">
                            <span class="bdm-team-number"><?php echo $i + 1; ?>.</span>
                            <label>
                                Name:
                                <input type="text"
                                       name="badminton_ergebnisse_teams[<?php echo $i; ?>][name]"
                                       value="<?php echo esc_attr( $team['name'] ); ?>"
                                       placeholder="z.B. Mein Verein 1"
                                       class="regular-text" />
                            </label>
                            <label>
                                URL:
                                <input type="url"
                                       name="badminton_ergebnisse_teams[<?php echo $i; ?>][url]"
                                       value="<?php echo esc_url( $team['url'] ); ?>"
                                       placeholder="https://dbv.turnier.de/sport/league/team?id=...&team=..."
                                       class="large-text" />
                            </label>
                            <button type="button" class="button bdm-remove-team" title="Entfernen">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button" id="bdm-add-team">+ Mannschaft hinzufuegen</button>
                </p>

                <h2>Cache-Einstellungen</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Cache-Dauer</th>
                        <td>
                            <input type="number" name="badminton_ergebnisse_cache_hours"
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
                <button type="button" class="button" id="bdm-clear-cache">Cache jetzt leeren</button>
                <span id="bdm-cache-status"></span>
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
            var list = document.getElementById('bdm-teams-list');
            var addBtn = document.getElementById('bdm-add-team');

            addBtn.addEventListener('click', function() {
                var rows = list.querySelectorAll('.bdm-team-row');
                var idx = rows.length;
                var div = document.createElement('div');
                div.className = 'bdm-team-row';
                div.dataset.index = idx;
                div.innerHTML = '<span class="bdm-team-number">' + (idx + 1) + '.</span>' +
                    '<label>Name: <input type="text" name="badminton_ergebnisse_teams[' + idx + '][name]" value="" placeholder="z.B. Mannschaftsname" class="regular-text" /></label>' +
                    '<label>URL: <input type="url" name="badminton_ergebnisse_teams[' + idx + '][url]" value="" placeholder="https://dbv.turnier.de/sport/league/team?id=...&team=..." class="large-text" /></label>' +
                    '<button type="button" class="button bdm-remove-team" title="Entfernen">&times;</button>';
                list.appendChild(div);
            });

            list.addEventListener('click', function(e) {
                if (e.target.classList.contains('bdm-remove-team')) {
                    var rows = list.querySelectorAll('.bdm-team-row');
                    if (rows.length > 1) {
                        e.target.closest('.bdm-team-row').remove();
                    }
                }
            });

            document.getElementById('bdm-clear-cache').addEventListener('click', function() {
                var status = document.getElementById('bdm-cache-status');
                var btn = this;
                btn.disabled = true;
                status.textContent = 'Wird geleert...';

                var data = new FormData();
                data.append('action', 'badminton_ergebnisse_clear_cache');
                data.append('nonce', '<?php echo wp_create_nonce( 'badminton_ergebnisse_nonce' ); ?>');

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
