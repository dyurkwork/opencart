<?php

// вызываю конроллер через урл http://localhost:8080/admin/index.php?route=extension/module/retailcrm_sync/syncCustomers&user_token=iD9O6edFJgPvvE0JJXMpwwbxcgRSvRMz
class ControllerExtensionModuleRetailcrmSync extends Controller {
    public function syncCustomers() {
        // Проверка прав
        if (!$this->user->hasPermission('modify', 'extension/module/retailcrm_sync')) {
            return new Action('error/permission');
        }

        // Подключаем обёртку API retailCRM из модуля
        require_once(DIR_SYSTEM . 'library/retailcrm/retailcrm.php');

        // Загружаем настройки модуля retailCRM
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('module_retailcrm');

        $api_url = isset($settings['module_retailcrm_url']) ? $settings['module_retailcrm_url'] : '';
        $api_key = isset($settings['module_retailcrm_apikey']) ? $settings['module_retailcrm_apikey'] : '';
        $api_version = 'v5';

        if (!$api_url || !$api_key) {
            exit('RetailCRM: не заданы URL или API ключ');
        }

        // Инициализация API-клиента
        $client = new RetailcrmProxy($api_url, $api_key, $api_version);

        // Получаем последнее значение history_id из настроек
        $lastId = isset($settings['module_retailcrm_history_since_id']) ? (int)$settings['module_retailcrm_history_since_id'] : 0;

        $response = $client->customersHistory([], null, [
            'sinceId' => $lastId
        ]);
        // echo '<pre>';
        // print_r($response);
        // echo '</pre>';
        // exit;       

        if (!$response->isSuccessful()) {
            exit('Ошибка API: ' . $response->getStatusCode());
        }

        $history = $response->offsetGet('history');
        $maxId = $lastId;

        $this->load->model('customer/customer');

        foreach ($history as $record) {
            if (!isset($record['customer']['externalId'])) {
                continue; // пропускаем, если нет ID
            }
        
            $externalId = (int)$record['customer']['externalId'];
            $field = $record['field'] ?? '';
            $newValue = $record['newValue'] ?? '';
        
            // Поддерживаемые поля для обновления
            $allowedFields = ['first_name', 'last_name', 'email', 'phones', 'manager_comment', 'kids_count'];
        
            if (!in_array($field, $allowedFields)) {
                continue; // поле не обрабатываем
            }
        
            // Преобразуем имя поля под opencart
            $updateData = [];
        
            switch ($field) {
                case 'first_name':
                    $updateData['firstname'] = $newValue;
                    break;
                case 'last_name':
                    $updateData['lastname'] = $newValue;
                    break;
                case 'email':
                    $updateData['email'] = $newValue;
                    break;
                case 'phones':
                    if (is_array($newValue)) {
                        $updateData['telephone'] = $newValue[0]['number'] ?? '';
                    } else {
                        $updateData['telephone'] = $newValue;
                    }
                    break;
                case 'manager_comment':
                    $updateData['custom_field_comment'] = $newValue;
                    break;
                case 'kids_count':
                    $updateData['custom_field_kids'] = $newValue;
                    break;
            }
        
            // Формируем UPDATE только из существующих значений
            if (!empty($updateData)) {
                $fields = [];
        
                foreach ($updateData as $key => $val) {
                    $fields[] = "`" . $this->db->escape($key) . "` = '" . $this->db->escape($val) . "'";
                }
        
                if (!empty($fields)) {
                    $sql = "UPDATE " . DB_PREFIX . "customer SET " . implode(', ', $fields) . " WHERE customer_id = " . (int)$externalId;
                    $this->db->query($sql);
                }
            }
        
            // Обновляем maxId
            if ($record['id'] > $maxId) {
                $maxId = $record['id'];
            }
        }
        // Обновим последний ID
        $settings['module_retailcrm_history_since_id'] = $maxId;
        $this->model_setting_setting->editSetting('module_retailcrm', $settings);

        echo 'Синхронизация завершена. Последний ID: ' . $maxId;
    }

    public function syncOrders() {
        if (!$this->user->hasPermission('modify', 'extension/module/retailcrm_sync')) {
            return new Action('error/permission');
        }

        require_once(DIR_SYSTEM . 'library/retailcrm/retailcrm.php');

        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('module_retailcrm');

        $api_url = $settings['module_retailcrm_url'] ?? '';
        $api_key = $settings['module_retailcrm_apikey'] ?? '';
        $api_version = 'v5';

        if (!$api_url || !$api_key) {
            exit('RetailCRM: не заданы URL или API ключ');
        }

        $client = new RetailcrmProxy($api_url, $api_key, $api_version);
        $lastId = (int)($settings['module_retailcrm_orders_history_since_id'] ?? 0);

        $response = $client->ordersHistory([], null, ['sinceId' => $lastId]);

        if (!$response->isSuccessful()) {
            exit('Ошибка API: ' . $response->getStatusCode());
        }

        $history = $response['history'];
        $maxId = $lastId;

        foreach ($history as $record) {
            if (!isset($record['order']['externalId'])) {
                continue;
            }

            $externalId = (int)$record['order']['externalId'];
            $field = $record['field'] ?? '';
            $newValue = $record['newValue'] ?? '';
            $orderData = $record['order'] ?? [];
            $commentNote = '';

            // вместо track_number, т.к. не нашел поле в retailcrm, беру delivery_address.region
            if ($field === 'delivery_date' || $field === 'delivery_address.region') {
                if ($field === 'delivery_address.region') {
                    $field = 'track_number';
                }
                $sql = "UPDATE `" . DB_PREFIX . "order` SET $field = '" . $this->db->escape($newValue) . "' WHERE order_id = " . (int)$externalId;
                print_r($sql);
                $this->db->query($sql);

                if ($record['id'] > $maxId) {
                    $maxId = $record['id'];
                }
            }
        }

        $settings['module_retailcrm_orders_history_since_id'] = $maxId;
        $this->model_setting_setting->editSetting('module_retailcrm', $settings);

        echo 'Синхронизация заказов завершена. Последний ID: ' . $maxId;
    }
}
