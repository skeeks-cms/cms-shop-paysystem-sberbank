<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 * @author Semenov Alexander <semenov@skeeks.com>
 */

namespace skeeks\cms\shop\sberbank;

use skeeks\cms\helpers\StringHelper;
use skeeks\cms\shop\models\ShopOrder;
use skeeks\cms\shop\models\ShopPayment;
use skeeks\cms\shop\paysystem\PaysystemHandler;
use skeeks\yii2\form\fields\BoolField;
use skeeks\yii2\form\fields\FieldSet;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\httpclient\Client;

/**
 * @author Semenov Alexander <semenov@skeeks.com>
 */
class SberbankPaysystemHandler extends PaysystemHandler
{
    /**
     * @see https://developer.sberbank.ru/acquiring-api-rest-requests1pay
     */
    const ORDER_STATUS_2 = 2; //Проведена полная авторизация суммы заказа


    public $isLive = true; //https://auth.robokassa.ru/Merchant/Index.aspx

    public $gatewayUrl = 'https://securepayments.sberbank.ru/payment/rest/';
    public $gatewayTestUrl = 'https://3dsec.sberbank.ru/payment/rest/';
    public $thanksUrl = '/main/spasibo-za-zakaz';
    public $failUrl = '/main/problema-s-oplatoy';
    public $currency = 'RUB';
    public $username = '';
    public $password = '';
    
    /**
     * Можно задать название и описание компонента
     * @return array
     */
    static public function descriptorConfig()
    {
        return array_merge(parent::descriptorConfig(), [
            'name' => \Yii::t('skeeks/shop/app', 'Sberbank'),
        ]);
    }


    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [
            [['isLive'], 'boolean'],
            [['username'], 'string'],
            [['password'], 'string'],
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'username' => 'Идентификатор магазина из ЛК',
            'password' => 'Пароль',
            'isLive'   => 'Рабочий режим (не тестовый!)',
        ]);
    }

    public function attributeHints()
    {
        return ArrayHelper::merge(parent::attributeHints(), [
            'isLive' => 'Будет использован url: https://securepayments.sberbank.ru/payment/rest/ (тестовый: https://3dsec.sberbank.ru/payment/rest/)',
        ]);
    }
    
    /**
     * @return array
     */
    public function getConfigFormFields()
    {
        return [
            'main' => [
                'class'  => FieldSet::class,
                'name'   => 'Основные',
                'fields' => [
                    'username',
                    'password',

                    'isLive' => [
                        'class'     => BoolField::class,
                        'allowNull' => false,
                    ],
                ],
            ],

        ];
    }
    
    /**
     * @param $method
     * @param $data
     * @return mixed
     */
    public function gateway($method, $data)
    {
        $curl = curl_init(); // Инициализируем запрос
        curl_setopt_array($curl, [
            CURLOPT_URL            => ($this->isLive ? $this->gatewayUrl : $this->gatewayTestUrl).$method, // Полный адрес метода
            CURLOPT_RETURNTRANSFER => true, // Возвращать ответ
            CURLOPT_POST           => true, // Метод POST
            CURLOPT_POSTFIELDS     => http_build_query($data) // Данные в запросе
        ]);
        $response = curl_exec($curl); // Выполненяем запрос
        $response = json_decode($response, true); // Декодируем из JSON в массив
        curl_close($curl); // Закрываем соединение
        return $response; // Возвращаем ответ
    }
    
    
    /**
     * @param ShopPayment $shopPayment
     * @return \yii\console\Response|\yii\web\Response
     */
    public function actionPayOrder(ShopOrder $shopOrder)
    {
        $model = $this->getShopBill($shopOrder);

        $yooKassa = $model->shopPaySystem->handler;
        $money = $model->money->convertToCurrency("RUB");
        $returnUrl = $shopOrder->getUrl([], true);
        $successUrl = $shopOrder->getUrl(['success_paied' => true], true);
        $failUrl = $shopOrder->getUrl(['fail_paied' => true], true);

        /**
         * Для чеков нужно указывать информацию о товарах
         * https://yookassa.ru/developers/api?lang=php#create_payment
         */
        $shopBuyer = $shopOrder->shopBuyer;


        $data = [
            'TerminalKey'     => $this->terminal_key,
            'Amount'          => $money->amount * 100,
            'OrderId'         => $model->id,
            'Description'     => $model->description,
            'NotificationURL' => Url::to(['/tinkoff/tinkoff/notify'], true),
            'SuccessURL'      => $successUrl,
            'FailURL'         => $failUrl,
        ];


        $receipt = [];
        if ($yooKassa->is_receipt) {

            $receipt['Email'] = \Yii::$app->cms->adminEmail;
            if (trim($shopBuyer->email)) {
                $receipt['Email'] = trim($shopBuyer->email);
            }
            $receipt['Taxation'] = "usn_income"; //todo: вынести в настройки

            foreach ($shopOrder->shopOrderItems as $shopOrderItem) {
                $itemData = [];

                /**
                 * @see https://www.tinkoff.ru/kassa/develop/api/payments/init-request/#Items
                 */
                $itemData['Name'] = StringHelper::substr($shopOrderItem->name, 0, 128);
                $itemData['Quantity'] = (float)$shopOrderItem->quantity;
                $itemData['Tax'] = "none"; //todo: доработать этот момент
                $itemData['Price'] = $shopOrderItem->money->amount * 100;
                $itemData['Amount'] = $shopOrderItem->money->amount * $shopOrderItem->quantity * 100;

                $receipt['Items'][] = $itemData;
            }

            /**
             * Стоимость доставки так же нужно добавить
             */
            if ((float)$shopOrder->moneyDelivery->amount > 0) {
                $itemData = [];
                $itemData['Name'] = StringHelper::substr($shopOrder->shopDelivery->name, 0, 128);
                $itemData['Quantity'] = 1;
                $itemData['Tax'] = "none";
                $itemData['Amount'] = $shopOrder->moneyDelivery->amount * 100;
                $itemData['Price'] = $shopOrder->moneyDelivery->amount * 100;

                $receipt['Items'][] = $itemData;
            }

            $totalCalcAmount = 0;
            foreach ($receipt['Items'] as $itemData) {
                $totalCalcAmount = $totalCalcAmount + ($itemData['Amount'] * $itemData['Quantity']);
            }

            $discount = 0;
            if ($totalCalcAmount > (float)$money->amount) {
                $discount = abs((float)$money->amount - $totalCalcAmount);
            }

            /**
             * Стоимость скидки
             */
            //todo: тут можно еще подумать, это временное решение
            if ($discount > 0 && 1 == 2) {
                $discountValue = $discount;
                foreach ($receipt['items'] as $key => $item) {
                    if ($discountValue == 0) {
                        break;
                    }
                    if ($item['amount']['value']) {
                        if ($item['amount']['value'] >= $discountValue) {
                            $item['amount']['value'] = $item['amount']['value'] - $discountValue;
                            $discountValue = 0;
                        } else {
                            $item['amount']['value'] = 0;
                            $discountValue = $discountValue - $item['amount']['value'];
                        }
                    }

                    $receipt['items'][$key] = $item;
                }
                //$receipt['items'][] = $itemData;


            }


            $data["Receipt"] = $receipt;
        }



        $email = null;
        $phone = null;
        if ($model->shopBuyer) {
            if ($model->shopBuyer->email) {
                $data["DATA"]["Email"] = $model->shopBuyer->email;
            }
        }

        //print_r($data);die;

        $client = new Client();
        $request = $client
            ->post($this->tinkoff_url."Init")
            ->setFormat(Client::FORMAT_JSON)
            ->setData($data);
        ;

        \Yii::info(print_r($data, true), self::class);

        $response = $request->send();
        if (!$response->isOk) {
            \Yii::error($response->content, self::class);
            throw new Exception('Tinkoff api not found');
        }

        if (!ArrayHelper::getValue($response->data, "PaymentId")) {
            \Yii::error(print_r($response->data, true), self::class);
            throw new Exception('Tinkoff kassa payment id not found: ' . print_r($response->data, true));
        }

        $model->external_id = ArrayHelper::getValue($response->data, "PaymentId");
        $model->external_data = $response->data;

        if (!$model->save()) {
            throw new Exception("Не удалось сохранить платеж: ".print_r($model->errors, true));
        }

        return \Yii::$app->response->redirect(ArrayHelper::getValue($response->data, "PaymentURL"));
    }
}