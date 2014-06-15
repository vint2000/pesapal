<?php
/**
 * Created by PhpStorm.
 * User: eric
 * Date: 6/13/14
 * Time: 12:39 AM
 */

namespace Ericmuigai\Pesapal;

use Illuminate\Support\Facades\Redirect as Redirect;
use Illuminate\View\Environment;
use Illuminate\Support\Facades\Input as Input;

class Pesapal
{
    protected $view;

    public function __construct(Environment $view)
    {
        $this->view = $view;
    }

    /**
     * Was implemented to check status but not yet fully working
     */
    public static function checkStatus()
    {
        global $enabled, $consumer_key, $consumer_secret;
        $check = new Oauth\PesapalCheckStatus($consumer_key, $consumer_secret, $enabled);
        echo $check->checkStatusUsingTrackingIdandMerchantRef("Merchant", "1234");
    }

    /**
     * @return mixed Redirect to the specified url
     */
    public function redirectAfterPayment()
    {
        global $redirectTo;
        $tracking_id = Input::get("pesapal_transaction_tracking_id");
        $reference = Input::get("pesapal_merchant_reference");
        $query = Pesapalpayments::where("reference", $reference)->first();

        if (count($query) == 1) {
            $query->tracking_id = $tracking_id;
            $query->save();
        }
        return Redirect::to($redirectTo);
        //This is to check teh status but since the ipn will run I will ignore this and let ipn handle everything from there
        //  $check = new Oauth\PesapalCheckStatus($consumer_key,$consumer_secret,$enabled);
        //  $status 			= $check->checkStatusUsingTrackingIdandMerchantRef($reference,$tracking_id);
    }

    /**
     * This the main function that will control the ipn queries
     */
    public function listentToIpn()
    {
        global $enabled, $consumer_key, $consumer_secret, $controller, $key,$email,$mail,$name;
        //set if enabled
        if ($enabled == "true") {
            $link = 'https://www.pesapal.com/api/querypaymentstatus';
        } else {
            $link = 'http://demo.pesapal.com/api/querypaymentstatus';
        }
        new Oauth\Ipnlisten($consumer_key, $consumer_secret, $link, $key, $controller,$email,$mail,$name);
    }

    /**
     * generates the iframe from the given details
     * @param array $values this array should contain the fields required by pesapal
     * description - description of the item or service
     * currency - if set will override the config settings you have of currency
     * user -which should be your client user id if you have a system of users
     * first_name- the first name of the user that is paying
     * last_name - the last name of the user that is paying
     * email - this should be a valid email or pesapal will throw an error
     * phone_number -which is option if you have the email
     * amount - the total amount to be posted to pesapal
     * reference Please Make sure this is a unique key to the transaction. May be left empty it will be auto generated
     * type - default is MERCHANT
     * frame_height- this is the height of the iframe please provide integers as in 900 without the px
     *
     * @return string the iframe of pesapal
     */
    public function Iframe($values = array())
    {
        global $enabled, $consumer_key, $consumer_secret, $currency;

//        echo "$currency enabled $enabled consumer_key $consumer_key  consumer_se =$consumer_secret";
//die();
        $token = $params = NULL;
        //account on demo.pesapal.com. When you are ready to go live make sure you
        //change the secret to the live account registered on www.pesapal.com!
        $signature_method = new Oauth\OAuthSignatureMethod_HMAC_SHA1();


        //set if enabled
        if ($enabled == "true") {
            $iframelink = 'https://www.pesapal.com/api/PostPesapalDirectOrderV4';
        } else {
            $iframelink = 'http://demo.pesapal.com/API/PostPesapalDirectOrderV4';
        }
        $amount = $values['amount'];
        //removed the below code since I saw on the forums pesapal interprets as a fullstop as in 1,000 = 1.0
        // $amount = number_format($amount, 2); //format amount to 2 decimal places
        $desc = $values['description'];
        //$type = $values['type']; //default value = MERCHANT


        if (in_array("reference", $values, false)) {
            $ref = str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 5);
            $reference = substr(str_shuffle($ref), 0, 10);
        } else {
            $reference = $values['reference']; //unique order id of the transaction, generated by merchant
        }

        $first_name = $values['first_name']; //[optional]
        $last_name = $values['last_name']; //[optional]
        $email = $values['email'];
        $type = 'MERCHANT';
        if (!in_array("type", $values)) {
            $type = 'MERCHANT';
        } else {
            $type = $values['type'];
        }
        $phone_number = $values['phone_number'];

        if ((in_array("currency", $values, TRUE))) {
            $currency = $values['currency'];
        }


        $amount = number_format($amount, 2); //format amount to 2 decimal places


        if (Input::has("currency")) {
            $currency = Input::get('currency');
        }
        //the array data to be posted to pesapal
        $data = array(
            "currency" => $currency,
            "amount" => $amount,
            "description" => $desc,
            "type" => $type,
            "reference" => $reference,
            "first_name" => $first_name,
            "last_name" => $last_name,
            "phone_number" => $phone_number,
            "user" => $values['user'],
            "email" => $email,
        );
        //check to see if there is any payment with this reference id
        //and also avoids duplicates in the database
        $query = Pesapalpayments::where("reference", $reference)->first();
        if (count($query) == 0) {
            Pesapalpayments::create($data);
        }

        $callback_url = url('/pesapal_redirect'); //redirect url, the page that will handle the response from pesapal.
        $post_xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
				   <PesapalDirectOrderInfo
						xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
					  	xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\"
					  	Currency=\"" . $currency . "\"
					  	Amount=\"" . $amount . "\"
					  	Description=\"" . $desc . "\"
					  	Type=\"" . $type . "\"
					  	Reference=\"" . $reference . "\"
					  	FirstName=\"" . $first_name . "\"
					  	LastName=\"" . $last_name . "\"
					  	Email=\"" . $email . "\"
					  	PhoneNumber=\"" . $phone_number . "\"
					  	xmlns=\"http://www.pesapal.com\" />";
        $post_xml = htmlentities($post_xml);


        $consumer = new Oauth\OAuthConsumer($consumer_key, $consumer_secret);
        //post transaction to pesapal
        $iframe_src = Oauth\OAuthRequest::from_consumer_and_token($consumer, $token, "GET", $iframelink, $params);

        $iframe_src->set_parameter("oauth_callback", $callback_url);
        $iframe_src->set_parameter("pesapal_request_data", $post_xml);
        $iframe_src->sign_request($signature_method, $consumer, $token);
        // var_dump($post_xml);

        return '<iframe src="' . $iframe_src . '" width="100%" height="' . $values['frame_height'] . 'px" scrolling="no" frameBorder="0">';

        //return '<iframe src="'.$iframe_src.' width="500px" height="620px" scrolling="auto" frameBorder="0"> <p>Unable to load the payment page</p> </iframe>';
    }


}
