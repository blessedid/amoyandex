<?php
namespace App\CustomClass;

use YandexCheckout\Client;
use GuzzleHttp;

class AmoYandex
{
    private $conf = [];

    function __construct() {
        $this->conf = config('amoKassa');
    }

    public function check_UUID($lead=0, $sum=0, $UUID){
		/*
			Валидация UUID
			@var (int) $lead - id сделки
			@var (int) $sum - сумма для оплаты
			@var (str) $UUID - UUID
		*/
        $result = ($UUID == $this->UUIDv5($lead . $sum . $this->conf['salt'])) ? true : false;
        return $result;
    }

    public function getCustomByName($name, $customFields){
        $result = '';
        foreach ($customFields as $custom_field) {
            if ($custom_field['name'] == $name){
                $result = $custom_field['values'][0]['value'];
            }
        }
        return $result;
    }

    public function create_payment_yandex_api($sum=0, $lead=0, $shop='test', $description='Оплата заказа'){
		/*
			Генерация ссылки для оплаты через Яндекс.Кассу по методу API
			@var (int) $sum - сумма для оплаты
			@var (int) $lead - id сделки
			@var (str) $shop - идентификатор магазина
			@var (str) $description - описание транзакции
			@return (array) 
				(str) $id - идентификатор транзакции
				(str) $url - ссылка для оплаты
		*/
        $sets = $this->conf['kassa_api'][$shop];
        $yandex_client = new Client();
        $yandex_client->setAuth($sets['shopId'], $sets['api_key']);

        $yandex_payment = $yandex_client->createPayment(
            array(
                'amount' => array(
                    'value' => number_format($sum, 2, '.', ''),
                    'currency' => 'RUB',
                ),
                'description' => $description,
                'confirmation' => array(
                    'type' => 'redirect',
                    'return_url' => 'https://method-loskutova.center',
                ),
                'capture' => true
            ),
            //$this->UUIDv5(date("Y-m-d H:i:s") . 'lead_' . $lead . $sum)
			uniqid('', true)
        );

        return [
            'id' => $yandex_payment->id,
            'url' => $yandex_payment->confirmation->confirmation_url
        ];
    }

    public function create_payment_yandex_http($sum=0, $lead=0){
		/*
			Генерация ссылки для оплаты через Яндекс.Кассу по методу HTTP
			@var (int) $sum - сумма для оплаты
			@var (int) $lead - id сделки
			@return (array) 
				(str) $id - идентификатор транзакции
				(str) $url - ссылка для оплаты
		*/
        $sets = $this->conf['kassa_http'];
        $payment_param = [
            'shopId' => $sets['shopId'],
            'scid' => $sets['scid'],
            'sum' => number_format($sum, 2, '.', ''),
            'customerNumber' => md5('client_'.$lead.$sum),
            'paymentType' => '',
            'orderNumber' => md5('lead_' . $lead . $sum . date("Y-m-d H:i:s"))
        ];
        return [
            'id' => $payment_param['orderNumber'],
            'url' => 'https://money.yandex.ru/eshop.xml?'. http_build_query($payment_param)
        ];
    }

    public function gen_link($lead=0, $sum=0, $shop='test'){
		/*
			Генерация ссылки на основе id сделки и суммы оплаты
			@var (int) $lead - id сделки
			@var (int) $sum - сумма для оплаты
			@var (str) $shop - используемый магазин
			@return (array) 
				(str) $url - ссылка
				(str) $sig - сигнатура для проверки
		*/
        $params = [
            'lead' => $lead,
            'sum' => $sum,
            'sig' => $this->UUIDv5($lead . $sum . $this->conf['salt'])
        ];
        return [
            'url' => 'https://method-loskutova.center/go.php?'.http_build_query($params),
            'sig' => $params['sig']
        ];
    }

    public function send_sms($phones, $text=['', []]){
		/*
			Отправка SMS через сервис sms.ru с последующим разбором ответа
			@var (str) $phones - номер телефона
			@var (array) $text - массив принимающий строку и массив значений для подстановки
		*/
		$client = new GuzzleHttp\Client();
        if (isset($text[1]['link'])) {
			$req = $client->get('https://clck.ru/--',[
				'query' => [
						'url' => $text[1]['link']
				]
			]);
			$text[1]['link'] = $req->getBody();
        }
        if (empty($text[1])){
            $text[1] = [];
        }

        $SMS_data = [
            'to' => $phones,
            'text' => $this->format_string($text[0], $text[1]),
            'api_id' => $this->conf['smsru']['api_key'],
            'test' => $this->conf['smsru']['test'], // Тестовая отправка
            'from' => $this->conf['smsru']['sender'], // Если у вас уже одобрен буквенный отправитель
//            'translit' => 1, // Перевести все русские символы в латиницу (позволяет сэкономить на длине СМС)
            'json' => 1
        ];
        $req = $client->post('https://sms.ru/sms/send',['query' => $SMS_data]);
        $sms_request = $req->getBody();

        $result = '';
        $json = json_decode($sms_request);
        if ($json) {
            if ($json->status == "OK") {
                foreach ($json->sms as $phone => $data) {
                    if ($data->status == "OK") {
                        $result .= 'SMS «'.$SMS_data['text'].'» на номер: '.$phone." успешно отправлено\n";
                    } else {
                        $result .= 'SMS «'.$SMS_data['text'].'» на номер: '.$phone.' не отправлено. Ошибка: '.$data->status_code.':'.$data->status_text."\n";
                    }
                }
            } else {
                $result .= 'SMS не отправлено. Ошибка: '.$json->status_code.':'.$json->status_text."\n";
            }
        } else {
            $result .= "SMS не отправлено. Не удалось установить связь с сервером sms.ru\n";
        }
        return $result;
    }

    private function format_string($format, array $args, $pattern="/\{(\w+)\}/") {
        return preg_replace_callback($pattern, function ($matches) use ($args) {
            return @$args[$matches[1]] ?: $matches[0];
        }, $format);
    }

    private function UUIDv5($str){
		/*
			Генераци UUID ver. 5
			@var (str) $str - строка для кодирования
			https://en.wikipedia.org/wiki/Universally_unique_identifier#Versions_3_and_5_(namespace_name-based)
		*/
        // Get hexadecimal components of namespace
        $nhex = str_replace(array('-','{','}'), '', $this->conf['namespace']);
        // Binary Value
        $nstr = '';
        // Convert Namespace UUID to bits
        for($i = 0; $i < strlen($nhex); $i+=2)
        {
            $nstr .= chr(hexdec($nhex[$i].$nhex[$i+1]));
        }
        // Calculate hash value
        $hash = sha1($nstr . $str);
        return sprintf('%08s-%04s-%04x-%04x-%12s',
            // 32 bits for "time_low"
            substr($hash, 0, 8),
            // 16 bits for "time_mid"
            substr($hash, 8, 4),
            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 5
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            // 48 bits for "node"
            substr($hash, 20, 12)
        );
    }
}