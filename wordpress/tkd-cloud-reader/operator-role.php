<?php
if (!defined('ABSPATH')) { exit; }
final class TKD_Cloud_Operator_Role {
    const ROLE = 'tkd_cloud_operator';
    public static function legacy_caps() {
        return array('read'=>true,'tkd_cloud_read_orders'=>true,'tkd_cloud_change_status'=>true,'tkd_cloud_add_manual_payment'=>true,'manage_tkd_felicity'=>true);
    }
    public static function caps() {
        return self::legacy_caps() + array('tkd_cloud_delivery_draft'=>true,'tkd_cloud_invoice'=>true,'tkd_cloud_extend_reservation'=>true,'tkd_cloud_issue_delivery'=>true);
    }
    public static function ensure_role() {
        $role = get_role(self::ROLE);
        if (!$role) { add_role(self::ROLE, 'TKD Cloud Operator', self::caps()); return; }
        $actual = array_filter($role->capabilities); $expected = self::caps(); ksort($actual); ksort($expected);
        $legacy=self::legacy_caps();ksort($legacy);if($actual===$legacy){return;}
        if ($actual !== $expected) { wp_die('Права ролі оператора не відповідають перевіреному набору. Зміни заборонено.'); }
    }
    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_tkd_cloud_assign_operator', array(__CLASS__, 'assign'));
    }
    public static function menu() {
        add_management_page('Хмарний оператор ТКД', 'Хмарний оператор ТКД', 'manage_options', 'tkd-cloud-operator', array(__CLASS__, 'page'));
    }
    public static function page() {
        if (!current_user_can('manage_options')) { wp_die('Потрібні права адміністратора.'); }
        echo '<div class="wrap"><h1>Хмарний оператор ТКД</h1><p>Призначення лише для технічного користувача tkd-cloud (ID 10). Наявний ключ доступу зберігається. Зайві ролі або персональні права блокують призначення.</p><ul><li>Читання замовлень і складу</li><li>Зміна підтримуваних статусів через чинні правила ТКД</li><li>Додавання ручної оплати через чинні перевірки ТКД</li><li>Необхідний доступ до модуля замовлень ТКД</li></ul><p>Додатково: чернетка й випуск рахунку, строк резерву 36/48/72 та проведення видаткової через штатні перевірки. Відстрочка платежу й довільні складські зміни недоступні. Нові права потребують цього явного підтвердження.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="tkd_cloud_assign_operator">';
        wp_nonce_field('tkd_cloud_assign_operator');
        echo '<label><input type="checkbox" name="confirm" value="assign-tkd-cloud" required> Підтверджую наведені права для tkd-cloud (ID 10).</label>';
        submit_button('Призначити хмарного оператора ТКД');
        echo '</form></div>';
    }
    private static function baseline($user, $role) {
        $expected = $role === self::ROLE ? self::caps() : array('read' => true, 'tkd_cloud_read_orders' => true);
        if ($user->roles !== array($role) || $user->caps !== array($role => true)) { return false; }
        $definition = get_role($role);
        if (!$definition) { return false; }
        $actual = $definition->capabilities;
        if($role===self::ROLE){$legacy=self::legacy_caps();$a=$actual;ksort($a);ksort($legacy);if($a===$legacy){$expected=self::legacy_caps();}}
        ksort($actual); $sorted = $expected; ksort($sorted);
        if ($actual !== $sorted) { return false; }
        $user->get_role_caps();
        $expected[$role] = true; ksort($expected); $actual = $user->allcaps; ksort($actual);
        return $actual === $expected;
    }
    public static function assign() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options')) { wp_die('Потрібен POST-запит адміністратора.'); }
        check_admin_referer('tkd_cloud_assign_operator');
        if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'assign-tkd-cloud') { wp_die('Потрібне явне підтвердження.'); }
        $user = get_user_by('login', 'tkd-cloud');
        if (!$user || (int) $user->ID !== 10) { wp_die('Очікуваного технічного користувача не знайдено. Роль не змінено.'); }
        $previous = $user->roles === array(self::ROLE) ? self::ROLE : 'tkd_cloud_reader';
        if (!self::baseline($user, $previous)) { wp_die('Виявлено неочікувані ролі або права користувача. Призначення заблоковано; зверніться до адміністратора.'); }
        self::ensure_role();
        $definition=get_role(self::ROLE);
        $old_definition=$definition->capabilities;
        if(array_diff_key(self::caps(),$old_definition)){ $assigned=get_users(array('role'=>self::ROLE,'number'=>2,'fields'=>'ID'));if(!is_array($assigned)||array_diff(array_map('intval',$assigned),array(10))){wp_die('Роль має інших користувачів. Автоматичне розширення їхніх прав заборонено.');}}
        foreach(self::caps() as $cap=>$grant){if(empty($definition->capabilities[$cap])){$definition->add_cap($cap,$grant);}}
        $user->set_role(self::ROLE);
        if (!self::baseline($user, self::ROLE)) {
            foreach(array_diff_key($definition->capabilities,$old_definition) as $cap=>$unused){$definition->remove_cap($cap);}
            $user->set_role($previous);
            wp_die('Підсумкові права не відповідають дозволеним. Попередню роль відновлено.');
        }
        wp_safe_redirect(admin_url('tools.php?page=tkd-cloud-operator'));
        exit;
    }
}
