<?php

/*
 * @author tpay.com
 *
 * Plugin Name: tpay.com online payments
 * Plugin URI: http://www.tpay.com
 * Description: Payment gateway for Blesta.
 * Author: tpay.com
 * Author URI: http://www.tpay.com
 * Version: 1.0.0
 */

class TpayPayments extends NonmerchantGateway
{
    const TR_ERROR = 'tr_error';
    const CAUGHT_EXCEPTION = 'Caught exception: ';
    const RECUR = 'recur';
    const AMOUNT = 'amount';
    const UNSUPPORTED = "unsupported";
    /**
     * @var string The version of this gateway
     */
    private static $version = "1.0.0";
    /**
     * @var string The authors of this gateway
     */
    private static $authors = array(array('name' => "tpay.com", 'url' => "https://tpay.com"));
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;


    /**
     * Construct a new merchant gateway
     */
    public function __construct()
    {

        // Load components required by this gateway
        Loader::loadComponents($this, array("Input"));

        // Load the language required by this gateway
        Language::loadLang("tpay_payments", null, dirname(__FILE__) . DS . "language" . DS);

        $this->loadConfig(dirname(__FILE__) . DS . "config.json");

        include_once 'lib/src/_class_tpay/validate.php';
        include_once 'lib/src/_class_tpay/util.php';
        include_once 'lib/src/_class_tpay/exception.php';
        include_once 'lib/src/_class_tpay/paymentBasic.php';
        include_once 'lib/src/_class_tpay/curl.php';
        include_once 'lib/src/_class_tpay/lang.php';
    }

    /**
     * Returns the name of this gateway
     *
     * @return string The common name of this gateway
     */
    public function getName()
    {
        return Language::_("TpayPayments.name", true);
    }

    /**
     * Returns the version of this gateway
     *
     * @return string The current version of this gateway
     */
    public function getVersion()
    {
        return static::$version;
    }

    /**
     * Returns the name and URL for the authors of this gateway
     *
     * @return array The name and URL of the authors of this gateway
     */
    public function getAuthors()
    {
        return static::$authors;
    }

    /**
     * Return all currencies supported by this gateway
     *
     * @return array A numerically indexed array containing all currency codes (ISO 4217 format) this gateway supports
     */
    public function getCurrencies()
    {
        return array("PLN");
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function getLogo()
    {
        return "views/default/images/logotpay.png";
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView("settings", "default", str_replace(ROOTWEBDIR, "", dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("meta", $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        // Verify meta data is valid
        $rules = array(
            'user' => array(
                'empty' => array(
                    'rule'    => "isEmpty",
                    'negate'  => true,
                    'message' => Language::_("TpayPayments.!error.user.empty", true)
                )
            ),
            'key'  => array(
                'empty' => array(
                    'rule'    => "isEmpty",
                    'negate'  => true,
                    'message' => Language::_("TpayPayments.!error.key.empty", true)
                )
            ),

        );

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);
        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return array("key");
    }

    /**
     * Sets the meta data for this particular gateway
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form
     *
     * @param array $contactInfo An array of contact info including:
     *    - id The contact ID
     *    - client_id The ID of the client this contact belongs to
     *    - user_id The user ID this contact belongs to (if any)
     *    - contact_type The type of contact
     *    - contact_type_id The ID of the contact type
     *    - first_name The first name on the contact
     *    - last_name The last name on the contact
     *    - title The title of the contact
     *    - company The company name of the contact
     *    - address1 The address 1 line of the contact
     *    - address2 The address 2 line of the contact
     *    - city The city of the contact
     *    - state An array of state info including:
     *        - code The 2 or 3-character state code
     *        - name The local name of the country
     *    - country An array of country info including:
     *        - alpha2 The 2-character country code
     *        - alpha3 The 3-cahracter country code
     *        - name The english name of the country
     *        - alt_name The local name of the country
     *    - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoiceAmounts An array of invoices, each containing:
     *    - id The ID of the invoice being processed
     *    - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *    - description The Description of the charge
     *    - return_url The URL to redirect users to after a successful payment
     *    - recur An array of recurring info including:
     *        - amount The amount to recur
     *        - term The term to recur
     *        - period The recurring period (day, week, month, year, onetime) used in conjunction with term
     * in order to determine the next recurring payment
     * @return string HTML markup required to render an authorization and capture payment form
     */
    public function buildProcess(array $contactInfo, $amount, array $invoiceAmounts = null, array $options = null)
    {
        // Force 2-decimal places only
        $amount = round($amount, 2);
        if (isset($options[static::RECUR][static::AMOUNT])) {

            $options[static::RECUR][static::AMOUNT] = round($options[static::RECUR][static::AMOUNT], 2);
        }
        $this->view = $this->makeView("process", "default", str_replace(ROOTWEBDIR, "", dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));
        $crc = $this->ifSet($contactInfo['client_id']) . 'tpay' . base64_encode(serialize($invoiceAmounts));

        $fields = array(
            // Set account/invoice info to use later

            // Set required fields
            'opis'         => 'oplata',
            'crc'          => $crc,
            'kwota'        => $amount,
            'pow_url'      => $this->ifSet($options['return_url']),
            'pow_url_blad' => $this->ifSet($options['return_url']),
            'wyn_url'      => Configure::get("Blesta.gw_callback_url") .
                Configure::get("Blesta.company_id") . "/tpay_payments/",
            // Pre-populate billing information
            'imie'         => $this->ifSet($contactInfo['first_name']),
            'nazwisko'     => $this->ifSet($contactInfo['last_name']),
            'adres'        => $this->Html->concat(" ", $this->ifSet($contactInfo['address1']),
                $this->ifSet($contactInfo['address2'])),
            'miasto'       => $this->ifSet($contactInfo['city']),
            'kod'          => $this->ifSet($contactInfo['zip']),
            'kraj'         => $this->ifSet($contactInfo['country']['alpha3'])
        );

        // Set contact email address and phone number
        if ($this->ifSet($contactInfo['id'], false)) {
            Loader::loadModels($this, array("Contacts"));
            if (($contact = $this->Contacts->get($contactInfo['id']))) {
                $fields['email'] = $contact->email;

                // Set a phone number, if one exists
                $contactNumbers = $this->Contacts->getNumbers($contactInfo['id'], "phone");
                if (isset($contactNumbers[0]) && !empty($contactNumbers[0]->number)) {
                    $fields['telefon'] = preg_replace("/[^0-9]/", "", $contactNumbers[0]->number);
                }
            }
        }
        try {
            $paymentBasic = new tpay\PaymentBasic(
                (int)$this->ifSet($this->meta['user']),
                (string)$this->ifSet($this->meta['key'])
            );
            $res = $paymentBasic->getBankSelectionForm($fields);

        } catch (tpay\TException $exception) {
            echo static::CAUGHT_EXCEPTION, $exception->getMessage(), "\n";
            return;
        }

        return $res;
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *    - id The ID of the invoice to apply to
     *    - amount The amount to apply to the invoice
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the gateway to identify this transaction
     */
    public function validate(array $get, array $post)
    {

        try {
            $paymentBasic = new tpay\PaymentBasic(
                (int)$this->ifSet($this->meta['user']),
                (string)$this->ifSet($this->meta['key'])
            );
            $res = $paymentBasic->checkPayment();

        } catch (tpay\TException $exception) {
            $this->log($this->ifSet($_SERVER['REQUEST_URI']), serialize($post), "output", false);
            echo static::CAUGHT_EXCEPTION, $exception->getMessage(), "\n";
            return;
        }
        $orderNumber = $this->ifSet($post['tr_crc']);
        if ($res['tr_status'] == 'TRUE' &&
            ($res[static::TR_ERROR] === 'none' || $res[static::TR_ERROR] === 'overpay')) {
            // transaction successful
            $status = 'approved';
        } else {
            // transaction failed
            $status = 'declined';
        }
        // Log the response
        $this->log($this->ifSet($_SERVER['REQUEST_URI']), serialize($post), "output", true);
        $crc = explode('tpay', $orderNumber);
        return array(
            'client_id'             => $crc[0],
            static::AMOUNT            => $this->ifSet($post['tr_paid']),
            'currency'              => 'PLN',
            'invoices'              => unserialize(base64_decode($crc[1])),
            'status'                => $status,
            'reference_id'          => null,
            'transaction_id'        => $this->ifSet($post['tr_id']),
            'parent_transaction_id' => null
        );

    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *    - id The ID of the invoice to apply to
     *    - amount The amount to apply to the invoice
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - transaction_id The ID returned by the gateway to identify this transaction
     */
    public function success(array $get, array $post)
    {
        return array(
            '' => ''
        );
    }

    /**
     * Captures a previously authorized payment
     *
     * @param string $referenceId The reference ID for the previously authorized transaction
     * @param string $transactionId The transaction ID for the previously authorized transaction
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this
     * transaction status (optional)
     */
    public function capture($referenceId, $transactionId, $amount, array $invoiceAmounts = null)
    {
// This method is unsupported
        $this->Input->setErrors($this->getCommonError(static::UNSUPPORTED));
    }
    /**
     * Void a payment or authorization
     *
     * @param string $referenceId The reference ID for the previously submitted transaction
     * @param string $transactionId The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for this
     * transaction status (optional)
     */
    public function void($referenceId, $transactionId, $notes = null)
    {
// This method is unsupported
        $this->Input->setErrors($this->getCommonError(static::UNSUPPORTED));
    }

    /**
     * Refund a payment
     *
     * @param string $referenceId The reference ID for the previously submitted transaction
     * @param string $transactionId The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this card
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *    - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *    - reference_id The reference ID for gateway-only use with this transaction (optional)
     *    - transaction_id The ID returned by the remote gateway to identify this transaction
     *    - message The message to be displayed in the interface in addition to the standard message for
     * this transaction status (optional)
     */
    public function refund($referenceId, $transactionId, $amount, $notes = null)
    {

        $this->Input->setErrors($this->getCommonError(static::UNSUPPORTED));
    }
}
