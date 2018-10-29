<?php
/**
 * Fixer.io Import Rates
 *
 */

/**
 * Philwinkle_Fixerio_Model_Import class
 *
 * @category    Philwinkle
 * @package     Philwinkle_Fixerio
 */
class Philwinkle_Fixerio_Model_Import extends Mage_Directory_Model_Currency_Import_Abstract
{

    protected $_url = 'http://data.fixer.io/api/latest';
    protected $_messages = array();

    /**
     * HTTP client
     *
     * @var Varien_Http_Client
     */
    protected $_httpClient;

    public function __construct()
    {
        $this->_httpClient = new Varien_Http_Client();
    }

    /**
     * _getConfigAccessKey
     *
     * @return bool|mixed
     */
    protected function _getConfigAccessKey()
    {
        if ($accessKey = Mage::helper('core')->decrypt(Mage::getStoreConfig('currency/fixerio/access_key'))) {
            return $accessKey;
        }

        $this->_messages[] = Mage::helper('directory')
            ->__('Fixer.io access key missing.  Please obtain access key from fixer.io.');

        return false;
    }

    /**
     * getEndpointUrl
     *
     * @return string
     */
    public function getEndpointUrl()
    {
        return $this->_url;
    }

    /**
     * _convert
     *
     * @param string $currencyFrom
     * @param string $currencyTo
     * @param int    $retry
     *
     * @return float|null
     */
    protected function _convert($currencyFrom, $currencyTo, $retry = 0)
    {

        $queryParams = array(
            'access_key' => $this->_getConfigAccessKey(),
            'symbols'    => implode(',', array($currencyFrom, $currencyTo))
        );

        if (!$queryParams['access_key']) {
            return null;
        }

        try {
            $url = Mage::helper('core/url')->addRequestParam($this->getEndpointUrl(), $queryParams);

            $ch = curl_init();

            // set URL and other appropriate options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, Mage::getStoreConfig('currency/fixerio/timeout'));

            // grab URL and pass it to the browser
            $response = curl_exec($ch);
            curl_close($ch);

            /*$response = $this->_httpClient
                ->setUri($url)
                ->setConfig(array('timeout' => Mage::getStoreConfig('currency/fixerio/timeout')))
                ->request('GET')
                ->getBody();*/

            /** Second parameter is objectDecodeType - Zend_Json::TYPE_ARRAY, or Zend_Json::TYPE_OBJECT */
            $converted = Mage::helper('core')->jsonDecode($response, Zend_Json::TYPE_ARRAY);

            if (isset($converted['success'])) {
                if (!$converted['success']) {
                    $this->_messages[] = Mage::helper('directory')->__('Api Error: %s', $converted['error']['info']);
                    Mage::throwException($converted['error']['info']);
                }

                if (isset($converted['rates']) && $rates = $converted['rates']) {
                    if (isset($rates[$currencyTo], $rates[$currencyFrom])) {
                        $rate = $rates[$currencyTo] / $rates[$currencyFrom];

                        // test for bcmath to retain precision
                        if (function_exists('bcadd')) {
                            return bcadd($rate, '0', 12);
                        }

                        return (float) $rate;
                    }
                }

                Mage::throwException('Error fetching currency rates from API response');
            }
        } catch (Exception $e) {
            Mage::logException($e);
            if ($retry === 0) {
                return $this->_convert($currencyFrom, $currencyTo, 1);
            }

            $this->_messages[] = Mage::helper('directory')->__('Cannot retrieve rate from %s.', $url);
        }

        return null;
    }


    public function fetchRates()
    {
        $fetchOnlyBase = Mage::getStoreConfig('currency/fixerio/fetch_only_base');
        if(!$fetchOnlyBase) return parent::fetchRates();
        $data = array();
        $currencies = $this->_getCurrencyCodes();
        @set_time_limit(0);
        $baseCurrency = Mage::getStoreConfig('currency/fixerio/base');
        $defaultCurrencies = $this->_getDefaultCurrencyCodes();
        foreach ($defaultCurrencies as $currencyFrom) {
            if($currencyFrom != $baseCurrency) continue;
            if (!isset($data[$currencyFrom])) {
                $data[$currencyFrom] = array();
            }
            $convertedRates = $this->_convertAll($currencyFrom, $currencies);
            foreach ($convertedRates as $currencyTo => $currencyValue) {
                if ($currencyFrom == $currencyTo) {
                    $data[$currencyFrom][$currencyTo] = $this->_numberFormat(1);
                } else {
                    $data[$currencyFrom][$currencyTo] = $this->_numberFormat($currencyValue);
                    $data[$currencyTo][$currencyFrom] = $this->_numberFormat(1 / $currencyValue);
                }
            }
            ksort($data[$currencyFrom]);
        }

        return $data;
    }

    /**
     * _convert
     *
     * @param string $currencyFrom
     * @param string $currencyTo
     * @param int    $retry
     *
     * @return float|null
     */
    protected function _convertAll($currencyFrom, $currencyTo = array(), $retry = 0)
    {

        $queryParams = array(
            'access_key' => $this->_getConfigAccessKey(),
            'BASE'       => $currencyFrom,
            'symbols'    => implode(',', $currencyTo)
        );

        if (!$queryParams['access_key']) {
            return null;
        }

        try {
            $url = Mage::helper('core/url')->addRequestParam($this->getEndpointUrl(), $queryParams);
            $result = array();
            $ch = curl_init();

            // set URL and other appropriate options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, Mage::getStoreConfig('currency/fixerio/timeout'));

            // grab URL and pass it to the browser
            $response = curl_exec($ch);
            curl_close($ch);

            /** Second parameter is objectDecodeType - Zend_Json::TYPE_ARRAY, or Zend_Json::TYPE_OBJECT */
            $converted = Mage::helper('core')->jsonDecode($response, Zend_Json::TYPE_ARRAY);

            if (isset($converted['success'])) {
                if (!$converted['success']) {
                    $this->_messages[] = Mage::helper('directory')->__('Api Error: %s', $converted['error']['info']);
                    Mage::throwException($converted['error']['info']);
                }

                if (isset($converted['rates']) && $rates = $converted['rates']) {
                    foreach ($currencyTo as $currency){
                        if (isset($rates[$currency])) {
                            // test for bcmath to retain precision
                            if (function_exists('bcadd')) {
                                $result[$currency] = bcadd($rates[$currency], '0', 12);
                            }else{
                                $result[$currency] = (float) $rates[$currency];
                            }

                        }
                    }
                    return $result;
                }

                Mage::throwException('Error fetching currency rates from API response');
            }
        } catch (Exception $e) {
            Mage::logException($e);
            if ($retry === 0) {
                return $this->_convertAll($currencyFrom, $currencyTo, 1);
            }

            $this->_messages[] = Mage::helper('directory')->__('Cannot retrieve rate from %s.', $url);
        }

        return null;
    }

}
