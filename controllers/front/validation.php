<?php

require_once __DIR__ . "/ExpressPay.php";
/**
 * @since 1.0.0
 */
class expay_eripvalidationModuleFrontController extends ModuleFrontController
{
	public function postProcess()
	{
		$cart = $this->context->cart;

		var_dump($this->context->currency);

        if($this->context->currency->iso_code_num != 933)
            die($this->module->l('This payment module support only BYN currency.', 'expay_erip'));
        
        $expayConfig = json_decode(Configuration::get("EXPAY_CONFIG"), false);
        
        if(!isset($expayConfig->token))
            die($this->module->l('Token can\'t be empty.', 'expay_erip'));
            
        $expressPay = new ExpressPay($expayConfig->token, $expayConfig->testing_mode ? $expayConfig->test_api_url : $expayConfig->api_url, $expayConfig->use_digital_sign_send, $expayConfig->send_secret_word);

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
			Tools::redirect('index.php?controller=order&step=1');

		$authorized = false;
		foreach (Module::getPaymentModules() as $module)
			if ($module['name'] == 'expay_erip')
			{
				$authorized = true;
				break;
			}

		if (!$authorized)
			die($this->module->l('This payment method is not available.', 'validation'));

		$customer = new Customer($cart->id_customer);

		if (!Validate::isLoadedObject($customer))
			Tools::redirect('index.php?controller=order&step=1');

		$currency = $this->context->currency;
		$total = (float)$cart->getOrderTotal(true, Cart::BOTH);
        
        
		/*$mailVars =	array(
			'{cheque_name}' => Configuration::get('CHEQUE_NAME'),
			'{cheque_address}' => Configuration::get('CHEQUE_ADDRESS'),
			'{cheque_address_html}' => str_replace("\n", '<br />', Configuration::get('CHEQUE_ADDRESS')));*/

		$this->module->validateOrder((int)$cart->id, 10, $total, $this->module->displayName, NULL, "", (int)$currency->id, false, $customer->secure_key);
        
        $invoiceNo = $expressPay->addInvoice($this->module->currentOrder,
            str_replace('.', ',', $total),
            $this->context->currency->iso_code_num,
            "",
            "",
            $this->context->customer->lastname,
            $this->context->customer->firstname,
            "",
            "",
            "",
            "",
            "",
            $expayConfig->allow_change_name,
            $expayConfig->allow_change_address,
            $expayConfig->allow_change_amount,
            "");
        
		Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
	}
}
