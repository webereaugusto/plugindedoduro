<?php
/**
 * Plugin Name: Acessos de Franqueados
 * Plugin URI: https://megacubbo.com.br
 * Description: Registra e fornece relatórios sobre as visitas dos usuários cadastrados no WordPress
 * Version: 1.2.0
 * Author: Weber E. Augusto
 * Author URI: https://megacubbo.com.br
 * Text Domain: acessos-franqueados
 * 
 * Changelog:
 * 1.0.0 - Versão inicial
 * - Registro de acessos dos franqueados
 * - Relatório de visitas com filtros
 * - Visualização detalhada por franqueado
 * 
 * 1.1.1 - Correção do fuso horário
 * - Ajuste do fuso horário para São Paulo/Brasil
 * - Correção na exibição das datas e horários
 * 
 * 1.2.0 - Simplificação do plugin
 * - Remoção do sistema de alertas
 * - Foco apenas no registro e visualização de acessos
 */

// Prevenir acesso direto ao arquivo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes do plugin
define('AF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Criar tabela ao ativar o plugin
register_activation_hook(__FILE__, 'af_create_visits_table');

function af_create_visits_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        visit_date datetime DEFAULT CURRENT_TIMESTAMP,
        page_url varchar(255) NOT NULL,
        page_title varchar(255) NOT NULL,
        session_id varchar(32) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Carregar CSS
function af_enqueue_admin_styles($hook) {
    if ('toplevel_page_acessos-franqueados' !== $hook) {
        return;
    }
    wp_enqueue_style('acessos-franqueados', AF_PLUGIN_URL . 'assets/css/user-visit-tracker.css', array(), '1.0.0');
}
add_action('admin_enqueue_scripts', 'af_enqueue_admin_styles');

// Registrar visita do usuário
function af_record_visit() {
    if (!is_user_logged_in()) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';
    $user_id = get_current_user_id();
    $page_url = esc_url($_SERVER['REQUEST_URI']);
    $page_title = get_the_title();

    if (empty($_COOKIE['af_session_id'])) {
        $session_id = md5(uniqid('', true));
        setcookie('af_session_id', $session_id, time() + 3600, '/');
    } else {
        $session_id = $_COOKIE['af_session_id'];
    }

    // Obter o horário atual no fuso horário do WordPress
    $current_time = current_time('mysql');

    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'page_url' => $page_url,
            'page_title' => $page_title,
            'session_id' => $session_id,
            'visit_date' => $current_time
        ),
        array('%d', '%s', '%s', '%s', '%s')
    );
}
add_action('wp', 'af_record_visit');

// Adicionar menu no painel administrativo
function af_add_admin_menu() {
    add_menu_page(
        'Relatório de Acessos',
        'Acessos de Franqueados',
        'manage_options',
        'acessos-franqueados',
        'af_display_report',
        'dashicons-chart-area',
        30
    );
}
add_action('admin_menu', 'af_add_admin_menu');

// Adicionar submenu escondido para detalhes do usuário
function af_add_hidden_submenu() {
    add_submenu_page(
        null,
        'Detalhes de Acessos do Franqueado',
        'Detalhes de Acessos',
        'manage_options',
        'franqueado-details',
        'af_display_user_details'
    );
}
add_action('admin_menu', 'af_add_hidden_submenu');

// Adicionar submenu para Relatórios
function af_add_reports_submenu() {
    add_submenu_page(
        'acessos-franqueados',
        'Relatórios',
        'Relatórios',
        'manage_options',
        'franqueados-relatorios',
        'af_display_reports_page'
    );
}
add_action('admin_menu', 'af_add_reports_submenu');

// Função para formatar data e hora no fuso horário correto
function af_format_datetime($mysql_date, $format = 'd/m/Y H:i:s') {
    $datetime = get_date_from_gmt($mysql_date);
    return date_i18n($format, strtotime($datetime));
}

// Função para exibir detalhes do usuário
function af_display_user_details() {
    if (!isset($_GET['user_id'])) {
        wp_die('Franqueado não especificado');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';
    $user_id = intval($_GET['user_id']);
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;

    // Buscar informações do usuário
    $user = get_user_by('id', $user_id);
    if (!$user) {
        wp_die('Franqueado não encontrado');
    }

    // Buscar visitas do usuário usando UTC para comparação
    $visits = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name 
        WHERE user_id = %d 
        AND visit_date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
        ORDER BY visit_date DESC",
        $user_id,
        $days
    ));

    ?>
    <div class="wrap user-visit-tracker-wrap">
        <h1>
            Histórico de Acessos: <?php echo esc_html($user->display_name); ?>
            <a href="<?php echo admin_url('admin.php?page=acessos-franqueados'); ?>" class="page-title-action">Voltar ao Relatório</a>
        </h1>

        <div class="tablenav top">
            <form method="get">
                <input type="hidden" name="page" value="franqueado-details">
                <input type="hidden" name="user_id" value="<?php echo esc_attr($user_id); ?>">
                <select name="days">
                    <option value="7" <?php selected($days, 7); ?>>Últimos 7 dias</option>
                    <option value="30" <?php selected($days, 30); ?>>Últimos 30 dias</option>
                    <option value="90" <?php selected($days, 90); ?>>Últimos 90 dias</option>
                </select>
                <input type="submit" class="button" value="Filtrar">
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Data e Hora</th>
                    <th>Página Visitada</th>
                    <th>Título da Página</th>
                    <th>ID da Sessão</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($visits)): ?>
                    <tr>
                        <td colspan="4">Nenhum acesso registrado no período selecionado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($visits as $visit): ?>
                        <tr>
                            <td><?php echo esc_html(af_format_datetime($visit->visit_date)); ?></td>
                            <td><a href="<?php echo esc_url(home_url($visit->page_url)); ?>" target="_blank"><?php echo esc_html($visit->page_url); ?></a></td>
                            <td><?php echo esc_html($visit->page_title); ?></td>
                            <td><?php echo esc_html($visit->session_id); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Exibir relatório
function af_display_report() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';

    // Filtros
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // Construir query usando UTC para comparação
    $where = "WHERE 1=1";
    $where .= " AND visit_date >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $days DAY)";
    if ($user_id > 0) {
        $where .= $wpdb->prepare(" AND user_id = %d", $user_id);
    }

    // Buscar visitas
    $visits = $wpdb->get_results(
        "SELECT uv.*, u.display_name, 
        COUNT(DISTINCT uv.session_id) as total_sessions,
        COUNT(*) as total_pageviews,
        MAX(uv.visit_date) as last_visit_date
        FROM $table_name uv 
        LEFT JOIN {$wpdb->users} u ON uv.user_id = u.ID 
        $where
        GROUP BY uv.user_id
        ORDER BY last_visit_date DESC"
    );

    // Buscar usuários para o filtro
    $users = get_users(array('fields' => array('ID', 'display_name')));

    ?>
    <div class="wrap user-visit-tracker-wrap">
        <h1>Relatório de Acessos de Franqueados</h1>
        
        <div class="tablenav top">
            <form method="get">
                <input type="hidden" name="page" value="acessos-franqueados">
                <select name="days">
                    <option value="7" <?php selected($days, 7); ?>>Últimos 7 dias</option>
                    <option value="30" <?php selected($days, 30); ?>>Últimos 30 dias</option>
                    <option value="90" <?php selected($days, 90); ?>>Últimos 90 dias</option>
                </select>
                
                <select name="user_id">
                    <option value="0">Todos os franqueados</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($user_id, $user->ID); ?>>
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="submit" class="button" value="Filtrar">
            </form>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Franqueado</th>
                    <th>Último Acesso</th>
                    <th>Total de Sessões</th>
                    <th>Total de Páginas Visitadas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visits as $visit): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=franqueado-details&user_id=' . esc_attr($visit->user_id) . '&days=' . esc_attr($days)); ?>">
                                <?php echo esc_html($visit->display_name); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(af_format_datetime($visit->last_visit_date, 'd/m/Y H:i:s')); ?></td>
                        <td><?php echo esc_html($visit->total_sessions); ?></td>
                        <td><?php echo esc_html($visit->total_pageviews); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Função para gerar relatório geral em TXT
function af_generate_general_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';

    // Buscar dados dos usuários e suas visitas (todo histórico)
    $query = "SELECT 
        u.display_name,
        u.user_email,
        COUNT(DISTINCT v.session_id) as total_sessions,
        COUNT(v.id) as total_pageviews,
        MAX(v.visit_date) as last_visit,
        MIN(v.visit_date) as first_visit
    FROM {$wpdb->users} u
    LEFT JOIN $table_name v ON u.ID = v.user_id
    GROUP BY u.ID
    ORDER BY last_visit DESC";

    $users_data = $wpdb->get_results($query);

    // Gerar conteúdo do relatório
    $report_content = "\xEF\xBB\xBF"; // Adicionar BOM UTF-8
    $report_content .= "RELATÓRIO GERAL DE ACESSOS DE FRANQUEADOS\n";
    $report_content .= "Data de geração: " . date_i18n('d/m/Y H:i:s') . "\n\n";
    
    // Cabeçalho da tabela
    $report_content .= str_pad("Nome", 30);
    $report_content .= str_pad("E-mail", 35);
    $report_content .= str_pad("Último Acesso", 20);
    $report_content .= str_pad("Primeiro Acesso", 20);
    $report_content .= str_pad("Sessões", 10);
    $report_content .= "Páginas Visitadas\n";
    
    $report_content .= str_repeat("-", 120) . "\n";

    foreach ($users_data as $user) {
        $last_visit = $user->last_visit 
            ? date_i18n('d/m/Y H:i', strtotime($user->last_visit))
            : 'Nunca acessou';
            
        $first_visit = $user->first_visit
            ? date_i18n('d/m/Y H:i', strtotime($user->first_visit))
            : 'Nunca acessou';

        $report_content .= str_pad(mb_substr($user->display_name, 0, 29, 'UTF-8'), 30);
        $report_content .= str_pad(mb_substr($user->user_email, 0, 34, 'UTF-8'), 35);
        $report_content .= str_pad($last_visit, 20);
        $report_content .= str_pad($first_visit, 20);
        $report_content .= str_pad($user->total_sessions ?: '0', 10);
        $report_content .= $user->total_pageviews ?: '0';
        $report_content .= "\n";
    }

    // Adicionar totais ao final do relatório
    $totals = $wpdb->get_row("SELECT 
        COUNT(DISTINCT user_id) as total_users,
        COUNT(DISTINCT session_id) as total_sessions,
        COUNT(*) as total_pageviews
    FROM $table_name");

    $report_content .= "\n" . str_repeat("-", 120) . "\n";
    $report_content .= "TOTAIS:\n";
    $report_content .= "Total de usuários que já acessaram: " . ($totals->total_users ?: '0') . "\n";
    $report_content .= "Total de sessões: " . ($totals->total_sessions ?: '0') . "\n";
    $report_content .= "Total de páginas visitadas: " . ($totals->total_pageviews ?: '0') . "\n";

    // Configurar headers para download
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_acessos_' . date('Y-m-d_His') . '.txt"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Enviar conteúdo
    echo $report_content;
    exit;
}

// Adicionar ação para download do relatório
add_action('admin_post_download_general_report', 'af_generate_general_report');

// Função para gerar relatório detalhado em TXT
function af_generate_detailed_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';

    // Buscar todos os usuários
    $users = $wpdb->get_results(
        "SELECT DISTINCT u.ID, u.display_name, u.user_email
        FROM {$wpdb->users} u
        ORDER BY u.display_name"
    );

    // Gerar conteúdo do relatório
    $report_content = "\xEF\xBB\xBF"; // Adicionar BOM UTF-8
    $report_content .= "RELATÓRIO DETALHADO DE ACESSOS DE FRANQUEADOS\n";
    $report_content .= "Data de geração: " . date_i18n('d/m/Y H:i:s') . "\n";
    $report_content .= str_repeat("=", 100) . "\n\n";

    foreach ($users as $user) {
        // Buscar todas as visitas do usuário
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT visit_date, page_url, page_title, session_id
            FROM $table_name
            WHERE user_id = %d
            ORDER BY visit_date DESC",
            $user->ID
        ));

        $report_content .= "FRANQUEADO: " . $user->display_name . "\n";
        $report_content .= "E-mail: " . $user->user_email . "\n";
        
        if (empty($visits)) {
            $report_content .= "Nenhum acesso registrado\n";
        } else {
            $report_content .= "\nHistórico de Acessos:\n";
            $report_content .= str_pad("Data/Hora", 20);
            $report_content .= str_pad("Página", 50);
            $report_content .= "Título\n";
            $report_content .= str_repeat("-", 100) . "\n";

            $current_session = '';
            foreach ($visits as $visit) {
                // Se mudou a sessão, adiciona uma linha separadora
                if ($current_session != $visit->session_id) {
                    if ($current_session != '') {
                        $report_content .= str_repeat("-", 100) . "\n";
                    }
                    $current_session = $visit->session_id;
                }

                $report_content .= str_pad(
                    date_i18n('d/m/Y H:i:s', strtotime($visit->visit_date)),
                    20
                );
                $report_content .= str_pad(
                    substr($visit->page_url, 0, 48),
                    50
                );
                $report_content .= substr($visit->page_title, 0, 50) . "\n";
            }
        }
        
        // Adicionar estatísticas do usuário
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT session_id) as total_sessions,
                COUNT(*) as total_pageviews
            FROM $table_name
            WHERE user_id = %d",
            $user->ID
        ));

        $report_content .= "\nEstatísticas:\n";
        $report_content .= "Total de sessões: " . ($stats->total_sessions ?: '0') . "\n";
        $report_content .= "Total de páginas visitadas: " . ($stats->total_pageviews ?: '0') . "\n";
        $report_content .= "\n" . str_repeat("=", 100) . "\n\n";
    }

    // Adicionar totais gerais ao final do relatório
    $totals = $wpdb->get_row(
        "SELECT 
            COUNT(DISTINCT user_id) as total_users,
            COUNT(DISTINCT session_id) as total_sessions,
            COUNT(*) as total_pageviews
        FROM $table_name"
    );

    $report_content .= "TOTAIS GERAIS:\n";
    $report_content .= "Total de usuários que já acessaram: " . ($totals->total_users ?: '0') . "\n";
    $report_content .= "Total de sessões: " . ($totals->total_sessions ?: '0') . "\n";
    $report_content .= "Total de páginas visitadas: " . ($totals->total_pageviews ?: '0') . "\n";

    // Configurar headers para download
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_detalhado_' . date('Y-m-d_His') . '.txt"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Enviar conteúdo
    echo $report_content;
    exit;
}

// Adicionar ação para download do relatório detalhado
add_action('admin_post_download_detailed_report', 'af_generate_detailed_report');

// Função para gerar relatório de Fujões em TXT
function af_generate_inactive_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';

    // Obter período do filtro (padrão: 30 dias)
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;

    // Buscar usuários que não acessaram no período
    $query = $wpdb->prepare(
        "SELECT u.ID, u.display_name, u.user_email, 
        MAX(v.visit_date) as last_visit,
        DATEDIFF(UTC_TIMESTAMP(), MAX(v.visit_date)) as days_inactive
        FROM {$wpdb->users} u
        LEFT JOIN $table_name v ON u.ID = v.user_id
        GROUP BY u.ID
        HAVING last_visit IS NULL 
        OR last_visit < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
        ORDER BY last_visit DESC",
        $days
    );

    $inactive_users = $wpdb->get_results($query);

    // Gerar conteúdo do relatório
    $report_content = "\xEF\xBB\xBF"; // Adicionar BOM UTF-8
    $report_content .= "RELATÓRIO DE FUJÕES - FRANQUEADOS INATIVOS\n";
    $report_content .= "Data de geração: " . date_i18n('d/m/Y H:i:s') . "\n";
    $report_content .= "Período analisado: últimos " . $days . " dias\n";
    $report_content .= str_repeat("=", 100) . "\n\n";

    // Cabeçalho da tabela
    $report_content .= str_pad("Nome", 30);
    $report_content .= str_pad("E-mail", 35);
    $report_content .= str_pad("Último Acesso", 20);
    $report_content .= "Dias Inativo\n";
    $report_content .= str_repeat("-", 100) . "\n";

    foreach ($inactive_users as $user) {
        $last_visit = $user->last_visit 
            ? date_i18n('d/m/Y H:i', strtotime($user->last_visit))
            : 'Nunca acessou';
            
        $days_inactive = $user->last_visit 
            ? $user->days_inactive 
            : 'N/A';

        $report_content .= str_pad(mb_substr($user->display_name, 0, 29, 'UTF-8'), 30);
        $report_content .= str_pad(mb_substr($user->user_email, 0, 34, 'UTF-8'), 35);
        $report_content .= str_pad($last_visit, 20);
        $report_content .= $days_inactive . "\n";
    }

    // Adicionar totais ao final do relatório
    $report_content .= "\n" . str_repeat("-", 100) . "\n";
    $report_content .= "Total de franqueados inativos: " . count($inactive_users) . "\n";

    // Configurar headers para download
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_fujoes_' . date('Y-m-d_His') . '.txt"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Enviar conteúdo
    echo $report_content;
    exit;
}

// Adicionar ação para download do relatório de Fujões
add_action('admin_post_download_inactive_report', 'af_generate_inactive_report');

// Função para exibir a página de relatórios
function af_display_reports_page() {
    ?>
    <div class="wrap user-visit-tracker-wrap">
        <h1>Relatórios de Acessos</h1>

        <div class="af-reports-section">
            <h2>Relatório Geral</h2>
            <p>Gere um relatório resumido com o histórico de acessos dos franqueados.</p>
            
            <div class="af-report-download">
                <form method="get" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="download_general_report">
                    <?php submit_button('Baixar Relatório Geral TXT', 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>

        <div class="af-reports-section">
            <h2>Relatório Detalhado</h2>
            <p>Gere um relatório completo mostrando todas as visitas de cada franqueado, incluindo datas, horários e páginas acessadas.</p>
            
            <div class="af-report-download">
                <form method="get" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="download_detailed_report">
                    <?php submit_button('Baixar Relatório Detalhado TXT', 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>

        <div class="af-reports-section">
            <h2>Relatório de Fujões</h2>
            <p>Gere um relatório mostrando os franqueados que não acessaram o site no período selecionado.</p>
            
            <div class="af-report-download">
                <form method="get" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="download_inactive_report">
                    <select name="days">
                        <option value="7">Últimos 7 dias</option>
                        <option value="30" selected>Últimos 30 dias</option>
                        <option value="90">Últimos 90 dias</option>
                    </select>
                    <?php submit_button('Baixar Relatório de Fujões TXT', 'primary', 'submit', false); ?>
                </form>
            </div>
        </div>
    </div>
    <?php
} 