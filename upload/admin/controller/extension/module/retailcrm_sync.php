<?php

// вызываю конроллер через урл http://localhost:8080/admin/index.php?route=extension/module/retailcrm_sync/syncCustomers&user_token=iD9O6edFJgPvvE0JJXMpwwbxcgRSvRMz
class ControllerExtensionModuleRetailcrmSync extends Controller {
    private $settings;
    private $client;

    // Синхронизация клиентов из history
    public function syncCustomers() {
        if (!$this->checkPermission()) return;

        $this->initRetailcrmClient();
        $lastId = (int)($this->settings['module_retailcrm_history_since_id'] ?? 0);

        // Берём изменения клиентов из retailCRM
        $response = $this->client->customersHistory([], null, ['sinceId' => $lastId]);
        if (!$response->isSuccessful()) {
            exit('Ошибка API: ' . $response->getStatusCode());
        }

        $history = $response->offsetGet('history');
        $maxId = $lastId;

        foreach ($history as $record) {
            // Пропускаем, если нет externalId
            if (empty($record['customer']['externalId'])) continue;

            $externalId = (int)$record['customer']['externalId'];
            $field = $record['field'] ?? '';
            $newValue = $record['newValue'] ?? '';

            // Преобразуем поле из CRM -> поле в OpenCart
            $updateData = $this->mapCustomerField($field, $newValue);
            if (!empty($updateData)) {
                $this->updateEntity(DB_PREFIX . 'customer', 'customer_id', $externalId, $updateData);
            }

            // Следим за максимальным ID истории
            if ($record['id'] > $maxId) {
                $maxId = $record['id'];
            }
        }

        // Обновляем в настройках последний ID
        $this->updateSinceId('module_retailcrm_history_since_id', $maxId);
        echo 'Синхронизация завершена. Последний ID: ' . $maxId;
    }

    // Синхронизация заказов (для delivery_date, track_number (юзаю delivery_address.region))
    public function syncOrders() {
        if (!$this->checkPermission()) return;

        $this->initRetailcrmClient();
        $lastId = (int)($this->settings['module_retailcrm_orders_history_since_id'] ?? 0);

        $response = $this->client->ordersHistory([], null, ['sinceId' => $lastId]);
        if (!$response->isSuccessful()) {
            exit('Ошибка API: ' . $response->getStatusCode());
        }

        $history = $response['history'];
        $maxId = $lastId;

        foreach ($history as $record) {
            if (empty($record['order']['externalId'])) continue;

            $externalId = (int)$record['order']['externalId'];
            $field = $record['field'] ?? '';
            $newValue = $record['newValue'] ?? '';

            // Пока поддержка двух полей: delivery_date и delivery_address.region (как track_number)
            if ($field === 'delivery_date' || $field === 'delivery_address.region') {
                $mappedField = ($field === 'delivery_address.region') ? 'track_number' : $field;

                $this->updateEntity(DB_PREFIX . 'order', 'order_id', $externalId, [
                    $mappedField => $newValue
                ]);

                if ($record['id'] > $maxId) {
                    $maxId = $record['id'];
                }
            }
        }

        $this->updateSinceId('module_retailcrm_orders_history_since_id', $maxId);
        echo 'Синхронизация заказов завершена. Последний ID: ' . $maxId;
    }

    // Проверка прав доступа к контроллеру
    private function checkPermission() {
        if (!$this->user->hasPermission('modify', 'extension/module/retailcrm_sync')) {
            new Action('error/permission');
            return false;
        }
        return true;
    }

    // Инициализация клиента retailCRM + загрузка настроек
    private function initRetailcrmClient() {
        require_once(DIR_SYSTEM . 'library/retailcrm/retailcrm.php');

        $this->load->model('setting/setting');
        $this->settings = $this->model_setting_setting->getSetting('module_retailcrm');

        $api_url = $this->settings['module_retailcrm_url'] ?? '';
        $api_key = $this->settings['module_retailcrm_apikey'] ?? '';
        $api_version = 'v5';

        if (!$api_url || !$api_key) {
            exit('RetailCRM: не заданы URL или API ключ');
        }

        $this->client = new RetailcrmProxy($api_url, $api_key, $api_version);
    }

    // Обновление значения sinceId в настройках
    private function updateSinceId(string $key, int $value) {
        $this->settings[$key] = $value;
        $this->model_setting_setting->editSetting('module_retailcrm', $this->settings);
    }

    // Универсальный метод обновления строки в БД
    private function updateEntity(string $table, string $idField, int $id, array $data) {
        if (empty($data)) return;

        $fields = [];
        foreach ($data as $key => $val) {
            $fields[] = "`" . $this->db->escape($key) . "` = '" . $this->db->escape($val) . "'";
        }

        $sql = "UPDATE `" . $table . "` SET " . implode(', ', $fields) . " WHERE `" . $idField . "` = " . (int)$id;
        $this->db->query($sql);
    }

    // Преобразуем поля клиента из retailCRM в поля OpenCart
    private function mapCustomerField(string $field, $value): array {
        switch ($field) {
            case 'first_name':
                return ['firstname' => $value];
            case 'last_name':
                return ['lastname' => $value];
            case 'email':
                return ['email' => $value];
            case 'phones':
                return ['telephone' => is_array($value) ? ($value[0]['number'] ?? '') : $value];
            case 'manager_comment':
                return ['custom_field_comment' => $value];
            case 'kids_count':
                return ['custom_field_kids' => $value];
            default:
                return [];
        }
    }
}
