<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\CustomClass\AmoYandex;
use Illuminate\Support\Facades\Mail;
use App\Payment;
use Illuminate\Support\Facades\Log;

class AmoKassaController
{
    public function index(Request $request)
    {
        $req = $request->input();
        $amoKassa = new AmoYandex();
		$pageRedir = 'https://method-loskutova.center';
		
        if ($amoKassa->check_UUID(array_get($req, 'lead'), array_get($req, 'sum'), array_get($req, 'sig'))){
            $pay_data = Payment::where('sig', $req['sig'])->first();
            if ((!empty($pay_data)) && strtotime($pay_data->send_sms . ' + 3 day') > time()){
                $pay = $amoKassa->create_payment_yandex_api($pay_data->sum, $pay_data->lead, $pay_data->shop, 'Предоплата за '.$pay_data->service_type.' «'.$pay_data->service_name.'»');

                Payment::where('sig', $req['sig'])
                    ->update(['pay_id' => $pay['id']]);

                return redirect()->away($pay['url']);
            }else{
                return redirect()->away($pageRedir);
            }
        }else{
            return redirect()->away($pageRedir);
        }
    }

    public function amo(Request $request)
    {
		$req = $request->input();
        if((array_get($req, 'leads.status.0.status_id') == 19871725 && array_get($req, 'leads.status.0.pipeline_id') == 1120282) || // Воронка: "Сеансы"; Статус: "Счет отправлен"
            (array_get($req, 'leads.status.0.status_id') == 19871689 && array_get($req, 'leads.status.0.pipeline_id') == 1160947)) // Воронка "Обучение"; Статус: "Счет отправлен"
        {
            Log::info('Request /amo:', $req);
			$amoKassa = new AmoYandex();

            $amo = \Ufee\Amo\Amoapi::setInstance(config('amoKassa.amo'));
            $lead = $amo->leads()->find(array_get($req, 'leads.status.0.id'));
            $contact = $lead->contact;

            if($lead->cf('Вид оплаты')->getValue() != 'Яндекс касса' || empty($lead->cf('Предоплата')->getValue()) || empty($lead) || empty($contact)){
                die();
            }

            $payment = [
                'lead' => array_get($req, 'leads.status.0.id'),
				'service' => [
					'name' => str_replace('Семинар ', '', $lead->cf('Наименование услуги')->getValue()),
					'type' => '',
					'date' => $lead->cf('Дата записи, Yclients')->getValue(),
					'time' => $lead->cf('Время записи, Yclients')->getValue()
				],
                'amount' => $lead->cf('Предоплата')->getValue(),
                'contact_name' => $contact->name,
                'contact_info' => [
                    'phone' => $contact->cf('Телефон')->getValue(),
                    'email' => $contact->cf('Email')->getValue()
                ],
                'result' =>[
                    'sms' => "SMS не отправлено. Отсутствует номер телефона.\n",
                    'email' => 'Email не отправлен. Возможно адрес отсутствует'
                ]
            ];

            switch (array_get($req, 'leads.status.0.pipeline_id')){
                case 1120282:
					$payment['shop'] = 'ooo';
                    $payment['service']['type'] = 'сеанс';
                    $payment['kassa'] = $amoKassa->gen_link($payment['lead'], $payment['amount'], $payment['shop']);
                    break;
                case 1160947:
					$payment['shop'] = 'ip';
                    $payment['service']['type'] = 'семинар';
                    $payment['kassa'] = $amoKassa->gen_link($payment['lead'], $payment['amount'], $payment['shop']);
                    break;
            }

            if (!empty($payment['contact_info']['phone'])) {
                $str_sms = ['Ваша ссылка для внесения предоплаты: {link}',
                    [
                        'link' => $payment['kassa']['url']
                    ]
                ];
                $payment['result']['sms'] = $amoKassa->send_sms($payment['contact_info']['phone'], $str_sms);
            }
			
            if (!empty($payment['contact_info']['email'])) {
                $email = $payment['contact_info']['email'];
                $varMail = [
                    'name'=>$payment['contact_name'],
                    'link'=>$payment['kassa']['url'],
					'sum' => $payment['amount'],
                    'service_name' => $payment['service']['name'],
					'service_date' => $payment['service']['date'],
					'service_time' => $payment['service']['time']
                ];
				$mailTemplate = ($payment['service']['type'] == 'семинар') ? 'mails.workshopStage' : 'mails.seance';

                Mail::send($mailTemplate, $varMail, function ($m) use($email){
                    $m->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME'));
                    $m->to($email)->subject('Внесение предоплаты');
                });
                $payment['result']['email'] = 'Email на адрес: ' . $payment['contact_info']['email'] . ' отправлен';
            }
			
			$pay_data = Payment::firstOrCreate([
				
				'sig' => $payment['kassa']['sig']
			],[
				'lead' => $payment['lead'],
				'sum' => $payment['amount'],
				'shop' => $payment['shop'],
				'service_name' => $payment['service']['name'],
				'service_type' => $payment['service']['type'],
				'send_sms' => date("Y-m-d H:i:s")
			]);

            $note = $lead->createNote($type = 4);
            $note->text = $payment['result']['sms'] . $payment['result']['email'];
            $note->element_type = 2;
            $note->element_id = $payment['lead'];
            $note->save();
			Log::info('Result /amo:', $payment);
        }
    }

    public function yandexHttp(Request $request, $status){
		/*
			Правильный ответ функции не гарантирован
		*/
		if (!($status == 'check' || $status == 'aviso')){
            abort(404);
        }

        $req = $request->all();
        $pay_out = [
            'rootXML' => '',
            'dateTime' => date("Y-m-d\TH:i:s.vP"),
            'code' => false,
            'invoiceId' => array_get($req,'invoiceId'),
            'shopId' => array_get($req,'shopId')
        ];
        $pay_data = Payment::where('pay_id', array_get($req,'orderNumber'))->first();
        $md5_array = [
            array_get($req,'action'),
            number_format(array_get($pay_data, 'sum'), 2, '.', ''),
            array_get($req,'orderSumCurrencyPaycash'),
            array_get($req,'orderSumBankPaycash'),
            $pay_out['shopId'],
            array_get($req,'invoiceId'),
            array_get($req,'customerNumber'),
            config('amoKassa.kassa_http.shopPassword')
        ];

        if (md5(implode(';',$md5_array)) == strtolower(array_get($req,'md5'))) {
            $pay_out['code'] = 0;
        } else {
            $pay_out['code'] = 1;
        }
        if ((empty($pay_data->pay_id)) && array_get($req,'action') == 'checkOrder') {
            $pay_out['code'] = 100;
        }
        foreach ($md5_array as $item){
            if (empty($item)){
                $pay_out['code'] = 200;
            }
        }

        switch ($status){
            case 'check' :
                $pay_out['rootXML'] = 'checkOrderResponse';
                break;
            case 'aviso' :
                $pay_out['rootXML'] = 'paymentAvisoResponse';
                break;
        }

        if ($pay_out['code'] == 0) {
            if ($pay_data->shop == 'ooo'){
                $status_id = 19871728;
            } elseif ($pay_data->shop == 'ip'){
                $status_id = 19871692;
            }else{
                die();
            }
            $amo = \Ufee\Amo\Amoapi::setInstance(config('amoKassa.amo'));
            $lead = $amo->leads()->find($pay_data->lead);
            $lead->status_id = $status_id;
            $lead->save();
        }

        return response()->view('yandex_res',$pay_out)->header('Content-type', 'text/xml');
    }

    public function yandexAPI(Request $request)
    {
		$pay_data = Payment::where('pay_id', $request->input('object.id'))->first();
		//print_r($pay_data);
		Log::info('Result /yandexAPI:', $request->input());
        if ($request->input('object.status') == 'succeeded' && (!empty($pay_data))) {
            if ($pay_data->shop == 'ooo'){
                $status_id = 19871728;
            } elseif ($pay_data->shop == 'ip'){
                $status_id = 19871692;
            }else{
                die();
            }
            $amo = \Ufee\Amo\Amoapi::setInstance(config('amoKassa.amo'));
            $lead = $amo->leads()->find($pay_data->lead);
            $lead->status_id = $status_id;
            $lead->save();
			
			$note = $amo->notes()->create();
			$note->note_type = 4;
            $note->text = 'Платеж получен';
            $note->element_type = 2;
            $note->element_id = $pay_data->lead;
            $note->save();
        }
    }
}