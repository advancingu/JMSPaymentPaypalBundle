<?php
namespace JMS\Payment\PaypalBundle\Client;

use Symfony\Component\BrowserKit\Response as RawResponse;

use JMS\Payment\CoreBundle\BrowserKit\Request;
use JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException;
use JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface;

/*
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

abstract class AbstractClient
{
    protected $authenticationStrategy;

    protected $isDebug;

    protected $curlOptions;
    
    /** @var $responseClass string */
    protected $responseClass;

    public function __construct(AuthenticationStrategyInterface $authenticationStrategy, $isDebug, $responseClass)
    {
        $this->authenticationStrategy = $authenticationStrategy;
        $this->isDebug = !!$isDebug;
        $this->curlOptions = array();
        $this->responseClass = $responseClass;
    }

    /**
     * Sends a request with $parameters to PayPal API.
     * Add any constant client parameters and headers here and then call the 
     * request implementation executeSendApiRequest().
     * 
     * @param array $parameters
     * @return JMS\Payment\PaypalBundle\Client\Response\ResponseInterface
     */
    public function sendApiRequest(array $parameters)
    {
        throw new \RuntimeException('Method "sendApiRequest()" must be implemented in a subclass of AbstractClient.');
    }
    
    protected function executeSendApiRequest(array $parameters, array $headers = array())
    {
        // setup request, and authenticate it
        $request = new Request(
            $this->authenticationStrategy->getApiEndpoint($this->isDebug),
            'POST',
            $parameters,
            $headers
        );
        $this->authenticationStrategy->authenticate($request);

        $response = $this->request($request);
        if (200 !== $response->getStatus()) {
            throw new CommunicationException('The API request was not successful (Status: '.$response->getStatus().'): '.$response->getContent());
        }

        $parameters = array();
        parse_str($response->getContent(), $parameters);

        $r = new ReflectionClass($this->responseClass);
        return $r->newInstanceArgs($parameters);
    }
    
    /**
     * @param string $token
     * @return string URL to call in order to authorize the payment represented by $token
     */
    public function getAuthenticateExpressCheckoutTokenUrl($token)
    {
        throw new \RuntimeException('Method "getAuthenticateExpressCheckoutTokenUrl($token)" must be implemented in a subclass of AbstractClient.');
    }

    public function convertAmountToPaypalFormat($amount)
    {
        return number_format($amount, 2, '.', '');
    }

    public function setCurlOption($name, $value)
    {
        $this->curlOptions[$name] = $value;
    }

    /**
     * A small helper to url-encode an array
     *
     * @param array $encode
     * @return string
     */
    protected function urlEncodeArray(array $encode)
    {
        $encoded = '';
        foreach ($encode as $name => $value) {
            $encoded .= '&'.urlencode($name).'='.urlencode($value);
        }

        return substr($encoded, 1);
    }

    /**
     * Performs a request to an external payment service
     *
     * @throws CommunicationException when an curl error occurs
     * @param Request $request
     * @param mixed $parameters either an array for form-data, or an url-encoded string
     * @return Response
     */
    public function request(Request $request)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The cURL extension must be loaded.');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt_array($curl, $this->curlOptions);
        curl_setopt($curl, CURLOPT_URL, $request->getUri());
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);

        // add headers
        $headers = array();
        foreach ($request->headers->all() as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    $headers[] = sprintf('%s: %s', $name, $subValue);
                }
            } else {
                $headers[] = sprintf('%s: %s', $name, $value);
            }
        }
        if (count($headers) > 0) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        // set method
        $method = strtoupper($request->getMethod());
        if ('POST' === $method) {
            curl_setopt($curl, CURLOPT_POST, true);

            if (!$request->headers->has('Content-Type') || 'multipart/form-data' !== $request->headers->get('Content-Type')) {
                $postFields = $this->urlEncodeArray($request->request->all());
            } else {
                $postFields = $request->request->all();
            }

            curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
        } else if ('PUT' === $method) {
            curl_setopt($curl, CURLOPT_PUT, true);
        } else if ('HEAD' === $method) {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        }

        // perform the request
        if (false === $returnTransfer = curl_exec($curl)) {
            throw new CommunicationException(
                'cURL Error: '.curl_error($curl), curl_errno($curl)
            );
        }

        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headers = array();
        if (preg_match_all('#^([^:\r\n]+):\s+([^\n\r]+)#m', substr($returnTransfer, 0, $headerSize), $matches)) {
            foreach ($matches[1] as $key => $name) {
                $headers[$name] = $matches[2][$key];
            }
        }

        $response = new RawResponse(
            substr($returnTransfer, $headerSize),
            curl_getinfo($curl, CURLINFO_HTTP_CODE),
            $headers
        );
        curl_close($curl);

        return $response;
    }
}
