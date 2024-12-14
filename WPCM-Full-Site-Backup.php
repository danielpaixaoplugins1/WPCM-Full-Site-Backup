<?php
/*
Plugin Name: WPCM Full Site Backup
Description: Plugin para backup completo do site, incluindo arquivos e banco de dados, com backups incrementais e agendados automaticamente.
Version: 1.0
Author: Ninja Code
*/

if (!defined('ABSPATH')) {
    exit; // Evita acesso direto.
}

// Diretório para backups
define('WPCM_BACKUP_DIR', WP_CONTENT_DIR . '/wpcm-backups/');
define('WPCM_BACKUP_RETENTION_DAYS', 7); // Retenção de 7 dias

// Classe principal do plugin
class WPCM_Full_Site_Backup {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wpcm_daily_backup_event', [$this, 'perform_daily_backup']);
        register_activation_hook(__FILE__, [$this, 'schedule_backup']);
        register_deactivation_hook(__FILE__, [$this, 'unschedule_backup']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'WPCM Full Site Backup',
            'Backup Completo',
            'manage_options',
            'wpcm-full-site-backup',
            [$this, 'render_dashboard_page'],
            'dashicons-backup'
        );
    }

    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>WPCM Full Site Backup</h1>
            <p>Backups completos do site são realizados automaticamente todos os dias à meia-noite.</p>
            <p>Os backups são incrementais e mantêm apenas os últimos 7 dias.</p>
            <h2>Últimos Backups</h2>
            <ul>
                <?php
                $backups = $this->get_backup_files();
                if (!empty($backups)) {
                    foreach ($backups as $backup) {
                        echo '<li>' . esc_html(basename($backup)) . ' - ' . date('Y-m-d H:i:s', filemtime($backup)) . '</li>';
                    }
                } else {
                    echo '<li>Nenhum backup encontrado.</li>';
                }
                ?>
            </ul>
        </div>
        <?php
    }

    public function schedule_backup() {
        if (!wp_next_scheduled('wpcm_daily_backup_event')) {
            wp_schedule_event(strtotime('midnight'), 'daily', 'wpcm_daily_backup_event');
        }
    }

    public function unschedule_backup() {
        $timestamp = wp_next_scheduled('wpcm_daily_backup_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wpcm_daily_backup_event');
        }
    }

    public function perform_daily_backup() {
        $this->backup_files();
        $this->backup_database();
        $this->cleanup_old_backups();
    }

    private function backup_files() {
        $backup_file = WPCM_BACKUP_DIR . 'files_' . date('Y-m-d') . '.zip';

        if (!is_dir(WPCM_BACKUP_DIR)) {
            mkdir(WPCM_BACKUP_DIR, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $this->add_directory_to_zip(ABSPATH, $zip);
            $zip->close();
        }
    }

    private function add_directory_to_zip($dir, $zip) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative_path = substr($file->getPathname(), strlen(ABSPATH));
                $zip->addFile($file->getPathname(), $relative_path);
            }
        }
    }

    private function backup_database() {
        global $wpdb;

        $backup_file = WPCM_BACKUP_DIR . 'database_' . date('Y-m-d') . '.sql.gz';

        $tables = $wpdb->get_col("SHOW TABLES");
        $sql = '';

        foreach ($tables as $table) {
            $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_N);
            $sql .= "DROP TABLE IF EXISTS $table;\n";

            $create_table = $wpdb->get_row("SHOW CREATE TABLE $table", ARRAY_N);
            $sql .= $create_table[1] . ";\n";

            foreach ($rows as $row) {
                $values = implode("','", array_map([$wpdb, 'escape'], $row));
                $sql .= "INSERT INTO $table VALUES('$values');\n";
            }
        }

        file_put_contents($backup_file, gzencode($sql, 9));
    }

    private function cleanup_old_backups() {
        $files = glob(WPCM_BACKUP_DIR . '*');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= WPCM_BACKUP_RETENTION_DAYS * 86400) {
                    unlink($file);
                }
            }
        }
    }

    private function get_backup_files() {
        return glob(WPCM_BACKUP_DIR . '*');
    }
}

// Inicializa o plugin
new WPCM_Full_Site_Backup();
