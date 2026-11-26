<?php
declare(strict_types=1);

define('ABSPATH', '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

function add_action(...$args): void {}
function add_filter(...$args): void {}
function register_activation_hook(...$args): void {}
function plugin_basename($path): string { return 'spam-email-za/' . basename($path); }
function get_option($key, $fallback = false) { return $GLOBALS['options'][$key] ?? $fallback; }
function get_site_option($key, $fallback = false) { return $GLOBALS['network'][$key] ?? $fallback; }
function update_site_option($key, $value): void { $GLOBALS['network'][$key] = $value; }
function get_site_transient($key) { return $GLOBALS['transient'][$key] ?? false; }
function set_site_transient($key, $value, $ttl): void { $GLOBALS['transient'][$key] = $value; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) ?: false; }
function is_wp_error($value): bool { return false; }
function sanitize_text_field($value): string { return trim(strip_tags($value)); }
function esc_html($value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function wp_safe_remote_get($url, $args): array { return ['status'=>200, 'body'=>$GLOBALS['remote_manifest']]; }
function wp_remote_retrieve_response_code($response): int { return $response['status']; }
function wp_remote_retrieve_body($response): string { return $response['body']; }
function wp_mail($recipients, $subject, $message, $headers): bool {
    $GLOBALS['mail'][] = [$recipients,$subject,$message,$headers];
    return array_shift($GLOBALS['mail_results']) ?? true;
}

function check(bool $expected, string $description): void {
    if (!$expected) throw new RuntimeException($description);
}

require __DIR__.'/../spam-email-za.php';

$defaults = SpamEmailZA\Rules::defaults();
$advertisement = 'Мы ведём проекты по привлечению клиентов. Готовы работать с оплатой только за лидов. Ведём несколько Reels-аккаунтов.';
check(SpamEmailZA\Rules::match($advertisement, $defaults) !== [], 'Реальный рекламный шаблон должен находиться');
foreach (['', 'Ищу Тигуан, объявление https://auto.example.test/123', 'Возможность сотрудничества по покупке трёх машин?', 'Увидел рекламу, напишите в Telegram', 'Оплата за лиды'] as $legit) {
    check(SpamEmailZA\Rules::match($legit, $defaults) === [], 'Одиночный сигнал не должен помечать заявку');
}
check(SpamEmailZA\Rules::match('ПРЕДЛАГАЕМ — партнёрство и экспресс-опрос', 'Свой шаблон | предлагаем партнерство | экспресс опрос / экспресс тест') === ['Свой шаблон'], 'Пользовательское правило поддерживает варианты, Ё и пунктуацию');
check(SpamEmailZA\Rules::match('предлагаем', 'Неполное | предлагаем') === [], 'Одно условие не должно становиться правилом');
check(SpamEmailZA\Rules::invalidLine('Повтор | реклама | РЕКЛАМА') === 1, 'Повтор условия не образует два признака');

$owner='owner@example.test'; $copy='review@example.test'; $to=[$owner, $copy];
$GLOBALS['options']['seza_settings'] = ['review_email'=>$copy,'rules'=>$defaults];
$html=['Content-Type: text/html; charset=UTF-8','Reply-To: buyer@example.test'];
$GLOBALS['mail']=[]; $GLOBALS['mail_results']=[];
check(SpamEmailZA\Plugin::deliver(null,$to,'Запрос','<p>Текст</p>',$html,'Пусто')===null && $GLOBALS['mail']===[], 'Обычная заявка должна идти по существующему маршруту');
check(SpamEmailZA\Plugin::deliver(null,[$owner],'Запрос','<p>Текст</p>',$html,$advertisement)===null, 'Новый получатель не добавляется автоматически');
check(SpamEmailZA\Plugin::deliver(null,$to,'Запрос','<p>Текст</p>',$html,$advertisement)===true, 'Отмеченная заявка должна доставляться');
check($GLOBALS['mail'][0]===[[$owner],'Запрос','<p>Текст</p>',$html], 'В письме владельцу сохраняются тема, тело и заголовки');
check($GLOBALS['mail'][1][0]===[$copy] && str_starts_with($GLOBALS['mail'][1][1],'[Возможный спам] ') && str_contains($GLOBALS['mail'][1][2],'Сработали правила'), 'Отметка и причины предназначены для копии');
$GLOBALS['mail']=[]; $GLOBALS['mail_results']=[true,false];
check(SpamEmailZA\Plugin::deliver(null,$to,'Запрос','Текст',[],$advertisement)===true && count($GLOBALS['mail'])===2, 'Сбой копии не должен вызывать повторную отправку владельцу');
$GLOBALS['mail']=[]; $GLOBALS['mail_results']=[false,true];
check(SpamEmailZA\Plugin::deliver(null,$to,'Запрос','Текст',[],$advertisement)===false && count($GLOBALS['mail'])===1, 'При ошибке основной доставки копия ждёт повтора');

$GLOBALS['network']['auto_update_plugins']=['other/plugin.php'];
SpamEmailZA\Plugin::activate();
check($GLOBALS['network']['auto_update_plugins']===['other/plugin.php','spam-email-za/spam-email-za.php'],'Автообновление включается только для своего плагина');
$GLOBALS['remote_manifest']='{"version":"1.1.0"}';
$update = SpamEmailZA\Plugin::checkUpdate(false,['Version'=>'1.0.0'],'spam-email-za/spam-email-za.php');
check($update['version']==='1.1.0' && str_ends_with($update['package'],'/v1.1.0/spam-email-za-1.1.0.zip'),'WordPress должен получить версию и ZIP GitHub');
check(SpamEmailZA\Plugin::checkUpdate(false,['Version'=>'1.2.0'],'spam-email-za/spam-email-za.php')===false,'Проверка не должна предлагать понижение версии');
check(SpamEmailZA\Plugin::checkUpdate(false,['Version'=>'0.1.0'],'other/other.php')===false,'Проверка не должна менять чужие плагины');
echo "Правила, маршрутизация и обновление проверены.\n";
