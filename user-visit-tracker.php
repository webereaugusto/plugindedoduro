<?php
/**
 * Plugin Name: Acessos de Franqueados
 * Plugin URI: https://megacubbo.com.br
 * Description: Registra e fornece relatórios sobre as visitas dos usuários cadastrados no WordPress
 * Version: 1.1.1
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
 * 1.1.0 - Adição do sistema de alertas
 * - Sistema de alertas de inatividade
 * - Configurações de alertas (ativar/desativar, modo de teste, dias de inatividade)
 * - Envio manual e automático de alertas
 * - Sistema de feedback detalhado para envio de alertas
 * 
 * 1.1.1 - Correção do fuso horário
 * - Ajuste do fuso horário para São Paulo/Brasil
 * - Correção na exibição das datas e horários
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

// Registrar configurações
function af_register_settings() {
    register_setting('af_alerts_options', 'af_alerts_enabled', 'boolval');
    register_setting('af_alerts_options', 'af_alerts_test_mode', 'boolval');
    register_setting('af_alerts_options', 'af_alerts_days_threshold', 'intval');
}
add_action('admin_init', 'af_register_settings');

// Adicionar submenu para Alertas
function af_add_alerts_submenu() {
    add_submenu_page(
        'acessos-franqueados',
        'Alertas de Inatividade',
        'Alertas',
        'manage_options',
        'franqueados-alertas',
        'af_display_alerts_page'
    );
}
add_action('admin_menu', 'af_add_alerts_submenu');

// Função para enviar e-mail de teste
function af_send_test_alert() {
    if (!check_admin_referer('af_send_test_email')) {
        wp_die('Ação não autorizada');
    }

    // Usar o e-mail fornecido ou o e-mail do usuário atual como fallback
    $test_email = !empty($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
    if (empty($test_email)) {
        $current_user = wp_get_current_user();
        $test_email = $current_user->user_email;
    }

    if (!is_email($test_email)) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'franqueados-alertas',
            'error' => 'invalid_email',
            'message' => urlencode('E-mail inválido: ' . esc_html($test_email))
        ), admin_url('admin.php')));
        exit;
    }
    
    // Preparar o e-mail de teste
    $subject = '[TESTE] Alerta de Inatividade - Área do Franqueado';
    $message = "Olá,\n\n";
    $message .= "Este é um e-mail de teste do sistema de alertas de inatividade.\n";
    $message .= "Se você está recebendo este e-mail, significa que o sistema está configurado corretamente.\n\n";
    $message .= "Equipe de Franquias - Grupo VOLL";
    
    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: Grupo VOLL <noreply@franquiadepilates.com.br>'
    );

    // Tentar enviar o e-mail
    try {
        if (!function_exists('wp_mail')) {
            throw new Exception('Função wp_mail não está disponível');
        }

        $success = wp_mail($test_email, $subject, $message, $headers);
        
        if ($success) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'franqueados-alertas',
                'status' => 'success',
                'message' => urlencode("E-mail de teste enviado com sucesso para {$test_email}")
            ), admin_url('admin.php')));
            exit;
        } else {
            throw new Exception('Falha no envio do e-mail - verifique as configurações SMTP');
        }
    } catch (Exception $e) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'franqueados-alertas',
            'error' => 'send_failed',
            'message' => urlencode('Erro ao tentar enviar e-mail: ' . $e->getMessage())
        ), admin_url('admin.php')));
        exit;
    }
}

// Função para exibir a página de alertas
function af_display_alerts_page() {
    // Verificar se o formulário foi enviado
    if (isset($_POST['af_send_test_email'])) {
        check_admin_referer('af_send_test_email');
        af_send_test_alert();
        return;
    } elseif (isset($_POST['af_send_alerts'])) {
        check_admin_referer('af_send_alerts');
        af_process_alerts();
        return;
    }

    $enabled = get_option('af_alerts_enabled', false);
    $test_mode = get_option('af_alerts_test_mode', true);
    $days_threshold = get_option('af_alerts_days_threshold', 7);
    ?>
    <div class="wrap user-visit-tracker-wrap">
        <h1>Alertas de Inatividade</h1>

        <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php" class="af-alerts-form">
            <?php settings_fields('af_alerts_options'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Ativar Alertas</th>
                    <td>
                        <label>
                            <input type="checkbox" name="af_alerts_enabled" value="1" <?php checked($enabled); ?>>
                            Enviar alertas para franqueados inativos
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Modo de Teste</th>
                    <td>
                        <label>
                            <input type="checkbox" name="af_alerts_test_mode" value="1" <?php checked($test_mode); ?>>
                            Enviar alertas apenas para administradores
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Dias de Inatividade</th>
                    <td>
                        <input type="number" name="af_alerts_days_threshold" value="<?php echo esc_attr($days_threshold); ?>" min="1" max="365" class="small-text">
                        <p class="description">Enviar alerta após quantos dias sem acesso</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Salvar Configurações'); ?>
        </form>

        <div class="af-alerts-actions">
            <h2>Ações</h2>
            
            <!-- Formulário de E-mail de Teste -->
            <div class="af-test-email-form" style="margin-bottom: 20px;">
                <form method="post" style="display: flex; align-items: flex-end; gap: 10px;">
                    <?php wp_nonce_field('af_send_test_email'); ?>
                    <div>
                        <label for="test_email" style="display: block; margin-bottom: 5px;">E-mail para teste:</label>
                        <input type="email" name="test_email" id="test_email" class="regular-text" 
                               placeholder="Digite o e-mail para teste" required>
                    </div>
                    <input type="hidden" name="af_send_test_email" value="1">
                    <?php submit_button('Enviar E-mail de Teste', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <!-- Formulário de Processamento de Alertas -->
            <form method="post">
                <?php wp_nonce_field('af_send_alerts'); ?>
                <input type="hidden" name="af_send_alerts" value="1">
                <?php submit_button('Processar Alertas Agora', 'primary', 'submit', false); ?>
            </form>
        </div>
    </div>
    <?php
}

// Função para processar todos os alertas
function af_process_alerts() {
    if (!get_option('af_alerts_enabled', false)) {
        wp_redirect(add_query_arg(array(
            'error' => 'disabled',
            'message' => urlencode('O sistema de alertas está desativado. Ative-o nas configurações.')
        ), wp_get_referer()));
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'franqueados_visits';
    $days_threshold = get_option('af_alerts_days_threshold', 7);
    $test_mode = get_option('af_alerts_test_mode', true);

    // Buscar usuários inativos
    $query = $wpdb->prepare(
        "SELECT DISTINCT u.* 
        FROM {$wpdb->users} u 
        LEFT JOIN (
            SELECT user_id, MAX(visit_date) as last_visit 
            FROM {$table_name} 
            GROUP BY user_id
        ) v ON u.ID = v.user_id 
        WHERE v.last_visit IS NULL 
        OR v.last_visit < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days_threshold
    );

    if ($test_mode) {
        $query .= " AND u.ID IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%')";
    }

    $inactive_users = $wpdb->get_results($query);
    
    if (empty($inactive_users)) {
        wp_redirect(add_query_arg(array(
            'status' => 'info',
            'message' => urlencode('Nenhum usuário inativo encontrado no período especificado.')
        ), wp_get_referer()));
        exit;
    }

    $emails_sent = 0;
    $failed_emails = array();

    foreach ($inactive_users as $user) {
        if (af_send_inactivity_email($user)) {
            $emails_sent++;
        } else {
            $failed_emails[] = $user->display_name;
        }
    }

    $redirect_args = array();

    if ($emails_sent > 0) {
        $redirect_args['processed'] = $emails_sent;
    }

    if (!empty($failed_emails)) {
        $redirect_args['failed'] = implode(',', $failed_emails);
    }

    if ($test_mode) {
        $redirect_args['test_mode'] = '1';
    }

    wp_redirect(add_query_arg($redirect_args, wp_get_referer()));
    exit;
}

// Função para enviar e-mail individual
function af_send_inactivity_email($user) {
    $to = $user->user_email;
    $subject = 'Estamos sentindo sua falta na área de franqueados';
    
    $message = "Olá {$user->display_name},\n\n";
    $message .= "Notamos que já faz um tempo desde sua última visita à área do franqueado. ";
    $message .= "Temos muitas coisas interessantes para você por lá! Assim que possível acesse:\n\n";
    $message .= "https://franquiadepilates.com.br/areadofranqueado/\n\n";
    $message .= "Equipe de Franquias - Grupo VOLL";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'From: Grupo VOLL <noreply@franquiadepilates.com.br>'
    );
    
    return wp_mail($to, $subject, $message, $headers);
}

// Agendar envio diário de alertas
function af_schedule_alerts() {
    if (!wp_next_scheduled('af_daily_alerts')) {
        wp_schedule_event(time(), 'daily', 'af_daily_alerts');
    }
}
add_action('wp', 'af_schedule_alerts');

// Processar alertas agendados
function af_process_scheduled_alerts() {
    if (get_option('af_alerts_enabled', false)) {
        af_process_alerts();
    }
}
add_action('af_daily_alerts', 'af_process_scheduled_alerts'); 
add_action('af_daily_alerts', 'af_process_scheduled_alerts'); 