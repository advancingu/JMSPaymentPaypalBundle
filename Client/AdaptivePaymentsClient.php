<?php
namespace JMS\Payment\PaypalBundle\Client;

use Symfony\Component\BrowserKit\Response as RawResponse;

use JMS\Payment\CoreBundle\BrowserKit\Request;
use JMS\Payment\CoreBundle\Plugin\Exception\CommunicationException;
use JMS\Payment\PaypalBundle\Client\Authentication\AuthenticationStrategyInterface;

/*
 * Copyright 2013 Markus Weiland <mweiland@graph-ix.net>
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

class AdaptivePaymentsClient extends AbstractClient
{
    public function requestPay(array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'actionPath' => 'Pay',
        )));
    }

    public function requestExecutePayment(array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'actionPath' => 'ExecutePayment',
        )));
    }

    public function requestPaymentDetails(array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'actionPath' => 'PaymentDetails',
        )));
    }
    
    public function sendApiRequest(array $parameters)
    {
        // include some default parameters
//         $parameters['VERSION'] = self::API_VERSION;

        $headers = array();
        $headers['X-PAYPAL-REQUEST-DATA-FORMAT'] = 'NV';
        $headers['X-PAYPAL-RESPONSE-DATA-FORMAT'] = 'NV';
        
        return $this->executeSendApiRequest($parameters, $headers);
    }

    public function getTokenAuthorizationUrl($token)
    {
        $host = $this->isDebug ? 'www.sandbox.paypal.com' : 'www.paypal.com';

        return sprintf(
            'https://%s/cgi-bin/webscr?cmd=_ap-payment&paykey=%s',
            $host,
            $token
        );
    }
}
