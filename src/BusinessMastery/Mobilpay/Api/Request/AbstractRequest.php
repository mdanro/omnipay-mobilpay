<?php

namespace Omnipay\MobilPay\Api\Request;

/**
 * Class Mobilpay_Payment_Request_Abstract
 * This class can be used for accessing mobilpay.ro payment interface for your configured online services
 * @copyright NETOPIA System
 * @author Claudiu Tudose
 * @version 1.0
 *
 * This class uses  OpenSSL
 * In order to use the OpenSSL functions you need to install the OpenSSL package.
 * Check PHP documentation for installing OpenSSL package
 */

use Exception;
use stdClass;
use DOMDocument;
use DOMElement;
use DOMNode;

abstract class AbstractRequest
{
    const PAYMENT_TYPE_SMS    = 'sms';
    const PAYMENT_TYPE_CARD    = 'card';

    const CONFIRM_ERROR_TYPE_NONE        = 0x00;
    const CONFIRM_ERROR_TYPE_TEMPORARY    = 0x01;
    const CONFIRM_ERROR_TYPE_PERMANENT    = 0x02;

    const ERROR_LOAD_X509_CERTIFICATE    = 0x10000001;
    const ERROR_ENCRYPT_DATA            = 0x10000002;

    const ERROR_PREPARE_MANDATORY_PROPERTIES_UNSET    = 0x11000001;

    const ERROR_FACTORY_BY_XML_ORDER_ELEM_NOT_FOUND            = 0x20000001;
    const ERROR_FACTORY_BY_XML_ORDER_TYPE_ATTR_NOT_FOUND    = 0x20000002;
    const ERROR_FACTORY_BY_XML_INVALID_TYPE                    = 0x20000003;

    const ERROR_LOAD_FROM_XML_ORDER_ID_ATTR_MISSING            = 0x30000001;
    const ERROR_LOAD_FROM_XML_SIGNATURE_ELEM_MISSING        = 0x30000002;

    const ERROR_CONFIRM_LOAD_PRIVATE_KEY                    = 0x300000f0;
    const ERROR_CONFIRM_FAILED_DECODING_DATA                = 0x300000f1;
    const ERROR_CONFIRM_FAILED_DECODING_ENVELOPE_KEY        = 0x300000f2;
    const ERROR_CONFIRM_FAILED_DECRYPT_DATA                    = 0x300000f3;
    const ERROR_CONFIRM_INVALID_POST_METHOD                    = 0x300000f4;
    const ERROR_CONFIRM_INVALID_POST_PARAMETERS                = 0x300000f5;
    const ERROR_CONFIRM_INVALID_ACTION                        = 0x300000f6;

    const VERSION_QUERY_STRING    = 0x01;
    const VERSION_XML            = 0x02;
    #declare member variables
    /**
     * signatue (Mandatory) 	- signature received from mobilpay.ro that identifies merchant account
     *
     * @var string(64)
     */
    public $signature    = null;
    /**
     * service - identifier of service/product for which you're requesting a payment
     * Mandatory for Mobilpay_Payment_Request_Sms
     * Optional for Mobilpay_Payment_Request_Card
     */
    public $service        = null;

    /**
     * orderId (Mandatory)	- payment transaction identifier generated by merchant; helps merchant to interpret a request to confirm or return url;
     * it should be unique for the specified signature
     *
     * @var string(64)
     */
    public $orderId        = null;
    public $timestamp    = null;
    public $type        = self::PAYMENT_TYPE_SMS;

    public $objPmNotify    = null;

    /**
     * returnUrl (Optional) 	- URL where the user is redirected from mobilpay.ro payment interface
     * when the transaction is canceled or confirmed. If it is not supplied the application will use
     * return URL configured in control panel
     * use it only if you want to overwrite the configured one otherwise set it's value to NULL
     *
     * @var string
     */
    public $returnUrl    = null;

    /**
     * confirmUrl (Optional)	- URL of the seller that will be requested when mobilpay.ro will make
     * a decision about payment (e.g. confirmed, canceled). If it is not supplied the application will use
     * confirm URL configured in control panel
     * use it only if you want to overwrite the configured one otherwise set it's value to NULL
     *
     * @var string
     */
    public $confirmUrl    = null;
    public $cancelUrl    = null;

    public $params        = [];

    /**
     * outEnvKey - output envelope key
     * in this property is stored the envelope key after encrypting data to send to payment interface
     */
    private $outEnvKey    = null;

    /**
     * outEncData - output encrypted data
     * in this property is stored the encrypted data to send to payment interface
     */
    private $outEncData    = null;

    protected $_xmlDoc    = null;

    protected $_requestIdentifier    = null;

    protected $_objRequestParams    = null;
    protected $_objRequestInfo        = null;

    public $objReqNotify            = null;
    public $selectedInstallments    = null;
    public $reqInstallments            = null;

    public function __construct()
    {
        srand((double) microtime() * 1000000);
        $this->_requestIdentifier = md5(uniqid(rand()));

        $this->_objRequestParams = new stdClass();
    }

    abstract protected function _prepare();
    abstract protected function _loadFromXml(DOMElement $elem);

    public static function factory($data)
    {
        $objPmReq = null;
        $xmlDoc = new DOMDocument();
        if (@$xmlDoc->loadXML($data) === true) {
            //try to create payment request from xml
            $objPmReq = self::_factoryFromXml($xmlDoc);
            $objPmReq->_setRequestInfo(self::VERSION_XML, $data);
        } else {
            //try to create payment request from query string
            $objPmReq = self::_factoryFromQueryString($data);
            $objPmReq->_setRequestInfo(self::VERSION_QUERY_STRING, $data);
        }

        return $objPmReq;
    }

    public static function factoryFromEncrypted($envKey, $encData, $privateKeyFilePath, $privateKeyPassword = null)
    {
        $privateKey = null;
        if ($privateKeyPassword == null) {
            $privateKey = @openssl_get_privatekey("file://{$privateKeyFilePath}");
        } else {
            $privateKey = @openssl_get_privatekey("file://{$privateKeyFilePath}", $privateKeyPassword);
        }
        if ($privateKey === false) {
            throw new Exception('Error loading private key', self::ERROR_CONFIRM_LOAD_PRIVATE_KEY);
        }

        $srcData = base64_decode($encData);
        if ($srcData === false) {
            @openssl_free_key($privateKey);
            throw new Exception('Failed decoding data', self::ERROR_CONFIRM_FAILED_DECODING_DATA);
        }

        $srcEnvKey = base64_decode($envKey);
        if ($srcEnvKey === false) {
            throw new Exception('Failed decoding envelope key', self::ERROR_CONFIRM_FAILED_DECODING_ENVELOPE_KEY);
        }

        $data = null;
        $result = @openssl_open($srcData, $data, $srcEnvKey, $privateKey);
        if ($result === false) {
            throw new Exception('Failed decrypting data', self::ERROR_CONFIRM_FAILED_DECRYPT_DATA);
        }

        return self::factory($data);
    }

    protected static function _factoryFromXml(DOMDocument $xmlDoc)
    {
        $elems = $xmlDoc->getElementsByTagName('order');
        if ($elems->length != 1) {
            throw new Exception('factoryFromXml order element not found', self::ERROR_FACTORY_BY_XML_ORDER_ELEM_NOT_FOUND);
        }
        $orderElem = $elems->item(0);

        $attr = $orderElem->attributes->getNamedItem('type');
        if ($attr == null || strlen($attr->nodeValue) == 0) {
            throw new Exception('factoryFromXml invalid payment request type=' . $attr->nodeValue, self::ERROR_FACTORY_BY_XML_ORDER_TYPE_ATTR_NOT_FOUND);
        }
        switch ($attr->nodeValue) {
        case self::PAYMENT_TYPE_CARD:
            $objPmReq = new Card();
            break;
        case self::PAYMENT_TYPE_SMS:
            $objPmReq =  new Sms();
            break;
        default:
            throw new Exception('factoryFromXml invalid payment request type=' . $attr->nodeValue, self::ERROR_FACTORY_BY_XML_INVALID_TYPE);
            break;
        }
        $objPmReq->_loadFromXml($orderElem);

        return $objPmReq;
    }

    protected static function _factoryFromQueryString($data)
    {
        $objPmReq = new Sms();
        $objPmReq->_loadFromQueryString($data);
        return $objPmReq;
    }

    protected function _setRequestInfo($reqVersion, $reqData)
    {
        $this->_objRequestInfo = new stdClass();
        $this->_objRequestInfo->reqVersion    = $reqVersion;
        $this->_objRequestInfo->reqData    = $reqData;
    }

    public function getRequestInfo()
    {
        return $this->_objRequestInfo;
    }

    protected function _parseFromXml(DOMNode $elem)
    {
        $xmlAttr = $elem->attributes->getNamedItem('id');
        if ($xmlAttr == null || strlen((string)$xmlAttr->nodeValue) == 0) {
            throw new Exception('Mobilpay_Payment_Request_Sms::_parseFromXml failed: empty order id', self::ERROR_LOAD_FROM_XML_ORDER_ID_ATTR_MISSING);
        }
        $this->orderId = $xmlAttr->nodeValue;

        $elems = $elem->getElementsByTagName('signature');
        if ($elems->length != 1) {
            throw new Exception('Mobilpay_Payment_Request_Sms::loadFromXml failed: signature is missing', self::ERROR_LOAD_FROM_XML_SIGNATURE_ELEM_MISSING);
        }
        $xmlElem = $elems->item(0);
        $this->signature = $xmlElem->nodeValue;

        $elems = $elem->getElementsByTagName('url');
        if ($elems->length == 1) {
            $xmlElem = $elems->item(0);
            //check for overwritten return url
            $elems = $xmlElem->getElementsByTagName('return');
            if ($elems->length == 1) {
                $this->returnUrl = $elems->item(0)->nodeValue;
            }
            //check for overwritten confirm url
            $elems = $xmlElem->getElementsByTagName('confirm');
            if ($elems->length == 1) {
                $this->confirmUrl = $elems->item(0)->nodeValue;
            }
            //check for overwritten cancel url
            $elems = $xmlElem->getElementsByTagName('cancel');
            if ($elems->length == 1) {
                $this->cancelUrl = $elems->item(0)->nodeValue;
            }
        }

        $this->params = [];
        $paramElems = $elem->getElementsByTagName('params');
        if ($paramElems->length == 1) {
            $paramElems = $paramElems->item(0)->getElementsByTagName('param');
            for ($i = 0; $i < $paramElems->length; $i++) {
                $xmlParam = $paramElems->item($i);
                $elems = $xmlParam->getElementsByTagName('name');
                if ($elems->length != 1) {
                    continue;
                }
                $paramName = $elems->item(0)->nodeValue;

                $elems = $xmlParam->getElementsByTagName('value');
                if ($elems->length != 1) {
                    continue;
                }

                $this->params[$paramName] = urldecode($elems->item(0)->nodeValue);
            }
        }

        $elems = $elem->getElementsByTagName('mobilpay');
        if ($elems->length == 1) {
            $this->objPmNotify = new Notify();
            $this->objPmNotify->loadFromXml($elems->item(0));
        }
    }

    public function encrypt($x509FilePath)
    {
        $this->_prepare();

        $publicKey = openssl_pkey_get_public("file://{$x509FilePath}");

        if ($publicKey === false) {
            $this->outEncData    = null;
            $this->outEnvKey    = null;
            $errorMessage = "Error while loading X509 public key certificate! Reason:";
            while (($errorString = openssl_error_string())) {
                $errorMessage .= $errorString . "\n";
            }
            throw new Exception($errorMessage, self::ERROR_LOAD_X509_CERTIFICATE);
        }

        $srcData = $this->_xmlDoc->saveXML();
        $publicKeys    = [$publicKey];
        $encData    = null;
        $envKeys    = null;

        $result = openssl_seal($srcData, $encData, $envKeys, $publicKeys, "RC4");
        if ($result === false) {
            $this->outEncData    = null;
            $this->outEnvKey    = null;
            $errorMessage = "Error while encrypting data! Reason:";
            while (($errorString = openssl_error_string())) {
                $errorMessage .= $errorString . "\n";
            }
            throw new Exception($errorMessage, self::ERROR_ENCRYPT_DATA);
        }

        $this->outEncData    = base64_encode($encData);
        $this->outEnvKey    = base64_encode($envKeys[0]);
    }

    public function getEnvKey()
    {
        return $this->outEnvKey;
    }

    public function getEncData()
    {
        return $this->outEncData;
    }

    public function getRequestIdentifier()
    {
        return $this->_requestIdentifier;
    }

    public function __isset($name)
    {
        return (isset($this->_objRequestParams) && isset($this->_objRequestParams->$name));
    }

    public function __set($name, $value)
    {
        $this->_objRequestParams->$name = $value;
    }

    public function __get($name)
    {
        if (!isset($this->_objRequestParams) || !isset($this->_objRequestParams->$name)) {
            return null;
        }

        return $this->_objRequestParams->$name;
    }
    public function __wakeup()
    {
        $this->_objRequestParams= new stdClass();
    }
    public function __sleep()
    {
        return ['_requestIdentifier','_objRequestInfo','invoice','orderId','signature', 'returnUrl', 'confirmUrl', 'cancelUrl','params','reqInstallments','selectedInstallments'];
    }

    public function getXml()
    {
        $this->_prepare();
        return $this->_xmlDoc;
    }
}
