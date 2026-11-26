<?php
/**
 * Plugin Name: Спам Email ZA
 * Description: Проверяет текст заявок по редактируемым правилам и помечает подозрительные копии писем.
 * Version: 1.0.0
 * Update URI: https://github.com/NikolayZasov/spam-email-za
 * Author: Агентство Засовского
 * Author URI: https://zasovskiy.ru/
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: spam-email-za
 */
declare(strict_types=1);

namespace SpamEmailZA;

defined('ABSPATH') || exit;
require_once __DIR__.'/includes/Rules.php';

final class Plugin {
    public const OPTION = 'seza_settings';
    private const SLUG = 'spam-email-za';
    private const REPO = 'https://github.com/NikolayZasov/spam-email-za';
    private const MANIFEST = self::REPO.'/releases/latest/download/update.json';

    public static function boot(): void {
        register_activation_hook(__FILE__, self::activate(...));
        add_filter('seza_deliver_inquiry', self::deliver(...), 10, 6);
        add_filter('update_plugins_github.com', self::checkUpdate(...), 10, 3);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), self::actionLinks(...));
        add_action('admin_menu', self::adminMenu(...));
        add_action('admin_init', self::registerSettings(...));
    }

    public static function activate(): void {
        $plugins = (array)get_site_option('auto_update_plugins', []);
        $file = plugin_basename(__FILE__);
        if (!in_array($file, $plugins, true)) {
            $plugins[] = $file;
            update_site_option('auto_update_plugins', $plugins);
        }
    }

    public static function settings(): array {
        $saved = (array)get_option(self::OPTION, []);
        return [
            'review_email' => is_string($saved['review_email'] ?? null) ? $saved['review_email'] : '',
            'rules' => is_string($saved['rules'] ?? null) ? $saved['rules'] : Rules::defaults(),
        ];
    }

    /** Применяется только для отметки уже настроенной копии письма. */
    public static function deliver(?bool $result, array $to, string $subject, string $body, array $headers, string $text): ?bool {
        if ($result !== null) return $result;
        $config = self::settings();
        if ($config['review_email'] === '') return null;
        $copy = array_values(array_filter($to, static fn (string $email): bool => strcasecmp($email, $config['review_email']) === 0));
        if (!$copy || !($matches = Rules::match($text, $config['rules']))) return null;
        $main = array_values(array_filter($to, static fn (string $email): bool => strcasecmp($email, $config['review_email']) !== 0));
        if ($main && !wp_mail($main, $subject, $body, $headers)) return false;
        $notice = 'Сработали правила: '.implode(', ', $matches);
        $html = preg_grep('/^content-type:\s*text\/html\b/i', $headers);
        $marked = $html ? '<p>'.esc_html($notice).'</p>'.$body : $notice."\n\n".$body;
        $copy_sent = wp_mail($copy, '[Возможный спам] '.$subject, $marked, $headers);
        // Сбой дополнительной копии не должен повторно отправлять письмо владельцу.
        return (bool)$main || $copy_sent;
    }

    public static function adminMenu(): void {
        add_options_page('Спам Email ZA', 'Спам Email ZA', 'manage_options', self::SLUG, self::render(...));
    }

    public static function actionLinks(array $links): array {
        $actions = [];
        if (current_user_can('manage_options')) {
            $actions['settings'] = '<a href="'.esc_url(admin_url('options-general.php?page='.self::SLUG)).'">Настройки</a>';
        }
        $actions['developer'] = '<a href="https://zasovskiy.ru/">Разработчик</a>';
        return $actions + $links;
    }

    public static function registerSettings(): void {
        register_setting(self::OPTION, self::OPTION, ['type'=>'array', 'sanitize_callback'=>self::sanitize(...)]);
    }

    public static function sanitize(mixed $input): array {
        $email = is_string($input['review_email'] ?? null) ? trim($input['review_email']) : '';
        $raw = $input['rules'] ?? null;
        if (!is_string($raw) || strlen($raw) > Rules::MAX_LENGTH || ($email !== '' && !is_email($email))) {
            add_settings_error(self::OPTION, 'invalid_fields', 'Проверьте адрес копии и текст правил. Сохранены прежние настройки.');
            return self::settings();
        }
        $rules = trim(implode("\n", array_map('sanitize_text_field', explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)))));
        $bad = Rules::invalidLine($rules);
        if ($bad !== null) {
            add_settings_error(self::OPTION, 'invalid_rule', sprintf('Проверьте правило в строке %d. Сохранены прежние настройки.', $bad));
            return self::settings();
        }
        return ['review_email'=>$email, 'rules'=>$rules];
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        ?>
        <div class="wrap">
            <h1>Спам Email ZA</h1>
            <?php settings_errors(self::OPTION); ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><label for="seza-review-email">Почта для пометок</label></th>
                        <td><input type="email" class="regular-text" id="seza-review-email" name="seza_settings[review_email]" value="<?php echo esc_attr($settings['review_email']); ?>" aria-describedby="seza-review-help">
                            <p class="description" id="seza-review-help">Этот адрес должен получать копию заявок. При совпадении правило добавляет пометку только в его письмо. Пустое поле отключает пометки.</p></td></tr>
                    <tr><th scope="row"><label for="seza-rules">Правила</label></th>
                        <td><textarea id="seza-rules" name="seza_settings[rules]" class="large-text" rows="13" spellcheck="false" maxlength="24000" aria-describedby="seza-rules-help"><?php echo esc_textarea($settings['rules']); ?></textarea>
                            <p class="description" id="seza-rules-help">Одно правило на строку. После названия укажите от двух до пяти признаков через <code>|</code>. Варианты одного признака разделяйте <code> / </code>. Строки с <code>#</code> — комментарии.</p>
                            <p class="description"><code>Предложение услуг | привлечение клиентов / лидогенерация | проведём аудит рекламы / оплата за лиды</code></p></td></tr>
                </tbody></table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function checkUpdate(array|false $update, array $plugin, string $file): array|false {
        if ($file !== plugin_basename(__FILE__)) return $update;
        $version = get_site_transient('seza_github_version');
        if ($version === false) {
            $http = wp_safe_remote_get(self::MANIFEST, ['timeout'=>5, 'redirection'=>5, 'limit_response_size'=>4096]);
            $data = !is_wp_error($http) && wp_remote_retrieve_response_code($http) === 200 ? json_decode(wp_remote_retrieve_body($http), true) : [];
            $version = is_string($data['version'] ?? null) && preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $data['version']) ? $data['version'] : '';
            set_site_transient('seza_github_version', $version, $version === '' ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS);
        }
        if (!is_string($version) || !preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $version) || !version_compare($version, $plugin['Version'] ?? '0.0.0', '>')) return $update;
        return [
            'id'=>self::REPO, 'slug'=>self::SLUG, 'version'=>$version, 'url'=>self::REPO.'/releases',
            'package'=>self::REPO.'/releases/download/v'.$version.'/spam-email-za-'.$version.'.zip',
            'requires'=>'6.4', 'requires_php'=>'8.1',
        ];
    }
}

Plugin::boot();
