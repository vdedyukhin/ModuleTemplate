<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2019
 */

namespace Modules\ModuleTemplate\Lib;
use Modules\ModuleTemplate\Models\ModuleTemplate;
use MikoPBX\Core\System\Util;
require_once 'globals.php';


class ModuleTemplateAMI
{
    private $am;
    private $url = '';

    /**
     * WorkerAmiListener constructor.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        $this->am = Util::getAstManager();
        $this->setFilter();

        /** @var ModuleTemplate $settings */
        $settings = ModuleTemplate::findFirst();
        if ($settings) {
            $this->url = $settings->url;
        }
    }

    /**
     * Отправка данных на сервер очередей.
     *
     * @param array $result - данные в ормате json для отправки.
     */
    private function Action_SendToHttp($result)
    {
        $params = [
            'caller'   => $result['CALLERID'],
            'called'   => $result['FROM_DID'],
            'linkedid' => $result['Linkedid'],
            'channel'  => $result['chan1c'],
        ];
        $this->http_postData($params);
    }

    /**
     * Отправка данных по http.
     *
     * @param      $value
     * @param bool $re_login
     */
    private function http_postData($value, $re_login = false)
    {
        if (empty($this->url)) {
            return;
        }
        $curl     = curl_init();
        $url      = $this->url;
        $url_data = parse_url($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        if ($url_data['scheme'] == 'https') {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        }
        curl_setopt($curl, CURLOPT_TIMEOUT, 2);

        $headers = [];

        if (isset($url_data['query'])) {
            $url = "{$url}&" . http_build_query($value);
        } else {
            $url = "{$url}?" . http_build_query($value);
            $url = str_replace('??', '?', $url);
        }
        curl_setopt($curl, CURLOPT_URL, $url);


        if (count($headers) > 0) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }
        $result_request = curl_exec($curl);
        $http_code      = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            Util::sysLogMsg('ModuleTemplateAMI_EXCEPTION', "http_code: '{$http_code}'; result_data: '$result_request'");
        }
    }


    /**
     * Функция обработки оповещений.
     *
     * @param $parameters
     */
    public function callback($parameters)
    {
        if ('ModuleTemplateAMIPing' == $parameters['UserEvent']) {
            usleep(50000);
            $this->am->UserEvent('ModuleTemplateAMIPong', []);

            return;
        }
        if ('Interception' != $parameters['UserEvent']) {
            return;
        }

        $this->Action_SendToHttp($parameters);
    }

    /**
     * Старт работы листнера.
     */
    public function start()
    {
        $this->am->addEventHandler('userevent', [$this, 'callback']);
        while (true) {
            $result = $this->am->waitUserEvent(true);
            if ($result === false) {
                // Нужен реконнект.
                usleep(100000);
                $this->am = Util::getAstManager();
                $this->setFilter();
            }
        }
    }

    /**
     * Установка фильтра
     *
     * @return array
     */
    private function setFilter()
    {
        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: ModuleTemplateAMIPing'];
        $this->am->sendRequestTimeout('Filter', $params);

        $params = ['Operation' => 'Add', 'Filter' => 'UserEvent: Interception'];
        $res    = $this->am->sendRequestTimeout('Filter', $params);

        return $res;
    }
}