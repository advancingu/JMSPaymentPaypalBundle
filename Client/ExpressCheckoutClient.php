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

class ExpressCheckoutClient extends AbstractClient
{
    const API_VERSION = '65.1';

    public function requestAddressVerify($email, $street, $postalCode)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'AddressVerify',
            'EMAIL'  => $email,
            'STREET' => $street,
            'ZIP'    => $postalCode,
        ));
    }

    public function requestBillOutstandingAmount($profileId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'BillOutstandingAmount',
            'PROFILEID' => $profileId,
        )));
    }

    public function requestCreateRecurringPaymentsProfile($token)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'CreateRecurringPaymentsProfile',
            'TOKEN' => $token,
        ));
    }

    public function requestDoAuthorization($transactionId, $amount, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoAuthorization',
            'TRANSACTIONID' => $transactionId,
            'AMT' => $this->convertAmountToPaypalFormat($amount),
        )));
    }

    public function requestDoCapture($authorizationId, $amount, $completeType, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoCapture',
            'AUTHORIZATIONID' => $authorizationId,
            'AMT' => $this->convertAmountToPaypalFormat($amount),
            'COMPLETETYPE' => $completeType,
        )));
    }

    public function requestDoDirectPayment($ipAddress, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoDirectPayment',
            'IPADDRESS' => $ipAddress,
        )));
    }

    public function requestDoExpressCheckoutPayment($token, $amount, $paymentAction, $payerId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoExpressCheckoutPayment',
            'TOKEN'  => $token,
            'PAYMENTREQUEST_0_AMT' => $this->convertAmountToPaypalFormat($amount),
            'PAYMENTREQUEST_0_PAYMENTACTION' => $paymentAction,
            'PAYERID' => $payerId,
        )));
    }

    public function requestDoVoid($authorizationId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'DoVoid',
            'AUTHORIZATIONID' => $authorizationId,
        )));
    }

    /**
     * Initiates an ExpressCheckout payment process
     *
     * Optional parameters can be found here:
     * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     *
     * @param float $amount
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param array $optionalParameters
     * @return Response
     */
    public function requestSetExpressCheckout($amount, $returnUrl, $cancelUrl, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'SetExpressCheckout',
            'PAYMENTREQUEST_0_AMT' => $this->convertAmountToPaypalFormat($amount),
            'RETURNURL' => $returnUrl,
            'CANCELURL' => $cancelUrl,
        )));
    }

    public function requestGetExpressCheckoutDetails($token)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'GetExpressCheckoutDetails',
            'TOKEN'  => $token,
        ));
    }

    public function requestGetTransactionDetails($transactionId)
    {
        return $this->sendApiRequest(array(
            'METHOD' => 'GetTransactionDetails',
            'TRANSACTIONID' => $transactionId,
        ));
    }

    public function requestRefundTransaction($transactionId, array $optionalParameters = array())
    {
        return $this->sendApiRequest(array_merge($optionalParameters, array(
            'METHOD' => 'RefundTransaction',
            'TRANSACTIONID' => $transactionId
        )));
    }

    public function sendApiRequest(array $parameters)
    {
        // include some default parameters
        $parameters['VERSION'] = self::API_VERSION;

        return $this->executeSendApiRequest($parameters);
    }

    public function getTokenAuthorizationUrl($token)
    {
        $host = $this->isDebug ? 'www.sandbox.paypal.com' : 'www.paypal.com';

        return sprintf(
            'https://%s/cgi-bin/webscr?cmd=_express-checkout&token=%s',
            $host,
            $token
        );
    }
}
