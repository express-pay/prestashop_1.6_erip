<?php

require_once __DIR__ . "/ExpressPay.php";
/**
 * @since 1.0.0
 */
class expay_eripnotificationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $config = json_decode(Configuration::get("EXPAY_CONFIG"));


        $notification = null;
        try {
            $notification = new Notification($config->use_digital_sign_receive, $config->receive_secret_word);
        }
        catch (Exception $exception)
        {
            die($exception->getMessage());
        }

        $history = new OrderHistory();

        $history->id_order = $notification->accountNo;

        
        switch($notification->cmdtype){
            case 1:
                header("HTTP/1.0 200 OK");
                die(); 
                break;
            case 2:
                header("HTTP/1.0 200 OK");
                die(); 
                break;
            case 3: 
                switch($notification->status){
                    case 1: 
                        // Ожидает оплату
                        $history->changeIdOrderState(_PS_OS_CHEQUE_, $history->id_order);//Изменим статус заказа на "Ожидает оплату"
                        header("HTTP/1.0 200 OK");
                        die('order is waiting for payment');
                        break;
                    case 2: 
                        // Просрочен
                        die();
                        break;
                    case 3: 
                        //Оплачен
                        $history->changeIdOrderState(_PS_OS_PAYMENT_, $history->id_order);//Изменим статус заказа на "Оплачен"
                        header("HTTP/1.0 200 OK");
                        die('Order payment success');
                        break;
                    case 4: 
                        //Оплачен частично
                        die();
                        break;
                    case 5: 
                        //Отменен
                        
                        $history->changeIdOrderState(_PS_OS_CANCELED_,$history->id_order);//Изменим статус заказа на "Отменен"
                        header("HTTP/1.0 200 OK");
                        die('Order canceled');
                        break;
                    default:
                        header("HTTP/1.0 200 OK");
                        die();
                        return;
                }
                break;
            default:
                header("HTTP/1.0 200 OK");
                die();
        }
    }
}
