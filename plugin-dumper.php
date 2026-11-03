<?php
/**
 * Plugin Name: Plugin Dumper
 * Description: Gera e baixa arquivos ZIP dos plugins instalados diretamente pelo painel do WordPress.
 * Version: 1.0.0
 * Author: OpenAI
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Plugin_Dumper {
    const VERSION = '1.0.0';
    const MENU_SLUG = 'plugin-dumper';
    const NONCE_ACTION = 'plugin_dumper_generate_zip';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_plugin_dumper_download', [$this, 'handle_download']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function register_admin_page() {
        add_management_page(
            'Plugin Dumper',
            'Plugin Dumper',
            'install_plugins',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        if (!empty($_GET['plugin_dumper_error'])) {
            $message = sanitize_text_field(wp_unslash($_GET['plugin_dumper_error']));
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
    }

    public function render_admin_page() {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Você não tem permissão para acessar esta página.', 'plugin-dumper'));
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $active_plugins = (array) get_option('active_plugins', []);
        ?>
        <div class="wrap">
            <h1>Plugin Dumper</h1>
            <p>Gere um arquivo ZIP da pasta de qualquer plugin instalado.</p>

            <table class="widefat fixed striped">
                <thead>
                <tr>
                    <th style="width:32%;">Plugin</th>
                    <th style="width:28%;">Arquivo</th>
                    <th style="width:12%;">Versão</th>
                    <th style="width:12%;">Status</th>
                    <th style="width:16%;">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($plugins as $plugin_file => $plugin_data) :
                    $is_active = in_array($plugin_file, $active_plugins, true);
                    $download_url = wp_nonce_url(
                        admin_url('admin-post.php?action=plugin_dumper_download&plugin=' . rawurlencode($plugin_file)),
                        self::NONCE_ACTION . '|' . $plugin_file,
                        '_wpnonce'
                    );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($plugin_data['Name'] ?: $plugin_file); ?></strong>
                            <?php if (!empty($plugin_data['Description'])) : ?>
                                <div style="color:#646970; margin-top:4px;"><?php echo wp_kses_post($plugin_data['Description']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($plugin_file); ?></code></td>
                        <td><?php echo esc_html($plugin_data['Version'] ?: '—'); ?></td>
                        <td><?php echo $is_active ? '<span style="color:#008a20;font-weight:600;">Ativo</span>' : '<span style="color:#646970;">Inativo</span>'; ?></td>
                        <td><a class="button button-primary" href="<?php echo esc_url($download_url); ?>">Gerar ZIP</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;color:#646970;">
                O ZIP contém apenas os arquivos da pasta do plugin selecionado. O banco de dados do WordPress não é incluído nesta versão.
            </p>
        </div>
        <?php
    }

    public function handle_download() {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Você não tem permissão para gerar dumps de plugins.', 'plugin-dumper'));
        }

        $plugin_file = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        if ($plugin_file === '') {
            $this->redirect_error('Plugin não informado.');
        }

        check_admin_referer(self::NONCE_ACTION . '|' . $plugin_file);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        if (!isset($plugins[$plugin_file])) {
            $this->redirect_error('Plugin inválido ou não encontrado.');
        }

        if (!class_exists('ZipArchive')) {
            $this->redirect_error('A extensão PHP ZipArchive não está disponível no servidor.');
        }

        $plugin_root = $this->resolve_plugin_root($plugin_file);
        if (!$plugin_root || !is_dir($plugin_root)) {
            $this->redirect_error('Não foi possível localizar a pasta do plugin.');
        }

        $real_plugins_dir = realpath(WP_PLUGIN_DIR);
        $real_plugin_root = realpath($plugin_root);
        if (!$real_plugins_dir || !$real_plugin_root || strpos($real_plugin_root, $real_plugins_dir) !== 0) {
            $this->redirect_error('Caminho de plugin inválido.');
        }

        $slug = basename($real_plugin_root);
        $version = !empty($plugins[$plugin_file]['Version']) ? sanitize_file_name($plugins[$plugin_file]['Version']) : 'sem-versao';
        $filename = sprintf('%s-v%s-%s.zip', $slug, $version, wp_date('Ymd-His'));

        $tmp = wp_tempnam($filename);
        if (!$tmp) {
            $this->redirect_error('Não foi possível criar o arquivo temporário.');
        }

        $zip_path = $tmp . '.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zip_path);
            $this->redirect_error('Não foi possível criar o arquivo ZIP.');
        }

        $base_path = dirname($real_plugin_root);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real_plugin_root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getRealPath();
            if ($path === false) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($base_path))), '/');

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } elseif ($item->isFile()) {
                $zip->addFile($path, $relative);
            }
        }

        $manifest = [
            'plugin_file' => $plugin_file,
            'plugin_name' => $plugins[$plugin_file]['Name'] ?? $slug,
            'plugin_version' => $plugins[$plugin_file]['Version'] ?? '',
            'wordpress_version' => get_bloginfo('version'),
            'generated_at' => wp_date('c'),
            'generated_by' => get_current_user_id(),
            'plugin_dumper_version' => self::VERSION,
        ];
        $zip->addFromString($slug . '/plugin-dumper-manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();

        if (!file_exists($zip_path)) {
            $this->redirect_error('O ZIP não foi gerado corretamente.');
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('X-Content-Type-Options: nosniff');

        readfile($zip_path);
        @unlink($zip_path);
        exit;
    }

    private function resolve_plugin_root($plugin_file) {
        $plugin_file = wp_normalize_path($plugin_file);
        $dirname = dirname($plugin_file);

        if ($dirname === '.' || $dirname === DIRECTORY_SEPARATOR) {
            $single_file = WP_PLUGIN_DIR . '/' . basename($plugin_file);
            if (is_file($single_file)) {
                return WP_PLUGIN_DIR;
            }
            return false;
        }

        return WP_PLUGIN_DIR . '/' . $dirname;
    }

    private function redirect_error($message) {
        $url = add_query_arg(
            [
                'page' => self::MENU_SLUG,
                'plugin_dumper_error' => $message,
            ],
            admin_url('tools.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}

new WP_Plugin_Dumper();
