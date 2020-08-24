<?php
/*
  Plugin Name: «Экспресс Платежи: ЕРИП» для PrestaShop
  Plugin URI: https://express-pay.by/cms-extensions/prestashop
  Description: «Экспресс Платежи: ЕРИП» - плагин для интеграции с сервисом «Экспресс Платежи» (express-pay.by) через API. Плагин позволяет выставить счет в системе ЕРИП, получить и обработать уведомление о платеже в системе ЕРИП. Описание плагина доступно по адресу: <a target="blank" href="https://express-pay.by/cms-extensions/prestashop">https://express-pay.by/cms-extensions/prestashop</a>
  Version: 2.4
  Author: ООО «ТриИнком»
  Author URI: https://express-pay.by/
 */

if (!defined('_PS_VERSION_'))
    exit;

class expay_erip extends PaymentModule
{
    private $_postErrors = [];
    public function __construct()
    {
        $this->name    = 'expay_erip';
        $this->tab     = 'payments_gateways';
        $this->version = '1.1';
        $this->author = 'ООО "ТриИнком"';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 1;

        $this->currencies      = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName      = $this->l('ExpressPay');
        $this->description      = $this->l('This module allows you to accepts ERIP payments');
        $this->confirmUninstall = $this->l('Are you sure you want to remove module ?');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.99.99');

        $values = json_decode(Configuration::get("EXPAY_CONFIG"), true);
        if ((empty($values['token'])))
            $this->warning = $this->l('The "Token" field must be configured before using this module.');
        if (!count(Currency::checkPaymentCurrencies($this->id)))
            $this->warning = $this->l('No currency has been set for this module.');
    }

    public function install()
    {
        if (!parent::install() || !Configuration::updateValue('EXPAY_CONFIG', json_encode([
                "token" => "",
                "notification_url" => '',
                "use_digital_sign_send" => false,
                "send_secret_word" => "",
                "use_digital_sign_receive" => false,
                "receive_secret_word" => "",
                "allow_change_name" => false,
                "allow_change_address" => false,
                "allow_change_amount" => false,
                "testing_mode" => true,
                "api_url" => "https://api.express-pay.by/v1/",
                "test_api_url" => "https://sandbox-api.express-pay.by/v1/",
                "success_payment_text" => "Ваш номер заказа ##order_id##.
Сумма к оплате: ##total_amount##."
            ]))
            || !$this->registerHook('payment')
            || !$this->registerHook('paymentReturn')
            || !$this->registerHook('displayPaymentEU')
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('EXPAY_CONFIG')
            || !$this->unregisterHook('payment')
            || !$this->unregisterHook('displayPaymentEU')
            || !$this->unregisterHook('paymentReturn')
            || !parent::uninstall())
            return false;
        return true;
    }

    private function _displayInfo()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('btnSubmit'))
        {
            if (empty($_POST['expay_token']))
                $this->_postErrors[] = $this->l('Token is required.');
            if (empty($_POST['api_url']))
                $this->_postErrors[] = $this->l('API URL is required.');
            if (!sizeof($this->_postErrors))
            {
                $config = [
                    "token" => $_POST['expay_token'],
                    "notification_url" => '',
                    "use_digital_sign_send" => $_POST['use_digital_sign_send'],
                    "send_secret_word" => $_POST['send_secret_word'],
                    "use_digital_sign_receive" => $_POST['use_digital_sign_receive'],
                    "receive_secret_word" => $_POST['receive_secret_word'],
                    "allow_change_name" => $_POST['allow_change_name'],
                    "allow_change_address" => $_POST['allow_change_address'],
                    "allow_change_amount" => $_POST['allow_change_amount'],
                    "testing_mode" => $_POST['testing_mode'],
                    "api_url" => $_POST['api_url'],
                    "test_api_url" => $_POST['test_api_url'],
                    "success_payment_text" => $_POST['success_payment_text']
                ];
                Configuration::updateValue('EXPAY_CONFIG', json_encode($config));
                $html .= $this->displayConfirmation($this->l('Settings updated'));
            }
            else
                foreach ($this->_postErrors as $err)
                    $html .= $this->displayError($err);
        }

        $html .= $this->_displayInfo();
        $html .= $this->renderForm();
        return $html;
    }

    public function hookDisplayPaymentEU($params)
    {
        if (!$this->active)
            return;

        if (!$this->checkCurrency($params['cart']))
        return;

        $this->context->controller->addCSS(__PS_BASE_URI__.'modules/'.$this->name.'/views/css/expay.css');
    
        $payment_options = array(
			'cta_text' => $this->l('Pay by Bank Wire'),
			'logo' => Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/logo.gif'),
			'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true)
		);

		return $payment_options;
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('ExpressPay Settings'),
                    'icon' => 'icon-envelope'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Token'),
                        'name' => 'expay_token',
                        'desc' => $this->l('Your token from express-pay.by website.'),
                        'required' => true
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Notification URL'),
                        'name' => 'notification_url',
                        'desc' => $this->l('Copy this URL to \"URL for notification\" field on express-pay.by.'),
                        'readonly' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Digital signature for API'),
                        'name' => 'use_digital_sign_send',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Secret word for bills signing'),
                        'name' => 'send_secret_word'
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Digital signature for notifications'),
                        'name' => 'use_digital_sign_receive',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Secret word for notifications'),
                        'name' => 'receive_secret_word'
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow to change payer name'),
                        'name' => 'allow_change_name',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow to change payer address'),
                        'name' => 'allow_change_address',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Allow to change pay amount'),
                        'name' => 'allow_change_amount',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Use test mode'),
                        'name' => 'testing_mode',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No')
                            ]
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('API URL'),
                        'name' => 'api_url'
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Test API URL'),
                        'name' => 'test_api_url'
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Success payment message'),
                        'desc' => $this->l('This message will be showed to payer after payment.'),
                        'name' => 'success_payment_text',
                        'required' => true
                    ]/*,
                    [
                        'type' => 'label',
                        'label' => '<h3>' . $this->l('Plugin settings') . '</h3>',
                        'name' => '_unused_lable'
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Order Status for successful transactions'),
                        'name' => 'success_status'
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Order Status for pending transactions'),
                        'name' => 'pending_status'
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Order Status for failed transactions'),
                        'name' => 'failed_status'
                    ]*/
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ]
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        $values = json_decode(Configuration::get("EXPAY_CONFIG"), true);
        $values["expay_token"] = $values["token"];
        $values["notification_url"] = $this->context->link->getModuleLink($this->name,'notification',[]);
        return $values;
    }

    public function hookPayment($params)
    {
        if (!$this->active)
            return;
        if (!$this->checkCurrency($params['cart']))
            return;

        $this->smarty->assign([
            'this_path' => $this->_path,
            'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
        ]);

        return $this->display(__FILE__, 'payment.tpl');
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active)
            return;

        $config = json_decode(Configuration::get("EXPAY_CONFIG"));
        $successMessage = str_replace('##order_id##', $params['objOrder']->id, $config->success_payment_text);
        $successMessage = str_replace('##total_amount##', Tools::displayPrice($params['total_to_pay']), $successMessage);
        $successMessage = nl2br($successMessage);


        $this->smarty->assign(array(
            'success_message' => $successMessage,
            'status' => 'ok'
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int)($cart->id_currency));
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (is_array($currencies_module))
            foreach ($currencies_module as $currency_module)
                if ($currency_order->id == $currency_module['id_currency'])
                    return true;
        return false;
    }
}
?>
